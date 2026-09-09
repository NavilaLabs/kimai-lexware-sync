<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

use App\Configuration\ConfigurationService;

final class DatabaseSettingReader implements SettingReader
{
    public function __construct(private readonly ConfigurationService $configurationService)
    {
    }

    public function read(string $name): ?string
    {
        return $this->configurationService->getConfiguration($name)?->getValue();
    }
}
