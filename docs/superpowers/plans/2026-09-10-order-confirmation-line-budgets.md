# Order Confirmation Line Budgets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Derive a Kimai `Activity` and `Project` time and cost budget from an order confirmation's
hour-based line items at conversion time, and give a human a manual, per-line resolution screen for
what to do when Lexware later changes an already converted order confirmation, without ever
deleting or reassigning already booked time.

**Architecture:** `TrackedOrderConfirmationLine` gains four columns (`quantity`, `unitName`,
`netAmount`, `isHourLine`) plus a `removedFromSource` flag. `OrderConfirmationProcessor::convertLines()`
derives budgets at conversion through a new, shared `OrderConfirmationLineActivityFactory`. A new
`OrderConfirmationLineDiffer` computes a positional diff between the stored lines and the current
`rawPayload`; a new `OrderConfirmationLineResolutionController` renders that diff and, on submit,
applies exactly the checked rows through a new `OrderConfirmationLineResolver`, reusing the same
factory, then clears `changedAfterConversion`.

**Tech Stack:** PHP 8.2, Symfony 6.4 components shipped with Kimai 2.65, PHPUnit 10, Doctrine
migrations. No new Composer dependencies.

**Spec:** `docs/superpowers/specs/2026-09-10-order-confirmation-line-budgets-design.md`

## Global Constraints

- No Composer dependencies of our own.
- `declare(strict_types=1)` in every file.
- Classes `final` unless Doctrine needs to derive a proxy. No new Doctrine entity is added; only
  new columns on the existing `TrackedOrderConfirmationLine` entity.
- Strict comparison, `===` and `!==`, always.
- English everywhere: identifiers, comments, commit messages, documentation. The two new
  `.xlf` translation entries are the only place German text belongs, in `messages.de.xlf`.
- No em dash and no double hyphen as punctuation in any project file.
- No abbreviations in identifiers or prose.
- Avoid comments. A comment is justified only where a non obvious external constraint needs
  explaining, such as the positional-diff limitation or the regex convention exception below.
- `lexware_sync.budget_unit_regex` is a deliberate, documented exception to the "empty regex
  matches everything" convention: it falls back to a hardcoded non-empty default when unset. Do
  not apply this exception to any other regex field.
- **Never delete or reassign a `Timesheet` row, and never delete an `Activity`, anywhere in this
  plan.** A removed line only ever zeroes a budget.
- No backfill migration for order confirmations converted before this feature ships.
- Every task ends with `just check` passing: code style, static analysis at level 9 with zero
  findings, and all local suites green.

---

## Task 1: Data model, `TrackedOrderConfirmationLine`, migration

**Files:**
- Modify: `Entity/TrackedOrderConfirmationLine.php`
- Create: `Migrations/Version20260910120000.php`
- Create: `Tests/Entity/TrackedOrderConfirmationLineTest.php`
- Modify: `Tests/Migration/PluginMigrationTest.php`

**Interfaces:**
- Produces: constructor gains four required arguments, `quantity: float`, `unitName: string`,
  `netAmount: float`, `isHourLine: bool`, inserted after the existing `matched: bool` argument.
  New getters `getQuantity(): float`, `getUnitName(): string`, `getNetAmount(): float`,
  `isHourLine(): bool`, `isRemovedFromSource(): bool`. New methods
  `updateFromLexwareLine(float $quantity, string $unitName, float $netAmount, bool $isHourLine): void`
  and `markRemovedFromSource(): void`.

- [x] **Step 1: Write the failing test**

```php
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

        $line->updateFromLexwareLine(12.0, 'Stunden', 1200.0, true);

        self::assertSame(12.0, $line->getQuantity());
        self::assertSame(1200.0, $line->getNetAmount());
    }

    public function testMarkRemovedFromSourceIsIrreversibleInPracticeButNeverTouchesTheActivity(): void
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
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter TrackedOrderConfirmationLineTest`
Expected: FAIL, the constructor does not accept the new arguments yet.

- [x] **Step 3: Write minimal implementation**

Add to `TrackedOrderConfirmationLine`, four new columns and their accessors, the constructor
extended with the four new required parameters after `matched`, and:

```php
    #[ORM\Column(name: 'quantity', type: 'float')]
    private float $quantity;

    #[ORM\Column(name: 'unit_name', type: 'string', length: 255)]
    private string $unitName;

    #[ORM\Column(name: 'net_amount', type: 'float')]
    private float $netAmount;

    #[ORM\Column(name: 'is_hour_line', type: 'boolean')]
    private bool $isHourLine;

    #[ORM\Column(name: 'removed_from_source', type: 'boolean')]
    private bool $removedFromSource = false;
```

```php
    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnitName(): string
    {
        return $this->unitName;
    }

    public function getNetAmount(): float
    {
        return $this->netAmount;
    }

    public function isHourLine(): bool
    {
        return $this->isHourLine;
    }

    public function isRemovedFromSource(): bool
    {
        return $this->removedFromSource;
    }

    public function updateFromLexwareLine(float $quantity, string $unitName, float $netAmount, bool $isHourLine): void
    {
        $this->quantity = $quantity;
        $this->unitName = $unitName;
        $this->netAmount = $netAmount;
        $this->isHourLine = $isHourLine;
    }

    public function markRemovedFromSource(): void
    {
        $this->removedFromSource = true;
    }
```

Create `Migrations/Version20260910120000.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260910120000 extends AbstractMigration
{
    private const TABLE_NAME = 'kimai2_ext_lexware_order_confirmation_line';

    public function getDescription(): string
    {
        return 'Store quantity, unit name, net amount and hour-line classification on every tracked order confirmation line, for budget derivation';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(self::TABLE_NAME);

        if (!$table->hasColumn('quantity')) {
            $table->addColumn('quantity', 'float', ['notnull' => true, 'default' => 0]);
        }
        if (!$table->hasColumn('unit_name')) {
            $table->addColumn('unit_name', 'string', ['notnull' => true, 'length' => 255, 'default' => '']);
        }
        if (!$table->hasColumn('net_amount')) {
            $table->addColumn('net_amount', 'float', ['notnull' => true, 'default' => 0]);
        }
        if (!$table->hasColumn('is_hour_line')) {
            $table->addColumn('is_hour_line', 'boolean', ['notnull' => true, 'default' => false]);
        }
        if (!$table->hasColumn('removed_from_source')) {
            $table->addColumn('removed_from_source', 'boolean', ['notnull' => true, 'default' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable(self::TABLE_NAME)) {
            return;
        }

        $table = $schema->getTable(self::TABLE_NAME);

        foreach (['quantity', 'unit_name', 'net_amount', 'is_hour_line', 'removed_from_source'] as $column) {
            if ($table->hasColumn($column)) {
                $table->dropColumn($column);
            }
        }
    }
}
```

