<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareApiClient
{
    private const BASE_URL = 'https://api.lexware.io';
    private const MINIMUM_INTERVAL_SECONDS = 0.5;
    private const ORDER_CONFIRMATION_STATUSES = 'draft,open,accepted,rejected,voided';

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
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
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $this->pace();

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $exception) {
            throw new LexwareApiException(\sprintf('Request to %s failed: %s', $path, $exception->getMessage()), 0, $exception);
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
