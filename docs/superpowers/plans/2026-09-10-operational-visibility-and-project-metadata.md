# Operational Visibility and Project Metadata Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a failed or silently stopped health check visible on the plugin's own screens, and start using Kimai's native `orderNumber`, `orderDate` and `comment` fields on a converted project, with a configurable choice of what the project is named.

**Architecture:** A `HealthCheckResultStore`, storing one result per named check as a row in Kimai's own `Configuration` table, the same pattern `LicenseStore` already uses. Both health check commands write into it on every run. A `HealthCheckStatusFormatter` turns a stored result, together with a configured expected interval, into zero or one warning messages per check. The existing license banner gains up to two neighbouring rows fed from this. Separately, `OrderConfirmationProcessor::convert()` gains a configurable `name` source and always populates `orderNumber`, `orderDate` and `comment`.

**Tech Stack:** PHP 8.2, Symfony 6.4 components shipped with Kimai 2.65, PHPUnit 10. No new Composer dependencies.

**Spec:** `docs/superpowers/specs/2026-09-10-operational-visibility-and-project-metadata-design.md`

## Global Constraints

- No Composer dependencies of our own. Only what Kimai already ships.
- `declare(strict_types=1)` in every file.
- Classes `final` unless Doctrine needs to derive a proxy. No new Doctrine entity is added here; `HealthCheckResultStore` reuses Kimai's existing `App\Entity\Configuration`.
- Strict comparison, `===` and `!==`, always.
- English everywhere: identifiers, comments, commit messages, documentation.
- No em dash and no double hyphen as punctuation in any project file.
- No abbreviations in identifiers or prose. `configuration`, not `config`.
- Avoid comments. A comment is justified only where a non obvious external constraint needs explaining.
- New health check classes live in the namespace `KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck`.
- Every regular expression field stays optional and every existing behaviour stays intact; this plan only adds fields and messages, it never removes an existing one.
- A health check result is purely informational. It must never gate `OrderConfirmationProcessor::convert()` or `InvoiceProcessor::convert()`.
- No new database table and no migration for Part A. No backfill migration for Part B.
- Every task ends with `just check` passing: code style, static analysis at level 9 with zero findings, and all local suites green.

---

## Part A: health check visibility

### Task 1: `HealthCheckResult` value object

**Files:**
- Create: `Service/HealthCheck/HealthCheckResult.php`
- Test: `Tests/Service/HealthCheck/HealthCheckResultTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: a readonly value object constructed with `checkName: string`, `checkedAt: \DateTimeImmutable`, `ok: bool`, `message: ?string`; a method `isStale(int $intervalDays, \DateTimeImmutable $now): bool` that is true when `checkedAt` is further in the past than `intervalDays` allows; `toJson(): string` and `static fromJson(string $json): self` for round tripping through `HealthCheckResultStore`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use PHPUnit\Framework\TestCase;

final class HealthCheckResultTest extends TestCase
{
    public function testASuccessWithinTheIntervalIsNotStale(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, true, null);

        self::assertFalse($result->isStale(7, $checkedAt->modify('+6 days')));
    }

    public function testAResultOlderThanTheIntervalIsStaleRegardlessOfWhetherItPassed(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $success = new HealthCheckResult('api_key', $checkedAt, true, null);
        $failure = new HealthCheckResult('api_key', $checkedAt, false, 'no longer authenticates');

        self::assertTrue($success->isStale(7, $checkedAt->modify('+8 days')));
        self::assertTrue($failure->isStale(7, $checkedAt->modify('+8 days')));
    }

    public function testExactlyOnTheIntervalIsNotYetStale(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, true, null);

        self::assertFalse($result->isStale(7, $checkedAt->modify('+7 days')));
    }

    public function testItRoundTripsThroughJson(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $checkedAt, false, 'no longer authenticates');

        $restored = HealthCheckResult::fromJson($result->toJson());

        self::assertSame('api_key', $restored->checkName);
        self::assertEquals($checkedAt, $restored->checkedAt);
        self::assertFalse($restored->ok);
        self::assertSame('no longer authenticates', $restored->message);
    }

    public function testAMalformedStoredValueIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\JsonException::class);

        HealthCheckResult::fromJson('not json');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter HealthCheckResultTest`
