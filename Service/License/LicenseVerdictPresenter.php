<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseVerdictPresenter
{
    /**
     * @return array{state: string, customer: string, reason: string|null}
     */
    public function present(LicenseVerdict $verdict): array
    {
        return [
            'state' => $verdict->state->key(),
            'customer' => $verdict->customer,
            'reason' => $verdict->reason,
        ];
    }
}
