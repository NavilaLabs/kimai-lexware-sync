<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;

final class HealthCheckBanner
{
    public function __construct(
        private readonly HealthCheckResultStore $resultStore,
        private readonly HealthCheckStatusFormatter $statusFormatter,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    /**
     * @return list<array{check: string, state: string, message: string}>
     */
    public function messages(): array
    {
        $now = new \DateTimeImmutable();
        $messages = [];

        $apiKey = $this->statusFormatter->format(
            $this->resultStore->latest('api_key'),
            $this->configuration->getCheckApiKeyIntervalDays(),
            $now,
        );
        if ($apiKey !== null) {
            $messages[] = ['check' => 'api_key'] + $apiKey;
        }

        $license = $this->statusFormatter->format(
            $this->resultStore->latest('license'),
            $this->configuration->getCheckLicenseIntervalDays(),
            $now,
        );
        if ($license !== null) {
            $messages[] = ['check' => 'license'] + $license;
        }

        return $messages;
    }
}
