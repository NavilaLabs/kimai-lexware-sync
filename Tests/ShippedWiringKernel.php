<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests;

/**
 * Boots without the license enforcement override, so that a test can assert what a customer
 * installation is actually wired to.
 */
final class ShippedWiringKernel extends TestKernel
{
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/plugin-test-shipped';
    }

    protected function testConfigurationFiles(): array
    {
        return [__DIR__ . '/config/test_environment.yaml'];
    }
}
