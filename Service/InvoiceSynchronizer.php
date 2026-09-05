<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;

final class InvoiceSynchronizer
{
    private const DRAFT_STATUS = 'draft';

    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedInvoiceRepository $repository,
        private readonly TrackedOrderConfirmationRepository $orderConfirmationRepository,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    public function synchronize(string $lexwareId): void
    {
        $payload = $this->client->getInvoice($lexwareId);
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing !== null && $existing->getStatus()->isTerminal()) {
            return;
        }

        $remoteUpdatedAt = isset($payload['updatedDate']) ? new \DateTimeImmutable((string) $payload['updatedDate']) : null;

        if ($existing !== null && $remoteUpdatedAt !== null && $existing->getRemoteUpdatedAt() !== null) {
            if ($remoteUpdatedAt <= $existing->getRemoteUpdatedAt()) {
                return;
            }
        }

        $voucherStatus = (string) ($payload['voucherStatus'] ?? '');

        if ($voucherStatus !== self::DRAFT_STATUS) {
            if ($existing !== null) {
                $existing->markSuperseded();
                $this->repository->save($existing);
            }

            return;
        }

        $relatedOrderConfirmation = $this->resolveRelatedOrderConfirmation($payload);
        if ($relatedOrderConfirmation === null || $relatedOrderConfirmation->getProject() === null) {
            return;
        }

        $title = (string) ($payload['title'] ?? '');
        if (!$this->matchingRuleEvaluator->matchesTitle($title, $this->configuration->getInvoiceTitleRegex())) {
            return;
        }

        $trackedInvoice = $existing ?? new TrackedInvoice($lexwareId, $relatedOrderConfirmation);

        if ($trackedInvoice->getStatus() === InvoiceStatus::Rejected) {
            $trackedInvoice->reopen();
        }

        $address = \is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $contactName = $address['name'] ?? '';

        $trackedInvoice->updateFromLexwarePayload(
            (string) ($payload['voucherNumber'] ?? ''),
            new \DateTimeImmutable((string) ($payload['voucherDate'] ?? 'now')),
            \is_string($contactName) ? $contactName : '',
            json_encode($payload, \JSON_THROW_ON_ERROR),
            $remoteUpdatedAt,
        );

        $this->repository->save($trackedInvoice);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRelatedOrderConfirmation(array $payload): ?TrackedOrderConfirmation
    {
        foreach (self::extractRelatedVoucherIds($payload) as $relatedId) {
            $orderConfirmation = $this->orderConfirmationRepository->findByLexwareId($relatedId);
            if ($orderConfirmation !== null) {
                return $orderConfirmation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string[]
     */
    private static function extractRelatedVoucherIds(array $payload): array
    {
        $relatedVouchers = $payload['relatedVouchers'] ?? [];
        if (!\is_array($relatedVouchers)) {
            return [];
        }

        $ids = [];

        foreach ($relatedVouchers as $relatedVoucher) {
            if (!\is_array($relatedVoucher)) {
                continue;
            }

            $id = (string) ($relatedVoucher['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
