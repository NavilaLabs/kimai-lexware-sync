<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineResolver;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class OrderConfirmationLineResolverTest extends FunctionalTestCase
{
    public function testApplyingAChangedLineUpdatesItsQuantityAndItsActivityBudget(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 12, 'unitName' => 'Stunden', 'lineItemAmount' => 1200.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, [0], '');
        $this->entityManager()->flush();

        self::assertSame(43200, $activity->getTimeBudget());
        self::assertSame(1200.0, $activity->getBudget());
        self::assertSame(43200, $project->getTimeBudget());
        self::assertSame(1200.0, $project->getBudget());
    }

    public function testApplyingAChangedLineAlsoUpdatesItsStoredNameAndDescription(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development work', 'description' => 'Renamed in Lexware', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, [0], '');
        $this->entityManager()->flush();

        $line = $orderConfirmation->getLines()->first();
        self::assertInstanceOf(TrackedOrderConfirmationLine::class, $line);
        self::assertSame('Development work', $line->getName());
        self::assertSame('Renamed in Lexware', $line->getDescription());
    }

    public function testApplyingARemovedLineZeroesItsActivityBudgetButKeepsTheActivity(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();
        $activityId = $activity->getId();

        $payload = new LexwarePayload(['lineItems' => []]);

        $this->resolver()->apply($orderConfirmation, $payload, [0], '');
        $this->entityManager()->flush();

        self::assertSame(0, $activity->getTimeBudget());
        self::assertSame(0.0, $activity->getBudget());
        self::assertSame(0, $project->getTimeBudget());
        self::assertNotNull($this->entityManager()->getRepository(Activity::class)->find($activityId));
    }

    public function testAPositionNotInTheApplyListIsLeftUntouched(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, , $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 999, 'unitName' => 'Stunden', 'lineItemAmount' => 99900.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, [], '');
        $this->entityManager()->flush();

        self::assertSame(28800, $activity->getTimeBudget());
    }

    public function testApplyingANewLineCreatesAnActivityWithABudget(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
            ['type' => 'custom', 'name' => 'Consulting', 'description' => 'More talking', 'quantity' => 4, 'unitName' => 'Stunden', 'lineItemAmount' => 400.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, [1], '');
        $this->entityManager()->flush();

        $activities = $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $project]);
        $byName = [];
        foreach ($activities as $found) {
            $byName[$found->getName()] = $found;
        }

        self::assertArrayHasKey('Consulting', $byName);
        self::assertSame(14400, $byName['Consulting']->getTimeBudget());
        self::assertSame(400.0, $byName['Consulting']->getBudget());
        self::assertSame(43200, $project->getTimeBudget());
        self::assertSame(1200.0, $project->getBudget());
    }

    public function testALineThatReappearsInLexwareCountsTowardsTheProjectBudgetAgain(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $this->resolver()->apply($orderConfirmation, new LexwarePayload(['lineItems' => []]), [0], '');
        $this->entityManager()->flush();

        self::assertSame(0, $project->getTimeBudget(), 'Removing the only line should leave the project without a budget.');

        $reappeared = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $reappeared, [0], '');
        $this->entityManager()->flush();

        $line = $orderConfirmation->getLines()->first();
        self::assertInstanceOf(TrackedOrderConfirmationLine::class, $line);
        self::assertFalse($line->isRemovedFromSource());
        self::assertSame(28800, $activity->getTimeBudget());
        self::assertSame(28800, $project->getTimeBudget());
        self::assertSame(800.0, $project->getBudget());
    }

    public function testWithBudgetDerivationOffTheLineDataIsUpdatedButNoBudgetIsTouched(): void
    {
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 12, 'unitName' => 'Stunden', 'lineItemAmount' => 1200.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, [0], '');
        $this->entityManager()->flush();

        $line = $orderConfirmation->getLines()->first();
        self::assertInstanceOf(TrackedOrderConfirmationLine::class, $line);
        self::assertSame(12.0, $line->getQuantity());
        self::assertFalse($line->isHourLine(), 'Nothing counts as an hour line while the feature is switched off.');
        self::assertSame(28800, $activity->getTimeBudget());
        self::assertSame(28800, $project->getTimeBudget());
    }

    /**
     * @return array{0: TrackedOrderConfirmation, 1: Project, 2: Activity}
     */
    private function givenAConvertedOrderConfirmationWithOneHourLine(): array
    {
        $project = $this->factory()->createProject();
        $activity = $this->factory()->createActivity($project, 'Development');

        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-resolver-' . $project->getId());
        $orderConfirmation->setProject($project);
        $line = new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true);
        $line->setActivity($activity);
        $orderConfirmation->addLine($line);

        $activity->setTimeBudget(28800);
        $activity->setBudget(800.0);
        $project->setTimeBudget(28800);
        $project->setBudget(800.0);

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        return [$orderConfirmation, $project, $activity];
    }

    private function resolver(): OrderConfirmationLineResolver
    {
        return $this->service(OrderConfirmationLineResolver::class);
    }
}
