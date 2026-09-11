<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Entity;

use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use PHPUnit\Framework\TestCase;

final class TrackedOrderConfirmationLineTest extends TestCase
{
    public function testConstructionStoresTheBudgetRelevantFields(): void
    {
        $line = new TrackedOrderConfirmationLine(
            new TrackedOrderConfirmation('lexware-id-1'),
            0,
            'custom',
            'Development',
            'Building the thing',
            true,
            8.0,
            'Stunden',
            800.0,
            true,
        );

        self::assertSame(8.0, $line->getQuantity());
        self::assertSame('Stunden', $line->getUnitName());
        self::assertSame(800.0, $line->getNetAmount());
        self::assertTrue($line->isHourLine());
        self::assertFalse($line->isRemovedFromSource());
        self::assertSame(0, $line->getPosition());
    }

    public function testUpdateFromLexwareLineOverwritesTheStoredValues(): void
    {
        $line = new TrackedOrderConfirmationLine(
            new TrackedOrderConfirmation('lexware-id-2'),
            0,
            'custom',
            'Development',
            'Building the thing',
            true,
            8.0,
            'Stunden',
            800.0,
            true,
        );

        $line->updateFromLexwareLine('Development work', 'A longer description', 12.0, 'Stunden', 1200.0, true);

        self::assertSame('Development work', $line->getName());
        self::assertSame('A longer description', $line->getDescription());
        self::assertSame(12.0, $line->getQuantity());
        self::assertSame(1200.0, $line->getNetAmount());
    }

    public function testMarkRemovedFromSourceNeverTouchesTheActivity(): void
    {
        $line = new TrackedOrderConfirmationLine(
            new TrackedOrderConfirmation('lexware-id-3'),
            0,
            'custom',
            'Development',
            'Building the thing',
            true,
            8.0,
            'Stunden',
            800.0,
            true,
        );

        $line->markRemovedFromSource();

        self::assertTrue($line->isRemovedFromSource());
        self::assertNull($line->getActivity());
    }

    public function testUpdatingFromLexwareTakesALineBackOutOfTheRemovedState(): void
    {
        $line = new TrackedOrderConfirmationLine(
            new TrackedOrderConfirmation('lexware-id-4'),
            0,
            'custom',
            'Development',
            'Building the thing',
            true,
            8.0,
            'Stunden',
            800.0,
            true,
        );
        $line->markRemovedFromSource();

        $line->updateFromLexwareLine('Development', 'Building the thing', 8.0, 'Stunden', 800.0, true);

        self::assertFalse($line->isRemovedFromSource());
    }
}
