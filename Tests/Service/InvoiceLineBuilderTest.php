<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use App\Entity\Activity;
use App\Entity\Timesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceLineBuilder;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\TimesheetRateResolver;
use PHPUnit\Framework\TestCase;

final class InvoiceLineBuilderTest extends TestCase
{
    private function createTimesheet(string $activityName, int $durationSeconds, float $hourlyRate, ?string $description = null): Timesheet
    {
        $activity = new Activity();
        $activity->setName($activityName);

        $timesheet = new Timesheet();
        $timesheet->setActivity($activity);
        $timesheet->setBegin(new \DateTime('2026-09-01 09:00:00'));
        $timesheet->setDuration($durationSeconds);
        $timesheet->setHourlyRate($hourlyRate);
        $timesheet->setDescription($description);

        return $timesheet;
    }

    private function createFixedRateTimesheet(string $activityName, int $durationSeconds, float $rate, ?string $description = null): Timesheet
    {
        $activity = new Activity();
        $activity->setName($activityName);

        $timesheet = new Timesheet();
        $timesheet->setActivity($activity);
        $timesheet->setBegin(new \DateTime('2026-09-01 09:00:00'));
        $timesheet->setDuration($durationSeconds);
        $timesheet->setHourlyRate(null);
        $timesheet->setRate($rate);
        $timesheet->setDescription($description);

        return $timesheet;
    }

    public function testFixedRateTimesheetIsBilledAtRateDividedByHours(): void
    {
        $builder = new InvoiceLineBuilder(new TimesheetRateResolver());
        $timesheets = [
            $this->createFixedRateTimesheet('Beratung', 7200, 300.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::PerTimesheet, 19, 'EUR');

        self::assertCount(1, $lines);
        self::assertSame(2.0, $lines[0]['quantity']);
        self::assertSame(150.0, (new LexwarePayload($lines[0]))->nested('unitPrice')->float('netAmount'));
    }

    public function testPerTimesheetProducesOneLineEach(): void
    {
        $builder = new InvoiceLineBuilder(new TimesheetRateResolver());
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Entwicklung', 7200, 80.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::PerTimesheet, 19, 'EUR');

        self::assertCount(2, $lines);
        self::assertSame('Beratung', $lines[0]['name']);
        self::assertSame(1.0, $lines[0]['quantity']);
        self::assertSame(100.0, (new LexwarePayload($lines[0]))->nested('unitPrice')->float('netAmount'));
        self::assertSame(19, (new LexwarePayload($lines[0]))->nested('unitPrice')->integer('taxRatePercentage'));
        self::assertSame('EUR', (new LexwarePayload($lines[0]))->nested('unitPrice')->string('currency'));
        self::assertSame(2.0, $lines[1]['quantity']);
    }

    public function testAggregatedByActivityCombinesSameActivityIntoAWeightedRate(): void
    {
        $builder = new InvoiceLineBuilder(new TimesheetRateResolver());
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Beratung', 3600, 120.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::AggregatedByActivity, 19, 'EUR');

        self::assertCount(1, $lines);
        self::assertSame('Beratung', $lines[0]['name']);
        self::assertSame(2.0, $lines[0]['quantity']);
        self::assertSame(110.0, (new LexwarePayload($lines[0]))->nested('unitPrice')->float('netAmount'));
    }

    public function testAggregatedByActivityKeepsDifferentActivitiesSeparate(): void
    {
        $builder = new InvoiceLineBuilder(new TimesheetRateResolver());
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Entwicklung', 3600, 80.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::AggregatedByActivity, 19, 'EUR');

        self::assertCount(2, $lines);
    }
}
