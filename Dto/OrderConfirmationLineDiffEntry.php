<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Dto;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;

final class OrderConfirmationLineDiffEntry
{
    public function __construct(
        public readonly int $position,
        public readonly OrderConfirmationLineDiffStatus $status,
        public readonly ?string $oldName,
        public readonly ?string $oldDescription,
        public readonly ?float $oldQuantity,
        public readonly ?string $oldUnitName,
        public readonly ?float $oldNetAmount,
        public readonly ?string $newType,
        public readonly ?string $newName,
        public readonly ?string $newDescription,
        public readonly ?float $newQuantity,
        public readonly ?string $newUnitName,
        public readonly ?float $newNetAmount,
    ) {
    }
}
