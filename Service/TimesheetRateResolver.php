<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Timesheet;

final class TimesheetRateResolver
{
    public function hoursFor(Timesheet $timesheet): float
    {
        return ($timesheet->getDuration() ?? 0) / 3600;
    }

    public function hourlyRateFor(Timesheet $timesheet): float
    {
        $hourlyRate = $timesheet->getHourlyRate();
        if ($hourlyRate !== null) {
            return $hourlyRate;
        }

        $hours = $this->hoursFor($timesheet);

        return $hours > 0.0 ? $timesheet->getRate() / $hours : 0.0;
    }

    public function invoicedQuantityFor(Timesheet $timesheet): float
    {
        return round($this->hoursFor($timesheet), 2);
    }

    public function invoicedUnitPriceFor(Timesheet $timesheet): float
    {
        return round($this->hourlyRateFor($timesheet), 2);
    }

    public function invoicedAmountFor(Timesheet $timesheet): float
    {
        return round($this->invoicedQuantityFor($timesheet) * $this->invoicedUnitPriceFor($timesheet), 2);
    }
}
