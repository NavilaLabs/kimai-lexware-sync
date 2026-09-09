<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use App\Configuration\ConfigurationService;
use App\Configuration\SystemConfiguration;
use App\Entity\Configuration;
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

    /**
     * The plugin reads its own settings from the configuration table rather than from Kimai's
     * cached snapshot, so a test that only set the snapshot would configure something the code
     * under test never sees. This writes the row and keeps the snapshot in step with it.
     */
    protected function configure(string $key, string|int|bool|null $value): void
    {
        $configurationService = $this->service(ConfigurationService::class);

        $stored = $configurationService->getConfiguration($key) ?? (new Configuration())->setName($key);
        $configurationService->saveConfiguration($stored->setValue($value));

        $this->service(SystemConfiguration::class)->set($key, $value);
    }

    protected function forgetEntityFactory(): void
    {
        $this->entityFactory = null;
    }
}