Add to `Tests/Migration/PluginMigrationTest.php`, mirroring `testUpgradingAnInstallationThatAlreadyHoldsDataKeepsThatData`:

```php
    public function testUpgradingAnInstallationWithExistingLinesFillsTheNewColumnsWithSafeDefaults(): void
    {
        $this->migrate(self::FIRST_VERSION);
        $this->givenTrackedOrderConfirmation('lexware-upgrade-2', 'AB-2026-901');
        // insert directly into kimai2_ext_lexware_order_confirmation_line for that order confirmation
        // before is_hour_line/quantity/unit_name/net_amount/removed_from_source exist

        $this->migrate();

        $statement = $this->connection->query(
            "SELECT quantity, unit_name, net_amount, is_hour_line, removed_from_source
             FROM kimai2_ext_lexware_order_confirmation_line
             WHERE order_confirmation_id = (SELECT id FROM kimai2_ext_lexware_order_confirmation WHERE lexware_id = 'lexware-upgrade-2')"
        );
        self::assertNotFalse($statement);

        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(0.0, (float) $row['quantity']);
        self::assertSame('', $row['unit_name']);
        self::assertSame(0.0, (float) $row['net_amount']);
        self::assertSame(0, (int) $row['is_hour_line']);
        self::assertSame(0, (int) $row['removed_from_source']);
    }
```

Insert the pre-migration line row with a direct `INSERT` in that test, using whatever columns
exist on the schema as of `self::FIRST_VERSION`, following the exact pattern
`givenTrackedOrderConfirmation()` already establishes for the parent table.

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter TrackedOrderConfirmationLineTest` and
`vendor/bin/phpunit --testsuite migration --filter PluginMigrationTest`
Expected: PASS.

- [x] **Step 5: Run `just check`**

Correction found during execution: the global constraint that every task ends with `just check`
passing means the existing call site in `OrderConfirmationProcessor::convertLines()` cannot be
left broken until Task 4 after all, PHPStan fails the build on an argument count mismatch.
Update that one call site here to pass `0.0, '', 0.0, false` as the four new arguments, real
values follow in Task 4, this is only about keeping the constructor call valid.

---

## Task 2: `MatchingRuleEvaluator::matchesUnit()`

**Files:**
- Modify: `Service/MatchingRuleEvaluator.php`
- Modify: `Tests/Service/MatchingRuleEvaluatorTest.php`

**Interfaces:**
- Produces: `matchesUnit(string $unitName, string $unitRegex): bool`, following the exact
  "empty regex matches everything" shape `matchesTitle()` already uses.

- [x] **Step 1: Write the failing test**

Add to `Tests/Service/MatchingRuleEvaluatorTest.php`:

```php
    public function testEmptyUnitRegexMatchesEverything(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesUnit('Stück', ''));
    }

    public function testUnitRegexMustMatchTheWholeUnitName(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesUnit('Stunden', '/^(Stunden?|Std\.?|hours?|hrs?|h)$/i'));
        self::assertTrue($evaluator->matchesUnit('hours', '/^(Stunden?|Std\.?|hours?|hrs?|h)$/i'));
        self::assertFalse($evaluator->matchesUnit('Stück', '/^(Stunden?|Std\.?|hours?|hrs?|h)$/i'));
    }
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter MatchingRuleEvaluatorTest`
Expected: FAIL, `matchesUnit` does not exist.

- [x] **Step 3: Write minimal implementation**

```php
    public function matchesUnit(string $unitName, string $unitRegex): bool
    {
        if ($unitRegex === '') {
            return true;
        }

        return preg_match($unitRegex, $unitName) === 1;
    }
```

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter MatchingRuleEvaluatorTest`
Expected: PASS, every test in the file green.

- [x] **Step 5: Run `just check`**

---

## Task 3: `derive_budget_enabled` and `budget_unit_regex` configuration keys

**Files:**
- Modify: `Configuration/LexwareSyncConfiguration.php`
- Modify: `EventSubscriber/SystemConfigurationSubscriber.php`
- Modify: `Tests/Configuration/LexwareSyncConfigurationTest.php`
- Modify: `Resources/translations/system-configuration.de.xlf`, `Resources/translations/system-configuration.en.xlf`

**Interfaces:**
- Produces: `isDeriveBudgetEnabled(): bool`, following the exact shape `isReadLinesEnabled()`
  already uses. `getBudgetUnitRegex(): string`, returning the stored value if non-empty, otherwise
  the hardcoded default `/^(Stunden?|Std\.?|hours?|hrs?|h)$/i`. This is the documented exception to
  the "empty regex matches everything" convention, see the spec section 4 and the global
  constraint above; the exception lives entirely in this one accessor, `matchesUnit()` itself stays
  generic.

- [x] **Step 1: Write the failing test**

Add to `Tests/Configuration/LexwareSyncConfigurationTest.php`:

```php
    public function testDeriveBudgetIsDisabledByDefault(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertFalse($configuration->isDeriveBudgetEnabled());
    }

    public function testDeriveBudgetCanBeEnabled(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.derive_budget_enabled' => '1',
        ]));

        self::assertTrue($configuration->isDeriveBudgetEnabled());
    }

    public function testBudgetUnitRegexFallsBackToAHardcodedDefaultWhenUnset(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame('/^(Stunden?|Std\.?|hours?|hrs?|h)$/i', $configuration->getBudgetUnitRegex());
    }

    public function testBudgetUnitRegexUsesTheStoredValueWhenSet(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.budget_unit_regex' => '/^Arbeitsstunden$/',
        ]));

        self::assertSame('/^Arbeitsstunden$/', $configuration->getBudgetUnitRegex());
    }
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest`
Expected: FAIL, neither accessor exists yet.

