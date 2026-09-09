<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\WebhookSubscriptionOutcome;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\WebhookSubscriptionConnector;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\InMemorySettingReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WebhookSubscriptionConnectorTest extends TestCase
{
    private const ORDER_CONFIRMATION_CALLBACK_URL = 'https://example.test/webhook/lexware/order-confirmation';
    private const INVOICE_CALLBACK_URL = 'https://example.test/webhook/lexware/invoice';

    private function createClient(callable $responseFactory): LexwareApiClient
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.api_key' => 'test-key']));

        return new LexwareApiClient(new MockHttpClient($responseFactory), $configuration);
    }

    /**
     * @param array<string, mixed> $requestOptions
     */
    private function readPostedEventType(array $requestOptions): string
    {
        if (!\is_string($requestOptions['body'] ?? null)) {
            throw new \RuntimeException('Expected the request options to carry a string body.');
        }

        $body = json_decode($requestOptions['body'], true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($body) || !\is_string($body['eventType'] ?? null)) {
            throw new \RuntimeException('Expected a decoded request body with a string eventType.');
        }

        return $body['eventType'];
    }

    public function testConnectCreatesAllSixSubscriptionsWhenNoneExistYet(): void
    {
        $createdEventTypes = [];
        $client = $this->createClient(function (string $method, string $url) use (&$createdEventTypes) {
            if ($method === 'GET') {
                return new MockResponse('[]', ['http_code' => 200]);
            }

            $createdEventTypes[] = $url;

            return new MockResponse('{"id":"new"}', ['http_code' => 200]);
        });

        $connector = new WebhookSubscriptionConnector($client);
        $results = $connector->connect(self::ORDER_CONFIRMATION_CALLBACK_URL, self::INVOICE_CALLBACK_URL);

        self::assertCount(6, $results);
        self::assertCount(6, $createdEventTypes);
        foreach ($results as $result) {
            self::assertSame(WebhookSubscriptionOutcome::Created, $result->outcome);
        }
    }

    public function testConnectSkipsEventTypeAlreadySubscribedWithMatchingCallbackUrl(): void
    {
        $postedEventTypes = [];
        $client = $this->createClient(function (string $method, string $url, array $options) use (&$postedEventTypes) {
            if ($method === 'GET') {
                return new MockResponse(json_encode([
                    ['eventType' => 'invoice.changed', 'callbackUrl' => self::INVOICE_CALLBACK_URL],
                ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            $postedEventTypes[] = $this->readPostedEventType($options);

            return new MockResponse('{"id":"new"}', ['http_code' => 200]);
        });

        $connector = new WebhookSubscriptionConnector($client);
        $results = $connector->connect(self::ORDER_CONFIRMATION_CALLBACK_URL, self::INVOICE_CALLBACK_URL);

        self::assertCount(6, $results);
        self::assertNotContains('invoice.changed', $postedEventTypes);
        self::assertCount(5, $postedEventTypes);

        $invoiceChangedResult = array_values(array_filter($results, fn ($result) => $result->eventType === 'invoice.changed'))[0];
        self::assertSame(WebhookSubscriptionOutcome::AlreadyConnected, $invoiceChangedResult->outcome);
    }

    public function testConnectRecreatesSubscriptionWhenCallbackUrlChanged(): void
    {
        $postedEventTypes = [];
        $client = $this->createClient(function (string $method, string $url, array $options) use (&$postedEventTypes) {
            if ($method === 'GET') {
                return new MockResponse(json_encode([
                    ['eventType' => 'invoice.changed', 'callbackUrl' => 'https://old-tunnel.test/webhook/lexware/invoice'],
                ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }

            $postedEventTypes[] = $this->readPostedEventType($options);

            return new MockResponse('{"id":"new"}', ['http_code' => 200]);
        });

        $connector = new WebhookSubscriptionConnector($client);
        $connector->connect(self::ORDER_CONFIRMATION_CALLBACK_URL, self::INVOICE_CALLBACK_URL);

        self::assertContains('invoice.changed', $postedEventTypes);
    }

    public function testConnectReportsOneFailureWithoutStoppingTheOthers(): void
    {
        $client = $this->createClient(function (string $method, string $url, array $options) {
            if ($method === 'GET') {
                return new MockResponse('[]', ['http_code' => 200]);
            }

            if ($this->readPostedEventType($options) === 'invoice.status.changed') {
                return new MockResponse('{"message":"already exists"}', ['http_code' => 409]);
            }

            return new MockResponse('{"id":"new"}', ['http_code' => 200]);
        });

        $connector = new WebhookSubscriptionConnector($client);
        $results = $connector->connect(self::ORDER_CONFIRMATION_CALLBACK_URL, self::INVOICE_CALLBACK_URL);

        self::assertCount(6, $results);

        $failed = array_values(array_filter($results, fn ($result) => $result->outcome === WebhookSubscriptionOutcome::Failed));
        self::assertCount(1, $failed);
        self::assertSame('invoice.status.changed', $failed[0]->eventType);
        self::assertNotNull($failed[0]->errorMessage);

        $created = array_values(array_filter($results, fn ($result) => $result->outcome === WebhookSubscriptionOutcome::Created));
        self::assertCount(5, $created);
    }
}
