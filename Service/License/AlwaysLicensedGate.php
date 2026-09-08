<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class AlwaysLicensedGate implements LicenseGate
{
    public function verdict(): LicenseVerdict
    {
        return LicenseVerdict::licensed('', null);
    }
}
