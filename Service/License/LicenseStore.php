<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use App\Configuration\SystemConfiguration;
use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;

final class LicenseStore
{
    private const CONFIGURATION_KEY = 'lexware_sync.license_token';

    public function __construct(
        private readonly ConfigurationRepository $repository,
        private readonly SystemConfiguration $configuration,
    ) {
    }

    public function storedToken(string $licenseKey): ?string
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        $value = $stored?->getValue();
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $separator = strpos($value, ':');
        if ($separator === false) {
            return null;
        }

        $fingerprint = substr($value, 0, $separator);

        return hash_equals($fingerprint, $this->fingerprint($licenseKey)) ? substr($value, $separator + 1) : null;
    }

    public function store(string $licenseKey, string $rawToken): void
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        if ($stored === null) {
            $stored = new Configuration();
            $stored->setName(self::CONFIGURATION_KEY);
        }

        $stored->setValue($this->fingerprint($licenseKey) . ':' . $rawToken);
        $this->repository->saveConfiguration($stored);
        $this->configuration->set(self::CONFIGURATION_KEY, $stored->getValue());
    }

    public function forget(): void
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        if ($stored === null) {
            return;
        }

        $stored->setValue('');
        $this->repository->saveConfiguration($stored);
        $this->configuration->set(self::CONFIGURATION_KEY, '');
    }

    private function fingerprint(string $licenseKey): string
    {
        return hash('sha256', $licenseKey);
    }
}
