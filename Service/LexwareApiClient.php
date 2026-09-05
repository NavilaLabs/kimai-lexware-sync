<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareApiClient
{
    private const BASE_URL = 'https://api.lexware.io';
    private const MINIMUM_INTERVAL_SECONDS = 0.5;
    private const ORDER_CONFIRMATION_STATUSES = 'draft,open,accepted,rejected,voided';
    private const INVOICE_STATUSES = 'draft,open,paidoff,voided';

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderConfirmation(string $lexwareId): array
    {
        return $this->request('GET', '/v1/order-confirmations/' . $lexwareId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listOrderConfirmationVoucherPage(int $page): array
    {
        return $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'orderconfirmation',
            'voucherStatus' => self::ORDER_CONFIRMATION_STATUSES,
            'page' => $page,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $lexwareId): array
    {
        return $this->request('GET', '/v1/invoices/' . $lexwareId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listInvoiceVoucherPage(int $page): array
    {
        return $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'invoice',
            'voucherStatus' => self::INVOICE_STATUSES,
            'page' => $page,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload, bool $finalize): array
    {
        return $this->request('POST', '/v1/invoices', $finalize ? ['finalize' => 'true'] : [], $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findInvoices(string $contactId, \DateTimeImmutable $voucherDateFrom): array
    {
        $result = $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'invoice',
            'voucherStatus' => 'draft',
            'contactId' => $contactId,
            'voucherDateFrom' => $voucherDateFrom->format('Y-m-d'),
        ]);

        $content = $result['content'] ?? [];

        return \is_array($content) ? $content : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listEventSubscriptions(): array
    {
        $result = $this->request('GET', '/v1/event-subscriptions');
        $content = $result['content'] ?? $result;

        return \is_array($content) ? array_values($content) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createEventSubscription(string $eventType, string $callbackUrl): array
    {
        return $this->request('POST', '/v1/event-subscriptions', [], [
            'eventType' => $eventType,
            'callbackUrl' => $callbackUrl,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $this->pace();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->getApiKey(),
                'Accept' => 'application/json',
            ],
            'query' => $query,
        ];

        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new AmbiguousLexwareRequestException(\sprintf('Request to %s could not be confirmed: %s', $path, $exception->getMessage()), 0, $exception);
        } catch (ExceptionInterface $exception) {
            throw new LexwareApiException(\sprintf('Request to %s failed: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if ($statusCode >= 500 || $statusCode === 408) {
            throw new AmbiguousLexwareRequestException(\sprintf('Lexware API returned status %d for %s, which may or may not have been applied: %s', $statusCode, $path, $content));
        }

        if ($statusCode >= 400) {
            throw new LexwareApiException(\sprintf('Lexware API returned status %d for %s: %s', $statusCode, $path, $content));
        }

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            throw new LexwareApiException(\sprintf('Lexware API returned a non-object response for %s', $path));
        }

        return $decoded;
    }

    private function pace(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestAt;

        if ($this->lastRequestAt > 0.0 && $elapsed < self::MINIMUM_INTERVAL_SECONDS) {
            usleep((int) ((self::MINIMUM_INTERVAL_SECONDS - $elapsed) * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }
}
