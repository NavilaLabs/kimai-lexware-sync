<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Dto\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\License\LicenseState;

final class LicenseVerdict
{
    private function __construct(
        public readonly LicenseState $state,
        public readonly string $customer,
        public readonly ?string $reason,
        public readonly ?\DateTimeImmutable $confirmedAt,
    ) {
    }

    public static function licensed(string $customer, ?\DateTimeImmutable $confirmedAt): self
    {
        return new self(LicenseState::Licensed, $customer, null, $confirmedAt);
    }

    public static function refused(
        LicenseState $state,
        ?string $reason = null,
        string $customer = '',
        ?\DateTimeImmutable $confirmedAt = null,
    ): self {
        return new self($state, $customer, $reason, $confirmedAt);
    }

    public function allowsConversion(): bool
    {
        return $this->state === LicenseState::Licensed;
    }
}
