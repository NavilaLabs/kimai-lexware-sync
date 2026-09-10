<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class HealthCheckResultStoreTest extends FunctionalTestCase
{
    public function testNothingStoredYieldsNull(): void
    {
        self::assertNull($this->store()->latest('api_key'));
    }

    public function testAStoredResultComesBackForTheSameCheckName(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $this->store()->store(new HealthCheckResult('api_key', $checkedAt, true, null));

        $latest = $this->store()->latest('api_key');

        self::assertNotNull($latest);
        self::assertTrue($latest->ok);
        self::assertEquals($checkedAt, $latest->checkedAt);
    }

    public function testDifferentChecksAreStoredSeparately(): void
    {
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable(), true, null));

        self::assertNull($this->store()->latest('license'));
    }

    public function testStoringTwiceForTheSameCheckReplacesRatherThanAccumulates(): void
    {
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable('2026-09-01'), false, 'first failure'));
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable('2026-09-10'), true, null));

        $latest = $this->store()->latest('api_key');

        self::assertNotNull($latest);
        self::assertTrue($latest->ok);
    }

    private function store(): HealthCheckResultStore
    {
        return $this->service(HealthCheckResultStore::class);
    }
}
