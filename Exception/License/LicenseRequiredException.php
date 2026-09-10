<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Exception\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\License\LicenseVerdict;

final class LicenseRequiredException extends \RuntimeException
{
    public function __construct(private readonly LicenseVerdict $verdict)
    {
        parent::__construct('This installation has no confirmed license, so nothing is converted.');
    }

    public function verdict(): LicenseVerdict
    {
        return $this->verdict;
    }
}
