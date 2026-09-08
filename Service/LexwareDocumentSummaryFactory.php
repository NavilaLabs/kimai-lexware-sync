<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class LexwareDocumentSummaryFactory
{
    public function fromRawPayload(string $rawPayload): LexwareDocumentSummary
    {
        $payload = json_decode($rawPayload, true);
        if (!\is_array($payload)) {
            $payload = [];
        }

        $address = \is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $totalPrice = \is_array($payload['totalPrice'] ?? null) ? $payload['totalPrice'] : [];
        $lineItems = \is_array($payload['lineItems'] ?? null) ? $payload['lineItems'] : [];
        $files = \is_array($payload['files'] ?? null) ? $payload['files'] : [];

        $contactName = $address['name'] ?? '';
        $totalNetAmount = $totalPrice['totalNetAmount'] ?? 0;
        $currency = $totalPrice['currency'] ?? 'EUR';
        $documentFileId = $files['documentFileId'] ?? null;

        return new LexwareDocumentSummary(
            \is_string($contactName) ? $contactName : '',
            \is_numeric($totalNetAmount) ? (float) $totalNetAmount : 0.0,
            \is_string($currency) ? $currency : 'EUR',
            \count($lineItems),
            \is_string($documentFileId) && $documentFileId !== '',
        );
    }
}
