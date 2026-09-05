<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseSignatureVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseToken;
use PHPUnit\Framework\TestCase;

final class LicenseSignatureVerifierTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    public function testAGenuineSignaturePasses(): void
    {
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testAModifiedBodyFails(): void
    {
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);
        $token = $this->sign(['licensed' => false], $this->secretKey);

        $tampered = LicenseToken::parse(self::encode('{"licensed":true}') . '.' . explode('.', $token->raw())[1]);

        self::assertFalse($verifier->isSignatureValid($tampered));
    }

    public function testASignatureFromAnotherKeyFails(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);

        self::assertFalse($verifier->isSignatureValid(
            $this->sign(['licensed' => true], sodium_crypto_sign_secretkey($otherPair))
        ));
    }

    public function testAnyKeyOnTheAcceptedListIsEnoughSoThatARotationIsPossible(): void
    {
        $retiringPair = sodium_crypto_sign_keypair();
        $verifier = new LicenseSignatureVerifier([
            base64_encode(sodium_crypto_sign_publickey($retiringPair)),
            base64_encode($this->publicKey),
        ]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testAnUnusableEntryOnTheListIsIgnoredRatherThanFatal(): void
    {
        $verifier = new LicenseSignatureVerifier(['not base64 at all', base64_encode('too short'), base64_encode($this->publicKey)]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testWithoutAnyUsableKeyNothingIsValid(): void
    {
        $verifier = new LicenseSignatureVerifier([]);

        self::assertFalse($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sign(array $body, string $secretKey): LicenseToken
    {
        if ($secretKey === '') {
            throw new \RuntimeException('Secret key must not be empty');
        }

        $encodedBody = self::encode(json_encode($body, JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached($encodedBody, $secretKey);

        return LicenseToken::parse($encodedBody . '.' . self::encode($signature));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
