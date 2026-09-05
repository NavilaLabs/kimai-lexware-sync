<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\AmbiguousLexwareRequestException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LexwareApiClientTest extends TestCase
{
    public function testGetOrderConfirmationSendsBearerTokenAndDecodesResponse(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"abc","voucherNumber":"AB0001"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->getOrderConfirmation('abc');

        self::assertSame('AB0001', $result['voucherNumber']);
        self::assertSame('GET', $seenRequest[0]);
        self::assertStringContainsString('/v1/order-confirmations/abc', $seenRequest[1]);
        self::assertSame('Authorization: Bearer test-key', $seenRequest[2]['normalized_headers']['authorization'][0]);
    }

    public function testErrorStatusThrowsException(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"message":"not found"}', ['http_code' => 404]));
        $client = new LexwareApiClient($httpClient, 'test-key');

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

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->createInvoice(['voucherDate' => '2026-09-05'], true);

        self::assertSame('new-invoice-id', $result['id']);
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

        $client = new LexwareApiClient($httpClient, 'test-key');

        $this->expectException(AmbiguousLexwareRequestException::class);
        $client->createInvoice(['voucherDate' => '2026-09-05'], false);
    }

    public function testGetInvoiceSendsBearerTokenAndDecodesResponse(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"id":"abc","voucherStatus":"draft"}', ['http_code' => 200]));

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->getInvoice('abc');

        self::assertSame('draft', $result['voucherStatus']);
    }
}
