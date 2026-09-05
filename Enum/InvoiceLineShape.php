<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum InvoiceLineShape: string
{
    case PerTimesheet = 'per_timesheet';
    case AggregatedByActivity = 'aggregated_by_activity';
}
