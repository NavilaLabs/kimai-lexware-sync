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
        $payload = new LexwarePayload($this->client->getInvoice($lexwareId));
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing !== null && $existing->getStatus()->isTerminal()) {
            return;
        }

        $remoteUpdatedAt = $payload->dateTime('updatedDate');

        if ($existing !== null && $remoteUpdatedAt !== null && $existing->getRemoteUpdatedAt() !== null) {
            if ($remoteUpdatedAt <= $existing->getRemoteUpdatedAt()) {
                return;
            }
        }

        $voucherStatus = $payload->string('voucherStatus');

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

        $title = $payload->string('title');
        if (!$this->matchingRuleEvaluator->matchesTitle($title, $this->configuration->getInvoiceTitleRegex())) {
            return;
        }

        $trackedInvoice = $existing ?? new TrackedInvoice($lexwareId, $relatedOrderConfirmation);

        if ($trackedInvoice->getStatus() === InvoiceStatus::Rejected) {
            $trackedInvoice->reopen();
        }

        $trackedInvoice->updateFromLexwarePayload(
            $payload->string('voucherNumber'),
            $payload->dateTime('voucherDate') ?? new \DateTimeImmutable(),
            $payload->nested('address')->string('name'),
            json_encode($payload->toArray(), \JSON_THROW_ON_ERROR),
            $remoteUpdatedAt,
        );

        $this->repository->save($trackedInvoice);
    }

    private function resolveRelatedOrderConfirmation(LexwarePayload $payload): ?TrackedOrderConfirmation
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
     * @return list<string>
     */
    private static function extractRelatedVoucherIds(LexwarePayload $payload): array
    {
        $ids = [];

        foreach ($payload->nestedList('relatedVouchers') as $relatedVoucher) {
            $id = $relatedVoucher->string('id');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
