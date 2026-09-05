<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests;

use App\Kernel;
use KimaiPlugin\KimaiLexwareSyncBundle\KimaiLexwareSyncBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class TestKernel extends Kernel
{
    private ?string $kimaiDirectory = null;

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new KimaiLexwareSyncBundle();
    }

    public function getProjectDir(): string
    {
        return $this->kimaiDirectory ??= $this->locateKimaiDirectory();
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/plugin-test';
    }

    public function boot(): void
    {
        $dataDirectory = $this->getProjectDir() . '/var/plugin-test-data';
        if (!is_dir($dataDirectory) && !mkdir($dataDirectory, 0o775, true) && !is_dir($dataDirectory)) {
            throw new \RuntimeException(\sprintf('Cannot create the test data directory at %s', $dataDirectory));
        }

        parent::boot();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $loader->load(__DIR__ . '/config/test_environment.yaml');
    }

    private function locateKimaiDirectory(): string
    {
        $directory = __DIR__;

        while ($directory !== \dirname($directory)) {
            if (is_file($directory . '/src/Kernel.php') && is_file($directory . '/config/bundles.php')) {
                return $directory;
            }

            $directory = \dirname($directory);
        }

        throw new \RuntimeException('Cannot locate the Kimai installation above ' . __DIR__);
    }
}
