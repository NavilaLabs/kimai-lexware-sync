<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Activity\ActivityService;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\OrderConfirmationLineDiffEntry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;

final class OrderConfirmationLineResolver
{
    public function __construct(
        private readonly OrderConfirmationLineDiffer $differ,
        private readonly OrderConfirmationLineActivityFactory $activityFactory,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly ActivityService $activityService,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    /**
     * @param list<int> $positionsToApply
     */
    public function apply(TrackedOrderConfirmation $orderConfirmation, LexwarePayload $payload, array $positionsToApply, string $lineRegex): void
    {
        $lines = [];
        foreach ($orderConfirmation->getLines() as $line) {
            $lines[$line->getPosition()] = $line;
        }

        foreach ($this->differ->diff($orderConfirmation, $payload) as $entry) {
            if (!\in_array($entry->position, $positionsToApply, true)) {
                continue;
            }

            match ($entry->status) {
                OrderConfirmationLineDiffStatus::Changed => $this->applyChanged($lines[$entry->position], $entry),
                OrderConfirmationLineDiffStatus::New => $this->applyNew($orderConfirmation, $entry, $lineRegex),
                OrderConfirmationLineDiffStatus::Removed => $this->applyRemoved($lines[$entry->position]),
                OrderConfirmationLineDiffStatus::Unchanged => null,
            };
        }

        $this->recomputeProjectBudget($orderConfirmation);
    }

    private function applyChanged(TrackedOrderConfirmationLine $line, OrderConfirmationLineDiffEntry $entry): void
    {
        $name = $entry->newName ?? '';
        $description = $entry->newDescription;
        $quantity = $entry->newQuantity ?? 0.0;
        $unitName = $entry->newUnitName ?? '';
        $netAmount = $entry->newNetAmount ?? 0.0;
        $isHourLine = $this->isHourLine($unitName);

        $line->updateFromLexwareLine($name, $description, $quantity, $unitName, $netAmount, $isHourLine);

        $activity = $line->getActivity();
        if ($activity === null || !$this->configuration->isDeriveBudgetEnabled()) {
            return;
        }

        if ($isHourLine) {
            $this->activityFactory->applyBudget($activity, $quantity, $netAmount);
        } else {
            $this->activityFactory->clearBudget($activity);
        }
    }

    private function applyNew(TrackedOrderConfirmation $orderConfirmation, OrderConfirmationLineDiffEntry $entry, string $lineRegex): void
    {
        $project = $orderConfirmation->getProject();
        if ($project === null) {
            return;
        }

        $type = $entry->newType ?? 'custom';
        $name = $entry->newName ?? '';
        $description = $entry->newDescription;
        $quantity = $entry->newQuantity ?? 0.0;
        $unitName = $entry->newUnitName ?? '';
        $netAmount = $entry->newNetAmount ?? 0.0;

        $matched = $this->matchingRuleEvaluator->matchesLine($type, $name, $description, $lineRegex);
        $isHourLine = $this->isHourLine($unitName);

        $line = new TrackedOrderConfirmationLine($orderConfirmation, $entry->position, $type, $name, $description, $matched, $quantity, $unitName, $netAmount, $isHourLine);
        $orderConfirmation->addLine($line);

        if (!$matched) {
            return;
        }

        $activity = $this->activityFactory->createActivity($project, $name, $description);
        if ($activity === null) {
            return;
        }

        if ($isHourLine) {
            $this->activityFactory->applyBudget($activity, $quantity, $netAmount);
        }

        $this->activityService->saveActivity($activity);
        $line->setActivity($activity);
    }

    private function applyRemoved(TrackedOrderConfirmationLine $line): void
    {
        $line->markRemovedFromSource();

        $activity = $line->getActivity();
        if ($activity !== null && $this->configuration->isDeriveBudgetEnabled()) {
            $this->activityFactory->clearBudget($activity);
        }
    }

    private function isHourLine(string $unitName): bool
    {
        return $this->configuration->isDeriveBudgetEnabled()
            && $this->matchingRuleEvaluator->matchesUnit($unitName, $this->configuration->getBudgetUnitRegex());
    }

    private function recomputeProjectBudget(TrackedOrderConfirmation $orderConfirmation): void
    {
        $project = $orderConfirmation->getProject();
        if ($project === null || !$this->configuration->isDeriveBudgetEnabled()) {
            return;
        }

        $timeBudget = 0;
        $budget = 0.0;
        foreach ($orderConfirmation->getLines() as $line) {
            if ($line->isHourLine() && !$line->isRemovedFromSource()) {
                $timeBudget += $this->activityFactory->timeBudgetFor($line->getQuantity());
                $budget += $line->getNetAmount();
            }
        }

        $project->setTimeBudget($timeBudget);
        $project->setBudget($budget);
    }
}
