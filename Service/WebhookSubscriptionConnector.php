<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\WebhookSubscriptionOutcome;

final class WebhookSubscriptionConnector
{
    private const ORDER_CONFIRMATION_EVENT_TYPES = [
        'order-confirmation.created',
        'order-confirmation.changed',
        'order-confirmation.status.changed',
    ];

    private const INVOICE_EVENT_TYPES = [
        'invoice.created',
        'invoice.changed',
        'invoice.status.changed',
    ];

    public function __construct(private readonly LexwareApiClient $client)
    {
    }

    /**
     * @return WebhookSubscriptionResult[]
     */
    public function connect(string $orderConfirmationCallbackUrl, string $invoiceCallbackUrl): array
    {
        $targets = [];
        foreach (self::ORDER_CONFIRMATION_EVENT_TYPES as $eventType) {
            $targets[$eventType] = $orderConfirmationCallbackUrl;
        }
        foreach (self::INVOICE_EVENT_TYPES as $eventType) {
            $targets[$eventType] = $invoiceCallbackUrl;
        }

        $existingSubscriptions = $this->client->listEventSubscriptions();

        $results = [];
        foreach ($targets as $eventType => $callbackUrl) {
            if ($this->isAlreadySubscribed($existingSubscriptions, $eventType, $callbackUrl)) {
                $results[] = new WebhookSubscriptionResult($eventType, WebhookSubscriptionOutcome::AlreadyConnected);
                continue;
            }

            try {
                $this->client->createEventSubscription($eventType, $callbackUrl);
                $results[] = new WebhookSubscriptionResult($eventType, WebhookSubscriptionOutcome::Created);
            } catch (LexwareApiException|AmbiguousLexwareRequestException $exception) {
                $results[] = new WebhookSubscriptionResult($eventType, WebhookSubscriptionOutcome::Failed, $exception->getMessage());
            }
        }

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $existingSubscriptions
     */
    private function isAlreadySubscribed(array $existingSubscriptions, string $eventType, string $callbackUrl): bool
    {
        foreach ($existingSubscriptions as $subscription) {
            if (($subscription['eventType'] ?? null) === $eventType && ($subscription['callbackUrl'] ?? null) === $callbackUrl) {
                return true;
            }
        }

        return false;
    }
}