- [x] **Step 3: Write minimal implementation**

Add to `LexwareSyncConfiguration`:

```php
    private const DEFAULT_BUDGET_UNIT_REGEX = '/^(Stunden?|Std\.?|hours?|hrs?|h)$/i';

    public function isDeriveBudgetEnabled(): bool
    {
        return $this->readFlag('lexware_sync.derive_budget_enabled');
    }

    public function getBudgetUnitRegex(): string
    {
        $value = $this->readText('lexware_sync.budget_unit_regex');

        return $value !== '' ? $value : self::DEFAULT_BUDGET_UNIT_REGEX;
    }
```

Add both keys to `SystemConfigurationSubscriber::onSystemConfiguration()`, next to
`lexware_sync.line_regex`: `derive_budget_enabled` as a `CheckboxType`, `budget_unit_regex` as a
`TextType` with `createRegexConstraint()`, both `setRequired(false)`. Give `budget_unit_regex` a
`help` option explaining the exception in one sentence, translated in both `.xlf` files: leaving it
empty makes every matched line count as hours, unlike the plugin's other regex fields, which is
exactly what a customer with only material lines needs to know before they turn on
`derive_budget_enabled`.

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest`
Expected: PASS.

- [x] **Step 5: Run `just check`**

---

## Task 4: Budget derivation at conversion, `OrderConfirmationLineActivityFactory`

**Files:**
- Create: `Service/OrderConfirmationLineActivityFactory.php`
- Create: `Tests/Service/OrderConfirmationLineActivityFactoryTest.php`
- Modify: `Service/OrderConfirmationProcessor.php`
- Modify: `Tests/Functional/OrderConfirmationProcessorTest.php`

**Interfaces:**
- Produces: `OrderConfirmationLineActivityFactory::createActivity(Project $project, string $name, ?string $description): ?Activity`,
  extracted verbatim from `OrderConfirmationProcessor::resolveActivityName()` / `isUsableAsActivityName()` /
  `pickRandomColor()`, so `OrderConfirmationLineResolver` in Task 7 can create a new-line activity
  with identical rules without duplicating them. `applyBudget(Activity $activity, float $quantity, float $netAmount): void`
  sets `setTimeBudget((int) round($quantity * 3600))` and `setBudget($netAmount)`.
- `OrderConfirmationProcessor` now depends on this factory instead of holding
  `resolveActivityName()`/`isUsableAsActivityName()`/`pickRandomColor()` itself; `convertLines()`
  reads `quantity`, `unitName`, `lineItemAmount` from each line, computes `isHourLine` when
  `derive_budget_enabled` is on, passes all four to the `TrackedOrderConfirmationLine` constructor,
  calls `applyBudget()` on a matched hour line's activity, and sums every hour line's contribution
  into the project's own budget and time budget after the loop.

- [x] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use App\Entity\Activity;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineActivityFactory;
use PHPUnit\Framework\TestCase;

final class OrderConfirmationLineActivityFactoryTest extends TestCase
{
    public function testApplyBudgetConvertsQuantityToSecondsAndUsesTheNetAmountAsIs(): void
    {
        $activity = new Activity();
        $factory = new OrderConfirmationLineActivityFactory();

        $factory->applyBudget($activity, 8.0, 720.0);

        self::assertSame(28800, $activity->getTimeBudget());
        self::assertSame(720.0, $activity->getBudget());
    }

    public function testAFractionalHourRoundsToTheNearestSecond(): void
    {
        $activity = new Activity();
        $factory = new OrderConfirmationLineActivityFactory();

        $factory->applyBudget($activity, 0.5, 45.0);

        self::assertSame(1800, $activity->getTimeBudget());
    }
}
```

Add to `Tests/Functional/OrderConfirmationProcessorTest.php`, extending `payload()` with a variant
that carries `quantity`/`unitName`/`lineItemAmount` (see Step 3 below for the exact fixture), then:

```php
    public function testDerivedBudgetSumsOnlyTheHourLinesOntoTheirActivitiesAndTheProject(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-budget-1', 'AB-2026-100', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->budgetedPayload()), null, '', true);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);
        self::assertSame(1200.0, $project->getBudget());
        self::assertSame(43200, $project->getTimeBudget());

        $activities = $this->entityManager()->getRepository(Activity::class)->findBy(['project' => $project]);
        $byName = [];
        foreach ($activities as $activity) {
            $byName[$activity->getName()] = $activity;
        }

        self::assertSame(28800, $byName['Development']->getTimeBudget());
        self::assertSame(800.0, $byName['Development']->getBudget());
        self::assertSame(0, $byName['Material']->getTimeBudget());
        self::assertSame(0.0, $byName['Material']->getBudget());
    }

    public function testDerivedBudgetIsNeverSetWhenTheSettingIsOff(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-budget-2', 'AB-2026-101', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->budgetedPayload()), null, '', true);
        $this->entityManager()->flush();

        self::assertSame(0.0, $orderConfirmation->getProject()?->getBudget());
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetedPayload(): array
    {
        return [
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [
                ['type' => 'custom', 'name' => 'Development', 'description' => 'Building the thing', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
                ['type' => 'custom', 'name' => 'Consulting', 'description' => 'Talking about the thing', 'quantity' => 4, 'unitName' => 'Stunden', 'lineItemAmount' => 400.0],
                ['type' => 'custom', 'name' => 'Material', 'description' => 'A physical thing', 'quantity' => 2, 'unitName' => 'Stück', 'lineItemAmount' => 100.0],
            ],
        ];
    }
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter OrderConfirmationLineActivityFactoryTest` and
`vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: FAIL, `OrderConfirmationLineActivityFactory` does not exist, `convertLines()` does not
pass the new constructor arguments and never touches any budget.

- [x] **Step 3: Write minimal implementation**

Create `Service/OrderConfirmationLineActivityFactory.php`, moving `resolveActivityName()`,
`isUsableAsActivityName()` out of `OrderConfirmationProcessor` verbatim as private methods here,
behind one public method, plus the new budget method:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Activity;

final class OrderConfirmationLineActivityFactory
{
    public function resolveActivityName(string $name, ?string $description): ?string
    {
        if ($this->isUsableAsActivityName($name)) {
            return $name;
        }

        if ($description !== null && $this->isUsableAsActivityName($description)) {
            return $description;
        }

        return null;
    }

    public function applyBudget(Activity $activity, float $quantity, float $netAmount): void
    {
        $activity->setTimeBudget((int) round($quantity * 3600));
        $activity->setBudget($netAmount);
    }

    private function isUsableAsActivityName(string $value): bool
    {
        $length = \strlen($value);

        return $length >= 2 && $length <= 150;
    }
}
```

