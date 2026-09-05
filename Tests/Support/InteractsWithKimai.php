<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use App\Configuration\SystemConfiguration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

trait InteractsWithKimai
{
    private ?KimaiEntityFactory $entityFactory = null;

    protected function container(): ContainerInterface
    {
        return self::getContainer();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $identifier
     *
     * @return T
     */
    protected function service(string $identifier): object
    {
        $service = $this->container()->get($identifier);

        if (!$service instanceof $identifier) {
            throw new \RuntimeException(\sprintf('The test container holds no service of type %s', $identifier));
        }

        return $service;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return $this->service(EntityManagerInterface::class);
    }

    protected function lexware(): FakeLexwareHttpClient
    {
        return $this->service(FakeLexwareHttpClient::class);
    }

    protected function factory(): KimaiEntityFactory
    {
        return $this->entityFactory ??= new KimaiEntityFactory($this->entityManager());
    }

    protected function configure(string $key, mixed $value): void
    {
        $this->service(SystemConfiguration::class)->set($key, $value);
    }

    protected function forgetEntityFactory(): void
    {
        $this->entityFactory = null;
    }
}