Expected: FAIL, class `HealthCheckResult` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

final class HealthCheckResult
{
    public function __construct(
        public readonly string $checkName,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly bool $ok,
        public readonly ?string $message,
    ) {
    }

    public function isStale(int $intervalDays, \DateTimeImmutable $now): bool
    {
        return $this->checkedAt->modify('+' . $intervalDays . ' days') < $now;
    }

    public function toJson(): string
    {
        return json_encode([
            'checkName' => $this->checkName,
            'checkedAt' => $this->checkedAt->format(\DateTimeInterface::ATOM),
            'ok' => $this->ok,
            'message' => $this->message,
        ], \JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return new self(
            (string) $decoded['checkName'],
            new \DateTimeImmutable((string) $decoded['checkedAt']),
            (bool) $decoded['ok'],
            $decoded['message'] !== null ? (string) $decoded['message'] : null,
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter HealthCheckResultTest`
Expected: PASS, all five tests green.

- [ ] **Step 5: Run `just check`**

---

### Task 2: `HealthCheckResultStore`

**Files:**
- Create: `Service/HealthCheck/HealthCheckResultStore.php`
- Test: `Tests/Functional/HealthCheck/HealthCheckResultStoreTest.php`

**Interfaces:**
- Consumes: `App\Repository\ConfigurationRepository`, following the exact pattern `Service/License/LicenseStore.php` already establishes.
- Produces: `store(HealthCheckResult $result): void` and `latest(string $checkName): ?HealthCheckResult`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class HealthCheckResultStoreTest extends FunctionalTestCase
{
    public function testNothingStoredYieldsNull(): void
    {
        self::assertNull($this->store()->latest('api_key'));
    }

    public function testAStoredResultComesBackForTheSameCheckName(): void
    {
        $checkedAt = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $this->store()->store(new HealthCheckResult('api_key', $checkedAt, true, null));

        $latest = $this->store()->latest('api_key');

        self::assertNotNull($latest);
        self::assertTrue($latest->ok);
        self::assertEquals($checkedAt, $latest->checkedAt);
    }

    public function testDifferentChecksAreStoredSeparately(): void
    {
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable(), true, null));

        self::assertNull($this->store()->latest('license'));
    }

    public function testStoringTwiceForTheSameCheckReplacesRatherThanAccumulates(): void
    {
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable('2026-09-01'), false, 'first failure'));
        $this->store()->store(new HealthCheckResult('api_key', new \DateTimeImmutable('2026-09-10'), true, null));

        $latest = $this->store()->latest('api_key');

        self::assertNotNull($latest);
        self::assertTrue($latest->ok);
    }

    private function store(): HealthCheckResultStore
    {
        return $this->service(HealthCheckResultStore::class);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter HealthCheckResultStoreTest`
Expected: FAIL, class `HealthCheckResultStore` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;

final class HealthCheckResultStore
{
    private const CONFIGURATION_KEY_PREFIX = 'lexware_sync.health_check.';

    public function __construct(private readonly ConfigurationRepository $repository)
    {
    }

    public function store(HealthCheckResult $result): void
    {
        $key = self::CONFIGURATION_KEY_PREFIX . $result->checkName;
        $stored = $this->repository->findOneBy(['name' => $key]);
        if ($stored === null) {
            $stored = new Configuration();
            $stored->setName($key);
        }

        $stored->setValue($result->toJson());
        $this->repository->saveConfiguration($stored);
    }

    public function latest(string $checkName): ?HealthCheckResult
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY_PREFIX . $checkName]);
        $value = $stored?->getValue();

        return \is_string($value) && $value !== '' ? HealthCheckResult::fromJson($value) : null;
    }
}
```

Register the service in `Resources/config/services.yaml` following the existing autowiring pattern already used for every other service in this bundle, no explicit entry needed beyond the default `_defaults` autowire rule already in place.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter HealthCheckResultStoreTest`
Expected: PASS, all four tests green.

- [ ] **Step 5: Run `just check`**

---

### Task 3: Two new configuration keys

**Files:**
- Modify: `Configuration/LexwareSyncConfiguration.php`
- Modify: `EventSubscriber/SystemConfigurationSubscriber.php`
- Modify: `Tests/Configuration/LexwareSyncConfigurationTest.php`
- Modify: `Resources/translations/system-configuration.de.xlf`, `Resources/translations/system-configuration.en.xlf`

**Interfaces:**
- Produces: `LexwareSyncConfiguration::getCheckApiKeyIntervalDays(): int` (default `7`), `getCheckLicenseIntervalDays(): int` (default `1`), both following the exact fallback shape `getReconcileIntervalMinutes()` already uses: any value that is not a positive integer falls back to the default.

- [ ] **Step 1: Extend the failing test**

Add to `Tests/Configuration/LexwareSyncConfigurationTest.php`:

```php
    public function testTheHealthCheckIntervalsFallBackWhenNotAPositiveNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame(7, $configuration->getCheckApiKeyIntervalDays());
        self::assertSame(1, $configuration->getCheckLicenseIntervalDays());

        $withOverrides = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.check_api_key_interval_days' => '3',
            'lexware_sync.check_license_interval_days' => '0',
        ]));

        self::assertSame(3, $withOverrides->getCheckApiKeyIntervalDays());
        self::assertSame(1, $withOverrides->getCheckLicenseIntervalDays());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest`
Expected: FAIL, `getCheckApiKeyIntervalDays` and `getCheckLicenseIntervalDays` do not exist.

- [ ] **Step 3: Write minimal implementation**

Add to `LexwareSyncConfiguration`, next to `getReconcileIntervalMinutes()`:

```php
    private const DEFAULT_CHECK_API_KEY_INTERVAL_DAYS = 7;
    private const DEFAULT_CHECK_LICENSE_INTERVAL_DAYS = 1;

    public function getCheckApiKeyIntervalDays(): int
    {
        $days = (int) $this->readText('lexware_sync.check_api_key_interval_days');

        return $days > 0 ? $days : self::DEFAULT_CHECK_API_KEY_INTERVAL_DAYS;
    }

    public function getCheckLicenseIntervalDays(): int
    {
        $days = (int) $this->readText('lexware_sync.check_license_interval_days');

        return $days > 0 ? $days : self::DEFAULT_CHECK_LICENSE_INTERVAL_DAYS;
    }
```

Add both keys to `SystemConfigurationSubscriber::onSystemConfiguration()`, next to `lexware_sync.reconcile_interval_minutes`, as `IntegerType`, `setRequired(false)`, each with a `help` option explaining it is documentation for the cron entry and enforces nothing itself, mirroring the existing help text style for `reconcile_interval_minutes` in the translation files. Add the matching label and help strings to both `system-configuration.de.xlf` and `system-configuration.en.xlf`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest`
Expected: PASS.

- [ ] **Step 5: Run `just check`**

---

### Task 4: `HealthCheckStatusFormatter`

**Files:**
- Create: `Service/HealthCheck/HealthCheckStatusFormatter.php`
- Test: `Tests/Service/HealthCheck/HealthCheckStatusFormatterTest.php`
- Modify: `Resources/translations/messages.de.xlf`, `Resources/translations/messages.en.xlf`

**Interfaces:**
- Consumes: `Symfony\Contracts\Translation\TranslatorInterface`, mirroring `Service/License/LicenseVerdictMessageFormatter.php` exactly.
- Produces: `format(?HealthCheckResult $result, int $intervalDays, \DateTimeImmutable $now): ?array{state: string, message: string}`. Returns `null` when there is nothing to say: no result yet, or a passing result within the interval. Returns `['state' => 'failed', 'message' => ...]` for a failing latest result, `['state' => 'stale', 'message' => ...]` for a result, passing or not, that is older than `intervalDays` allows. A stale, failing result reports `failed`, since an active failure is the more urgent fact.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckStatusFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HealthCheckStatusFormatterTest extends TestCase
{
    public function testNothingStoredYieldsNoMessage(): void
    {
        $formatter = new HealthCheckStatusFormatter($this->translator());

        self::assertNull($formatter->format(null, 7, new \DateTimeImmutable()));
    }

    public function testARecentSuccessYieldsNoMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-1 day'), true, null);

        $formatter = new HealthCheckStatusFormatter($this->translator());

        self::assertNull($formatter->format($result, 7, $now));
    }

    public function testARecentFailureYieldsAFailedMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-1 day'), false, 'no longer authenticates');

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('failed', $message['state']);
    }

    public function testAnOldSuccessYieldsAStaleMessage(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-10 days'), true, null);

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('stale', $message['state']);
    }

    public function testAnOldFailureYieldsAFailedMessageRatherThanStale(): void
    {
        $now = new \DateTimeImmutable('2026-09-10T08:00:00+02:00');
        $result = new HealthCheckResult('api_key', $now->modify('-10 days'), false, 'no longer authenticates');

        $formatter = new HealthCheckStatusFormatter($this->translator());
        $message = $formatter->format($result, 7, $now);

        self::assertNotNull($message);
        self::assertSame('failed', $message['state']);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter HealthCheckStatusFormatterTest`
Expected: FAIL, class `HealthCheckStatusFormatter` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck;

use Symfony\Contracts\Translation\TranslatorInterface;

final class HealthCheckStatusFormatter
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @return array{state: string, message: string}|null
     */
    public function format(?HealthCheckResult $result, int $intervalDays, \DateTimeImmutable $now): ?array
    {
        if ($result === null) {
            return null;
        }

        if (!$result->ok) {
            return [
                'state' => 'failed',
                'message' => $this->translator->trans(
                    'lexware_sync.health_check.failed',
                    ['%message%' => $result->message ?? ''],
                    'messages',
                ),
            ];
        }

        if ($result->isStale($intervalDays, $now)) {
            return [
                'state' => 'stale',
                'message' => $this->translator->trans(
                    'lexware_sync.health_check.stale',
                    ['%days%' => (string) $intervalDays],
                    'messages',
                ),
            ];
        }

        return null;
    }
}
```

Add `lexware_sync.health_check.failed` and `lexware_sync.health_check.stale` to both `messages.de.xlf` and `messages.en.xlf`, each with a `%message%` or `%days%` placeholder, matching the interpolation style already used for `lexware_sync.license.*` entries.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter HealthCheckStatusFormatterTest`
Expected: PASS, all five tests green.

