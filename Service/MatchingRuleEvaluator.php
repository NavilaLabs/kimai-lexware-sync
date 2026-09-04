<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class MatchingRuleEvaluator
{
    public function matchesTitle(string $title, string $titleRegex): bool
    {
        if ($titleRegex === '') {
            return true;
        }

        return preg_match($titleRegex, $title) === 1;
    }

    public function matchesLine(string $lineType, string $lineName, ?string $lineDescription, string $lineRegex): bool
    {
        if ($lineType === 'text') {
            return false;
        }

        if ($lineRegex === '') {
            return true;
        }

        if (preg_match($lineRegex, $lineName) === 1) {
            return true;
        }

        return $lineDescription !== null && preg_match($lineRegex, $lineDescription) === 1;
    }
}
