<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseToken;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\MalformedLicenseToken;
use PHPUnit\Framework\TestCase;

final class LicenseTokenTest extends TestCase
{
    public function testAWellFormedTokenExposesEveryField(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => true,
            'customer' => 'Example GmbH',
            'version' => '1.0.0',
            'issued_at' => '2026-09-06T10:00:00+02:00',
            'recheck_after' => '2026-09-13T10:00:00+02:00',
            'valid_until' => '2026-10-06T10:00:00+02:00',
        ]));

        self::assertTrue($token->isLicensed());
        self::assertSame('Example GmbH', $token->customer());
        self::assertSame('1.0.0', $token->version());
        self::assertEquals(new \DateTimeImmutable('2026-09-13T10:00:00+02:00'), $token->recheckAfter());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:00:00+02:00'), $token->validUntil());
        self::assertNull($token->reason());
    }

    public function testARefusalCarriesItsReason(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => false,
            'customer' => 'Example GmbH',
            'version' => '1.0.0',
            'reason' => 'expired',
        ]));

        self::assertFalse($token->isLicensed());
        self::assertSame('expired', $token->reason());
    }

    public function testTheSignedContentIsTheEncodedBodyAndNotTheWholeToken(): void
    {
        $raw = self::tokenFor(['licensed' => true, 'version' => '1.0.0']);
        $token = LicenseToken::parse($raw);

        self::assertSame(explode('.', $raw)[0], $token->signedContent);
        self::assertSame($raw, $token->raw());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableTokens(): array
    {
        return [
            'empty' => [''],
            'one part' => ['abc'],
            'three parts' => ['a.b.c'],
            'body is not base64url' => ['!!!.' . self::encode('signature')],
            'body is not json' => [self::encode('not json') . '.' . self::encode('signature')],
            'body is a json list' => [self::encode('[1,2,3]') . '.' . self::encode('signature')],
            'signature is not base64url' => [self::encode('{"licensed":true}') . '.!!!'],
        ];
    }

    /**
     * @dataProvider unusableTokens
     */
    public function testAnUnusableTokenIsRefusedWithAnException(string $raw): void
    {
        $this->expectException(MalformedLicenseToken::class);

        LicenseToken::parse($raw);
    }

    public function testAnUnparseableDateIsNullRatherThanAnException(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => true,
            'version' => '1.0.0',
            'valid_until' => 'whenever',
        ]));

        self::assertNull($token->validUntil());
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function tokenFor(array $body): string
    {
        return self::encode(json_encode($body, JSON_THROW_ON_ERROR)) . '.' . self::encode('a signature');
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
