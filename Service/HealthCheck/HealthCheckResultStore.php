<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\HealthCheck\HealthCheckResult;

final class HealthCheckResultStore
{
    private const CONFIGURATION_KEY_PREFIX = 'lexware_sync.health_check.';

    public function __construct(private readonly ConfigurationRepository $repository)
    {
    }

    public function store(HealthCheckResult $result): void
    {
        $key = self::CONFIGURATION_KEY_PREFIX . $result->checkName;
        $stored = $this->repository->findOneBy(['name' => $key]);
        if ($stored === null) {
            $stored = new Configuration();
            $stored->setName($key);
        }

        $stored->setValue($result->toJson());
        $this->repository->saveConfiguration($stored);
    }

    public function latest(string $checkName): ?HealthCheckResult
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY_PREFIX . $checkName]);
        $value = $stored?->getValue();

        return \is_string($value) && $value !== '' ? HealthCheckResult::fromJson($value) : null;
    }
}
