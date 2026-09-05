<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isTerminal(): bool
    {
        return $this === self::Converted || $this === self::Superseded;
    }
}
