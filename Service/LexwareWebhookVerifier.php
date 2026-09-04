<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareWebhookVerifier
{
    private const PUBLIC_KEY_URL = 'https://developers.lexware.io/webhookSignature/public/public_key.pub';
    private const SIGNATURE_HEADER = 'x-lxo-signature';

    private ?string $publicKey = null;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    public function verify(string $rawBody, array $headers): bool
    {
        $signatureHeader = $headers[self::SIGNATURE_HEADER][0] ?? null;
        if (!\is_string($signatureHeader) || $signatureHeader === '') {
            return false;
        }

        $signature = base64_decode($signatureHeader, true);
        if ($signature === false) {
            return false;
        }

        $publicKey = $this->getPublicKey();
        if ($publicKey === null) {
            return false;
        }

        return openssl_verify($rawBody, $signature, $publicKey, OPENSSL_ALGO_SHA512) === 1;
    }

    private function getPublicKey(): ?string
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        try {
            $pem = $this->httpClient->request('GET', self::PUBLIC_KEY_URL)->getContent();
        } catch (\Throwable) {
            return null;
        }

        if ($pem === '') {
            return null;
        }

        $this->publicKey = $pem;

        return $pem;
    }
}
