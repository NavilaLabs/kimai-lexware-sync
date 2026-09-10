<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use App\Activity\ActivityService;
use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\Activity;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineActivityFactory;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\ThemeColorPicker;
use PHPUnit\Framework\TestCase;

final class OrderConfirmationLineActivityFactoryTest extends TestCase
{
    public function testApplyBudgetConvertsQuantityToSecondsAndUsesTheNetAmountAsIs(): void
    {
        $activity = new Activity();

        $this->factory()->applyBudget($activity, 8.0, 720.0);

        self::assertSame(28800, $activity->getTimeBudget());
        self::assertSame(720.0, $activity->getBudget());
    }

    public function testAFractionalHourRoundsToTheNearestSecond(): void
    {
        $activity = new Activity();

        $this->factory()->applyBudget($activity, 0.5, 45.0);

        self::assertSame(1800, $activity->getTimeBudget());
    }

    public function testClearBudgetZeroesBothBudgets(): void
    {
        $activity = new Activity();
        $activity->setTimeBudget(28800);
        $activity->setBudget(720.0);

        $this->factory()->clearBudget($activity);

        self::assertSame(0, $activity->getTimeBudget());
        self::assertSame(0.0, $activity->getBudget());
    }

    public function testCreateActivityUsesTheLineNameWhenItIsUsable(): void
    {
        $activity = $this->factory()->createActivity(new Project(), 'Development', 'irrelevant');

        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('Development', $activity->getName());
        self::assertSame('#000000', $activity->getColor());
    }

    public function testCreateActivityFallsBackToTheDescription(): void
    {
        $activity = $this->factory()->createActivity(new Project(), 'x', 'A usable description');

        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('A usable description', $activity->getName());
    }

    public function testCreateActivityReturnsNullWhenNeitherIsUsable(): void
    {
        self::assertNull($this->factory()->createActivity(new Project(), 'x', 'y'));
        self::assertNull($this->factory()->createActivity(new Project(), 'x', null));
    }

    private function factory(): OrderConfirmationLineActivityFactory
    {
        $activityService = $this->createMock(ActivityService::class);
        $activityService->method('createNewActivity')->willReturnCallback(static function (?Project $project): Activity {
            $activity = new Activity();
            $activity->setProject($project);

            return $activity;
        });

        $configLoader = $this->createMock(ConfigLoaderInterface::class);
        $configLoader->method('getConfigurations')->willReturn(['theme.color_choices' => 'Black|#000000']);

        return new OrderConfirmationLineActivityFactory(
            $activityService,
            new ThemeColorPicker(new SystemConfiguration($configLoader)),
        );
    }
}