Note `pickRandomColor()` stays on `OrderConfirmationProcessor`, it reads `SystemConfiguration`
which this factory has no reason to depend on; only the activity-name and budget logic move,
since those are exactly what Task 7's resolver also needs.

Inject `OrderConfirmationLineActivityFactory $activityFactory` into `OrderConfirmationProcessor`'s
constructor. In `convertLines()`, replace the direct calls to the now-removed private methods with
`$this->activityFactory->resolveActivityName(...)`, and change the loop to:

```php
    private function convertLines(TrackedOrderConfirmation $orderConfirmation, array $lineItems, Project $project, string $lineRegex): void
    {
        $deriveBudgetEnabled = $this->configuration->isDeriveBudgetEnabled();
        $budgetUnitRegex = $this->configuration->getBudgetUnitRegex();
        $projectTimeBudget = 0;
        $projectBudget = 0.0;

        foreach ($lineItems as $position => $lineItem) {
            $type = $lineItem->string('type', 'custom');
            $name = $lineItem->string('name');
            $description = $lineItem->nullableString('description');
            $quantity = $lineItem->float('quantity');
            $unitName = $lineItem->string('unitName');
            $netAmount = $lineItem->float('lineItemAmount');

            $matched = $this->matchingRuleEvaluator->matchesLine($type, $name, $description, $lineRegex);
            $isHourLine = $deriveBudgetEnabled && $this->matchingRuleEvaluator->matchesUnit($unitName, $budgetUnitRegex);

            $line = new TrackedOrderConfirmationLine($orderConfirmation, $position, $type, $name, $description, $matched, $quantity, $unitName, $netAmount, $isHourLine);
            $orderConfirmation->addLine($line);

            if (!$matched) {
                continue;
            }

            $activityName = $this->activityFactory->resolveActivityName($name, $description);
            if ($activityName === null) {
                continue;
            }

            $activity = $this->activityService->createNewActivity($project);
            $activity->setName($activityName);
            $activity->setColor($this->pickRandomColor());

            if ($isHourLine) {
                $this->activityFactory->applyBudget($activity, $quantity, $netAmount);
                $projectTimeBudget += $activity->getTimeBudget();
                $projectBudget += $netAmount;
            }

            $this->activityService->saveActivity($activity);

            $line->setActivity($activity);
        }

        if ($deriveBudgetEnabled) {
            $project->setTimeBudget($projectTimeBudget);
            $project->setBudget($projectBudget);
        }
    }
```

`convertLines()` is called before `$this->projectService->saveProject($project)` today; confirm the
project save still happens after this method returns so the budget totals are persisted, no change
needed to the call order in `convert()` itself since `convertLines()` already runs last.

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter OrderConfirmationLineActivityFactoryTest` and
`vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: PASS, every test in both files green, including every pre-existing one in
`OrderConfirmationProcessorTest`.

- [x] **Step 5: Run `just check`, then the full unit and functional suites** to confirm the
extraction did not change behaviour for any test that predates this task.

---

## Task 5: `OrderConfirmationLineDiffer`

**Files:**
- Create: `Enum/OrderConfirmationLineDiffStatus.php`
- Create: `Dto/OrderConfirmationLineDiffEntry.php`
- Create: `Service/OrderConfirmationLineDiffer.php`
- Create: `Tests/Service/OrderConfirmationLineDifferTest.php`

