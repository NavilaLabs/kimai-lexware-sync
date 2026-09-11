<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\License\LicenseVerdict;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LicenseVerdictMessageFormatter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function format(LicenseVerdict $verdict): string
    {
        $message = $this->translator->trans('lexware_sync.license.' . $verdict->state->key(), [], 'messages');

        if ($verdict->reason !== null) {
            $message .= ' ' . $this->translator->trans('lexware_sync.license.reason.' . $verdict->reason, [], 'messages');
        }

        return $message;
    }
}
