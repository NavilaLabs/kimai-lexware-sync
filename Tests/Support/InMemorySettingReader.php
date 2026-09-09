<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\SettingReader;

final class InMemorySettingReader implements SettingReader
{
    /**
     * @param array<string, string> $settings
     */
    public function __construct(private array $settings = [])
    {
    }

    public function read(string $name): ?string
    {
        return $this->settings[$name] ?? null;
    }

    public function write(string $name, string $value): void
    {
        $this->settings[$name] = $value;
    }
}
