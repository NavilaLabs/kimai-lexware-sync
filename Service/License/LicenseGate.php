<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\License\LicenseVerdict;

interface LicenseGate
{
    public function verdict(): LicenseVerdict;
}
