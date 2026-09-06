<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

interface LicenseGate
{
    public function verdict(): LicenseVerdict;
}
