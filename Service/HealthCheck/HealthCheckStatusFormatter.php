<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

use Symfony\Contracts\Translation\TranslatorInterface;

final class HealthCheckStatusFormatter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return array{state: string, message: string}|null
     */
    public function format(?HealthCheckResult $result, int $intervalDays, \DateTimeImmutable $now): ?array
    {
        if ($result === null) {
            return null;
        }

        if (!$result->ok) {
            return [
                'state' => 'failed',
                'message' => $this->translator->trans(
                    'lexware_sync.health_check.failed',
                    ['%message%' => $result->message ?? ''],
                    'messages',
                ),
            ];
        }

        if ($result->isStale($intervalDays, $now)) {
            return [
                'state' => 'stale',
                'message' => $this->translator->trans(
                    'lexware_sync.health_check.stale',
                    ['%days%' => (string) $intervalDays],
                    'messages',
                ),
            ];
        }

        return null;
    }
}
