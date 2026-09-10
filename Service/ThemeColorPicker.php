<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Configuration\SystemConfiguration;

final class ThemeColorPicker
{
    private const FALLBACK_COLOR = '#c0c0c0';

    public function __construct(private readonly SystemConfiguration $systemConfiguration)
    {
    }

    public function pick(): string
    {
        $colors = array_values($this->systemConfiguration->getThemeColors());
        if (\count($colors) === 0) {
            return self::FALLBACK_COLOR;
        }

        return $colors[array_rand($colors)];
    }
}
