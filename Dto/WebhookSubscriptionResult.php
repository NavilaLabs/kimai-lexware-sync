<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Dto;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\WebhookSubscriptionOutcome;

final class WebhookSubscriptionResult
{
    public function __construct(
        public readonly string $eventType,
        public readonly WebhookSubscriptionOutcome $outcome,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
