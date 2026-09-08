<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

enum LicenseState
{
    case Licensed;
    case NoKeyConfigured;
    case NoSigningKeyConfigured;
    case Rejected;
    case SignatureInvalid;
    case Unreachable;
    case VersionNotCovered;

    public function key(): string
    {
        return match ($this) {
            self::Licensed => 'licensed',
            self::NoKeyConfigured => 'no_key_configured',
            self::NoSigningKeyConfigured => 'no_signing_key_configured',
            self::Rejected => 'rejected',
            self::SignatureInvalid => 'signature_invalid',
            self::Unreachable => 'unreachable',
            self::VersionNotCovered => 'version_not_covered',
        };
    }
}