**Interfaces:**
- Produces: `OrderConfirmationLineDiffStatus` (string-backed enum: `Unchanged`, `Changed`, `New`,
  `Removed`). `OrderConfirmationLineDiffEntry`, a readonly value object: `position: int`,
  `status: OrderConfirmationLineDiffStatus`, `oldName: ?string`, `oldQuantity: ?float`,
  `oldUnitName: ?string`, `oldNetAmount: ?float`, `newType: ?string`, `newName: ?string`,
  `newDescription: ?string`, `newQuantity: ?float`, `newUnitName: ?string`, `newNetAmount: ?float`.
  `newType` and `newDescription` carry no "old" counterpart, since the diff table only ever
  displays old name/quantity/unit/amount (per the spec's diff table columns) but Task 6's resolver
  needs the new line's `type` and `description` to run the same `line_regex` matching and activity
  naming a `New` row requires, they exist on the entry purely for that, not for display.
  `OrderConfirmationLineDiffer::diff(TrackedOrderConfirmation $orderConfirmation, LexwarePayload $payload): list<OrderConfirmationLineDiffEntry>`,
  comparing `$orderConfirmation->getLines()` (indexed by their stored `position`, read through a
  new `TrackedOrderConfirmationLine::getPosition(): int` getter, added in this task since nothing
  needed it publicly before) against `$payload->nestedList('lineItems')` by array index.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineDiffer;
use PHPUnit\Framework\TestCase;

final class OrderConfirmationLineDifferTest extends TestCase
{
    public function testAnUnchangedLineIsReportedAsUnchanged(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-1');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertCount(1, $entries);
        self::assertSame(OrderConfirmationLineDiffStatus::Unchanged, $entries[0]->status);
    }

    public function testAChangedQuantityIsReportedAsChangedWithBothValues(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-2');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 12, 'unitName' => 'Stunden', 'lineItemAmount' => 1200.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Changed, $entries[0]->status);
        self::assertSame(8.0, $entries[0]->oldQuantity);
        self::assertSame(12.0, $entries[0]->newQuantity);
    }

    public function testALineOnlyInThePayloadIsReportedAsNew(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-3');

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 8, 'unitName' => 'Stunden', 'lineItemAmount' => 800.0],
        ]]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::New, $entries[0]->status);
        self::assertNull($entries[0]->oldName);
    }

    public function testALineOnlyStoredIsReportedAsRemoved(): void
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-4');
        $orderConfirmation->addLine(new TrackedOrderConfirmationLine($orderConfirmation, 0, 'custom', 'Development', null, true, 8.0, 'Stunden', 800.0, true));

        $payload = new LexwarePayload(['lineItems' => []]);

        $entries = (new OrderConfirmationLineDiffer())->diff($orderConfirmation, $payload);

        self::assertSame(OrderConfirmationLineDiffStatus::Removed, $entries[0]->status);
        self::assertNull($entries[0]->newName);
    }
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter OrderConfirmationLineDifferTest`
Expected: FAIL, none of the three new classes exist yet.

- [x] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum OrderConfirmationLineDiffStatus: string
{
    case Unchanged = 'unchanged';
    case Changed = 'changed';
    case New = 'new';
    case Removed = 'removed';
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Dto;

use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;

final class OrderConfirmationLineDiffEntry
{
    public function __construct(
        public readonly int $position,
        public readonly OrderConfirmationLineDiffStatus $status,
        public readonly ?string $oldName,
        public readonly ?float $oldQuantity,
        public readonly ?string $oldUnitName,
        public readonly ?float $oldNetAmount,
        public readonly ?string $newType,
        public readonly ?string $newName,
        public readonly ?string $newDescription,
        public readonly ?float $newQuantity,
        public readonly ?string $newUnitName,
        public readonly ?float $newNetAmount,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\OrderConfirmationLineDiffEntry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationLineDiffStatus;

final class OrderConfirmationLineDiffer
{
    /**
     * @return list<OrderConfirmationLineDiffEntry>
     */
    public function diff(TrackedOrderConfirmation $orderConfirmation, LexwarePayload $payload): array
    {
        $oldLines = [];
        foreach ($orderConfirmation->getLines() as $line) {
            $oldLines[$line->getPosition()] = $line;
        }

        $newLines = $payload->nestedList('lineItems');

        $positions = array_unique(array_merge(array_keys($oldLines), array_keys($newLines)));
        sort($positions);

        $entries = [];
        foreach ($positions as $position) {
            $old = $oldLines[$position] ?? null;
            $new = $newLines[$position] ?? null;

            $entries[] = $this->buildEntry($position, $old, $new);
        }

        return $entries;
    }

    private function buildEntry(int $position, ?TrackedOrderConfirmationLine $old, ?LexwarePayload $new): OrderConfirmationLineDiffEntry
    {
        $newName = $new?->string('name');
        $newQuantity = $new?->float('quantity');
        $newUnitName = $new?->string('unitName');
        $newNetAmount = $new?->float('lineItemAmount');

        $status = match (true) {
            $old === null => OrderConfirmationLineDiffStatus::New,
            $new === null => OrderConfirmationLineDiffStatus::Removed,
            $old->getName() === $newName && $old->getQuantity() === $newQuantity
                && $old->getUnitName() === $newUnitName && $old->getNetAmount() === $newNetAmount => OrderConfirmationLineDiffStatus::Unchanged,
            default => OrderConfirmationLineDiffStatus::Changed,
        };

        return new OrderConfirmationLineDiffEntry(
            $position,
            $status,
            $old?->getName(),
            $old?->getQuantity(),
            $old?->getUnitName(),
            $old?->getNetAmount(),
            $new?->string('type', 'custom'),
            $new === null ? null : $newName,
            $new?->nullableString('description'),
            $new === null ? null : $newQuantity,
            $new === null ? null : $newUnitName,
            $new === null ? null : $newNetAmount,
        );
    }
}
```

Add `getPosition(): int` to `TrackedOrderConfirmationLine`, returning the existing private
`position` property, which already exists on the entity but had no accessor.

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter OrderConfirmationLineDifferTest`
Expected: PASS, all four tests green.

- [x] **Step 5: Run `just check`**

---

## Task 6: `OrderConfirmationLineResolver`

**Files:**
- Create: `Service/OrderConfirmationLineResolver.php`
- Create: `Tests/Functional/OrderConfirmationLineResolverTest.php`

**Interfaces:**
- Produces: `apply(TrackedOrderConfirmation $orderConfirmation, LexwarePayload $payload, list<int> $positionsToApply): void`.
  For each `OrderConfirmationLineDiffer::diff()` entry whose position is in `$positionsToApply`:
  - `Changed`: call `updateFromLexwareLine()` on the stored line with the freshly read
    quantity/unitName/netAmount and a freshly evaluated `isHourLine`; if it has an activity and is
    an hour line, call `OrderConfirmationLineActivityFactory::applyBudget()` again with the new
    numbers; if it has an activity and is no longer an hour line, zero that activity's budget.
  - `New`: exactly the same activity-creation path `OrderConfirmationProcessor::convertLines()`
    uses, reusing `OrderConfirmationLineActivityFactory` and `MatchingRuleEvaluator`, evaluated
    against `line_regex` and `budget_unit_regex`, and a new `TrackedOrderConfirmationLine` added to
    the order confirmation at that position.
  - `Removed`: call `markRemovedFromSource()` on the stored line; if it has an activity, zero its
    budget and time budget; never call anything that deletes the activity or touches a `Timesheet`.
  - `Unchanged` and any position not in `$positionsToApply`: untouched.
  After applying every requested position, recompute and set the project's `budget`/`timeBudget` as
  the sum over every currently `isHourLine()` line that is not `isRemovedFromSource()`. Does not
  touch `changedAfterConversion`, does not open or commit a transaction, both are the caller's
  responsibility (Task 7).

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\Activity;
use App\Entity\Project;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
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

        $this->resolver()->apply($orderConfirmation, $payload, [0]);
        $this->entityManager()->flush();

        self::assertSame(43200, $activity->getTimeBudget());
        self::assertSame(1200.0, $activity->getBudget());
        self::assertSame(43200, $project->getTimeBudget());
        self::assertSame(1200.0, $project->getBudget());
    }

    public function testApplyingARemovedLineZeroesItsActivityBudgetButKeepsTheActivity(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();
        $activityId = $activity->getId();

        $payload = new LexwarePayload(['lineItems' => []]);

        $this->resolver()->apply($orderConfirmation, $payload, [0]);
        $this->entityManager()->flush();

        self::assertSame(0, $activity->getTimeBudget());
        self::assertSame(0.0, $activity->getBudget());
        self::assertSame(0, $project->getTimeBudget());
        self::assertNotNull($this->entityManager()->getRepository(Activity::class)->find($activityId));
    }

    public function testAPositionNotInTheApplyListIsLeftUntouched(): void
    {
        $this->configure('lexware_sync.derive_budget_enabled', true);
        [$orderConfirmation, $project, $activity] = $this->givenAConvertedOrderConfirmationWithOneHourLine();

        $payload = new LexwarePayload(['lineItems' => [
            ['type' => 'custom', 'name' => 'Development', 'quantity' => 999, 'unitName' => 'Stunden', 'lineItemAmount' => 99900.0],
        ]]);

        $this->resolver()->apply($orderConfirmation, $payload, []);
        $this->entityManager()->flush();

        self::assertSame(28800, $activity->getTimeBudget());
    }

    /**
     * @return array{0: TrackedOrderConfirmation, 1: Project, 2: Activity}
     */
    private function givenAConvertedOrderConfirmationWithOneHourLine(): array
    {
        $project = $this->factory()->createProject();
        $activity = $this->factory()->createActivity($project, 'Development');

        $orderConfirmation = new TrackedOrderConfirmation('lexware-id-resolver-1');
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
```

