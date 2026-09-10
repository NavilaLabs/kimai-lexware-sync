<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Dto\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\HealthCheck\HealthCheckResult;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultTest extends TestCase
{
    public function testASuccessWithinTheIntervalIsNotStale(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, true, null);

        self::assertFalse($result->isStale(7, $checkedAt->modify('+6 days')));
    }

    public function testAResultOlderThanTheIntervalIsStaleRegardlessOfWhetherItPassed(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $success = new HealthCheckResult('api_key', $checkedAt, true, null);
        $failure = new HealthCheckResult('api_key', $checkedAt, false, 'no longer authenticates');

        self::assertTrue($success->isStale(7, $checkedAt->modify('+8 days')));
        self::assertTrue($failure->isStale(7, $checkedAt->modify('+8 days')));
    }

    public function testExactlyOnTheIntervalIsNotYetStale(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, true, null);

        self::assertFalse($result->isStale(7, $checkedAt->modify('+7 days')));
    }

    public function testItRoundTripsThroughJson(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, false, 'no longer authenticates');

        $restored = HealthCheckResult::fromJson($result->toJson());

        self::assertSame('api_key', $restored->checkName);
        self::assertEquals($checkedAt, $restored->checkedAt);
        self::assertFalse($restored->ok);
        self::assertSame('no longer authenticates', $restored->message);
    }

    public function testAMalformedStoredValueIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\JsonException::class);

        HealthCheckResult::fromJson('not json');
    }
}
