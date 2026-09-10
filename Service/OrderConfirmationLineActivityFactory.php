<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Activity\ActivityService;
use App\Entity\Activity;
use App\Entity\Project;

final class OrderConfirmationLineActivityFactory
{
    public function __construct(
        private readonly ActivityService $activityService,
        private readonly ThemeColorPicker $colorPicker,
    ) {
    }

    public function createActivity(Project $project, string $name, ?string $description): ?Activity
    {
        $activityName = $this->resolveActivityName($name, $description);
        if ($activityName === null) {
            return null;
        }

        $activity = $this->activityService->createNewActivity($project);
        $activity->setName($activityName);
        $activity->setColor($this->colorPicker->pick());

        return $activity;
    }

    public function applyBudget(Activity $activity, float $quantity, float $netAmount): void
    {
        $activity->setTimeBudget($this->timeBudgetFor($quantity));
        $activity->setBudget($netAmount);
    }

    public function clearBudget(Activity $activity): void
    {
        $activity->setTimeBudget(0);
        $activity->setBudget(0.0);
    }

    public function timeBudgetFor(float $quantity): int
    {
        return (int) round($quantity * 3600);
    }

    private function resolveActivityName(string $name, ?string $description): ?string
    {
        if ($this->isUsableAsActivityName($name)) {
            return $name;
        }

        if ($description !== null && $this->isUsableAsActivityName($description)) {
            return $description;
        }

        return null;
    }

    private function isUsableAsActivityName(string $value): bool
    {
        $length = \strlen($value);

        return $length >= 2 && $length <= 150;
    }
}