Confirmed against `Tests/Support/KimaiEntityFactory.php`: `createProject(?Customer $customer = null, string $name = 'Test project'): Project`
and `createActivity(?Project $project = null, string $name = 'Test activity'): Activity`, project
argument first, name second, both already used correctly above.

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationLineResolverTest`
Expected: FAIL, `OrderConfirmationLineResolver` does not exist.

- [x] **Step 3: Write minimal implementation**

```php
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
        $quantity = $entry->newQuantity ?? 0.0;
        $unitName = $entry->newUnitName ?? '';
        $netAmount = $entry->newNetAmount ?? 0.0;
        $isHourLine = $this->matchingRuleEvaluator->matchesUnit($unitName, $this->configuration->getBudgetUnitRegex());

        $line->updateFromLexwareLine($quantity, $unitName, $netAmount, $isHourLine);

        $activity = $line->getActivity();
        if ($activity === null) {
            return;
        }

        if ($isHourLine) {
            $this->activityFactory->applyBudget($activity, $quantity, $netAmount);
        } else {
            $activity->setTimeBudget(0);
            $activity->setBudget(0.0);
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
        $isHourLine = $this->matchingRuleEvaluator->matchesUnit($unitName, $this->configuration->getBudgetUnitRegex());

        $line = new TrackedOrderConfirmationLine($orderConfirmation, $entry->position, $type, $name, $description, $matched, $quantity, $unitName, $netAmount, $isHourLine);
        $orderConfirmation->addLine($line);

        if (!$matched) {
            return;
        }

        $activityName = $this->activityFactory->resolveActivityName($name, $description);
        if ($activityName === null) {
            return;
        }

        $activity = $this->activityService->createNewActivity($project);
        $activity->setName($activityName);

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
        if ($activity !== null) {
            $activity->setTimeBudget(0);
            $activity->setBudget(0.0);
        }
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
                $timeBudget += (int) round($line->getQuantity() * 3600);
                $budget += $line->getNetAmount();
            }
        }

        $project->setTimeBudget($timeBudget);
        $project->setBudget($budget);
    }
}
```

Note `applyNew()` deliberately does not set `$activity->setColor(...)`, unlike
`OrderConfirmationProcessor::convertLines()`. `pickRandomColor()` stays on the processor and reads
`SystemConfiguration`, which this resolver has no constructor argument for; either inject
`SystemConfiguration` here too and duplicate `pickRandomColor()`, or accept Kimai's own default
activity color for a line added through this screen. Decide which while implementing, both are
minor, but do not leave the activity uncolored silently without picking one deliberately, since
that is an inconsistency a reviewer would otherwise have to chase down themselves; the tests in
Step 1 do not assert on color either way.

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationLineResolverTest`
Expected: PASS, all three tests green.

- [x] **Step 5: Run `just check`**

---

## Task 7: `OrderConfirmationLineResolutionController`

**Files:**
- Create: `Controller/OrderConfirmationLineResolutionController.php`
- Create: `Resources/views/triage/resolve_lines.html.twig`
- Create: `Tests/Functional/OrderConfirmationLineResolutionControllerTest.php`

**Interfaces:**
- Produces: `GET /admin/lexware-sync/triage/{id}/resolve-lines` (route
  `lexware_sync_triage_resolve_lines`), rendering the diff table from `OrderConfirmationLineDiffer`,
  gated the same way `TriageController` gates its own actions (`#[IsGranted('manage_lexware_sync')]`),
  404 or redirect when the order confirmation is not converted, `read_lines_enabled` is off, or
  `derive_budget_enabled` is off, since the screen has nothing meaningful to show otherwise.
  `POST` on the same path, `lexware_sync_triage_apply_lines`, CSRF protected the same way
  `TriageController::convert()` is, reading the checked positions from the request, calling
  `OrderConfirmationLineResolver::apply()` inside one Doctrine transaction, then
  `$orderConfirmation->clearChangedAfterConversion()` (a new method on `TrackedOrderConfirmation`,
  simply `$this->changedAfterConversion = false;`), commit, flash message, redirect back to
  `lexware_sync_triage`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class OrderConfirmationLineResolutionControllerTest extends WebTestCase
{
    use SignsLicenseArtefacts;

    public function testTheDiffScreenShowsAChangedLine(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-line-diff-status="changed"]'));
    }

    public function testSubmittingWithNothingCheckedStillClearsTheChangedFlag(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));
        $form = $crawler->filter('form')->form();
        $form->setValues(['positions' => []]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $this->entityManager()->refresh($orderConfirmation);
        self::assertFalse($orderConfirmation->hasChangedAfterConversion());
    }

    public function testSubmittingAChosenPositionUpdatesTheActivityBudget(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.read_lines_enabled', true);
        $this->configure('lexware_sync.derive_budget_enabled', true);
        $orderConfirmation = $this->givenAConvertedOrderConfirmationWithAChangedLine();

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage_resolve_lines', ['id' => $orderConfirmation->getId()]));
        $form = $crawler->filter('form')->form();
        $form->setValues(['positions' => ['0']]);
        $browser->submit($form);

        self::assertResponseRedirects();
        $this->entityManager()->refresh($orderConfirmation);
        self::assertFalse($orderConfirmation->hasChangedAfterConversion());
        self::assertSame(1200.0, $orderConfirmation->getLines()->first()->getNetAmount());
    }

    private function givenAConvertedOrderConfirmationWithAChangedLine(): TrackedOrderConfirmation
    {
        // build and persist a converted TrackedOrderConfirmation with one line and an activity,
        // matching the fixture in OrderConfirmationLineResolverTest, then call
        // updateFromLexwarePayload() with a raw_payload whose line item quantity/lineItemAmount
        // differ from the stored line, exactly the situation that sets changedAfterConversion.
    }
}
```

Fill in `givenAConvertedOrderConfirmationWithAChangedLine()` for real using this suite's existing
entity and license helpers, following `OrderConfirmationLineResolverTest`'s fixture and
`TrackedOrderConfirmation::updateFromLexwarePayload()`'s existing change-detection behaviour.

- [x] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationLineResolutionControllerTest`
Expected: FAIL, the routes do not exist.

