<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckStatusFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HealthCheckStatusFormatterTest extends TestCase
{
    public function testNothingStoredYieldsNoMessage(): void
    {
        $formatter = new HealthCheckStatusFormatter($this->translator());

        self::assertNull($formatter->format(null, 7, new \DateTimeImmutable()));
    }

    public function testARecentSuccessYieldsNoMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-1 day'), true, null);

        $formatter = new HealthCheckStatusFormatter($this->translator());

        self::assertNull($formatter->format($result, 7, $now));
    }

    public function testARecentFailureYieldsAFailedMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-1 day'), false, 'no longer authenticates');

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('failed', $message['state']);
    }

    public function testAnOldSuccessYieldsAStaleMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-10 days'), true, null);

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('stale', $message['state']);
    }

    public function testAnOldFailureYieldsAFailedMessageRatherThanStale(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-10 days'), false, 'no longer authenticates');

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('failed', $message['state']);
    }

    private function translator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
