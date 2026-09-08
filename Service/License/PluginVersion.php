<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use App\Plugin\PluginMetadata;

final class PluginVersion
{
    public function current(): string
    {
        return PluginMetadata::createFromPath(\dirname(__DIR__, 2))->getVersion();
    }
}
