<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Activity\ActivityService;
use App\Configuration\SystemConfiguration;
use App\Customer\CustomerService;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use App\Project\ProjectService;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;

final class OrderConfirmationProcessor
{
    public function __construct(
        private readonly ContactMappingRepository $contactMappingRepository,
        private readonly CustomerService $customerService,
        private readonly ProjectService $projectService,
        private readonly ActivityService $activityService,
        private readonly SystemConfiguration $systemConfiguration,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function convert(
        TrackedOrderConfirmation $orderConfirmation,
        array $payload,
        ?User $processedBy,
        string $lineRegex,
        bool $readLinesEnabled
    ): void {
        $address = $payload['address'] ?? [];
        $contactId = (string) ($address['contactId'] ?? '');
        $contactName = (string) ($address['name'] ?? $orderConfirmation->getTitle());

        $customer = $this->resolveCustomer($contactId, $contactName);

        $project = $this->projectService->createNewProject($customer);
        $project->setName($orderConfirmation->getVoucherNumber());
        $project->setStart(\DateTime::createFromImmutable($orderConfirmation->getVoucherDate()));
        $project->setColor($this->pickRandomColor());
        $this->projectService->saveProject($project);

        $orderConfirmation->setCustomer($customer);
        $orderConfirmation->setProject($project);

        if ($processedBy !== null) {
            $orderConfirmation->markManuallyConverted($processedBy);
        } else {
            $orderConfirmation->markAutomaticallyConverted();
        }

        if ($readLinesEnabled) {
            $this->convertLines($orderConfirmation, $payload['lineItems'] ?? [], $project, $lineRegex);
        }
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
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function convertLines(TrackedOrderConfirmation $orderConfirmation, array $lineItems, Project $project, string $lineRegex): void
    {
        foreach ($lineItems as $position => $lineItem) {
            $type = (string) ($lineItem['type'] ?? 'custom');
            $name = (string) ($lineItem['name'] ?? '');
            $description = isset($lineItem['description']) ? (string) $lineItem['description'] : null;

            $matched = $this->matchingRuleEvaluator->matchesLine($type, $name, $description, $lineRegex);

            $line = new TrackedOrderConfirmationLine($orderConfirmation, $position, $type, $name, $description, $matched);
            $orderConfirmation->addLine($line);

            if (!$matched) {
                continue;
            }

            $activity = $this->activityService->createNewActivity($project);
            $activity->setName($name);
            $activity->setColor($this->pickRandomColor());
            $this->activityService->saveActivity($activity);

            $line->setActivity($activity);
        }
    }

    private function pickRandomColor(): string
    {
        $colors = array_values($this->systemConfiguration->getThemeColors());
        if (\count($colors) === 0) {
            return '#c0c0c0';
        }

        return $colors[array_rand($colors)];
    }
}
