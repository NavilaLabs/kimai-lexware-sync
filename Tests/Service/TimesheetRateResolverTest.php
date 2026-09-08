<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use App\Entity\Timesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\TimesheetRateResolver;
use PHPUnit\Framework\TestCase;

final class TimesheetRateResolverTest extends TestCase
{
    public function testHourlyRateIsUsedWhenItIsSet(): void
    {
        $resolver = new TimesheetRateResolver();
        $timesheet = new Timesheet();
        $timesheet->setDuration(5400);
        $timesheet->setHourlyRate(80.0);

        self::assertSame(1.5, $resolver->hoursFor($timesheet));
        self::assertSame(80.0, $resolver->hourlyRateFor($timesheet));
        self::assertSame(120.0, $resolver->invoicedAmountFor($timesheet));
    }

    public function testFixedRateIsSpreadOverTheTrackedHours(): void
    {
        $resolver = new TimesheetRateResolver();
        $timesheet = new Timesheet();
        $timesheet->setDuration(7200);
        $timesheet->setHourlyRate(null);
        $timesheet->setRate(300.0);

        self::assertSame(150.0, $resolver->hourlyRateFor($timesheet));
        self::assertSame(300.0, $resolver->invoicedAmountFor($timesheet));
    }

    public function testTimesheetWithoutAnyRateIsWorthNothing(): void
    {
        $resolver = new TimesheetRateResolver();
        $timesheet = new Timesheet();
        $timesheet->setDuration(3600);
        $timesheet->setHourlyRate(null);

        self::assertSame(0.0, $resolver->hourlyRateFor($timesheet));
        self::assertSame(0.0, $resolver->invoicedAmountFor($timesheet));
    }

    public function testZeroDurationDoesNotDivideByZero(): void
    {
        $resolver = new TimesheetRateResolver();
        $timesheet = new Timesheet();
        $timesheet->setDuration(0);
        $timesheet->setHourlyRate(null);
        $timesheet->setRate(100.0);

        self::assertSame(0.0, $resolver->hoursFor($timesheet));
        self::assertSame(0.0, $resolver->hourlyRateFor($timesheet));
        self::assertSame(0.0, $resolver->invoicedAmountFor($timesheet));
    }

    public function testInvoicedValuesAreRoundedTheSameWayTheInvoiceLineIs(): void
    {
        $resolver = new TimesheetRateResolver();
        $timesheet = new Timesheet();
        $timesheet->setDuration(3670);
        $timesheet->setHourlyRate(99.999);

        self::assertSame(1.02, $resolver->invoicedQuantityFor($timesheet));
        self::assertSame(100.0, $resolver->invoicedUnitPriceFor($timesheet));
        self::assertSame(102.0, $resolver->invoicedAmountFor($timesheet));
    }
}
