<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

enum LicenseState
{
    case Licensed;
    case NoKeyConfigured;
    case Rejected;
    case Unreachable;
    case VersionNotCovered;
}
