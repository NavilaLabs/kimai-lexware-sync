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

        $this->recordConversion($trackedInvoice, (string) ($response['id'] ?? ''), $timesheets, $markProjectCompleted, $processedBy);
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
        $this->assertCurrencyMatches($trackedInvoice);

        $this->recordConversion($trackedInvoice, $existingLexwareInvoiceId, $timesheets, $markProjectCompleted, $processedBy);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlausibleMatch(TrackedInvoice $trackedInvoice): ?array
    {
        $creationAttemptedAt = $trackedInvoice->getCreationAttemptedAt();
        if ($creationAttemptedAt === null) {
            return null;
        }

        $payload = $this->decodePayload($trackedInvoice);
        $address = $payload['address'] ?? [];
        $contactId = \is_array($address) ? (string) ($address['contactId'] ?? '') : '';

        if ($contactId === '') {
            return null;
        }

        foreach ($this->client->findInvoices($contactId, $creationAttemptedAt) as $candidate) {
            if (\is_array($candidate) && ($candidate['id'] ?? null) !== null) {
                return $candidate;
            }
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

        $originalLines = $payload['lineItems'] ?? [];
        $originalLines = \is_array($originalLines) ? $originalLines : [];

        $firstLine = \is_array($originalLines[0] ?? null) ? $originalLines[0] : [];
        $unitPrice = \is_array($firstLine['unitPrice'] ?? null) ? $firstLine['unitPrice'] : [];
        $currency = (string) ($unitPrice['currency'] ?? 'EUR');
        $taxRatePercentage = (int) ($unitPrice['taxRatePercentage'] ?? 19);

        $newLines = $this->lineBuilder->buildLines($timesheets, $shape, $taxRatePercentage, $currency);

        return [
            'voucherDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'address' => $payload['address'] ?? [],
            'lineItems' => array_merge($originalLines, $newLines),
            'taxConditions' => $payload['taxConditions'] ?? ['taxType' => 'net'],
            'shippingConditions' => $payload['shippingConditions'] ?? [
                'shippingType' => 'service',
                'shippingDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            ],
            'totalPrice' => ['currency' => $currency],
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(TrackedInvoice $trackedInvoice): array
    {
        $payload = json_decode($trackedInvoice->getRawPayload(), true);

        return \is_array($payload) ? $payload : [];
    }
}