- [ ] **Step 5: Run `just check`**

---

### Task 5: `CheckLexwareApiKeyCommand` records its result

**Files:**
- Modify: `Command/CheckLexwareApiKeyCommand.php`
- Create: `Tests/Functional/HealthCheck/CheckLexwareApiKeyCommandTest.php`

**Interfaces:**
- Consumes: `HealthCheckResultStore` (new constructor argument).
- Produces: no change to the command's existing exit code or console output behaviour, only an added write to the store on every run.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckLexwareApiKeyCommandTest extends FunctionalTestCase
{
    public function testASuccessfulCallIsRecordedAsPassing(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', ['content' => [], 'last' => true]);

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $result = $this->service(HealthCheckResultStore::class)->latest('api_key');
        self::assertNotNull($result);
        self::assertTrue($result->ok);
    }

    public function testAFailingCallIsRecordedAsFailingWithItsMessage(): void
    {
        $this->lexware()->willRespondWith('GET', '/v1/voucherlist', ['message' => 'invalid token'], 401);

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $result = $this->service(HealthCheckResultStore::class)->latest('api_key');
        self::assertNotNull($result);
        self::assertFalse($result->ok);
        self::assertNotNull($result->message);
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('kimai:lexware-sync:check-api-key'));
        $tester->execute([]);

        return $tester;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLexwareApiKeyCommandTest`
Expected: FAIL, no result is stored yet.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:check-api-key', description: 'Confirm the configured Lexware API key still authenticates')]
final class CheckLexwareApiKeyCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly HealthCheckResultStore $resultStore,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->client->listOrderConfirmationVoucherPage(0);
        } catch (LexwareApiException $exception) {
            $message = 'The Lexware API key no longer authenticates: ' . $exception->getMessage();
            $this->logger->error($message);
            $io->error($message);
            $this->resultStore->store(new HealthCheckResult('api_key', new \DateTimeImmutable(), false, $message));

            return Command::FAILURE;
        }

        $this->resultStore->store(new HealthCheckResult('api_key', new \DateTimeImmutable(), true, null));
        $io->success('The Lexware API key still authenticates.');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLexwareApiKeyCommandTest`
Expected: PASS, both tests green.

- [ ] **Step 5: Run `just check`**

---

### Task 6: `CheckLicenseCommand` also records a staleness result

**Files:**
- Modify: `Command/CheckLicenseCommand.php`
- Modify: `Tests/Functional/License/CheckLicenseCommandTest.php`

**Interfaces:**
- Consumes: `HealthCheckResultStore` (new constructor argument).
- Produces: no change to the command's existing behaviour, `LicenseStore` interaction, or exit codes. Only an added write to `HealthCheckResultStore` under the check name `license` at the end of every run, success or failure, used purely for staleness detection, never for the licensed or refused decision itself, which keeps coming from `LicenseGate`.

- [ ] **Step 1: Extend the failing test**

Add to `Tests/Functional/License/CheckLicenseCommandTest.php`:

```php
    public function testEveryRunRecordsAHealthCheckResultRegardlessOfOutcome(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->refusal('expired')]);

        $this->runCommand();

        $result = $this->service(\KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore::class)->latest('license');
        self::assertNotNull($result);
        self::assertFalse($result->ok);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLicenseCommandTest`
Expected: FAIL, no result is stored yet.

- [ ] **Step 3: Write minimal implementation**

In `CheckLicenseCommand`, add a `HealthCheckResultStore $resultStore` constructor argument, then record a `HealthCheckResult('license', new \DateTimeImmutable(), $fresh->isLicensed(), $fresh->reason())` (or the equivalent value at each existing return point: the "no signing key" and "no license key" early returns count as a failure with that message, the reused-stored-result branch records whatever `$stored->isLicensed()` says, and the freshly fetched branch records `$fresh->isLicensed()`) immediately before every `return` in the method, so a run that exits early still leaves a trace.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLicenseCommandTest`
Expected: PASS, the new test and every existing one in the file still green.

- [ ] **Step 5: Run `just check`**

---

### Task 7: Wire the banner into the three existing screens

**Files:**
- Create: `Resources/views/health_check/banner.html.twig`
- Modify: `Controller/TriageController.php`
- Modify: `Controller/InvoiceAssignmentController.php`
- Modify: `Resources/views/triage/index.html.twig`, `Resources/views/invoice/index.html.twig`, `Resources/views/invoice/assign.html.twig`
- Create: `Tests/Functional/HealthCheck/HealthCheckBannerTest.php`

**Interfaces:**
- Consumes: `HealthCheckResultStore`, `HealthCheckStatusFormatter`, `LexwareSyncConfiguration` in both controllers.
- Produces: a `healthCheckMessages` template variable, a list of `['check' => 'api_key'|'license', 'state' => 'failed'|'stale', 'message' => string]`, rendered as one alert row per entry with `data-health-check="{{ entry.check }}"` and `data-health-check-state="{{ entry.state }}"` attributes, mirroring the existing `data-license-state` convention in `license/banner.html.twig`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\HealthCheck;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResult;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\HealthCheck\HealthCheckResultStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\SignsLicenseArtefacts;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class HealthCheckBannerTest extends WebTestCase
{
    use SignsLicenseArtefacts;

    public function testAFailingApiKeyCheckShowsOnTheTriageScreen(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('api_key', new \DateTimeImmutable(), false, 'no longer authenticates'),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-health-check="api_key"][data-health-check-state="failed"]'));
    }

    public function testAStaleLicenseCheckShowsOnTheInvoiceScreen(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('license', new \DateTimeImmutable('-10 days'), true, null),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-health-check="license"][data-health-check-state="stale"]'));
    }

    public function testARecentPassingResultShowsNoBanner(): void
    {
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);
        $this->givenAConfirmedLicense();
        $this->service(HealthCheckResultStore::class)->store(
            new HealthCheckResult('api_key', new \DateTimeImmutable(), true, null),
        );

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-health-check]'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter HealthCheckBannerTest`
Expected: FAIL, no `data-health-check` element exists yet.

- [ ] **Step 3: Write minimal implementation**

Add a private `healthCheckMessages(): array` method to both `TriageController` and `InvoiceAssignmentController`, built once per request from the newly injected `HealthCheckResultStore`, `HealthCheckStatusFormatter` and `LexwareSyncConfiguration`:

```php
    /**
     * @return list<array{check: string, state: string, message: string}>
     */
    private function healthCheckMessages(): array
    {
        $now = new \DateTimeImmutable();
        $messages = [];

        $apiKey = $this->healthCheckFormatter->format(
            $this->healthCheckStore->latest('api_key'),
            $this->configuration->getCheckApiKeyIntervalDays(),
            $now,
        );
        if ($apiKey !== null) {
            $messages[] = ['check' => 'api_key'] + $apiKey;
        }

        $license = $this->healthCheckFormatter->format(
            $this->healthCheckStore->latest('license'),
            $this->configuration->getCheckLicenseIntervalDays(),
            $now,
        );
        if ($license !== null) {
            $messages[] = ['check' => 'license'] + $license;
        }

        return $messages;
    }
```

Pass `'healthCheckMessages' => $this->healthCheckMessages()` alongside the existing `licenseState`/`licenseMessage` render parameters at every `render()` call site in both controllers. Create `Resources/views/health_check/banner.html.twig`:

```twig
{% for entry in healthCheckMessages %}
    <div class="alert alert-warning" data-health-check="{{ entry.check }}" data-health-check-state="{{ entry.state }}">
        {{ entry.message }}
    </div>
{% endfor %}
```

Include it directly below `{{ include('@KimaiLexwareSync/license/banner.html.twig') }}` in `triage/index.html.twig`, `invoice/index.html.twig` and `invoice/assign.html.twig`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter HealthCheckBannerTest`
Expected: PASS, all three tests green.

- [ ] **Step 5: Run `just check`, then the full functional suite** to confirm nothing in `TriageControllerTest` or the license banner tests regressed.

---

## Part B: project metadata mapping

### Task 8: Populate `orderNumber`, `orderDate` and `comment`, tighten the voucher number guard

**Files:**
- Modify: `Service/OrderConfirmationProcessor.php`
- Modify: `Tests/Functional/OrderConfirmationProcessorTest.php`

**Interfaces:**
- Produces: no new public method signature change. `convert()` now also calls `setOrderNumber()`, `setOrderDate()` and `setComment()` on the created `Project`, and the existing voucher number length guard changes from `2..150` to `2..50`.

- [ ] **Step 1: Extend the failing test**

Add to `Tests/Functional/OrderConfirmationProcessorTest.php`:

```php
    public function testConversionPopulatesOrderNumberOrderDateAndComment(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-order-metadata', 'AB-2026-050', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        $project = $orderConfirmation->getProject();
        self::assertInstanceOf(Project::class, $project);
        self::assertSame('AB-2026-050', $project->getOrderNumber());
        self::assertEquals(new \DateTime('2026-09-01'), $project->getOrderDate());
        self::assertSame('Order confirmation for a test', $project->getComment());
    }

    public function testAVoucherNumberLongerThanFiftyCharactersIsUnprocessable(): void
    {
        $this->givenAConfirmedLicense();
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-long-voucher', str_repeat('A', 51), 'Contact GmbH');

        $this->expectException(\KimaiPlugin\KimaiLexwareSyncBundle\Service\UnprocessableOrderConfirmationException::class);

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: FAIL, `orderNumber`/`orderDate`/`comment` stay empty, and a 51 character voucher number is currently accepted rather than rejected.

- [ ] **Step 3: Write minimal implementation**

In `OrderConfirmationProcessor::convert()`, change:

```php
        $voucherNumberLength = \strlen($orderConfirmation->getVoucherNumber());
        if ($voucherNumberLength < 2 || $voucherNumberLength > 150) {
```

to:

```php
        $voucherNumberLength = \strlen($orderConfirmation->getVoucherNumber());
        if ($voucherNumberLength < 2 || $voucherNumberLength > 50) {
```

and after the existing `$project->setStart(...)` line, add:

```php
        $project->setOrderNumber($orderConfirmation->getVoucherNumber());
        $project->setOrderDate(\DateTime::createFromImmutable($orderConfirmation->getVoucherDate()));
        $project->setComment($orderConfirmation->getTitle());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: PASS, all tests in the file green, including the pre-existing ones.

- [ ] **Step 5: Run `just check`**

---

### Task 9: `project_title_source` setting

**Files:**
- Modify: `Configuration/LexwareSyncConfiguration.php`
- Modify: `EventSubscriber/SystemConfigurationSubscriber.php`
- Modify: `Service/OrderConfirmationProcessor.php`
- Modify: `Tests/Configuration/LexwareSyncConfigurationTest.php`
- Modify: `Tests/Functional/OrderConfirmationProcessorTest.php`
- Modify: `Resources/translations/system-configuration.de.xlf`, `Resources/translations/system-configuration.en.xlf`

**Interfaces:**
- Produces: `LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER` (default), `PROJECT_TITLE_ORDER_CONFIRMATION_TITLE`, `PROJECT_TITLE_CUSTOMER_AND_TITLE`, and `getProjectTitleSource(): string`, mirroring `getProjectCompletionMode()` exactly. `OrderConfirmationProcessor::convert()` resolves `name` from the configured source, with a fallback to the voucher number for a resolved title that is empty, longer than 150 characters, or contains `<`, `>`, `"` or `=`.

- [ ] **Step 1: Extend the failing tests**

Add to `Tests/Configuration/LexwareSyncConfigurationTest.php`:

```php
    public function testTheProjectTitleSourceDefaultsToTheVoucherNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader());

        self::assertSame(LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER, $configuration->getProjectTitleSource());
    }

    public function testAnUnknownProjectTitleSourceFallsBackToTheVoucherNumber(): void
    {
        $configuration = new LexwareSyncConfiguration(new InMemorySettingReader([
            'lexware_sync.project_title_source' => 'something_unexpected',
        ]));

        self::assertSame(LexwareSyncConfiguration::PROJECT_TITLE_VOUCHER_NUMBER, $configuration->getProjectTitleSource());
    }
```

Add to `Tests/Functional/OrderConfirmationProcessorTest.php`:

```php
    public function testTheOrderConfirmationTitleCanBeUsedAsTheProjectName(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source', 'AB-2026-060', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('Order confirmation for a test', $orderConfirmation->getProject()?->getName());
    }

    public function testCustomerAndTitleCanBeCombinedAsTheProjectName(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_CUSTOMER_AND_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source-2', 'AB-2026-061', 'Contact GmbH');

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('Contact GmbH - Order confirmation for a test', $orderConfirmation->getProject()?->getName());
    }

    public function testAnUnusableTitleFallsBackToTheVoucherNumber(): void
    {
        $this->givenAConfirmedLicense();
        $this->configure('lexware_sync.project_title_source', LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE);
        $orderConfirmation = $this->trackedOrderConfirmation('lexware-id-title-source-3', 'AB-2026-062', 'Contact GmbH');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-062',
            'Title with a disallowed " character',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            json_encode($this->payload(), \JSON_THROW_ON_ERROR),
            null,
        );
        $this->entityManager()->flush();

        $this->processor()->convert($orderConfirmation, new LexwarePayload($this->payload()), null, '', false);
        $this->entityManager()->flush();

        self::assertSame('AB-2026-062', $orderConfirmation->getProject()?->getName());
    }
```

Note: `configure()` requires a `string|int|bool|null` value; pass the enum-like constants directly, they are already plain strings.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest` and `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: FAIL, the constants and accessor do not exist yet, and `convert()` always uses the voucher number.

- [ ] **Step 3: Write minimal implementation**

Add to `LexwareSyncConfiguration`:

```php
    public const PROJECT_TITLE_VOUCHER_NUMBER = 'voucher_number';
    public const PROJECT_TITLE_ORDER_CONFIRMATION_TITLE = 'order_confirmation_title';
    public const PROJECT_TITLE_CUSTOMER_AND_TITLE = 'customer_and_title';

    public function getProjectTitleSource(): string
    {
        $value = $this->readText('lexware_sync.project_title_source');

        return match ($value) {
            self::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE, self::PROJECT_TITLE_CUSTOMER_AND_TITLE => $value,
            default => self::PROJECT_TITLE_VOUCHER_NUMBER,
        };
    }
```

Add the key to `SystemConfigurationSubscriber::onSystemConfiguration()` as a `ChoiceType` with the three constants as choices, mirroring `lexware_sync.project_completion_mode` exactly, default label "Voucher number" selected. Add labels to both translation files.

In `OrderConfirmationProcessor`, replace the single `$project->setName($orderConfirmation->getVoucherNumber());` line with a call to a new private method, and thread the configured source through. Since `convert()` does not currently receive `LexwareSyncConfiguration` as a parameter, but the class already injects `SystemConfiguration` directly rather than `LexwareSyncConfiguration`, add a `LexwareSyncConfiguration $configuration` constructor argument (the class already reads other plugin settings this way everywhere else in the codebase, this is the first place `OrderConfirmationProcessor` itself needs one):

```php
    private function resolveProjectName(TrackedOrderConfirmation $orderConfirmation, Customer $customer): string
    {
        $voucherNumber = $orderConfirmation->getVoucherNumber();

        $candidate = match ($this->configuration->getProjectTitleSource()) {
            LexwareSyncConfiguration::PROJECT_TITLE_ORDER_CONFIRMATION_TITLE => $orderConfirmation->getTitle(),
            LexwareSyncConfiguration::PROJECT_TITLE_CUSTOMER_AND_TITLE => $customer->getName() . ' - ' . $orderConfirmation->getTitle(),
            default => $voucherNumber,
        };

        return $this->isUsableAsProjectName($candidate) ? $candidate : $voucherNumber;
    }

    private function isUsableAsProjectName(string $value): bool
    {
        $length = \strlen($value);
        if ($length < 2 || $length > 150) {
            return false;
        }

        foreach (['<', '>', '"', '='] as $character) {
            if (str_contains($value, $character)) {
                return false;
            }
        }

        return true;
    }
```

Call it as `$project->setName($this->resolveProjectName($orderConfirmation, $customer));`, after `$customer` has been resolved and before `$project` is saved.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LexwareSyncConfigurationTest` and `vendor/bin/phpunit --testsuite functional --filter OrderConfirmationProcessorTest`
Expected: PASS, every test in both files green, including every pre-existing one.

- [ ] **Step 5: Run `just check`, then the full unit and functional suites** to confirm nothing elsewhere regressed from the constructor signature change.

---

## Final verification

- [ ] `just check` passes at the repository root.
- [ ] `bin/console kimai:reload -n` and `bin/console cache:clear` succeed with the plugin installed, per the lint, boot, exercise loop in the `kimai-plugin` skill.
- [ ] Manually exercise the triage screen and the invoice list with a deliberately broken API key configured, confirm the new banner row appears and disappears again once `kimai:lexware-sync:check-api-key` is run with a working key.
- [ ] Manually convert an order confirmation with each of the three `project_title_source` values selected, confirm the resulting project's name, order number, order date and comment in Kimai's own project detail screen.
- [ ] Update `README.md`'s configuration table with the three new keys, and its "Running it" section if the health check banner changes what an administrator is told to expect from the existing cron entries.
