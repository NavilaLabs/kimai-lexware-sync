<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Activity\ActivityService;
use App\Customer\CustomerService;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Project\ProjectService;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\License\LicenseRequiredException;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\UnprocessableOrderConfirmationException;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;

final class OrderConfirmationProcessor
{
    public function __construct(
        private readonly ContactMappingRepository $contactMappingRepository,
        private readonly CustomerService $customerService,
        private readonly ProjectService $projectService,
        private readonly ActivityService $activityService,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly LicenseGate $licenseGate,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly OrderConfirmationLineActivityFactory $activityFactory,
        private readonly ThemeColorPicker $colorPicker,
    ) {
    }

    public function convert(
        TrackedOrderConfirmation $orderConfirmation,
        LexwarePayload $payload,
        ?User $processedBy,
        string $lineRegex,
        bool $readLinesEnabled
    ): void {
        $verdict = $this->licenseGate->verdict();
        if (!$verdict->allowsConversion()) {
            throw new LicenseRequiredException($verdict);
        }

        $voucherNumberLength = \strlen($orderConfirmation->getVoucherNumber());
        if ($voucherNumberLength < 2 || $voucherNumberLength > 50) {
            throw new UnprocessableOrderConfirmationException(\sprintf(
                'Order confirmation "%s" has a voucher number that is unusable as a project name.',
                $orderConfirmation->getLexwareId(),
            ));
        }

        $address = $payload->nested('address');
        $contactId = $address->string('contactId');
        $contactName = $address->string('name', $orderConfirmation->getTitle());

        $customer = $this->resolveCustomer($contactId, $contactName);

        $project = $this->projectService->createNewProject($customer);
        $project->setName($this->resolveProjectName($orderConfirmation, $customer));
        $project->setStart(\DateTime::createFromImmutable($orderConfirmation->getVoucherDate()));
        $project->setOrderNumber($orderConfirmation->getVoucherNumber());
        $project->setOrderDate(\DateTime::createFromImmutable($orderConfirmation->getVoucherDate()));
        $project->setComment($orderConfirmation->getTitle());
        $project->setColor($this->colorPicker->pick());
        $this->projectService->saveProject($project);

        $orderConfirmation->setCustomer($customer);
        $orderConfirmation->setProject($project);

        if ($processedBy !== null) {
            $orderConfirmation->markManuallyConverted($processedBy);
        } else {
            $orderConfirmation->markAutomaticallyConverted();
        }

        if ($readLinesEnabled) {
            $this->convertLines($orderConfirmation, $payload->nestedList('lineItems'), $project, $lineRegex);
        }
    }

    private function resolveProjectName(TrackedOrderConfirmation $orderConfirmation, Customer $customer): string
    {
        $voucherNumber = $orderConfirmation->getVoucherNumber();

        $candidate = match ($this->configuration->getProjectTitleSource()) {
            LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE => $orderConfirmation->getTitle(),
            LexwareSyncConfiguration::PROJECT_TITLE_CUSTOMER_AND_TITLE => ($customer->getName() ?? '') . ' - ' . $orderConfirmation->getTitle(),
            default => $voucherNumber,
        };

        return $this->isUsableAsProjectName($candidate) ? $candidate : $voucherNumber;
    }

    private function isUsableAsProjectName(string $value): bool
    {
        $length = \strlen($value);
        if ($length < 2 || $length > 150) {
            return false;
        }

        foreach (['<', '>', '"', '='] as $character) {
            if (str_contains($value, $character)) {
                return false;
            }
        }

        return true;
    }

    private function resolveCustomer(string $contactId, string $contactName): Customer
    {
        $mapping = $this->contactMappingRepository->findByLexwareContactId($contactId);
        if ($mapping !== null) {
            $customer = $mapping->getCustomer();

            if ($customer->getCurrency() !== Customer::DEFAULT_CURRENCY) {
                throw new CustomerCurrencyMismatchException(\sprintf(
                    'Customer "%s" is mapped to Lexware contact "%s" but uses currency "%s" instead of "%s".',
                    $customer->getName(),
                    $contactId,
                    $customer->getCurrency(),
                    Customer::DEFAULT_CURRENCY,
                ));
            }

            return $customer;
        }

        $customer = $this->customerService->createNewCustomer($contactName);
        $this->customerService->saveCustomer($customer);

        $this->contactMappingRepository->save(new ContactMapping($contactId, $customer));

        return $customer;
    }

    /**
     * @param list<LexwarePayload> $lineItems
     */
    private function convertLines(TrackedOrderConfirmation $orderConfirmation, array $lineItems, Project $project, string $lineRegex): void
    {
        $deriveBudgetEnabled = $this->configuration->isDeriveBudgetEnabled();
        $budgetUnitRegex = $this->configuration->getBudgetUnitRegex();
        $projectTimeBudget = 0;
        $projectBudget = 0.0;

        foreach ($lineItems as $position => $lineItem) {
            $type = $lineItem->string('type', 'custom');
            $name = $lineItem->string('name');
            $description = $lineItem->nullableString('description');
            $quantity = $lineItem->float('quantity');
            $unitName = $lineItem->string('unitName');
            $netAmount = $lineItem->float('lineItemAmount');

            $matched = $this->matchingRuleEvaluator->matchesLine($type, $name, $description, $lineRegex);
            $isHourLine = $deriveBudgetEnabled && $this->matchingRuleEvaluator->matchesUnit($unitName, $budgetUnitRegex);

            $line = new TrackedOrderConfirmationLine($orderConfirmation, $position, $type, $name, $description, $matched, $quantity, $unitName, $netAmount, $isHourLine);
            $orderConfirmation->addLine($line);

            if ($isHourLine) {
                $projectTimeBudget += $this->activityFactory->timeBudgetFor($quantity);
                $projectBudget += $netAmount;
            }

            if (!$matched) {
                continue;
            }

            $activity = $this->activityFactory->createActivity($project, $name, $description);
            if ($activity === null) {
                continue;
            }

            if ($isHourLine) {
                $this->activityFactory->applyBudget($activity, $quantity, $netAmount);
            }

            $this->activityService->saveActivity($activity);

            $line->setActivity($activity);
        }

        if ($deriveBudgetEnabled) {
            $project->setTimeBudget($projectTimeBudget);
            $project->setBudget($projectBudget);
        }
    }
}
