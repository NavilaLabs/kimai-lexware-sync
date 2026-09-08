<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum OrderConfirmationStatus: string
{
    case Pending = 'pending';
    case AutomaticallyConverted = 'automatically_converted';
    case ManuallyConverted = 'manually_converted';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isConverted(): bool
    {
        return $this === self::AutomaticallyConverted || $this === self::ManuallyConverted;
    }

    public function isRejected(): bool
    {
        return $this === self::Rejected;
    }

    public function isConvertible(): bool
    {
        return $this === self::Pending || $this === self::Rejected;
    }
}