- [x] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Dto\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineDiffer;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationLineResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/triage/{id}')]
#[IsGranted('manage_lexware_sync')]
final class OrderConfirmationLineResolutionController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationLineDiffer $differ,
        private readonly OrderConfirmationLineResolver $resolver,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/resolve-lines', name: 'lexware_sync_triage_resolve_lines', methods: ['GET'])]
    public function show(int $id): Response
    {
        $orderConfirmation = $this->guardedOrderConfirmation($id);
        if ($orderConfirmation instanceof RedirectResponse) {
            return $orderConfirmation;
        }

        $entries = $this->differ->diff($orderConfirmation, LexwarePayload::fromJson($orderConfirmation->getRawPayload()));

        return $this->render('@KimaiLexwareSync/triage/resolve_lines.html.twig', [
            'orderConfirmation' => $orderConfirmation,
            'entries' => $entries,
        ]);
    }

    #[Route(path: '/resolve-lines', name: 'lexware_sync_triage_apply_lines', methods: ['POST'])]
    public function apply(int $id, Request $request): RedirectResponse
    {
        $orderConfirmation = $this->guardedOrderConfirmation($id);
        if ($orderConfirmation instanceof RedirectResponse) {
            return $orderConfirmation;
        }

        if (!$this->isCsrfTokenValid('lexware_sync_triage', $request->request->getString('_token'))) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        $positions = array_map('intval', $request->request->all('positions'));

        $this->entityManager->beginTransaction();
        try {
            $this->resolver->apply(
                $orderConfirmation,
                LexwarePayload::fromJson($orderConfirmation->getRawPayload()),
                $positions,
                $this->configuration->getLineRegex(),
            );
            $orderConfirmation->clearChangedAfterConversion();
            $this->repository->save($orderConfirmation);

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $this->addFlash('success', $this->translator()->trans('lexware_sync.triage.lines_resolved', [], 'messages'));

        return $this->redirectToRoute('lexware_sync_triage');
    }

    private function guardedOrderConfirmation(int $id): TrackedOrderConfirmation|RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if (
            $orderConfirmation === null
            || !$orderConfirmation->getStatus()->isConverted()
            || !$this->configuration->isReadLinesEnabled()
            || !$this->configuration->isDeriveBudgetEnabled()
        ) {
            return $this->redirectToRoute('lexware_sync_triage');
        }

        return $orderConfirmation;
    }
}
```

Add `clearChangedAfterConversion(): void` to `TrackedOrderConfirmation`, setting
`$this->changedAfterConversion = false;`. Add a `translator()` accessor or inject
`TranslatorInterface` directly, following whatever pattern `TriageController` already uses for its
own flash messages, check that controller before assuming `$this->translator()` exists on
`AbstractController`. Add `lexware_sync.triage.lines_resolved` to both message translation files.

Create `Resources/views/triage/resolve_lines.html.twig`, a simple table with one row per entry,
`data-line-diff-status="{{ entry.status.value }}"` on each row, a checkbox named `positions[]` with
value `{{ entry.position }}` on every row whose status is not `unchanged`, pre-checked via the
`checked` attribute for exactly those rows, old and new values shown side by side for quantity,
unit name and net amount, and the form's submit button carrying
`data-confirm="{{ 'lexware_sync.triage.resolve_lines_confirm'|trans }}"`, wired to a small inline
script that calls `window.confirm()` before allowing the submit to proceed, in its own
`{% block javascripts %}` following the same inline-script style already used in
`SystemConfigurationSubscriber::buildConnectWebhooksHelpHtml()`. Extend the existing warning
triangle in `triage/index.html.twig` (and `project/origin.html.twig`) to wrap it in
`<a href="{{ path('lexware_sync_triage_resolve_lines', {id: orderConfirmation.id}) }}">` whenever
`orderConfirmation.status.isConverted` is true, leaving it a plain, unlinked icon otherwise (a
pending order confirmation cannot have converted lines to resolve).

- [x] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationLineResolutionControllerTest`
Expected: PASS, all three tests green.

- [x] **Step 5: Run `just check`, then the full functional suite** to confirm the triage template
change did not break `TriageControllerTest` or any existing snapshot of that page.

---

## Task 8: Translations and README

**Files:**
- Modify: `Resources/translations/messages.de.xlf`, `Resources/translations/messages.en.xlf`
- Modify: `Resources/translations/system-configuration.de.xlf`, `Resources/translations/system-configuration.en.xlf`
- Modify: `README.md`

**Interfaces:** none, this task only adds missing strings and documentation, no behaviour change.

- [x] **Step 1: Confirm every `|trans` key introduced in Tasks 3 and 7 exists in both language files**

Grep the new templates and controller for every translation key used:
`lexware_sync.triage.lines_resolved`, `lexware_sync.triage.resolve_lines_confirm`, and whatever
labels Task 3 used for `derive_budget_enabled` and `budget_unit_regex` in the system configuration
screen, plus column headers for the new diff table (`lexware_sync.resolve_lines.quantity`,
`unit_name`, `net_amount`, `status.unchanged`/`changed`/`new`/`removed`). Add any that are missing
to all four `.xlf` files, English and German versions carrying the same meaning, not a literal
translation of the English wording where a natural German phrasing reads better.

