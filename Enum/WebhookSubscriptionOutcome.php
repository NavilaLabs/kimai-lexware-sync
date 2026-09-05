<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum WebhookSubscriptionOutcome: string
{
    case Created = 'created';
    case AlreadyConnected = 'already_connected';
    case Failed = 'failed';
}
