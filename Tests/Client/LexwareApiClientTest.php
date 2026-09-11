<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Client;

use KimaiPlugin\KimaiLexwareSyncBundle\Client\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\AmbiguousLexwareRequestException;
use KimaiPlugin\KimaiLexwareSyncBundle\Exception\LexwareApiException;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\InMemorySettingReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LexwareApiClientTest extends TestCase
{
    private function createConfiguration(string $apiKey): LexwareSyncConfiguration
    {
        return new LexwareSyncConfiguration(new InMemorySettingReader(['lexware_sync.api_key' => $apiKey]));
    }

    public function testGetOrderConfirmationSendsBearerTokenAndDecodesResponse(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"abc","voucherNumber":"AB0001"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->getOrderConfirmation('abc');

        self::assertSame('AB0001', $result['voucherNumber']);
        self::assertNotNull($seenRequest, 'The client made no request at all.');
        self::assertSame('GET', $seenRequest[0]);
        self::assertStringContainsString('/v1/order-confirmations/abc', $seenRequest[1]);
        self::assertSame('Authorization: Bearer test-key', $seenRequest[2]['normalized_headers']['authorization'][0]);
    }

    public function testErrorStatusThrowsException(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"message":"not found"}', ['http_code' => 404]));
        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));

        $this->expectException(LexwareApiException::class);
        $client->getOrderConfirmation('missing');
    }

    public function testCreateInvoiceSendsFinalizeQueryParameterAndJsonBody(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"new-invoice-id"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->createInvoice(['voucherDate' => '2026-09-05'], true);

        self::assertSame('new-invoice-id', $result['id']);
        self::assertNotNull($seenRequest, 'The client made no request at all.');
        self::assertSame('POST', $seenRequest[0]);
        self::assertStringContainsString('/v1/invoices', $seenRequest[1]);
        self::assertStringContainsString('finalize=true', $seenRequest[1]);
        self::assertSame('{"voucherDate":"2026-09-05"}', $seenRequest[2]['body']);
    }

    public function testAmbiguousTransportFailureThrowsDistinctException(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new TransportException('Connection timed out');
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));

        $this->expectException(AmbiguousLexwareRequestException::class);
        $client->createInvoice(['voucherDate' => '2026-09-05'], false);
    }

    public function testGetInvoiceSendsBearerTokenAndDecodesResponse(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"id":"abc","voucherStatus":"draft"}', ['http_code' => 200]));

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->getInvoice('abc');

        self::assertSame('draft', $result['voucherStatus']);
    }

    public function testFindInvoicesQueriesVoucherListWithContactAndDateFilter(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"content":[{"id":"invoice-1"}]}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->findInvoices('contact-1', new \DateTimeImmutable('2026-01-01'));

        self::assertSame([['id' => 'invoice-1']], $result);
        self::assertNotNull($seenRequest, 'The client made no request at all.');
        self::assertSame('GET', $seenRequest[0]);
        self::assertStringContainsString('/v1/voucherlist', $seenRequest[1]);
        self::assertStringContainsString('voucherType=invoice', $seenRequest[1]);
        self::assertStringContainsString('contactId=contact-1', $seenRequest[1]);
        self::assertStringContainsString('voucherDateFrom=2026-01-01', $seenRequest[1]);
    }

    public function testListEventSubscriptionsDecodesBareArrayResponse(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse(
            '[{"id":"sub-1","eventType":"invoice.changed","callbackUrl":"https://example.test/webhook/lexware/invoice"}]',
            ['http_code' => 200],
        ));

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->listEventSubscriptions();

        self::assertSame(
            [['id' => 'sub-1', 'eventType' => 'invoice.changed', 'callbackUrl' => 'https://example.test/webhook/lexware/invoice']],
            $result,
        );
    }

    public function testListEventSubscriptionsDecodesContentWrappedResponse(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"content":[{"id":"sub-1","eventType":"invoice.changed"}]}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->listEventSubscriptions();

        self::assertSame([['id' => 'sub-1', 'eventType' => 'invoice.changed']], $result);
        self::assertNotNull($seenRequest, 'The client made no request at all.');
        self::assertSame('GET', $seenRequest[0]);
        self::assertStringContainsString('/v1/event-subscriptions', $seenRequest[1]);
    }

    public function testCreateEventSubscriptionSendsEventTypeAndCallbackUrl(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"sub-1","eventType":"order-confirmation.changed"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, $this->createConfiguration('test-key'));
        $result = $client->createEventSubscription('order-confirmation.changed', 'https://example.test/webhook/lexware/order-confirmation');

        self::assertSame('sub-1', $result['id']);
        self::assertNotNull($seenRequest, 'The client made no request at all.');
        self::assertSame('POST', $seenRequest[0]);
        self::assertStringContainsString('/v1/event-subscriptions', $seenRequest[1]);
        self::assertSame(
            '{"eventType":"order-confirmation.changed","callbackUrl":"https:\/\/example.test\/webhook\/lexware\/order-confirmation"}',
            $seenRequest[2]['body'],
        );
    }
}
