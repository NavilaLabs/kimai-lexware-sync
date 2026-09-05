<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareWebhookVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LexwareWebhookVerifierTest extends TestCase
{
    private static string $publicKeyPem;
    private static string $privateKeyPem;

    public static function setUpBeforeClass(): void
    {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $privateKeyPem);
        self::$privateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($keyPair);
        self::$publicKeyPem = $details['key'];
    }

    public function testValidSignaturePasses(): void
    {
        $body = '{"eventType":"order-confirmation.changed","resourceId":"abc"}';
        $signature = $this->sign($body);
        $verifier = $this->createVerifier();

        self::assertTrue($verifier->verify($body, ['x-lxo-signature' => [$signature]]));
    }

    public function testTamperedBodyFails(): void
    {
        $body = '{"eventType":"order-confirmation.changed","resourceId":"abc"}';
        $signature = $this->sign($body);
        $verifier = $this->createVerifier();

        self::assertFalse($verifier->verify($body . 'tampered', ['x-lxo-signature' => [$signature]]));
    }

    public function testMissingHeaderFails(): void
    {
        $verifier = $this->createVerifier();

        self::assertFalse($verifier->verify('{}', []));
    }

    public function testEmptyHeaderValueArrayFails(): void
    {
        $verifier = $this->createVerifier();

        self::assertFalse($verifier->verify('{}', ['x-lxo-signature' => []]));
    }

    public function testInvalidBase64SignatureFails(): void
    {
        $verifier = $this->createVerifier();

        self::assertFalse($verifier->verify('{}', ['x-lxo-signature' => ['not valid base64!!!']]));
    }

    public function testPublicKeyFetchFailureReturnsFalseWithoutThrowing(): void
    {
        $body = '{"eventType":"order-confirmation.changed","resourceId":"abc"}';
        $signature = $this->sign($body);
        $httpClient = new MockHttpClient(function () {
            throw new TransportException('network down');
        });
        $verifier = new LexwareWebhookVerifier($httpClient, new ArrayAdapter());

        self::assertFalse($verifier->verify($body, ['x-lxo-signature' => [$signature]]));
    }

    public function testPublicKeyIsFetchedOnlyOnce(): void
    {
        $body = '{"eventType":"order-confirmation.changed","resourceId":"abc"}';
        $signature = $this->sign($body);
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount) {
            $requestCount++;

            return new MockResponse(self::$publicKeyPem, ['http_code' => 200]);
        });
        $verifier = new LexwareWebhookVerifier($httpClient, new ArrayAdapter());

        $verifier->verify($body, ['x-lxo-signature' => [$signature]]);
        $verifier->verify($body, ['x-lxo-signature' => [$signature]]);
        $verifier->verify($body, ['x-lxo-signature' => [$signature]]);

        self::assertSame(1, $requestCount);
    }

    private function sign(string $body): string
    {
        openssl_sign($body, $signature, self::$privateKeyPem, OPENSSL_ALGO_SHA512);

        return base64_encode($signature);
    }

    private function createVerifier(): LexwareWebhookVerifier
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse(self::$publicKeyPem, ['http_code' => 200]));

        return new LexwareWebhookVerifier($httpClient, new ArrayAdapter());
    }
}
