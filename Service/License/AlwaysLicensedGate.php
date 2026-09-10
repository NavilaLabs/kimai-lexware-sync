<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\License\LicenseVerdict;

final class AlwaysLicensedGate implements LicenseGate
{
    public function verdict(): LicenseVerdict
    {
        return LicenseVerdict::licensed('', null);
    }
}
