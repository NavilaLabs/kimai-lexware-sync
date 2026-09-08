<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseSignatureVerifier
{
    /**
     * @var list<string>
     */
    private readonly array $publicKeys;

    /**
     * @param list<string> $base64PublicKeys
     */
    public function __construct(array $base64PublicKeys)
    {
        $keys = [];
        foreach ($base64PublicKeys as $encoded) {
            $key = base64_decode($encoded, true);
            if ($key !== false && \strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }

        $this->publicKeys = $keys;
    }

    public function acceptsAnySigningKey(): bool
    {
        return $this->publicKeys !== [];
    }

    public function isSignatureValid(LicenseToken $token): bool
    {
        if (\strlen($token->signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        foreach ($this->publicKeys as $publicKey) {
            if (sodium_crypto_sign_verify_detached($token->signature, $token->signedContent, $publicKey)) {
                return true;
            }
        }

        return false;
    }
}
