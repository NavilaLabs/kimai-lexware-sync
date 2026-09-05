<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareWebhookVerifier
{
    private const PUBLIC_KEY_URL = 'https://developers.lexware.io/webhookSignature/public/public_key.pub';
    private const SIGNATURE_HEADER = 'x-lxo-signature';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
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
        try {
            return $this->cache->get('lexware_sync.webhook_public_key', function (ItemInterface $item): string {
                $item->expiresAfter(86400);

                $pem = $this->httpClient->request('GET', self::PUBLIC_KEY_URL)->getContent();
                if ($pem === '') {
                    throw new \RuntimeException('Lexware public key fetch returned an empty response');
                }

                return $pem;
            });
        } catch (\Throwable) {
            return null;
        }
    }
}
