<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final readonly class LexwareDocumentSummary
{
    public function __construct(
        public string $contactName,
        public float $totalNetAmount,
        public string $currency,
        public int $lineItemCount,
        public bool $hasDocumentFile,
    ) {
    }
}
