<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum DocumentStatusFilter: string
{
    case Open = 'open';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case All = 'all';

    public static function fromRequestValue(mixed $value): self
    {
        if (!\is_string($value)) {
            return self::Open;
        }

        return self::tryFrom($value) ?? self::Open;
    }
}
