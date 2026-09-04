<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use PHPUnit\Framework\TestCase;
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
}
