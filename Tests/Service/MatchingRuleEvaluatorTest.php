<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\MatchingRuleEvaluator;
use PHPUnit\Framework\TestCase;

final class MatchingRuleEvaluatorTest extends TestCase
{
    public function testEmptyTitleRegexMatchesEverything(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesTitle('Auftragsbestätigung', ''));
    }

    public function testTitleRegexMustMatch(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesTitle('Projekt Alpha', '/^Projekt/'));
        self::assertFalse($evaluator->matchesTitle('Auftragsbestätigung', '/^Projekt/'));
    }

    public function testTextTypeLineNeverMatches(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertFalse($evaluator->matchesLine('text', 'irrelevant', 'irrelevant', ''));
    }

    public function testEmptyLineRegexMatchesEveryNonTextLine(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesLine('custom', 'Beratung', null, ''));
    }

    public function testLineRegexChecksNameAndDescription(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesLine('custom', 'Beratung', null, '/Beratung/'));
        self::assertTrue($evaluator->matchesLine('custom', 'Position', 'enthält Beratung', '/Beratung/'));
        self::assertFalse($evaluator->matchesLine('custom', 'Lieferung', 'Versandkosten', '/Beratung/'));
    }
}
