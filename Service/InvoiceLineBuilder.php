<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Timesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;

final class InvoiceLineBuilder
{
    private const UNIT_NAME = 'Stunden';

    public function __construct(private readonly TimesheetRateResolver $rateResolver)
    {
    }

    /**
     * @param Timesheet[] $timesheets
     * @return list<array<string, mixed>>
     */
    public function buildLines(array $timesheets, InvoiceLineShape $shape, int $taxRatePercentage, string $currency): array
    {
        return match ($shape) {
            InvoiceLineShape::PerTimesheet => $this->buildPerTimesheetLines($timesheets, $taxRatePercentage, $currency),
            InvoiceLineShape::AggregatedByActivity => $this->buildAggregatedLines($timesheets, $taxRatePercentage, $currency),
        };
    }

    /**
     * @param Timesheet[] $timesheets
     * @return list<array<string, mixed>>
     */
    private function buildPerTimesheetLines(array $timesheets, int $taxRatePercentage, string $currency): array
    {
        $lines = [];

        foreach ($timesheets as $timesheet) {
            $activity = $timesheet->getActivity();

            $lines[] = $this->buildLine(
                $activity !== null ? (string) $activity->getName() : '',
                $this->descriptionFor($timesheet),
                $this->rateResolver->invoicedQuantityFor($timesheet),
                $this->rateResolver->invoicedUnitPriceFor($timesheet),
                $taxRatePercentage,
                $currency,
            );
        }

        return $lines;
    }

    /**
     * @param Timesheet[] $timesheets
     * @return list<array<string, mixed>>
     */
    private function buildAggregatedLines(array $timesheets, int $taxRatePercentage, string $currency): array
    {
        $groups = [];

        foreach ($timesheets as $timesheet) {
            $activity = $timesheet->getActivity();
            if ($activity !== null) {
                $id = $activity->getId();
                $activityKey = $id !== null ? $id : 'name:' . $activity->getName();
            } else {
                $activityKey = 0;
            }

            if (!isset($groups[$activityKey])) {
                $groups[$activityKey] = [
                    'name' => $activity !== null ? (string) $activity->getName() : '',
                    'hours' => 0.0,
                    'amount' => 0.0,
                ];
            }

            $hours = $this->rateResolver->hoursFor($timesheet);
            $groups[$activityKey]['hours'] += $hours;
            $groups[$activityKey]['amount'] += $hours * $this->rateResolver->hourlyRateFor($timesheet);
        }

        $lines = [];

        foreach ($groups as $group) {
            $hours = $group['hours'];
            $averageRate = $hours > 0.0 ? $group['amount'] / $hours : 0.0;

            $lines[] = $this->buildLine($group['name'], null, $hours, $averageRate, $taxRatePercentage, $currency);
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLine(string $name, ?string $description, float $hours, float $unitPrice, int $taxRatePercentage, string $currency): array
    {
        $line = [
            'type' => 'custom',
            'name' => $name,
            'quantity' => round($hours, 2),
            'unitName' => self::UNIT_NAME,
            'unitPrice' => [
                'currency' => $currency,
                'netAmount' => round($unitPrice, 2),
                'taxRatePercentage' => $taxRatePercentage,
            ],
        ];

        if ($description !== null) {
            $line['description'] = $description;
        }

        return $line;
    }

    private function descriptionFor(Timesheet $timesheet): string
    {
        $begin = $timesheet->getBegin();
        $description = $timesheet->getDescription();
        $date = $begin !== null ? $begin->format('Y-m-d') : '';

        if ($description === null || $description === '') {
            return $date;
        }

        return $date . ': ' . $description;
    }
}
