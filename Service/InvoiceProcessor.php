<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Customer;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Project\ProjectService;
use App\Repository\TimesheetRepository;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoiceTimesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseRequiredException;

final class InvoiceProcessor
{
    public function __construct(
        private readonly TrackedInvoiceRepository $trackedInvoiceRepository,
        private readonly TrackedInvoiceTimesheetRepository $trackedInvoiceTimesheetRepository,
        private readonly TimesheetRepository $timesheetRepository,
        private readonly InvoiceLineBuilder $lineBuilder,
        private readonly LexwareApiClient $client,
        private readonly ProjectService $projectService,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
        private readonly LicenseGate $licenseGate,
    ) {
    }

    /**
     * @param Timesheet[] $timesheets
     */
    public function convert(
        TrackedInvoice $trackedInvoice,
        array $timesheets,
        InvoiceLineShape $shape,
        bool $finalize,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->assertLicenseAllowsConversion();
        $this->assertCurrencyMatches($trackedInvoice);

        $requestBody = $this->buildRequestBody($trackedInvoice, $timesheets, $shape);

        $trackedInvoice->markCreationAttempted();
        $this->trackedInvoiceRepository->save($trackedInvoice);

        try {
            $response = $this->client->createInvoice($requestBody, $finalize);
        } catch (AmbiguousLexwareRequestException $exception) {
            throw $exception;
        } catch (LexwareApiException $exception) {
            $trackedInvoice->clearCreationAttempt();
            $this->trackedInvoiceRepository->save($trackedInvoice);

            throw $exception;
        }

        $newInvoiceId = (new LexwarePayload($response))->string('id');
        if ($newInvoiceId === '') {
            throw new LexwareApiException('Lexware did not return an id for the newly created invoice.');
        }

        $this->recordConversion($trackedInvoice, $newInvoiceId, $timesheets, $markProjectCompleted, $processedBy);
    }

    /**
     * @param Timesheet[] $timesheets
     */
    public function confirmExisting(
        TrackedInvoice $trackedInvoice,
        string $existingLexwareInvoiceId,
        array $timesheets,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->assertLicenseAllowsConversion();
        $this->assertCurrencyMatches($trackedInvoice);

        $this->recordConversion($trackedInvoice, $existingLexwareInvoiceId, $timesheets, $markProjectCompleted, $processedBy);
    }

