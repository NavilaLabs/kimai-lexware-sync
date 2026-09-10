<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum OrderConfirmationLineDiffStatus: string
{
    case Unchanged = 'unchanged';
    case Changed = 'changed';
    case New = 'new';
    case Removed = 'removed';
}