- [x] **Step 2: Run `just check`**

Static analysis and the functional suite both exercise missing-translation fallbacks indirectly
through the controller tests already written; a missing key shows up as the raw key string in a
rendered page, not as a test failure by itself, so also grep the two `messages.*.xlf` files for
each key used in `resolve_lines.html.twig` and `triage/index.html.twig` to confirm none was missed.

- [x] **Step 3: Update `README.md`**

Add `lexware_sync.derive_budget_enabled` and `lexware_sync.budget_unit_regex` to the configuration
table, with the deliberate regex-convention exception spelled out in one sentence, the same way the
spec's section 4 explains it. Mention the new resolve-lines screen in whatever section already
describes the triage screen's changed-after-conversion warning.

---

## Final verification

- [x] `just check` passes at the repository root: 164 tests, 440 assertions, codestyle and
  PHPStan level 9 both clean.
- [x] `bin/console kimai:reload -n` succeeds with the plugin installed (routes for
  `lexware_sync_triage_resolve_lines` and `lexware_sync_triage_apply_lines` confirmed present via
  `bin/console debug:router`), and the new migration was applied to the real, non-test dev
  database (`doctrine:migrations:migrate`, confirmed via `information_schema.columns` that
  `quantity`, `unit_name`, `net_amount`, `is_hour_line`, `removed_from_source` all exist on
  `kimai2_ext_lexware_order_confirmation_line`).
- [ ] Manually convert an order confirmation on the real sandbox account with
  `derive_budget_enabled` on and at least one `Stunden` line, confirm the resulting project and
  activity budgets in Kimai's own project and activity detail screens. **Not done**: every order
  confirmation currently on the sandbox account uses `unitName: "Stück"`, none use an hour-based
  unit, confirmed by fetching several via the real API during spec verification. Creating one to
  test this would write new data to the shared sandbox account, which was not asked for.
- [ ] Manually edit that same order confirmation in Lexware (change a quantity, add a line, delete
  a line), run `kimai:lexware-sync:reconcile`, confirm the warning triangle becomes a link on the
  triage screen, open it, confirm the diff table shows exactly the changes made, apply a subset,
  confirm the flag clears and the budgets reflect only the applied rows. **Not done**, same reason,
  blocked on the same missing hour-based sandbox data; the equivalent behaviour is covered by
  `OrderConfirmationLineResolverTest` and `OrderConfirmationLineResolutionControllerTest` against
  the test database instead.
- [x] Confirm a removed line's activity and any timesheet booked against it are still fully intact
  after applying that removal: covered by
  `OrderConfirmationLineResolverTest::testApplyingARemovedLineZeroesItsActivityBudgetButKeepsTheActivity`,
  which asserts the activity still exists after removal, not by a browser click-through.
- [ ] Re-check the default `budget_unit_regex` and the `lineItemAmount`/`discountPercentage`
  interaction against a real Lexware line once one with an hour-based unit or a non-zero discount
  exists on the sandbox account, per the spec's open risks in section 10. **Still open**, blocked
  on the same missing sandbox data as above.

**Deviation from the plan, decided during execution and revised afterwards:**
`project/origin.html.twig` was first left unlinked, unlike `triage/index.html.twig`. It renders
under `IsGranted('view', 'project')`, a broader permission than the `manage_lexware_sync`
permission the resolve-lines controller requires, so linking it there could show a link that 403s
for a project viewer without that permission. The triage screen itself is already gated on
`manage_lexware_sync` for its entire index, so the button there is safe unconditionally.

The user later asked for the project screen to reach the diff too. Resolved by keeping the same
button there but guarding it with `is_granted('manage_lexware_sync')` in the template, on top of
the `read_lines_enabled`, `changedAfterConversion` and `status.isConverted` conditions the triage
screen already uses. `Tests/Functional/ProjectOriginControllerTest.php` covers all four cases,
including a teamlead who legitimately sees the project through their team but holds no Lexware
permission and therefore gets the warning icon without the button.

**UI revised after manual browser testing, post-implementation:** the user tried the shipped
screen and asked for three changes, all applied: (1) the warning triangle stopped being a link,
a proper `<a class="btn btn-sm btn-warning">` button was added to the action column instead, since
an icon reading as clickable was not obvious; (2) the ten-column side-by-side old/new diff table
was reworked into a six-column table (checkbox, status, position, quantity, unit, net amount) with
the old line's row stacked above the new line's row rather than side by side, `table-danger`/
`table-success` row tinting plus a bold weight on only the specific cells that actually differ;
(3) the cancel button's made-up `action.cancel` key was replaced with Kimai core's own `cancel`
key, which already exists and was rendering untranslated. Written up as a standing UI convention
in [[feedback-ui-conventions]] for any future screen this plugin adds.

**Corrections made during execution, worth remembering for the next plan:**
- Task 1's plan text said not to patch `OrderConfirmationProcessor::convertLines()`'s constructor
  call with placeholder values until Task 4. That contradicted this plan's own global constraint
  that every task ends with `just check` passing, PHPStan failed on the argument count mismatch.
  Fixed by passing `0.0, '', 0.0, false` there in Task 1 itself, replaced with real values in Task 4.
- `OrderConfirmationLineResolver`, introduced in Task 6 ahead of its first real consumer, needed
  `public: true` in `Tests/config/test_environment.yaml`, the same trap already documented for
  `HealthCheckResultStore` in the previous feature's memory.
- The controller test for the POST action could not use `entityManager()->refresh($orderConfirmation)`
  after a browser request, `disableReboot()` alone was not enough, the entity was reported as "not
  managed". Fixed by re-fetching the entity fresh via `repository->find($id)` after the request
  instead of trying to refresh a potentially detached reference, following `LicenseBannerTest`'s
  established `disableReboot()` pattern but not relying on the same in-memory object across the
  request boundary.
- PHPStan level 9 rejected both `array_map('intval', ...)` (mixed input) and a direct `(int) $mixed`
  cast for reading `positions[]` from the request. Fixed with an explicit `is_numeric()` check per
  entry before casting, which PHPStan accepts as type narrowing.