    /**
     * @param Timesheet[] $timesheets
     */
    public function findPlausibleMatch(TrackedInvoice $trackedInvoice, array $timesheets, InvoiceLineShape $shape): ?LexwarePayload
    {
        $creationAttemptedAt = $trackedInvoice->getCreationAttemptedAt();
        if ($creationAttemptedAt === null) {
            return null;
        }

        $payload = $this->decodePayload($trackedInvoice);
        $contactId = $payload->nested('address')->string('contactId');

        if ($contactId === '') {
            return null;
        }

        $expectedTotal = $this->calculateExpectedGrossTotal($trackedInvoice, $timesheets, $shape);

        foreach ($this->client->findInvoices($contactId, $creationAttemptedAt) as $rawCandidate) {
            $candidate = new LexwarePayload($rawCandidate);

            $candidateId = $candidate->string('id');
            if ($candidateId === '' || $candidateId === $trackedInvoice->getLexwareId()) {
                continue;
            }

            if (!$candidate->has('totalAmount') || abs($candidate->float('totalAmount') - $expectedTotal) > 0.01) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param Timesheet[] $timesheets
     * @return array<string, mixed>
     */
    private function buildRequestBody(TrackedInvoice $trackedInvoice, array $timesheets, InvoiceLineShape $shape): array
    {
        $payload = $this->decodePayload($trackedInvoice);

        $originalLines = $payload->rawList('lineItems');

        $currency = $payload->nested('totalPrice')->string('currency', 'EUR');
        $taxRatePercentage = 19;

        foreach ($payload->nestedList('lineItems') as $line) {
            if (!$line->has('unitPrice')) {
                continue;
            }

            $unitPrice = $line->nested('unitPrice');
            $currency = $unitPrice->string('currency', $currency);
            $taxRatePercentage = $unitPrice->integer('taxRatePercentage', $taxRatePercentage);
            break;
        }

        $newLines = $this->lineBuilder->buildLines($timesheets, $shape, $taxRatePercentage, $currency);

        $requestBody = [
            'voucherDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'address' => $payload->nested('address')->toArray(),
            'lineItems' => array_merge($originalLines, $newLines),
            'taxConditions' => $payload->has('taxConditions') ? $payload->nested('taxConditions')->toArray() : ['taxType' => 'net'],
            'shippingConditions' => $payload->has('shippingConditions') ? $payload->nested('shippingConditions')->toArray() : [
                'shippingType' => 'service',
                'shippingDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            ],
            'totalPrice' => ['currency' => $currency],
        ];

        $carried = $payload->toArray();
        foreach (['title', 'introduction', 'remark', 'paymentConditions', 'language'] as $carriedField) {
            if (isset($carried[$carriedField])) {
                $requestBody[$carriedField] = $carried[$carriedField];
            }
        }

        return $requestBody;
    }

    /**
     * @param Timesheet[] $timesheets
     */
    private function calculateExpectedGrossTotal(TrackedInvoice $trackedInvoice, array $timesheets, InvoiceLineShape $shape): float
    {
        $requestBody = $this->buildRequestBody($trackedInvoice, $timesheets, $shape);
        $total = 0.0;

        foreach ((new LexwarePayload($requestBody))->nestedList('lineItems') as $line) {
            $unitPrice = $line->nested('unitPrice');
            $quantity = $line->float('quantity');
            $netAmount = $unitPrice->float('netAmount');
            $taxRatePercentage = $unitPrice->float('taxRatePercentage');

            $total += $quantity * $netAmount * (1 + $taxRatePercentage / 100);
        }

        return round($total, 2);
    }

    /**
     * @param Timesheet[] $timesheets
     */
    private function recordConversion(
        TrackedInvoice $trackedInvoice,
        string $newInvoiceId,
        array $timesheets,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->entityManager->beginTransaction();

        try {
            $trackedInvoice->markConverted($newInvoiceId, $processedBy);
            $this->trackedInvoiceRepository->save($trackedInvoice);

            $timesheetIds = [];

            foreach ($timesheets as $timesheet) {
                $id = $timesheet->getId();
                if ($id !== null) {
                    $timesheetIds[] = $id;
                }

                $this->trackedInvoiceTimesheetRepository->save(
                    new TrackedInvoiceTimesheet($trackedInvoice, $timesheet, $timesheet->getModifiedAt()),
                );
            }

            if (\count($timesheetIds) > 0) {
                $this->timesheetRepository->setExported($timesheetIds);
            }

            if ($markProjectCompleted) {
                $this->completeProject($trackedInvoice);
            }

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    private function assertLicenseAllowsConversion(): void
    {
        $verdict = $this->licenseGate->verdict();
        if (!$verdict->allowsConversion()) {
            throw new LicenseRequiredException($verdict);
        }
    }

    private function assertCurrencyMatches(TrackedInvoice $trackedInvoice): void
    {
        $customer = $trackedInvoice->getRelatedOrderConfirmation()->getCustomer();
        if ($customer !== null && $customer->getCurrency() !== Customer::DEFAULT_CURRENCY) {
            throw new CustomerCurrencyMismatchException(\sprintf(
                'Customer "%s" uses currency "%s" instead of "%s".',
                $customer->getName(),
                $customer->getCurrency(),
                Customer::DEFAULT_CURRENCY,
            ));
        }
    }

    private function completeProject(TrackedInvoice $trackedInvoice): void
    {
        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        if ($project === null) {
            return;
        }

        if ($this->configuration->getProjectCompletionMode() === LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN) {
            $project->setVisible(false);
        } else {
            $project->setEnd(new \DateTime());
        }

        $this->projectService->updateProject($project);
    }

    private function decodePayload(TrackedInvoice $trackedInvoice): LexwarePayload
    {
        return LexwarePayload::fromJson($trackedInvoice->getRawPayload());
    }
}
