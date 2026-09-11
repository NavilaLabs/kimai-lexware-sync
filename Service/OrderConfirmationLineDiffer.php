<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\OrderConfirmationLineDiffEntry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;

final class OrderConfirmationLineDiffer
{
    /**
     * @return list<OrderConfirmationLineDiffEntry>
     */
    public function diff(TrackedOrderConfirmation $orderConfirmation, LexwarePayload $payload): array
    {
        $oldLines = [];
        foreach ($orderConfirmation->getLines() as $line) {
            $oldLines[$line->getPosition()] = $line;
        }

        $newLines = $payload->nestedList('lineItems');

        $positions = array_unique(array_merge(array_keys($oldLines), array_keys($newLines)));
        sort($positions);

        $entries = [];
        foreach ($positions as $position) {
            $old = $oldLines[$position] ?? null;
            $new = $newLines[$position] ?? null;

            if ($new === null && $old !== null && $old->isRemovedFromSource()) {
                continue;
            }

            $entries[] = $this->buildEntry($position, $old, $new);
        }

        return $entries;
    }

    private function buildEntry(int $position, ?TrackedOrderConfirmationLine $old, ?LexwarePayload $new): OrderConfirmationLineDiffEntry
    {
        $newName = $new?->string('name');
        $newDescription = $new?->nullableString('description');
        $newQuantity = $new?->float('quantity');
        $newUnitName = $new?->string('unitName');
        $newNetAmount = $new?->float('lineItemAmount');

        $status = match (true) {
            $old === null => OrderConfirmationLineDiffStatus::New,
            $new === null => OrderConfirmationLineDiffStatus::Removed,
            $old->isRemovedFromSource() => OrderConfirmationLineDiffStatus::Changed,
            $old->getName() === $newName && $old->getDescription() === $newDescription
                && $old->getQuantity() === $newQuantity
                && $old->getUnitName() === $newUnitName && $old->getNetAmount() === $newNetAmount => OrderConfirmationLineDiffStatus::Unchanged,
            default => OrderConfirmationLineDiffStatus::Changed,
        };

        return new OrderConfirmationLineDiffEntry(
            $position,
            $status,
            $old?->getName(),
            $old?->getDescription(),
            $old?->getQuantity(),
            $old?->getUnitName(),
            $old?->getNetAmount(),
            $new?->string('type', 'custom'),
            $new === null ? null : $newName,
            $newDescription,
            $new === null ? null : $newQuantity,
            $new === null ? null : $newUnitName,
            $new === null ? null : $newNetAmount,
        );
    }
}
