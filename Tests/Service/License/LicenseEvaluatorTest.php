<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseEvaluator;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseSignatureVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use PHPUnit\Framework\TestCase;

final class LicenseEvaluatorTest extends TestCase
{
    private string $secretKey = '';
    private LicenseEvaluator $evaluator;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->evaluator = new LicenseEvaluator(
            new LicenseSignatureVerifier([base64_encode(sodium_crypto_sign_publickey($pair))])
        );
    }

    public function testAGenuineCurrentTokenIsUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0', 'valid_until' => '2026-10-06T00:00:00+02:00']);

        self::assertNotNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testNothingStoredIsNotUsable(): void
    {
        self::assertNull($this->evaluator->usableToken(null, '1.0.0', $this->now()));
        self::assertNull($this->evaluator->usableToken('', '1.0.0', $this->now()));
    }

    public function testAMalformedTokenIsNotUsable(): void
    {
        self::assertNull($this->evaluator->usableToken('nonsense', '1.0.0', $this->now()));
    }

    public function testATokenWithABrokenSignatureIsNotUsable(): void
    {
        // The signed body says false and the pasted body says true, so the two really differ.
        // Signing and pasting the same content would leave the signature valid and the
        // assertion impossible to satisfy.
        $signedForARefusal = $this->sign(['licensed' => false, 'version' => '1.0.0']);
        $tampered = self::encode('{"licensed":true,"version":"1.0.0"}') . '.' . explode('.', $signedForARefusal)[1];

        self::assertNull($this->evaluator->usableToken($tampered, '1.0.0', $this->now()));
    }

    public function testATokenIssuedForAnotherVersionIsNotUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0']);

        self::assertNull($this->evaluator->usableToken($raw, '1.1.0', $this->now()));
    }

    public function testATokenPastItsValidityIsNotUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0', 'valid_until' => '2026-09-01T00:00:00+02:00']);

        self::assertNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testATokenWithoutAValidityDateNeverGoesStale(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0']);

        self::assertNotNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testAnApprovalBecomesALicensedVerdict(): void
    {
        $raw = $this->sign([
            'licensed' => true,
            'version' => '1.0.0',
            'customer' => 'Example GmbH',
            'issued_at' => '2026-09-06T10:00:00+02:00',
        ]);
        $token = $this->evaluator->usableToken($raw, '1.0.0', $this->now());
        self::assertNotNull($token);

        $verdict = $this->evaluator->verdictFor($token);

        self::assertSame(LicenseState::Licensed, $verdict->state);
        self::assertTrue($verdict->allowsConversion());
        self::assertSame('Example GmbH', $verdict->customer);
        self::assertEquals(new \DateTimeImmutable('2026-09-06T10:00:00+02:00'), $verdict->confirmedAt);
    }

    /**
     * @return array<string, array{0: string, 1: LicenseState}>
     */
    public static function refusals(): array
    {
        return [
            'expired' => ['expired', LicenseState::Rejected],
            'revoked' => ['revoked', LicenseState::Rejected],
            'unknown key' => ['unknown_key', LicenseState::Rejected],
            'version not covered' => ['version_not_covered', LicenseState::VersionNotCovered],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalKeepsItsReasonAndMapsToAState(string $reason, LicenseState $expected): void
    {
        $raw = $this->sign(['licensed' => false, 'version' => '1.0.0', 'reason' => $reason]);
        $token = $this->evaluator->usableToken($raw, '1.0.0', $this->now());
        self::assertNotNull($token);

        $verdict = $this->evaluator->verdictFor($token);

        self::assertSame($expected, $verdict->state);
        self::assertSame($reason, $verdict->reason);
        self::assertFalse($verdict->allowsConversion());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06T12:00:00+02:00');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sign(array $body): string
    {
        if ($this->secretKey === '') {
            throw new \RuntimeException('Secret key not initialized');
        }

        $encodedBody = self::encode(json_encode($body, JSON_THROW_ON_ERROR));

        return $encodedBody . '.' . self::encode(sodium_crypto_sign_detached($encodedBody, $this->secretKey));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
