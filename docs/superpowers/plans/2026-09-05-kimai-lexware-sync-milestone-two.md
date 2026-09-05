# KimaiLexwareSync Milestone Two Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build milestone two of the KimaiLexwareSyncBundle plugin so that a Lexware invoice draft, created by a person inside Lexware by pursuing a tracked order confirmation, surfaces inside Kimai, lets a person assign booked timesheets to it, and produces a new, combined invoice pushed back to Lexware.

**Architecture:** A second webhook route and a second reconciliation console command feed the same kind of single, synchronous entry point milestone one already uses, `InvoiceSynchronizer`, which refetches the invoice from the Lexware API, and tracks it only when it is still a draft and its `relatedVouchers` entry points at an order confirmation already converted into a Kimai project. A person then opens a dedicated assignment screen, picks timesheets and a line shape, and `InvoiceProcessor` builds the combined line items, calls the Lexware API to create the new invoice, and, only once that call has definitely succeeded, records the outcome locally in one Doctrine transaction. Creating a Lexware invoice is not idempotent, so a creation-attempted timestamp is written and committed by itself before that call, outside the transaction, so an ambiguous network failure can be told apart from a clear one.

**Tech Stack:** PHP 8.2+, Symfony 6.4 components already bundled with Kimai (`symfony/http-client`, `symfony/console`, `symfony/form`, `symfony/validator`), Doctrine ORM 2.20, Twig, plain PHPUnit for the parts that need neither the kernel nor a database.

**Spec:** `docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`, sections 12 through 18.

## Global Constraints

- Every file in this repository is written in English: code, comments, commit messages, documentation. Chat with the user stays in German, nothing else does.
- Never use an em dash or a double hyphen as punctuation in a project file. A markdown table separator row is exempt.
- Avoid abbreviations in prose and in code identifiers.
- Declare `strict_types=1` in every PHP file.
- Use constructor property promotion and typed, readonly properties where the value never changes after construction.
- Mark classes `final` unless there is a concrete, current reason for something else to extend them. `TrackedInvoice` is the one exception in this plan, for the same reason `TrackedOrderConfirmation` is not final: Doctrine's proxy generator cannot lazily load a `final` class as the target of a `ManyToOne` association, and `TrackedInvoiceTimesheet` needs exactly that.
- Keep a class responsible for one thing.
- Use strict comparison, `===` and `!==`, always.
- Avoid comments. The rare exception explains a genuinely non-obvious external constraint, never what the code already says through its names.
- Do not divide a class into sections with banner comments. Split into a new class or namespace instead.
- Single quoted strings, four space indentation, PHP attributes for routing and mapping, business logic in services rather than controllers, matching the conventions the `kimai-plugin` skill describes for Kimai's own core code.
- No Composer dependencies of the plugin's own. Only what Kimai already ships at runtime is usable.
- This repository is MIT licensed. Never add or vendor GPL or AGPL licensed code.
- Plugin database tables use the `kimai2_ext_` prefix.
- Every regular expression configuration field is optional; an empty value matches everything, never nothing.
- Never trust the content of a webhook body for data, only as a signal to refetch the authoritative resource by identifier.
- One Doctrine transaction for the locally recorded outcome of a conversion: the `TrackedInvoice` status, the timesheets marked exported, and an optional project completion happen together. The one exception, deliberately, is the creation-attempted timestamp on `TrackedInvoice`, which is written and committed by itself before the non-idempotent `POST /v1/invoices` call, so it survives even if the process crashes before that call returns.
- This Kimai installation has no plugin test harness: no separate `tests` directory beyond this plugin's own, no `phpunit.xml.dist` outside this plugin, `APP_ENV=prod`, one database with no separate test environment. `phpunit.xml.dist` and `Tests/bootstrap.php` already exist from milestone one; this plan only adds test files under `Tests/`. Automated tests are plain PHPUnit tests that need neither the kernel nor a database. Anything touching Doctrine or the kernel is verified by hand through the lint, boot, exercise loop: rebuild the cache, confirm wiring with `debug:*` commands, run the install command, exercise the real behavior through the Lexware sandbox account and the Kimai browser interface.
- The bundle directory must be named `KimaiLexwareSyncBundle` for Kimai's kernel to discover it. Every file path below is relative to the plugin root regardless of what the mount is currently named on the host.
- The Lexware API base URL is `https://api.lexware.io`, rate limited to two requests per second, authenticated with `Authorization: Bearer <key>`, read from the `LEXWARE_API_KEY` environment variable.
- Confirmed Lexware invoice `voucherStatus` values: `draft`, then, once left, `open`, `paidoff`, `voided`. An invoice never returns to `draft` once it has left that status.
- The Lexware filtered voucher list deep link for a superseded draft's voucher number is `https://app.lexware.de/vouchers#!/VoucherList/?filter=invoice&sort=sortByVoucherDate&sortDirection=desc&query={voucherNumber}`, confirmed directly against the live account during this milestone's brainstorming.
- The two open items the spec carried into this plan, whether a newly created Lexware invoice can itself carry an explicit `relatedVouchers` entry, and the exact shape of Lexware's list filtering for invoices, were both settled by a live spike against the sandbox account on 2026-09-05 (see Task 4): no, an outgoing `relatedVouchers` is silently dropped; and there is no bare `GET /v1/invoices` list endpoint at all, `GET /v1/voucherlist?voucherType=invoice&...` is the correct one, exactly mirroring how order confirmations are listed. The same spike found a third, previously unknown requirement: Lexware requires a `shippingConditions` object on every invoice, which Task 9 now sends.

---

## Task 1: Rename the triage permission to manage_lexware_sync

**Files:**
- Modify: `Controller/TriageController.php`
- Modify: `EventSubscriber/TriageActionSubscriber.php`
- Modify: `DependencyInjection/KimaiLexwareSyncExtension.php`

**Interfaces:**
- Produces: the permission string `manage_lexware_sync`, gating both the existing triage screen and, from Task 10 onward, the new invoice assignment screen. Nothing outside these three files refers to the old name; it was only ever declared and checked here.

This permission started as `triage_lexware_sync` in milestone one, scoped to a single screen. Milestone two's design gave it the more general name because it now needs to cover both screens. It is a static role mapping declared in the bundle's own configuration, not a per-user assignment stored in the database, so this rename is a plain code change with no data migration.

- [ ] **Step 1: Rename in the controller**

In `Controller/TriageController.php`, change:

```php
#[IsGranted('triage_lexware_sync')]
```

to:

```php
#[IsGranted('manage_lexware_sync')]
```

- [ ] **Step 2: Rename in the action subscriber**

In `EventSubscriber/TriageActionSubscriber.php`, change:

```php
if (!$this->isGranted('triage_lexware_sync')) {
```

to:

```php
if (!$this->isGranted('manage_lexware_sync')) {
```

- [ ] **Step 3: Rename in the permission declaration**

In `DependencyInjection/KimaiLexwareSyncExtension.php`, change:

```php
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['triage_lexware_sync'],
                    'ROLE_ADMIN' => ['triage_lexware_sync'],
                ],
            ],
        ]);
```

to:

```php
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['manage_lexware_sync'],
                    'ROLE_ADMIN' => ['manage_lexware_sync'],
                ],
            ],
        ]);
```

- [ ] **Step 4: Manual verification**

```bash
bin/console kimai:reload -n
```

Log in as a user with `ROLE_ADMIN`, open the existing triage screen exactly as before, and confirm it still works. Open Kimai's role permission administration screen and confirm `manage_lexware_sync` appears instead of `triage_lexware_sync`.

- [ ] **Step 5: Commit**

```bash
git add Controller/TriageController.php EventSubscriber/TriageActionSubscriber.php DependencyInjection/KimaiLexwareSyncExtension.php
git commit -m "Rename the triage permission to manage_lexware_sync ahead of the invoice screen"
```

---

## Task 2: Data model for tracked invoices

**Files:**
- Create: `Enum/InvoiceStatus.php`
- Create: `Entity/TrackedInvoice.php`
- Create: `Entity/TrackedInvoiceTimesheet.php`
- Create: `Repository/TrackedInvoiceRepository.php`
- Create: `Repository/TrackedInvoiceTimesheetRepository.php`
- Create: `Migrations/Version20260905120000.php`

**Interfaces:**
- Consumes: `KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation` from milestone one; `App\Entity\User`; `App\Entity\Timesheet`.
- Produces: `TrackedInvoice` with `getStatus(): InvoiceStatus`, `getRelatedOrderConfirmation(): TrackedOrderConfirmation`, `getRawPayload(): string`, `getCreationAttemptedAt(): ?\DateTimeImmutable`, `getCreatedInvoiceLexwareId(): ?string`, `markCreationAttempted(): void`, `clearCreationAttempt(): void`, `markConverted(string $createdInvoiceLexwareId, User $processedBy): void`, `markRejected(User $processedBy): void`, `markSuperseded(): void`, `reopen(): void`. `TrackedInvoiceRepository` with `findByLexwareId()`, `findPending()`, `countPending()`, `findRecentlyConverted(int $limit = 20)`, `save()`. `TrackedInvoiceTimesheet` with `getTrackedInvoice()`, `getTimesheet(): ?Timesheet`, `wasModifiedAfterExport(): bool`. `TrackedInvoiceTimesheetRepository` with `save()` and `hasModifiedTimesheets(TrackedInvoice $trackedInvoice): bool`. Every later task in this plan builds on exactly these methods.

There is deliberately no separate line table for a tracked invoice, unlike milestone one's order confirmation lines. A draft's original lines are never matched against a rule or turned into a Kimai entity; `InvoiceProcessor` in Task 9 reads them back out of `rawPayload` unchanged when it builds the new invoice.

- [ ] **Step 1: Write the status enum**

`Enum/InvoiceStatus.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isTerminal(): bool
    {
        return $this === self::Converted || $this === self::Superseded;
    }
}
```

`Superseded` means the draft left `draft` status inside Lexware without ever being converted through the plugin. It is kept distinct from `Rejected`, a deliberate human decision, precisely so the two are never confused with each other: a rejected draft can still reappear if it changes further, a superseded one never can, since Lexware's own documented status transitions never move an invoice back to `draft`.

- [ ] **Step 2: Write the tracked invoice entity**

`Entity/TrackedInvoice.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;

#[ORM\Entity(repositoryClass: TrackedInvoiceRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_invoice')]
class TrackedInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_id', type: 'string', length: 100, unique: true)]
    private string $lexwareId;

    #[ORM\Column(name: 'voucher_number', type: 'string', length: 100)]
    private string $voucherNumber = '';

    #[ORM\Column(name: 'voucher_date', type: 'datetime_immutable')]
    private \DateTimeImmutable $voucherDate;

    #[ORM\Column(name: 'raw_payload', type: 'text')]
    private string $rawPayload = '{}';

    #[ORM\ManyToOne(targetEntity: TrackedOrderConfirmation::class)]
    #[ORM\JoinColumn(name: 'related_order_confirmation_id', nullable: false)]
    private TrackedOrderConfirmation $relatedOrderConfirmation;

    #[ORM\Column(name: 'status', type: 'string', length: 30, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status;

    #[ORM\Column(name: 'creation_attempted_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $creationAttemptedAt = null;

    #[ORM\Column(name: 'created_invoice_lexware_id', type: 'string', length: 100, nullable: true)]
    private ?string $createdInvoiceLexwareId = null;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_synchronized_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSynchronizedAt;

    #[ORM\Column(name: 'remote_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remoteUpdatedAt = null;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'processed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $processedBy = null;

    public function __construct(string $lexwareId, TrackedOrderConfirmation $relatedOrderConfirmation)
    {
        $this->lexwareId = $lexwareId;
        $this->relatedOrderConfirmation = $relatedOrderConfirmation;
        $this->status = InvoiceStatus::Pending;
        $this->voucherDate = new \DateTimeImmutable();
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSynchronizedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLexwareId(): string
    {
        return $this->lexwareId;
    }

    public function getVoucherNumber(): string
    {
        return $this->voucherNumber;
    }

    public function getVoucherDate(): \DateTimeImmutable
    {
        return $this->voucherDate;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getRelatedOrderConfirmation(): TrackedOrderConfirmation
    {
        return $this->relatedOrderConfirmation;
    }

    public function getStatus(): InvoiceStatus
    {
        return $this->status;
    }

    public function getCreationAttemptedAt(): ?\DateTimeImmutable
    {
        return $this->creationAttemptedAt;
    }

    public function getCreatedInvoiceLexwareId(): ?string
    {
        return $this->createdInvoiceLexwareId;
    }

    public function getRemoteUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->remoteUpdatedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    public function updateFromLexwarePayload(
        string $voucherNumber,
        \DateTimeImmutable $voucherDate,
        string $rawPayload,
        ?\DateTimeImmutable $remoteUpdatedAt
    ): void {
        $this->voucherNumber = $voucherNumber;
        $this->voucherDate = $voucherDate;
        $this->rawPayload = $rawPayload;
        $this->lastSynchronizedAt = new \DateTimeImmutable();
        $this->remoteUpdatedAt = $remoteUpdatedAt;
    }

    public function markCreationAttempted(): void
    {
        $this->creationAttemptedAt = new \DateTimeImmutable();
    }

    public function clearCreationAttempt(): void
    {
        $this->creationAttemptedAt = null;
    }

    public function markConverted(string $createdInvoiceLexwareId, User $processedBy): void
    {
        $this->status = InvoiceStatus::Converted;
        $this->createdInvoiceLexwareId = $createdInvoiceLexwareId;
        $this->creationAttemptedAt = null;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }

    public function markRejected(User $processedBy): void
    {
        $this->status = InvoiceStatus::Rejected;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }

    public function markSuperseded(): void
    {
        $this->status = InvoiceStatus::Superseded;
    }

    public function reopen(): void
    {
        $this->status = InvoiceStatus::Pending;
    }
}
```

- [ ] **Step 3: Write the tracked invoice timesheet entity**

`Entity/TrackedInvoiceTimesheet.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Timesheet;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;

#[ORM\Entity(repositoryClass: TrackedInvoiceTimesheetRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_invoice_timesheet')]
final class TrackedInvoiceTimesheet
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackedInvoice::class)]
    #[ORM\JoinColumn(name: 'tracked_invoice_id', nullable: false, onDelete: 'CASCADE')]
    private TrackedInvoice $trackedInvoice;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(name: 'timesheet_id', nullable: true, onDelete: 'SET NULL')]
    private ?Timesheet $timesheet;

    #[ORM\Column(name: 'timesheet_modified_at_snapshot', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $timesheetModifiedAtSnapshot;

    public function __construct(TrackedInvoice $trackedInvoice, ?Timesheet $timesheet, ?\DateTimeImmutable $timesheetModifiedAtSnapshot)
    {
        $this->trackedInvoice = $trackedInvoice;
        $this->timesheet = $timesheet;
        $this->timesheetModifiedAtSnapshot = $timesheetModifiedAtSnapshot;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTrackedInvoice(): TrackedInvoice
    {
        return $this->trackedInvoice;
    }

    public function getTimesheet(): ?Timesheet
    {
        return $this->timesheet;
    }

    public function wasModifiedAfterExport(): bool
    {
        if ($this->timesheet === null || $this->timesheetModifiedAtSnapshot === null) {
            return false;
        }

        $modifiedAt = $this->timesheet->getModifiedAt();

        return $modifiedAt !== null && $modifiedAt > $this->timesheetModifiedAtSnapshot;
    }
}
```

The snapshot exists because Kimai's own `modifiedAt` on `Timesheet` is only bumped by an entity level save, not by the bulk `UPDATE` statement `TimesheetRepository::setExported()` issues in Task 9. Recording what `getModifiedAt()` looked like at the moment of export is the only way to later tell whether the timesheet changed since then.

- [ ] **Step 4: Write the repositories**

`Repository/TrackedInvoiceRepository.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;

/**
 * @extends ServiceEntityRepository<TrackedInvoice>
 */
final class TrackedInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedInvoice::class);
    }

    public function findByLexwareId(string $lexwareId): ?TrackedInvoice
    {
        return $this->findOneBy(['lexwareId' => $lexwareId]);
    }

    /**
     * @return TrackedInvoice[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => InvoiceStatus::Pending], ['voucherDate' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => InvoiceStatus::Pending]);
    }

    /**
     * @return TrackedInvoice[]
     */
    public function findRecentlyConverted(int $limit = 20): array
    {
        return $this->findBy(['status' => InvoiceStatus::Converted], ['processedAt' => 'DESC'], $limit);
    }

    public function save(TrackedInvoice $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
```

`Repository/TrackedInvoiceTimesheetRepository.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoiceTimesheet;

/**
 * @extends ServiceEntityRepository<TrackedInvoiceTimesheet>
 */
final class TrackedInvoiceTimesheetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedInvoiceTimesheet::class);
    }

    public function save(TrackedInvoiceTimesheet $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }

    public function hasModifiedTimesheets(TrackedInvoice $trackedInvoice): bool
    {
        foreach ($this->findBy(['trackedInvoice' => $trackedInvoice]) as $link) {
            if ($link->wasModifiedAfterExport()) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Write the migration**

`Migrations/Version20260905120000.php`. This mirrors milestone one's `Version20260904120000.php` exactly in namespace and structure; `App\Doctrine\AbstractMigration` and the `KimaiLexwareSyncBundle\Migrations` namespace, without the `KimaiPlugin\` prefix, are what Kimai's migration loader already expects for this plugin, confirmed by the existing file.

```php
<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260905120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the KimaiLexwareSync milestone two invoice tracking tables';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_ext_lexware_invoice')) {
            $table = $schema->createTable('kimai2_ext_lexware_invoice');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_number', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_date', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('raw_payload', 'text', ['notnull' => true]);
            $table->addColumn('related_order_confirmation_id', 'integer', ['notnull' => true]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 30]);
            $table->addColumn('creation_attempted_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('created_invoice_lexware_id', 'string', ['notnull' => false, 'length' => 100]);
            $table->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('last_synchronized_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('remote_updated_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_by_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_order_confirmation', ['related_order_confirmation_id'], ['id']);
            $table->addForeignKeyConstraint('kimai2_users', ['processed_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_invoice_timesheet')) {
            $table = $schema->createTable('kimai2_ext_lexware_invoice_timesheet');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('tracked_invoice_id', 'integer', ['notnull' => true]);
            $table->addColumn('timesheet_id', 'integer', ['notnull' => false]);
            $table->addColumn('timesheet_modified_at_snapshot', 'datetime_immutable', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_invoice', ['tracked_invoice_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_timesheet', ['timesheet_id'], ['id'], ['onDelete' => 'SET NULL']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['kimai2_ext_lexware_invoice_timesheet', 'kimai2_ext_lexware_invoice'] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }
}
```

- [ ] **Step 6: Manual verification**

```bash
bin/console kimai:reload -n
bin/console doctrine:migrations:migrate --no-interaction
```

Confirm both `kimai2_ext_lexware_invoice` and `kimai2_ext_lexware_invoice_timesheet` exist with `DESCRIBE kimai2_ext_lexware_invoice;` and `DESCRIBE kimai2_ext_lexware_invoice_timesheet;` against the development database, and that the two foreign keys on the second table are nullable as declared.

- [ ] **Step 7: Commit**

```bash
git add Enum/InvoiceStatus.php Entity/TrackedInvoice.php Entity/TrackedInvoiceTimesheet.php Repository/TrackedInvoiceRepository.php Repository/TrackedInvoiceTimesheetRepository.php Migrations/Version20260905120000.php
git commit -m "Add the tracked invoice data model for milestone two"
```

---

## Task 3: Configuration keys for milestone two

**Files:**
- Modify: `Configuration/LexwareSyncConfiguration.php`
- Modify: `EventSubscriber/SystemConfigurationSubscriber.php`
- Modify: `Resources/translations/system-configuration.en.xlf`
- Modify: `Resources/translations/system-configuration.de.xlf`

**Interfaces:**
- Produces: `LexwareSyncConfiguration::getInvoiceTitleRegex(): string`, `LexwareSyncConfiguration::getProjectCompletionMode(): string`, and the two class constants `LexwareSyncConfiguration::PROJECT_COMPLETION_END_DATE` and `LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN`. Task 6 (`InvoiceSynchronizer`) uses the first; Task 9 (`InvoiceProcessor`) uses the second and both constants.

- [ ] **Step 1: Extend the configuration reader**

In `Configuration/LexwareSyncConfiguration.php`, add the two constants right after the class declaration line, and the two new methods at the end of the class, right before its closing brace:

```php
final class LexwareSyncConfiguration
{
    public const PROJECT_COMPLETION_END_DATE = 'end_date';
    public const PROJECT_COMPLETION_HIDDEN = 'hidden';

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }
```

```php
    public function getInvoiceTitleRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.invoice_title_regex');

        return \is_string($value) ? $value : '';
    }

    public function getProjectCompletionMode(): string
    {
        $value = $this->configuration->find('lexware_sync.project_completion_mode');

        return $value === self::PROJECT_COMPLETION_HIDDEN ? self::PROJECT_COMPLETION_HIDDEN : self::PROJECT_COMPLETION_END_DATE;
    }
}
```

- [ ] **Step 2: Register the two new fields on the system configuration screen**

In `EventSubscriber/SystemConfigurationSubscriber.php`, add the import:

```php
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
```

and append two entries to the array passed to `setConfiguration()`, right after the existing `lexware_sync.reconcile_interval_minutes` entry:

```php
                    (new Configuration('lexware_sync.reconcile_interval_minutes'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.invoice_title_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.project_completion_mode'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(ChoiceType::class)
                        ->setRequired(false)
                        ->setOptions([
                            'choices' => [
                                'End date' => LexwareSyncConfiguration::PROJECT_COMPLETION_END_DATE,
                                'Hidden' => LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN,
                            ],
                        ]),
```

- [ ] **Step 3: Add the translations**

In `Resources/translations/system-configuration.en.xlf`, add before the closing `</body>`:

```xml
            <trans-unit id="lexware_sync.invoice_title_regex" resname="lexware_sync.invoice_title_regex">
                <source>lexware_sync.invoice_title_regex</source>
                <target>Title pattern an invoice draft must match to be tracked, empty matches every title</target>
            </trans-unit>
            <trans-unit id="lexware_sync.project_completion_mode" resname="lexware_sync.project_completion_mode">
                <source>lexware_sync.project_completion_mode</source>
                <target>What marking a project completed does: set an end date, or hide its visibility</target>
            </trans-unit>
```

In `Resources/translations/system-configuration.de.xlf`, add before the closing `</body>`:

```xml
            <trans-unit id="lexware_sync.invoice_title_regex" resname="lexware_sync.invoice_title_regex">
                <source>lexware_sync.invoice_title_regex</source>
                <target>Titelmuster, das ein Rechnungsentwurf erfüllen muss, um erfasst zu werden, leer bedeutet jeder Titel passt</target>
            </trans-unit>
            <trans-unit id="lexware_sync.project_completion_mode" resname="lexware_sync.project_completion_mode">
                <source>lexware_sync.project_completion_mode</source>
                <target>Was das Markieren eines Projekts als abgeschlossen bewirkt: ein Enddatum setzen oder die Sichtbarkeit verstecken</target>
            </trans-unit>
```

- [ ] **Step 4: Manual verification**

```bash
bin/console lint:xliff Resources/translations/system-configuration.en.xlf Resources/translations/system-configuration.de.xlf
bin/console kimai:reload -n
```

Open Kimai's system configuration screen and confirm both new fields appear under the existing Lexware sync section, the regular expression field rejects an invalid pattern the same way `title_regex` already does, and the choice field offers exactly the two options.

- [ ] **Step 5: Commit**

```bash
git add Configuration/LexwareSyncConfiguration.php EventSubscriber/SystemConfigurationSubscriber.php Resources/translations/system-configuration.en.xlf Resources/translations/system-configuration.de.xlf
git commit -m "Add the milestone two configuration keys for the invoice title filter and project completion mode"
```

---

## Task 4: Extend the Lexware API client for invoices

**Files:**
- Modify: `Service/LexwareApiClient.php`
- Create: `Service/AmbiguousLexwareRequestException.php`
- Modify: `Tests/Service/LexwareApiClientTest.php`

**Interfaces:**
- Produces: `LexwareApiClient::getInvoice(string $lexwareId): array`, `LexwareApiClient::listInvoiceVoucherPage(int $page): array`, `LexwareApiClient::createInvoice(array $payload, bool $finalize): array`, `LexwareApiClient::findInvoices(string $contactId, \DateTimeImmutable $voucherDateFrom): array`. `AmbiguousLexwareRequestException extends LexwareApiException`, thrown instead of the plain exception whenever the underlying HTTP call could not be confirmed to have reached Lexware at all, as opposed to reaching it and getting a clear error response. Task 6, Task 7 and Task 9 consume these four methods; Task 9 is the one that distinguishes the two exception types.

This task originally carried two open items this plan could not resolve by reading documentation alone. Both were settled by a live spike against the sandbox account on 2026-09-05, run by the controller directly once the already-present `LEXWARE_API_KEY` in this plugin's own `.env` was found (an earlier belief that no key was available in this sandbox was a controller error, corrected during Task 4's review):

- A newly created invoice cannot carry an explicit `relatedVouchers` entry: sending one in the `POST /v1/invoices` body is silently ignored, confirmed by creating a real test invoice with `relatedVouchers` set and reading it back with an empty array. `createInvoice()` below never attempts to send one.
- `GET /v1/invoices?...` as a bare list endpoint does not exist at all (confirmed 404). Filtering by contact and date instead goes through `GET /v1/voucherlist?voucherType=invoice&voucherStatus=draft&contactId=...&voucherDateFrom=...`, confirmed to work and to return the same `content` envelope shape as everywhere else `voucherlist` is used in this plugin. `findInvoices()` below uses this corrected shape.
- As a byproduct of the same spike: a real invoice pursued from a tracked order confirmation does carry a correct `relatedVouchers` entry pointing at it (confirmed with a fresh, live pursue action during the same spike, after an earlier, differently-created invoice draft in the sandbox showed an empty array and briefly cast doubt on the whole mechanism), and creating an invoice through the API requires a `shippingConditions` object that milestone one's order confirmations never needed; Task 9 was corrected to send one, see its own text.

- [ ] **Step 1: Write the failing tests**

Add to `Tests/Service/LexwareApiClientTest.php`, inside the existing `LexwareApiClientTest` class, right before its closing brace. Add the two new imports at the top of the file first:

```php
use Symfony\Component\HttpClient\Exception\TransportException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\AmbiguousLexwareRequestException;
```

```php
    public function testCreateInvoiceSendsFinalizeQueryParameterAndJsonBody(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"new-invoice-id"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->createInvoice(['voucherDate' => '2026-09-05'], true);

        self::assertSame('new-invoice-id', $result['id']);
        self::assertSame('POST', $seenRequest[0]);
        self::assertStringContainsString('/v1/invoices', $seenRequest[1]);
        self::assertStringContainsString('finalize=true', $seenRequest[1]);
        self::assertSame(['voucherDate' => '2026-09-05'], $seenRequest[2]['json']);
    }

    public function testAmbiguousTransportFailureThrowsDistinctException(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new TransportException('Connection timed out');
        });

        $client = new LexwareApiClient($httpClient, 'test-key');

        $this->expectException(AmbiguousLexwareRequestException::class);
        $client->createInvoice(['voucherDate' => '2026-09-05'], false);
    }

    public function testGetInvoiceSendsBearerTokenAndDecodesResponse(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"id":"abc","voucherStatus":"draft"}', ['http_code' => 200]));

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->getInvoice('abc');

        self::assertSame('draft', $result['voucherStatus']);
    }
```

- [ ] **Step 2: Run the tests to confirm they fail**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/LexwareApiClientTest.php
```

Expected: FAIL, `Call to undefined method KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient::createInvoice()`.

- [ ] **Step 3: Manual spike against the sandbox account — already completed by the controller on 2026-09-05, findings below**

This step already ran, directly by the controller against the real sandbox account, once it was discovered that `LEXWARE_API_KEY` was in fact present in this plugin's own `.env` (the belief that it was missing was a controller search error, corrected during this task's review). If you are implementing this task fresh and the credential is genuinely unavailable, treat these findings as trustworthy and skip re-running the spike; if you have reason to distrust them, re-run the three checks below before proceeding.

1. `POST https://api.lexware.io/v1/invoices` with a minimal body needs more than the fields milestone one's order confirmations ever needed: a `taxConditions` object and a `shippingConditions` object are both mandatory (confirmed by a `406 Not Acceptable` "shipping conditions must not be null" response when it was omitted), and the target contact needs at least one billing address on file (confirmed by a second `406` when the sandbox contact had none; a minimal `addresses.billing` entry was added to the sandbox contact by `PUT /v1/contacts/{id}` to unblock the spike). A `relatedVouchers` entry included in the request body is silently dropped: a real created invoice was read back afterward with `relatedVouchers: []` despite one having been sent. `createInvoice()` below sends no `relatedVouchers`, matching this.
2. `GET https://api.lexware.io/v1/invoices?contactId={id}` does not exist as a list endpoint at all (`404 Not Found`), the same way milestone one already found no bare list endpoint for order confirmations. `GET /v1/voucherlist?voucherType=invoice&voucherStatus=draft&contactId={id}&voucherDateFrom={date}` does work, confirmed to return the same `content` envelope shape used everywhere else in this plugin, and confirmed to actually filter by the given contact. `findInvoices()` below uses this endpoint instead.
3. As a byproduct: fetching an existing sandbox invoice draft that predated this spike showed an empty `relatedVouchers`, briefly casting doubt on the whole correlation mechanism Task 6 depends on. A fresh, live "pursue" action performed on the sandbox order confirmation during this same spike produced a new draft whose `relatedVouchers` correctly contained an entry with the order confirmation's own Lexware id and `voucherType: "orderconfirmation"`, confirming the mechanism works for a genuine pursue and that the earlier empty one was an unrelated anomaly, not a sign the design is wrong.
4. The test invoice created in check 1 was left in the sandbox account as a real draft, since Lexware has no deletion or void endpoint; it needs deleting by hand in the Lexware web interface, exactly as the finished screen in Task 10 will later ask a person to do for every superseded draft.

Record what was actually observed directly in this file before moving on, the same way milestone one's webhook signature spike was written up before the verifier code was trusted.

- [ ] **Step 4: Write the implementation**

Replace the whole contents of `Service/LexwareApiClient.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareApiClient
{
    private const BASE_URL = 'https://api.lexware.io';
    private const MINIMUM_INTERVAL_SECONDS = 0.5;
    private const ORDER_CONFIRMATION_STATUSES = 'draft,open,accepted,rejected,voided';
    private const INVOICE_STATUSES = 'draft,open,paidoff,voided';

    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderConfirmation(string $lexwareId): array
    {
        return $this->request('GET', '/v1/order-confirmations/' . $lexwareId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listOrderConfirmationVoucherPage(int $page): array
    {
        return $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'orderconfirmation',
            'voucherStatus' => self::ORDER_CONFIRMATION_STATUSES,
            'page' => $page,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(string $lexwareId): array
    {
        return $this->request('GET', '/v1/invoices/' . $lexwareId);
    }

    /**
     * @return array<string, mixed>
     */
    public function listInvoiceVoucherPage(int $page): array
    {
        return $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'invoice',
            'voucherStatus' => self::INVOICE_STATUSES,
            'page' => $page,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload, bool $finalize): array
    {
        return $this->request('POST', '/v1/invoices', $finalize ? ['finalize' => 'true'] : [], $payload);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findInvoices(string $contactId, \DateTimeImmutable $voucherDateFrom): array
    {
        $result = $this->request('GET', '/v1/voucherlist', [
            'voucherType' => 'invoice',
            'voucherStatus' => 'draft',
            'contactId' => $contactId,
            'voucherDateFrom' => $voucherDateFrom->format('Y-m-d'),
        ]);

        $content = $result['content'] ?? [];

        return \is_array($content) ? $content : [];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $jsonBody
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null): array
    {
        $this->pace();

        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
            'query' => $query,
        ];

        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, $options);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (TransportExceptionInterface $exception) {
            throw new AmbiguousLexwareRequestException(\sprintf('Request to %s could not be confirmed: %s', $path, $exception->getMessage()), 0, $exception);
        } catch (ExceptionInterface $exception) {
            throw new LexwareApiException(\sprintf('Request to %s failed: %s', $path, $exception->getMessage()), 0, $exception);
        }

        if ($statusCode >= 400) {
            throw new LexwareApiException(\sprintf('Lexware API returned status %d for %s: %s', $statusCode, $path, $content));
        }

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        if (!\is_array($decoded)) {
            throw new LexwareApiException(\sprintf('Lexware API returned a non-object response for %s', $path));
        }

        return $decoded;
    }

    private function pace(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestAt;

        if ($this->lastRequestAt > 0.0 && $elapsed < self::MINIMUM_INTERVAL_SECONDS) {
            usleep((int) ((self::MINIMUM_INTERVAL_SECONDS - $elapsed) * 1_000_000));
        }

        $this->lastRequestAt = microtime(true);
    }
}
```

`Service/AmbiguousLexwareRequestException.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class AmbiguousLexwareRequestException extends LexwareApiException
{
}
```

`TransportExceptionInterface` extends the client's existing `ExceptionInterface`, so it must be caught first; a genuine timeout or dropped connection throws the more specific `AmbiguousLexwareRequestException`, while a clear HTTP status code error, meaning Lexware definitely received and answered the request, keeps throwing the plain `LexwareApiException` exactly as before.

- [ ] **Step 5: Run the tests to confirm they pass**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/LexwareApiClientTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add Service/LexwareApiClient.php Service/AmbiguousLexwareRequestException.php Tests/Service/LexwareApiClientTest.php
git commit -m "Extend the Lexware API client with invoice endpoints and ambiguous failure detection"
```

---

## Task 5: Invoice line builder

**Files:**
- Create: `Enum/InvoiceLineShape.php`
- Create: `Service/InvoiceLineBuilder.php`
- Create: `Tests/Service/InvoiceLineBuilderTest.php`

**Interfaces:**
- Consumes: `App\Entity\Timesheet`, `App\Entity\Activity`.
- Produces: `InvoiceLineBuilder::buildLines(array $timesheets, InvoiceLineShape $shape, int $taxRatePercentage, string $currency): array`, returning Lexware `lineItems` shaped arrays (`type`, `name`, optionally `description`, `quantity`, `unitName`, `unitPrice` with `currency`, `netAmount`, `taxRatePercentage`). Task 9 (`InvoiceProcessor`) is the only consumer, merging this method's return value with the original draft's own lines.

This is pure PHP logic with no Doctrine or kernel dependency, real `Timesheet` and `Activity` entities can be constructed directly in a test without a database, exactly like milestone one's other plain PHPUnit tests.

- [ ] **Step 1: Write the enum**

`Enum/InvoiceLineShape.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum InvoiceLineShape: string
{
    case PerTimesheet = 'per_timesheet';
    case AggregatedByActivity = 'aggregated_by_activity';
}
```

- [ ] **Step 2: Write the failing tests**

`Tests/Service/InvoiceLineBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use App\Entity\Activity;
use App\Entity\Timesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceLineBuilder;
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

    public function testPerTimesheetProducesOneLineEach(): void
    {
        $builder = new InvoiceLineBuilder();
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Entwicklung', 7200, 80.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::PerTimesheet, 19, 'EUR');

        self::assertCount(2, $lines);
        self::assertSame('Beratung', $lines[0]['name']);
        self::assertSame(1.0, $lines[0]['quantity']);
        self::assertSame(100.0, $lines[0]['unitPrice']['netAmount']);
        self::assertSame(19, $lines[0]['unitPrice']['taxRatePercentage']);
        self::assertSame('EUR', $lines[0]['unitPrice']['currency']);
        self::assertSame(2.0, $lines[1]['quantity']);
    }

    public function testAggregatedByActivityCombinesSameActivityIntoAWeightedRate(): void
    {
        $builder = new InvoiceLineBuilder();
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Beratung', 3600, 120.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::AggregatedByActivity, 19, 'EUR');

        self::assertCount(1, $lines);
        self::assertSame('Beratung', $lines[0]['name']);
        self::assertSame(2.0, $lines[0]['quantity']);
        self::assertSame(110.0, $lines[0]['unitPrice']['netAmount']);
    }

    public function testAggregatedByActivityKeepsDifferentActivitiesSeparate(): void
    {
        $builder = new InvoiceLineBuilder();
        $timesheets = [
            $this->createTimesheet('Beratung', 3600, 100.0),
            $this->createTimesheet('Entwicklung', 3600, 80.0),
        ];

        $lines = $builder->buildLines($timesheets, InvoiceLineShape::AggregatedByActivity, 19, 'EUR');

        self::assertCount(2, $lines);
    }
}
```

- [ ] **Step 3: Run the tests to confirm they fail**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/InvoiceLineBuilderTest.php
```

Expected: FAIL, `Class "KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceLineBuilder" not found`.

- [ ] **Step 4: Write the implementation**

`Service/InvoiceLineBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Timesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;

final class InvoiceLineBuilder
{
    private const UNIT_NAME = 'Stunden';

    /**
     * @param Timesheet[] $timesheets
     * @return array<int, array<string, mixed>>
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
     * @return array<int, array<string, mixed>>
     */
    private function buildPerTimesheetLines(array $timesheets, int $taxRatePercentage, string $currency): array
    {
        $lines = [];

        foreach ($timesheets as $timesheet) {
            $activity = $timesheet->getActivity();

            $lines[] = $this->buildLine(
                $activity !== null ? (string) $activity->getName() : '',
                $this->descriptionFor($timesheet),
                $this->hoursFor($timesheet),
                (float) ($timesheet->getHourlyRate() ?? 0.0),
                $taxRatePercentage,
                $currency,
            );
        }

        return $lines;
    }

    /**
     * @param Timesheet[] $timesheets
     * @return array<int, array<string, mixed>>
     */
    private function buildAggregatedLines(array $timesheets, int $taxRatePercentage, string $currency): array
    {
        $groups = [];

        foreach ($timesheets as $timesheet) {
            $activity = $timesheet->getActivity();
            $activityId = $activity?->getId() ?? 0;

            if (!isset($groups[$activityId])) {
                $groups[$activityId] = [
                    'name' => $activity !== null ? (string) $activity->getName() : '',
                    'hours' => 0.0,
                    'amount' => 0.0,
                ];
            }

            $hours = $this->hoursFor($timesheet);
            $groups[$activityId]['hours'] += $hours;
            $groups[$activityId]['amount'] += $hours * (float) ($timesheet->getHourlyRate() ?? 0.0);
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

    private function hoursFor(Timesheet $timesheet): float
    {
        return ($timesheet->getDuration() ?? 0) / 3600;
    }

    private function descriptionFor(Timesheet $timesheet): ?string
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
```

The aggregated shape uses a duration weighted average rate, `sum(hours * rate) / sum(hours)`, rather than requiring every timesheet in a group to share one rate, since Kimai lets a rate change over time on the same activity and this keeps the aggregated line's total equal to what the underlying timesheets are actually worth.

- [ ] **Step 5: Run the tests to confirm they pass**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/InvoiceLineBuilderTest.php
```

Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add Enum/InvoiceLineShape.php Service/InvoiceLineBuilder.php Tests/Service/InvoiceLineBuilderTest.php
git commit -m "Add the invoice line builder for per timesheet and aggregated line shapes"
```

---

## Task 6: Invoice synchronizer

**Files:**
- Create: `Service/InvoiceSynchronizer.php`
- Create: `Tests/Service/InvoiceSynchronizerRelationTest.php`

**Interfaces:**
- Consumes: `LexwareApiClient::getInvoice()` from Task 4; `TrackedInvoiceRepository`, `TrackedInvoice`, `InvoiceStatus` from Task 2; `TrackedOrderConfirmationRepository::findByLexwareId()` from milestone one; `MatchingRuleEvaluator::matchesTitle()` from milestone one; `LexwareSyncConfiguration::getInvoiceTitleRegex()` from Task 3.
- Produces: `InvoiceSynchronizer` with `synchronize(string $lexwareId): void`. Task 7 (the reconciliation command) and Task 8 (the webhook controller) call exactly this one method and nothing else on this class.

- [ ] **Step 1: Write the failing test for the relatedVouchers parsing**

The `relatedVouchers` parsing is the one piece of this class complex enough to deserve its own isolated test, using a stub in place of the real Doctrine dependent collaborators. `Tests/Service/InvoiceSynchronizerRelationTest.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class InvoiceSynchronizerRelationTest extends TestCase
{
    public function testFirstMatchingRelatedVoucherWins(): void
    {
        $method = new ReflectionMethod(InvoiceSynchronizer::class, 'extractRelatedVoucherIds');
        $method->setAccessible(true);

        $payload = [
            'relatedVouchers' => [
                ['id' => 'unrelated-id'],
                ['id' => 'matching-id'],
            ],
        ];

        self::assertSame(['unrelated-id', 'matching-id'], $method->invoke(null, $payload));
    }

    public function testMissingRelatedVouchersProducesAnEmptyList(): void
    {
        $method = new ReflectionMethod(InvoiceSynchronizer::class, 'extractRelatedVoucherIds');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke(null, []));
    }
}
```

`extractRelatedVoucherIds` is written as a private static method precisely so this parsing, the part most likely to need correcting once Task 4's spike or a real pursued draft shows the actual field shape, is isolated and testable without a database.

- [ ] **Step 2: Run the test to confirm it fails**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/InvoiceSynchronizerRelationTest.php
```

Expected: FAIL, `Class "KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer" not found`.

- [ ] **Step 3: Write the implementation**

`Service/InvoiceSynchronizer.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;

final class InvoiceSynchronizer
{
    private const DRAFT_STATUS = 'draft';

    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedInvoiceRepository $repository,
        private readonly TrackedOrderConfirmationRepository $orderConfirmationRepository,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    public function synchronize(string $lexwareId): void
    {
        $payload = $this->client->getInvoice($lexwareId);
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing !== null && $existing->getStatus()->isTerminal()) {
            return;
        }

        $remoteUpdatedAt = isset($payload['updatedDate']) ? new \DateTimeImmutable((string) $payload['updatedDate']) : null;

        if ($existing !== null && $remoteUpdatedAt !== null && $existing->getRemoteUpdatedAt() !== null) {
            if ($remoteUpdatedAt <= $existing->getRemoteUpdatedAt()) {
                return;
            }
        }

        $voucherStatus = (string) ($payload['voucherStatus'] ?? '');

        if ($voucherStatus !== self::DRAFT_STATUS) {
            if ($existing !== null) {
                $existing->markSuperseded();
                $this->repository->save($existing);
            }

            return;
        }

        $relatedOrderConfirmation = $this->resolveRelatedOrderConfirmation($payload);
        if ($relatedOrderConfirmation === null || $relatedOrderConfirmation->getProject() === null) {
            return;
        }

        $title = (string) ($payload['title'] ?? '');
        if (!$this->matchingRuleEvaluator->matchesTitle($title, $this->configuration->getInvoiceTitleRegex())) {
            return;
        }

        $trackedInvoice = $existing ?? new TrackedInvoice($lexwareId, $relatedOrderConfirmation);

        if ($trackedInvoice->getStatus() === InvoiceStatus::Rejected) {
            $trackedInvoice->reopen();
        }

        $trackedInvoice->updateFromLexwarePayload(
            (string) ($payload['voucherNumber'] ?? ''),
            new \DateTimeImmutable((string) ($payload['voucherDate'] ?? 'now')),
            json_encode($payload, \JSON_THROW_ON_ERROR),
            $remoteUpdatedAt,
        );

        $this->repository->save($trackedInvoice);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRelatedOrderConfirmation(array $payload): ?TrackedOrderConfirmation
    {
        foreach (self::extractRelatedVoucherIds($payload) as $relatedId) {
            $orderConfirmation = $this->orderConfirmationRepository->findByLexwareId($relatedId);
            if ($orderConfirmation !== null) {
                return $orderConfirmation;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return string[]
     */
    private static function extractRelatedVoucherIds(array $payload): array
    {
        $relatedVouchers = $payload['relatedVouchers'] ?? [];
        if (!\is_array($relatedVouchers)) {
            return [];
        }

        $ids = [];

        foreach ($relatedVouchers as $relatedVoucher) {
            if (!\is_array($relatedVoucher)) {
                continue;
            }

            $id = (string) ($relatedVoucher['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
```

An invoice draft only becomes a tracked, actionable record once its related order confirmation has itself already produced a Kimai project; there would be nothing to assign timesheets against otherwise. A draft pursued from an order confirmation still sitting in milestone one's own triage screen is therefore left untracked until that order confirmation is converted, at which point the next webhook delivery or reconciliation poll picks it up.

- [ ] **Step 4: Run the test to confirm it passes**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/InvoiceSynchronizerRelationTest.php
```

Expected: PASS, 2 tests.

- [ ] **Step 5: Manual verification**

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\Service\\InvoiceSynchronizer
```

Using a throwaway console command exactly like the one used to verify `OrderConfirmationSynchronizer` in milestone one, call `synchronize()` with the Lexware id of a real invoice draft pursued from the sandbox order confirmation, and confirm `SELECT * FROM kimai2_ext_lexware_invoice` shows one row with `status = 'pending'` and `related_order_confirmation_id` pointing at the right row. Then finalize that same draft directly in the Lexware web interface, run `synchronize()` again, and confirm the row's status becomes `superseded`.

- [ ] **Step 6: Commit**

```bash
git add Service/InvoiceSynchronizer.php Tests/Service/InvoiceSynchronizerRelationTest.php
git commit -m "Add the invoice synchronizer that tracks drafts linked to converted order confirmations"
```

---

## Task 7: Reconcile invoices command

**Files:**
- Create: `Command/ReconcileInvoicesCommand.php`

**Interfaces:**
- Consumes: `LexwareApiClient::listInvoiceVoucherPage()` from Task 4; `TrackedInvoiceRepository` from Task 2; `ContactMappingRepository::findByLexwareContactId()` from milestone one; `InvoiceSynchronizer::synchronize()` from Task 6.
- Produces: the console command `kimai:lexware-sync:reconcile-invoices`. Task 11 documents its cron entry.

Order confirmations are, in practice, few and all potentially relevant, so milestone one's reconciliation command refetches every one of them on every poll. A Lexware account's invoices vastly outnumber the ones that ever relate to a tracked order confirmation, so refetching every single one on every poll would waste API calls against the two request per second limit for no benefit. Since a voucherlist entry already carries the invoice's `contactId` at no extra cost, this command skips the full refetch for any untracked invoice whose contact is not already known through milestone one's contact mapping table. If `contactId` turns out to be missing from a real voucherlist entry, confirmed or refuted during Task 4's spike, the check below falls back to the old, safe behavior of refetching anyway rather than silently skipping invoices it should not.

- [ ] **Step 1: Write the implementation**

`Command/ReconcileInvoicesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:reconcile-invoices', description: 'Poll Lexware for invoice drafts that a webhook delivery might have missed')]
final class ReconcileInvoicesCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedInvoiceRepository $repository,
        private readonly ContactMappingRepository $contactMappingRepository,
        private readonly InvoiceSynchronizer $synchronizer,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = 0;
        $synchronized = 0;
        $failed = 0;
        $isLastPage = false;

        while (!$isLastPage) {
            $result = $this->client->listInvoiceVoucherPage($page);
            $content = $result['content'] ?? [];

            foreach ($content as $voucher) {
                $lexwareId = (string) ($voucher['id'] ?? '');
                if ($lexwareId === '') {
                    continue;
                }

                if (!$this->needsSynchronization($lexwareId, $voucher)) {
                    continue;
                }

                try {
                    $this->synchronizer->synchronize($lexwareId);
                    $synchronized++;
                } catch (\Throwable $exception) {
                    $message = \sprintf('Failed to synchronize invoice %s: %s', $lexwareId, $exception->getMessage());
                    $this->logger->error($message);
                    $io->error($message);
                    $failed++;
                }
            }

            $isLastPage = (bool) ($result['last'] ?? true);
            $page++;
        }

        $io->success(\sprintf('Reconciled %d invoice(s), %d failed.', $synchronized, $failed));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param array<string, mixed> $voucher
     */
    private function needsSynchronization(string $lexwareId, array $voucher): bool
    {
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing === null) {
            $contactId = (string) ($voucher['contactId'] ?? '');
            if ($contactId !== '' && $this->contactMappingRepository->findByLexwareContactId($contactId) === null) {
                return false;
            }

            return true;
        }

        if ($existing->getStatus()->isTerminal()) {
            return false;
        }

        $updatedDate = $voucher['updatedDate'] ?? null;
        if ($updatedDate === null) {
            return true;
        }

        $remoteUpdatedAt = $existing->getRemoteUpdatedAt();
        if ($remoteUpdatedAt === null) {
            return true;
        }

        return $remoteUpdatedAt < new \DateTimeImmutable((string) $updatedDate);
    }
}
```

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console kimai:lexware-sync:reconcile-invoices
```

Confirm the command finishes with exit code 0 and reports zero failures against the sandbox account, and that it correctly finds the invoice draft tracked by hand in Task 6's manual verification even after deleting its `kimai2_ext_lexware_invoice` row first, to confirm the reconciliation path alone can rediscover it.

- [ ] **Step 3: Commit**

```bash
git add Command/ReconcileInvoicesCommand.php
git commit -m "Add the invoice reconciliation command"
```

---

## Task 8: Webhook controller dispatch for invoice events

**Files:**
- Modify: `Controller/LexwareWebhookController.php`

**Interfaces:**
- Consumes: `InvoiceSynchronizer::synchronize()` from Task 6.
- Produces: the route `lexware_sync_webhook_invoice` at `POST /webhook/lexware/invoice`. Task 11 documents its Lexware event subscription entry.

- [ ] **Step 1: Write the implementation**

Replace the whole contents of `Controller/LexwareWebhookController.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use KimaiPlugin\KimaiLexwareSyncBundle\Entity\WebhookEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\WebhookEventRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceSynchronizer;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareWebhookVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationSynchronizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/webhook/lexware')]
final class LexwareWebhookController
{
    public function __construct(
        private readonly LexwareWebhookVerifier $verifier,
        private readonly WebhookEventRepository $webhookEventRepository,
        private readonly OrderConfirmationSynchronizer $orderConfirmationSynchronizer,
        private readonly InvoiceSynchronizer $invoiceSynchronizer,
    ) {
    }

    #[Route(path: '/order-confirmation', name: 'lexware_sync_webhook_order_confirmation', methods: ['POST'])]
    public function orderConfirmation(Request $request): Response
    {
        return $this->handle($request, function (string $resourceId): void {
            $this->orderConfirmationSynchronizer->synchronize($resourceId);
        });
    }

    #[Route(path: '/invoice', name: 'lexware_sync_webhook_invoice', methods: ['POST'])]
    public function invoice(Request $request): Response
    {
        return $this->handle($request, function (string $resourceId): void {
            $this->invoiceSynchronizer->synchronize($resourceId);
        });
    }

    private function handle(Request $request, callable $synchronize): Response
    {
        $rawBody = $request->getContent();
        $headers = $request->headers->all();
        $signatureValid = $this->verifier->verify($rawBody, $headers);

        $decoded = json_decode($rawBody, true);
        $eventType = \is_array($decoded) ? (string) ($decoded['eventType'] ?? 'unknown') : 'unknown';
        $resourceId = \is_array($decoded) ? ($decoded['resourceId'] ?? null) : null;

        $webhookEvent = new WebhookEvent($eventType, \is_string($resourceId) ? $resourceId : null, $signatureValid);
        $this->webhookEventRepository->save($webhookEvent);

        if (!$signatureValid) {
            return new JsonResponse(['message' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        if (!\is_string($resourceId) || $resourceId === '') {
            $webhookEvent->markFailed('Webhook payload carried no resource identifier');
            $this->webhookEventRepository->save($webhookEvent);

            return new JsonResponse(['message' => 'No resource identifier'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $synchronize($resourceId);
            $webhookEvent->markProcessed();
        } catch (\Throwable $exception) {
            $webhookEvent->markFailed($exception->getMessage());
        }

        $this->webhookEventRepository->save($webhookEvent);

        return new JsonResponse(['message' => 'Accepted']);
    }
}
```

The order confirmation path is unchanged in behavior, only refactored into the shared `handle()` method so the signature verification and audit trail bookkeeping is written once, not twice. `Resources/config/routes.yaml` already points at this whole controller file with `type: attribute`, so the new route needs no routing configuration change of its own.

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console debug:router | grep lexware_sync_webhook
```

Confirm both `lexware_sync_webhook_order_confirmation` and `lexware_sync_webhook_invoice` are listed. Re-run milestone one's existing webhook signature test suite to confirm the refactor did not change its behavior:

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php
```

Expected: PASS, all existing tests plus this plan's new ones so far, no regression.

- [ ] **Step 3: Commit**

```bash
git add Controller/LexwareWebhookController.php
git commit -m "Add the invoice webhook route and share the delivery handling with order confirmations"
```

---

## Task 9: Invoice processor

**Files:**
- Create: `Service/InvoiceProcessor.php`

**Interfaces:**
- Consumes: `TrackedInvoiceRepository`, `TrackedInvoiceTimesheetRepository` from Task 2; `InvoiceLineBuilder::buildLines()`, `InvoiceLineShape` from Task 5; `LexwareApiClient::createInvoice()`, `LexwareApiClient::findInvoices()`, `LexwareApiException`, `AmbiguousLexwareRequestException` from Task 4; `LexwareSyncConfiguration::getProjectCompletionMode()` and its two constants from Task 3; `App\Repository\TimesheetRepository::setExported(array $timesheets): void`; `App\Project\ProjectService::updateProject(Project $project): Project`; milestone one's `CustomerCurrencyMismatchException` and `Customer::DEFAULT_CURRENCY`.
- Produces: `InvoiceProcessor::convert(TrackedInvoice $trackedInvoice, array $timesheets, InvoiceLineShape $shape, bool $finalize, bool $markProjectCompleted, User $processedBy): void`, `InvoiceProcessor::confirmExisting(TrackedInvoice $trackedInvoice, string $existingLexwareInvoiceId, array $timesheets, bool $markProjectCompleted, User $processedBy): void`, `InvoiceProcessor::findPlausibleMatch(TrackedInvoice $trackedInvoice): ?array`. Both `convert()` and `confirmExisting()` can throw milestone one's existing `CustomerCurrencyMismatchException`, reused here without change. Task 10 (the assignment controller) is the only consumer of all three methods and the one that catches that exception.

Unlike every controller in milestone one, the controller in Task 10 must never wrap the call to `convert()` in its own `EntityManagerInterface::beginTransaction()`. The whole point of the creation-attempted timestamp, written and committed before the Lexware call, is that it survives independently of whatever happens next; wrapping it in an outer transaction would defeat that, since Doctrine's transaction nesting would only decrement a counter on the inner commit rather than actually persisting anything until the outer transaction closes. `InvoiceProcessor` manages its own transaction boundaries entirely, exactly like `OrderConfirmationSynchronizer` does in milestone one.

- [ ] **Step 1: Write the implementation**

`Service/InvoiceProcessor.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Entity\Customer;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Project\ProjectService;
use App\Repository\TimesheetRepository;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoiceTimesheet;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;

final class InvoiceProcessor
{
    public function __construct(
        private readonly TrackedInvoiceRepository $trackedInvoiceRepository,
        private readonly TrackedInvoiceTimesheetRepository $trackedInvoiceTimesheetRepository,
        private readonly TimesheetRepository $timesheetRepository,
        private readonly InvoiceLineBuilder $lineBuilder,
        private readonly LexwareApiClient $client,
        private readonly ProjectService $projectService,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param Timesheet[] $timesheets
     */
    public function convert(
        TrackedInvoice $trackedInvoice,
        array $timesheets,
        InvoiceLineShape $shape,
        bool $finalize,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->assertCurrencyMatches($trackedInvoice);

        $requestBody = $this->buildRequestBody($trackedInvoice, $timesheets, $shape);

        $trackedInvoice->markCreationAttempted();
        $this->trackedInvoiceRepository->save($trackedInvoice);

        try {
            $response = $this->client->createInvoice($requestBody, $finalize);
        } catch (AmbiguousLexwareRequestException $exception) {
            throw $exception;
        } catch (LexwareApiException $exception) {
            $trackedInvoice->clearCreationAttempt();
            $this->trackedInvoiceRepository->save($trackedInvoice);

            throw $exception;
        }

        $this->recordConversion($trackedInvoice, (string) ($response['id'] ?? ''), $timesheets, $markProjectCompleted, $processedBy);
    }

    /**
     * @param Timesheet[] $timesheets
     */
    public function confirmExisting(
        TrackedInvoice $trackedInvoice,
        string $existingLexwareInvoiceId,
        array $timesheets,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->assertCurrencyMatches($trackedInvoice);

        $this->recordConversion($trackedInvoice, $existingLexwareInvoiceId, $timesheets, $markProjectCompleted, $processedBy);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPlausibleMatch(TrackedInvoice $trackedInvoice): ?array
    {
        $creationAttemptedAt = $trackedInvoice->getCreationAttemptedAt();
        if ($creationAttemptedAt === null) {
            return null;
        }

        $payload = $this->decodePayload($trackedInvoice);
        $address = $payload['address'] ?? [];
        $contactId = \is_array($address) ? (string) ($address['contactId'] ?? '') : '';

        if ($contactId === '') {
            return null;
        }

        foreach ($this->client->findInvoices($contactId, $creationAttemptedAt) as $candidate) {
            if (\is_array($candidate) && ($candidate['id'] ?? null) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param Timesheet[] $timesheets
     * @return array<string, mixed>
     */
    private function buildRequestBody(TrackedInvoice $trackedInvoice, array $timesheets, InvoiceLineShape $shape): array
    {
        $payload = $this->decodePayload($trackedInvoice);

        $originalLines = $payload['lineItems'] ?? [];
        $originalLines = \is_array($originalLines) ? $originalLines : [];

        $firstLine = \is_array($originalLines[0] ?? null) ? $originalLines[0] : [];
        $unitPrice = \is_array($firstLine['unitPrice'] ?? null) ? $firstLine['unitPrice'] : [];
        $currency = (string) ($unitPrice['currency'] ?? 'EUR');
        $taxRatePercentage = (int) ($unitPrice['taxRatePercentage'] ?? 19);

        $newLines = $this->lineBuilder->buildLines($timesheets, $shape, $taxRatePercentage, $currency);

        return [
            'voucherDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'address' => $payload['address'] ?? [],
            'lineItems' => array_merge($originalLines, $newLines),
            'taxConditions' => $payload['taxConditions'] ?? ['taxType' => 'net'],
            'shippingConditions' => $payload['shippingConditions'] ?? [
                'shippingType' => 'service',
                'shippingDate' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            ],
            'totalPrice' => ['currency' => $currency],
        ];
    }

    /**
     * @param Timesheet[] $timesheets
     */
    private function recordConversion(
        TrackedInvoice $trackedInvoice,
        string $newInvoiceId,
        array $timesheets,
        bool $markProjectCompleted,
        User $processedBy,
    ): void {
        $this->entityManager->beginTransaction();

        try {
            $trackedInvoice->markConverted($newInvoiceId, $processedBy);
            $this->trackedInvoiceRepository->save($trackedInvoice);

            $timesheetIds = [];

            foreach ($timesheets as $timesheet) {
                $id = $timesheet->getId();
                if ($id !== null) {
                    $timesheetIds[] = $id;
                }

                $this->trackedInvoiceTimesheetRepository->save(
                    new TrackedInvoiceTimesheet($trackedInvoice, $timesheet, $timesheet->getModifiedAt()),
                );
            }

            if (\count($timesheetIds) > 0) {
                $this->timesheetRepository->setExported($timesheetIds);
            }

            if ($markProjectCompleted) {
                $this->completeProject($trackedInvoice);
            }

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }

    private function assertCurrencyMatches(TrackedInvoice $trackedInvoice): void
    {
        $customer = $trackedInvoice->getRelatedOrderConfirmation()->getCustomer();
        if ($customer !== null && $customer->getCurrency() !== Customer::DEFAULT_CURRENCY) {
            throw new CustomerCurrencyMismatchException(\sprintf(
                'Customer "%s" uses currency "%s" instead of "%s".',
                $customer->getName(),
                $customer->getCurrency(),
                Customer::DEFAULT_CURRENCY,
            ));
        }
    }

    private function completeProject(TrackedInvoice $trackedInvoice): void
    {
        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        if ($project === null) {
            return;
        }

        if ($this->configuration->getProjectCompletionMode() === LexwareSyncConfiguration::PROJECT_COMPLETION_HIDDEN) {
            $project->setVisible(false);
        } else {
            $project->setEnd(new \DateTime());
        }

        $this->projectService->updateProject($project);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(TrackedInvoice $trackedInvoice): array
    {
        $payload = json_decode($trackedInvoice->getRawPayload(), true);

        return \is_array($payload) ? $payload : [];
    }
}
```

`TimesheetRepository::setExported()` opens its own Doctrine transaction internally. Called from inside `recordConversion()`'s own outer transaction, Doctrine's connection only increments its nesting counter rather than issuing a second real `BEGIN`, so the bulk export still only takes effect if the outer transaction commits, exactly as intended.

`assertCurrencyMatches()` reuses the same check milestone one's `OrderConfirmationProcessor` performs when resolving a customer, since the tracked order confirmation's customer was already resolved back then; a currency mismatch here can only happen if a contact mapping was later pointed at an existing customer by hand, exactly the scenario the spec's error handling section calls out.

`shippingConditions` is required by Lexware for every invoice, confirmed during Task 4's live spike by a `406 Not Acceptable` response when it was omitted; milestone one's order confirmations never needed it, so nothing in this plugin built it before now. It is copied from the fetched draft's own value the same way `taxConditions` already is, since the draft is a real Lexware invoice and therefore already carries a valid one; the literal fallback only matters if a draft is ever missing the field entirely, which has not been observed.

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\Service\\InvoiceProcessor
```

Using a throwaway console command, call `convert()` against the pending `TrackedInvoice` row tracked in Task 6's manual verification, passing a handful of real, not yet exported timesheets booked on its linked project. Confirm the sandbox Lexware account shows a new invoice with the original lines plus the new timesheet lines, `SELECT * FROM kimai2_ext_lexware_invoice` shows `status = 'converted'` with `created_invoice_lexware_id` set, `SELECT * FROM kimai2_ext_lexware_invoice_timesheet` has one row per selected timesheet, and `SELECT exported FROM kimai2_timesheet WHERE id IN (...)` shows `1` for each of them.

- [ ] **Step 3: Commit**

```bash
git add Service/InvoiceProcessor.php
git commit -m "Add the invoice processor that builds and creates the combined invoice"
```

---

## Task 10: Invoice assignment screen

**Files:**
- Create: `Controller/InvoiceAssignmentController.php`
- Create: `EventSubscriber/InvoiceActionSubscriber.php`
- Create: `Resources/views/invoice/index.html.twig`
- Create: `Resources/views/invoice/assign.html.twig`
- Modify: `Resources/config/routes.yaml`
- Modify: `Resources/translations/messages.en.xlf`
- Modify: `Resources/translations/messages.de.xlf`

**Interfaces:**
- Consumes: `TrackedInvoiceRepository`, `TrackedInvoiceTimesheetRepository` from Task 2; `InvoiceProcessor` from Task 9; `App\Repository\TimesheetRepository::getTimesheetsForQuery()`, `App\Repository\Query\TimesheetQuery`; `App\Entity\Project`, `App\Entity\Timesheet`.
- Produces: the routes `lexware_sync_invoices`, `lexware_sync_invoices_assign`, `lexware_sync_invoices_reject`, `lexware_sync_invoices_convert`, `lexware_sync_invoices_check_status`, `lexware_sync_invoices_confirm_existing`, all gated by the `manage_lexware_sync` permission from Task 1.

The re-selection guard against a timesheet becoming ineligible between opening the assignment page and submitting it, required by the design spec's section 16, falls out of how selection is resolved: the controller always re-fetches the currently eligible timesheet list and intersects it with the submitted identifiers, rather than trusting the submitted identifiers on their own. A timesheet exported by something else in the meantime is silently absent from that fresh eligible list and therefore dropped from the selection; comparing the submitted identifier count against the resolved timesheet count is what lets the controller show the visible note the spec asks for whenever that happened, without needing to know which specific timesheet was dropped or why.

- [ ] **Step 1: Write the controller**

`Controller/InvoiceAssignmentController.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceTimesheetRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\AmbiguousLexwareRequestException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/invoices')]
#[IsGranted('manage_lexware_sync')]
final class InvoiceAssignmentController extends AbstractController
{
    private const LEXWARE_VOUCHER_LIST_URL = 'https://app.lexware.de/vouchers#!/VoucherList/?filter=invoice&sort=sortByVoucherDate&sortDirection=desc&query=';

    public function __construct(
        private readonly TrackedInvoiceRepository $repository,
        private readonly TrackedInvoiceTimesheetRepository $trackedInvoiceTimesheetRepository,
        private readonly TimesheetRepository $timesheetRepository,
        private readonly InvoiceProcessor $processor,
    ) {
    }

    #[Route(path: '', name: 'lexware_sync_invoices', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $recentlyConverted = $this->repository->findRecentlyConverted();

        $warnings = [];
        foreach ($recentlyConverted as $trackedInvoice) {
            $warnings[(int) $trackedInvoice->getId()] = $this->trackedInvoiceTimesheetRepository->hasModifiedTimesheets($trackedInvoice);
        }

        $convertedVoucherNumber = $request->query->get('converted_voucher_number');

        return $this->render('@KimaiLexwareSync/invoice/index.html.twig', [
            'pendingInvoices' => $this->repository->findPending(),
            'recentlyConverted' => $recentlyConverted,
            'warnings' => $warnings,
            'convertedVoucherNumber' => \is_string($convertedVoucherNumber) ? $convertedVoucherNumber : null,
            'lexwareVoucherListUrl' => self::LEXWARE_VOUCHER_LIST_URL,
        ]);
    }

    #[Route(path: '/{id}', name: 'lexware_sync_invoices_assign', methods: ['GET'])]
    public function assign(int $id): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        return $this->render('@KimaiLexwareSync/invoice/assign.html.twig', [
            'trackedInvoice' => $trackedInvoice,
            'timesheets' => $this->findEligibleTimesheets($trackedInvoice->getRelatedOrderConfirmation()->getProject()),
            'plausibleMatch' => null,
        ]);
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_invoices_reject', methods: ['POST'])]
    public function reject(int $id, Request $request): RedirectResponse
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $trackedInvoice->markRejected($this->getUser());
        $this->repository->save($trackedInvoice);

        return $this->redirectToRoute('lexware_sync_invoices');
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_invoices_convert', methods: ['POST'])]
    public function convert(int $id, Request $request): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        $submittedIds = array_map('intval', (array) $request->request->all('timesheets'));
        $timesheets = $this->resolveSelectedTimesheets($request, $project);
        $shape = $request->request->get('shape') === InvoiceLineShape::AggregatedByActivity->value
            ? InvoiceLineShape::AggregatedByActivity
            : InvoiceLineShape::PerTimesheet;

        if (\count($timesheets) < \count($submittedIds)) {
            $this->addFlash('warning', 'lexware_sync.invoice.timesheets_dropped');
        }

        try {
            $this->processor->convert(
                $trackedInvoice,
                $timesheets,
                $shape,
                $request->request->getBoolean('finalize'),
                $request->request->getBoolean('mark_project_completed'),
                $this->getUser(),
            );
        } catch (AmbiguousLexwareRequestException $exception) {
            $this->addFlash('error', 'lexware_sync.invoice.ambiguous_failure');

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        } catch (CustomerCurrencyMismatchException | LexwareApiException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        return $this->redirectToRoute('lexware_sync_invoices', ['converted_voucher_number' => $trackedInvoice->getVoucherNumber()]);
    }

    #[Route(path: '/{id}/check-status', name: 'lexware_sync_invoices_check_status', methods: ['POST'])]
    public function checkStatus(int $id, Request $request): Response
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        return $this->render('@KimaiLexwareSync/invoice/assign.html.twig', [
            'trackedInvoice' => $trackedInvoice,
            'timesheets' => $this->findEligibleTimesheets($trackedInvoice->getRelatedOrderConfirmation()->getProject()),
            'plausibleMatch' => $this->processor->findPlausibleMatch($trackedInvoice),
        ]);
    }

    #[Route(path: '/{id}/confirm-existing', name: 'lexware_sync_invoices_confirm_existing', methods: ['POST'])]
    public function confirmExisting(int $id, Request $request): RedirectResponse
    {
        $trackedInvoice = $this->repository->find($id);
        if ($trackedInvoice === null || !$trackedInvoice->getStatus()->isPending()) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        if (!$this->isCsrfTokenValid('lexware_sync_invoices', $request->request->get('_token'))) {
            return $this->redirectToRoute('lexware_sync_invoices');
        }

        $existingLexwareInvoiceId = (string) $request->request->get('existing_lexware_invoice_id', '');
        if ($existingLexwareInvoiceId === '') {
            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        $project = $trackedInvoice->getRelatedOrderConfirmation()->getProject();
        $submittedIds = array_map('intval', (array) $request->request->all('timesheets'));
        $timesheets = $this->resolveSelectedTimesheets($request, $project);

        if (\count($timesheets) < \count($submittedIds)) {
            $this->addFlash('warning', 'lexware_sync.invoice.timesheets_dropped');
        }

        try {
            $this->processor->confirmExisting(
                $trackedInvoice,
                $existingLexwareInvoiceId,
                $timesheets,
                $request->request->getBoolean('mark_project_completed'),
                $this->getUser(),
            );
        } catch (CustomerCurrencyMismatchException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('lexware_sync_invoices_assign', ['id' => $id]);
        }

        return $this->redirectToRoute('lexware_sync_invoices', ['converted_voucher_number' => $trackedInvoice->getVoucherNumber()]);
    }

    /**
     * @return Timesheet[]
     */
    private function findEligibleTimesheets(?Project $project): array
    {
        if ($project === null) {
            return [];
        }

        $query = new TimesheetQuery();
        $query->setProjects([$project]);
        $query->setState(TimesheetQuery::STATE_STOPPED);
        $query->setExported(TimesheetQuery::STATE_NOT_EXPORTED);

        return $this->timesheetRepository->getTimesheetsForQuery($query);
    }

    /**
     * @return Timesheet[]
     */
    private function resolveSelectedTimesheets(Request $request, ?Project $project): array
    {
        $selectedIds = array_map('intval', (array) $request->request->all('timesheets'));
        if (\count($selectedIds) === 0) {
            return [];
        }

        $eligible = $this->findEligibleTimesheets($project);

        return array_values(array_filter(
            $eligible,
            static fn (Timesheet $timesheet) => \in_array($timesheet->getId(), $selectedIds, true),
        ));
    }
}
```

- [ ] **Step 2: Write the action subscriber**

`EventSubscriber/InvoiceActionSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedInvoiceRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvoiceActionSubscriber extends AbstractActionsSubscriber
{
    public function __construct(
        AuthorizationCheckerInterface $auth,
        UrlGeneratorInterface $urlGenerator,
        private readonly TrackedInvoiceRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($auth, $urlGenerator);
    }

    public static function getActionName(): string
    {
        return 'projects';
    }

    public function onActions(PageActionsEvent $event): void
    {
        if (!$this->isGranted('manage_lexware_sync')) {
            return;
        }

        $pendingCount = $this->repository->countPending();
        $label = $this->translator->trans('lexware_sync.invoice.action') . ' (' . $pendingCount . ')';

        $event->addAction('lexware_sync_invoices', [
            'url' => $this->path('lexware_sync_invoices'),
            'class' => '',
            'title' => $label,
            'icon' => 'fas fa-file-invoice-dollar',
        ]);
    }
}
```

- [ ] **Step 3: Write the pending list and recently converted template**

`Resources/views/invoice/index.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block main %}
    {% if convertedVoucherNumber %}
        <div class="alert alert-success">
            {{ 'lexware_sync.invoice.converted'|trans }}
            <a href="{{ lexwareVoucherListUrl ~ convertedVoucherNumber|url_encode }}" target="_blank" rel="noopener">{{ 'lexware_sync.invoice.delete_original_hint'|trans }}</a>
        </div>
    {% endif %}

    {% embed '@theme/embeds/card.html.twig' %}
        {% block box_title %}{{ 'lexware_sync.invoice.title'|trans }}{% endblock %}
        {% block box_body %}
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ 'lexware_sync.invoice.voucher_number'|trans }}</th>
                        <th>{{ 'lexware_sync.invoice.voucher_date'|trans }}</th>
                        <th>{{ 'lexware_sync.invoice.project'|trans }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {% for trackedInvoice in pendingInvoices %}
                        <tr>
                            <td>{{ trackedInvoice.voucherNumber }}</td>
                            <td>{{ trackedInvoice.voucherDate|date('Y-m-d') }}</td>
                            <td>{{ trackedInvoice.relatedOrderConfirmation.project ? trackedInvoice.relatedOrderConfirmation.project.name : '' }}</td>
                            <td>
                                <a href="{{ path('lexware_sync_invoices_assign', {id: trackedInvoice.id}) }}" class="btn btn-sm btn-primary">{{ 'lexware_sync.invoice.assign'|trans }}</a>
                                <form method="post" action="{{ path('lexware_sync_invoices_reject', {id: trackedInvoice.id}) }}" style="display: inline">
                                    <input type="hidden" name="_token" value="{{ csrf_token('lexware_sync_invoices') }}">
                                    <button type="submit" class="btn btn-sm btn-secondary">{{ 'lexware_sync.invoice.reject'|trans }}</button>
                                </form>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        {% endblock %}
    {% endembed %}

    {% embed '@theme/embeds/card.html.twig' %}
        {% block box_title %}{{ 'lexware_sync.invoice.recently_converted'|trans }}{% endblock %}
        {% block box_body %}
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ 'lexware_sync.invoice.voucher_number'|trans }}</th>
                        <th>{{ 'lexware_sync.invoice.voucher_date'|trans }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {% for trackedInvoice in recentlyConverted %}
                        <tr>
                            <td>{{ trackedInvoice.voucherNumber }}</td>
                            <td>{{ trackedInvoice.voucherDate|date('Y-m-d') }}</td>
                            <td>
                                {% if warnings[trackedInvoice.id] %}
                                    <i class="fas fa-exclamation-triangle text-warning" data-toggle="tooltip" data-placement="top" title="{{ 'lexware_sync.invoice.modified_after_export'|trans }}"></i>
                                {% endif %}
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        {% endblock %}
    {% endembed %}
{% endblock %}
```

- [ ] **Step 4: Write the assignment page template**

`Resources/views/invoice/assign.html.twig`:

```twig
{% extends 'base.html.twig' %}

{% block main %}
    {% embed '@theme/embeds/card.html.twig' %}
        {% block box_title %}{{ 'lexware_sync.invoice.assign_title'|trans }} {{ trackedInvoice.voucherNumber }}{% endblock %}
        {% block box_body %}
            {% if plausibleMatch is not null %}
                <div class="alert alert-warning">
                    {{ 'lexware_sync.invoice.plausible_match_found'|trans }}
                    <strong>{{ plausibleMatch.voucherNumber|default('?') }}</strong>
                    ({{ plausibleMatch.voucherDate|default('') }})
                </div>
            {% elseif trackedInvoice.creationAttemptedAt is not null %}
                <div class="alert alert-warning">
                    {{ 'lexware_sync.invoice.ambiguous_failure'|trans }}
                </div>
            {% endif %}

            <h4>{{ 'lexware_sync.invoice.original_lines'|trans }}</h4>
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ 'lexware_sync.invoice.line_name'|trans }}</th>
                        <th>{{ 'lexware_sync.invoice.line_quantity'|trans }}</th>
                    </tr>
                </thead>
                <tbody>
                    {% for line in (trackedInvoice.rawPayload|json_decode(true)).lineItems|default([]) %}
                        <tr>
                            <td>{{ line.name|default('') }}</td>
                            <td>{{ line.quantity|default('') }}</td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>

            <form method="post" action="{{ path('lexware_sync_invoices_convert', {id: trackedInvoice.id}) }}">
                <input type="hidden" name="_token" value="{{ csrf_token('lexware_sync_invoices') }}">

                <h4>{{ 'lexware_sync.invoice.select_timesheets'|trans }}</h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>{{ 'lexware_sync.invoice.timesheet_date'|trans }}</th>
                            <th>{{ 'lexware_sync.invoice.timesheet_activity'|trans }}</th>
                            <th>{{ 'lexware_sync.invoice.timesheet_duration'|trans }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {% for timesheet in timesheets %}
                            <tr>
                                <td><input type="checkbox" name="timesheets[]" value="{{ timesheet.id }}"></td>
                                <td>{{ timesheet.begin ? timesheet.begin|date('Y-m-d') : '' }}</td>
                                <td>{{ timesheet.activity ? timesheet.activity.name : '' }}</td>
                                <td>{{ (timesheet.duration / 3600)|number_format(2) }}</td>
                            </tr>
                        {% endfor %}
                    </tbody>
                </table>

                <div class="form-group">
                    <label>
                        <input type="radio" name="shape" value="per_timesheet" checked> {{ 'lexware_sync.invoice.shape_per_timesheet'|trans }}
                    </label>
                    <label>
                        <input type="radio" name="shape" value="aggregated_by_activity"> {{ 'lexware_sync.invoice.shape_aggregated'|trans }}
                    </label>
                </div>

                <div class="form-group">
                    <label>
                        <input type="checkbox" name="finalize" value="1"> {{ 'lexware_sync.invoice.finalize'|trans }}
                    </label>
                    <label>
                        <input type="checkbox" name="mark_project_completed" value="1"> {{ 'lexware_sync.invoice.mark_project_completed'|trans }}
                    </label>
                </div>

                <button type="submit" class="btn btn-primary">{{ 'lexware_sync.invoice.submit'|trans }}</button>

                {% if plausibleMatch is not null %}
                    <input type="hidden" name="existing_lexware_invoice_id" value="{{ plausibleMatch.id }}">
                    <button type="submit" formaction="{{ path('lexware_sync_invoices_confirm_existing', {id: trackedInvoice.id}) }}" class="btn btn-warning">{{ 'lexware_sync.invoice.confirm_existing'|trans }}</button>
                {% elseif trackedInvoice.creationAttemptedAt is not null %}
                    <button type="submit" formaction="{{ path('lexware_sync_invoices_check_status', {id: trackedInvoice.id}) }}" class="btn btn-secondary">{{ 'lexware_sync.invoice.check_status'|trans }}</button>
                {% endif %}
            </form>
        {% endblock %}
    {% endembed %}
{% endblock %}
```

The `formaction` attribute on the second and third buttons keeps all three actions, the normal submission, the check status lookup, and confirming an already found match, inside one `<form>`, so the timesheet checkboxes and the shape and completion choices are always submitted together no matter which button was clicked. HTML forms cannot be nested, so three separate `<form>` elements would each need their own copy of the same selection state.

- [ ] **Step 5: Add the route**

Append to `Resources/config/routes.yaml`:

```yaml
lexware_sync_invoices:
    resource: '@KimaiLexwareSyncBundle/Controller/InvoiceAssignmentController.php'
    type: attribute
    prefix: /{_locale}
    requirements:
        _locale: '%app_locales%'
    defaults:
        _locale: '%locale%'
```

- [ ] **Step 6: Add the translations**

In `Resources/translations/messages.en.xlf`, add before the closing `</body>`:

```xml
            <trans-unit id="lexware_sync.invoice.title" resname="lexware_sync.invoice.title">
                <source>lexware_sync.invoice.title</source>
                <target>Pending invoice drafts</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.action" resname="lexware_sync.invoice.action">
                <source>lexware_sync.invoice.action</source>
                <target>Pending invoice drafts</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.voucher_number" resname="lexware_sync.invoice.voucher_number">
                <source>lexware_sync.invoice.voucher_number</source>
                <target>Voucher number</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.voucher_date" resname="lexware_sync.invoice.voucher_date">
                <source>lexware_sync.invoice.voucher_date</source>
                <target>Date</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.project" resname="lexware_sync.invoice.project">
                <source>lexware_sync.invoice.project</source>
                <target>Project</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.assign" resname="lexware_sync.invoice.assign">
                <source>lexware_sync.invoice.assign</source>
                <target>Assign timesheets</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.reject" resname="lexware_sync.invoice.reject">
                <source>lexware_sync.invoice.reject</source>
                <target>Reject</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.recently_converted" resname="lexware_sync.invoice.recently_converted">
                <source>lexware_sync.invoice.recently_converted</source>
                <target>Recently converted</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.modified_after_export" resname="lexware_sync.invoice.modified_after_export">
                <source>lexware_sync.invoice.modified_after_export</source>
                <target>A timesheet in this invoice was edited after it was created</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.assign_title" resname="lexware_sync.invoice.assign_title">
                <source>lexware_sync.invoice.assign_title</source>
                <target>Assign timesheets to</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.original_lines" resname="lexware_sync.invoice.original_lines">
                <source>lexware_sync.invoice.original_lines</source>
                <target>Original lines from the draft</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.line_name" resname="lexware_sync.invoice.line_name">
                <source>lexware_sync.invoice.line_name</source>
                <target>Name</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.line_quantity" resname="lexware_sync.invoice.line_quantity">
                <source>lexware_sync.invoice.line_quantity</source>
                <target>Quantity</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.select_timesheets" resname="lexware_sync.invoice.select_timesheets">
                <source>lexware_sync.invoice.select_timesheets</source>
                <target>Select timesheets to add</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_date" resname="lexware_sync.invoice.timesheet_date">
                <source>lexware_sync.invoice.timesheet_date</source>
                <target>Date</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_activity" resname="lexware_sync.invoice.timesheet_activity">
                <source>lexware_sync.invoice.timesheet_activity</source>
                <target>Activity</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_duration" resname="lexware_sync.invoice.timesheet_duration">
                <source>lexware_sync.invoice.timesheet_duration</source>
                <target>Hours</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.shape_per_timesheet" resname="lexware_sync.invoice.shape_per_timesheet">
                <source>lexware_sync.invoice.shape_per_timesheet</source>
                <target>One line per timesheet</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.shape_aggregated" resname="lexware_sync.invoice.shape_aggregated">
                <source>lexware_sync.invoice.shape_aggregated</source>
                <target>One aggregated line per activity</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.finalize" resname="lexware_sync.invoice.finalize">
                <source>lexware_sync.invoice.finalize</source>
                <target>Finalize the new invoice immediately</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.mark_project_completed" resname="lexware_sync.invoice.mark_project_completed">
                <source>lexware_sync.invoice.mark_project_completed</source>
                <target>Mark the project completed</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.submit" resname="lexware_sync.invoice.submit">
                <source>lexware_sync.invoice.submit</source>
                <target>Create combined invoice</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.plausible_match_found" resname="lexware_sync.invoice.plausible_match_found">
                <source>lexware_sync.invoice.plausible_match_found</source>
                <target>A possibly matching invoice was already found in Lexware:</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.ambiguous_failure" resname="lexware_sync.invoice.ambiguous_failure">
                <source>lexware_sync.invoice.ambiguous_failure</source>
                <target>The previous attempt could not be confirmed. Check Lexware before retrying.</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.confirm_existing" resname="lexware_sync.invoice.confirm_existing">
                <source>lexware_sync.invoice.confirm_existing</source>
                <target>Yes, this is it, mark resolved</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.check_status" resname="lexware_sync.invoice.check_status">
                <source>lexware_sync.invoice.check_status</source>
                <target>Check Lexware for a matching invoice</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.converted" resname="lexware_sync.invoice.converted">
                <source>lexware_sync.invoice.converted</source>
                <target>The combined invoice was created.</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.delete_original_hint" resname="lexware_sync.invoice.delete_original_hint">
                <source>lexware_sync.invoice.delete_original_hint</source>
                <target>Delete the original draft in Lexware</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheets_dropped" resname="lexware_sync.invoice.timesheets_dropped">
                <source>lexware_sync.invoice.timesheets_dropped</source>
                <target>One or more selected timesheets were no longer eligible and were left out.</target>
            </trans-unit>
```

In `Resources/translations/messages.de.xlf`, add before the closing `</body>`:

```xml
            <trans-unit id="lexware_sync.invoice.title" resname="lexware_sync.invoice.title">
                <source>lexware_sync.invoice.title</source>
                <target>Offene Rechnungsentwürfe</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.action" resname="lexware_sync.invoice.action">
                <source>lexware_sync.invoice.action</source>
                <target>Offene Rechnungsentwürfe</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.voucher_number" resname="lexware_sync.invoice.voucher_number">
                <source>lexware_sync.invoice.voucher_number</source>
                <target>Belegnummer</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.voucher_date" resname="lexware_sync.invoice.voucher_date">
                <source>lexware_sync.invoice.voucher_date</source>
                <target>Datum</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.project" resname="lexware_sync.invoice.project">
                <source>lexware_sync.invoice.project</source>
                <target>Projekt</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.assign" resname="lexware_sync.invoice.assign">
                <source>lexware_sync.invoice.assign</source>
                <target>Timesheets zuordnen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.reject" resname="lexware_sync.invoice.reject">
                <source>lexware_sync.invoice.reject</source>
                <target>Ablehnen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.recently_converted" resname="lexware_sync.invoice.recently_converted">
                <source>lexware_sync.invoice.recently_converted</source>
                <target>Kürzlich umgewandelt</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.modified_after_export" resname="lexware_sync.invoice.modified_after_export">
                <source>lexware_sync.invoice.modified_after_export</source>
                <target>Ein Timesheet dieser Rechnung wurde nach der Erstellung bearbeitet</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.assign_title" resname="lexware_sync.invoice.assign_title">
                <source>lexware_sync.invoice.assign_title</source>
                <target>Timesheets zuordnen zu</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.original_lines" resname="lexware_sync.invoice.original_lines">
                <source>lexware_sync.invoice.original_lines</source>
                <target>Ursprüngliche Positionen aus dem Entwurf</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.line_name" resname="lexware_sync.invoice.line_name">
                <source>lexware_sync.invoice.line_name</source>
                <target>Name</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.line_quantity" resname="lexware_sync.invoice.line_quantity">
                <source>lexware_sync.invoice.line_quantity</source>
                <target>Menge</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.select_timesheets" resname="lexware_sync.invoice.select_timesheets">
                <source>lexware_sync.invoice.select_timesheets</source>
                <target>Timesheets zum Hinzufügen auswählen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_date" resname="lexware_sync.invoice.timesheet_date">
                <source>lexware_sync.invoice.timesheet_date</source>
                <target>Datum</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_activity" resname="lexware_sync.invoice.timesheet_activity">
                <source>lexware_sync.invoice.timesheet_activity</source>
                <target>Aktivität</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheet_duration" resname="lexware_sync.invoice.timesheet_duration">
                <source>lexware_sync.invoice.timesheet_duration</source>
                <target>Stunden</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.shape_per_timesheet" resname="lexware_sync.invoice.shape_per_timesheet">
                <source>lexware_sync.invoice.shape_per_timesheet</source>
                <target>Eine Zeile pro Timesheet</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.shape_aggregated" resname="lexware_sync.invoice.shape_aggregated">
                <source>lexware_sync.invoice.shape_aggregated</source>
                <target>Eine aggregierte Zeile pro Aktivität</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.finalize" resname="lexware_sync.invoice.finalize">
                <source>lexware_sync.invoice.finalize</source>
                <target>Neue Rechnung sofort finalisieren</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.mark_project_completed" resname="lexware_sync.invoice.mark_project_completed">
                <source>lexware_sync.invoice.mark_project_completed</source>
                <target>Projekt als abgeschlossen markieren</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.submit" resname="lexware_sync.invoice.submit">
                <source>lexware_sync.invoice.submit</source>
                <target>Kombinierte Rechnung erstellen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.plausible_match_found" resname="lexware_sync.invoice.plausible_match_found">
                <source>lexware_sync.invoice.plausible_match_found</source>
                <target>Eine möglicherweise passende Rechnung wurde bereits in Lexware gefunden:</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.ambiguous_failure" resname="lexware_sync.invoice.ambiguous_failure">
                <source>lexware_sync.invoice.ambiguous_failure</source>
                <target>Der vorherige Versuch konnte nicht bestätigt werden. Bitte vor einem erneuten Versuch in Lexware nachsehen.</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.confirm_existing" resname="lexware_sync.invoice.confirm_existing">
                <source>lexware_sync.invoice.confirm_existing</source>
                <target>Ja, das ist sie, als erledigt markieren</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.check_status" resname="lexware_sync.invoice.check_status">
                <source>lexware_sync.invoice.check_status</source>
                <target>Lexware nach einer passenden Rechnung durchsuchen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.converted" resname="lexware_sync.invoice.converted">
                <source>lexware_sync.invoice.converted</source>
                <target>Die kombinierte Rechnung wurde erstellt.</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.delete_original_hint" resname="lexware_sync.invoice.delete_original_hint">
                <source>lexware_sync.invoice.delete_original_hint</source>
                <target>Ursprünglichen Entwurf in Lexware löschen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.invoice.timesheets_dropped" resname="lexware_sync.invoice.timesheets_dropped">
                <source>lexware_sync.invoice.timesheets_dropped</source>
                <target>Ein oder mehrere ausgewählte Timesheets waren nicht mehr wählbar und wurden weggelassen.</target>
            </trans-unit>
```

- [ ] **Step 7: Rebuild the cache and exercise the screen**

```bash
bin/console lint:xliff Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
bin/console kimai:reload -n
bin/console debug:router | grep lexware_sync_invoices
```

Then, logged in as a user with `manage_lexware_sync`, open the project overview, confirm the new button shows the current pending invoice count next to the existing triage button, open it, open the assignment page for the pending draft tracked in Task 6, select a couple of timesheets, submit with both shapes in turn on two different drafts if the sandbox account has more than one, and confirm the success banner shows the delete link pointing at the right voucher number. Confirm the row disappears from the pending list and appears under recently converted, with the warning icon showing once one of the included timesheets is edited afterward.

- [ ] **Step 8: Commit**

```bash
git add Controller/InvoiceAssignmentController.php EventSubscriber/InvoiceActionSubscriber.php Resources/views/invoice/index.html.twig Resources/views/invoice/assign.html.twig Resources/config/routes.yaml Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
git commit -m "Add the invoice assignment screen for timesheet selection and combined invoice creation"
```

---

## Task 11: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- None; this task only brings the plugin's own operational documentation up to date with the finished milestone.

- [ ] **Step 1: Update the workflow section**

In `README.md`, replace the fourth, "planned, not yet implemented" bullet under "The workflow" with:

```markdown
4. Once a person pursues a tracked order confirmation into an invoice draft inside Lexware, the
   plugin picks it up the same way, through a webhook with a reconciliation poll as a safety
   net, and lists it in a second screen. Assigning open, not yet exported timesheets to it, in
   either one line per timesheet or one aggregated line per activity, produces a new invoice
   containing both the draft's original lines and the new timesheet lines, pushed back to
   Lexware. Since Lexware offers no update or deletion endpoint for invoices, the original draft
   stays in Lexware afterward; the screen links directly to it so a person can delete it by hand.
```

- [ ] **Step 2: Update the status section**

Replace:

```markdown
- **Milestone two**, invoice ingestion followed by timesheet assignment and an outbound
  invoice, is described only at the roadmap level for now. A dedicated design pass will happen
  once milestone one is in use. See the specification for exactly which parts of milestone two
  are already decided and which parts are still open questions.
```

with:

```markdown
- **Milestone two**, invoice ingestion followed by timesheet assignment and an outbound
  invoice, is in development. The full design is written down in the specification linked
  below, sections 12 through 18.
```

- [ ] **Step 3: Extend the configuration table**

Append two rows to the configuration table in `README.md`:

```markdown
| `lexware_sync.invoice_title_regex` | Regular expression checked against an invoice draft's title, applied on top of its `relatedVouchers` link to a tracked order confirmation. An empty value matches every title. | empty |
| `lexware_sync.project_completion_mode` | What marking a project completed, offered when converting a tracked invoice, actually does: `end_date` sets an end date on the project, `hidden` hides its visibility. | `end_date` |
```

- [ ] **Step 4: Document the second cron entry and the second webhook subscription**

In the "Running it" section, after the existing `kimai:lexware-sync:check-api-key` bullet, add:

```markdown
- `bin/console kimai:lexware-sync:reconcile-invoices` polls Lexware for invoice drafts that a
  webhook delivery might have missed, on the same interval as the order confirmation
  reconciliation poll above.
```

After the existing paragraph about registering the order confirmation webhook subscription, add:

```markdown
Register a second event subscription the same way, with an `eventType` of `invoice.changed` and
a `callbackUrl` pointing at this instance's `/webhook/lexware/invoice` route, so invoice drafts
pursued from a tracked order confirmation are picked up the same way order confirmations
themselves are.
```

- [ ] **Step 5: Update the permissions section**

Replace:

```markdown
A dedicated `triage_lexware_sync` permission gates the manual triage screen. It is kept separate
from Kimai's general project management permissions, so it can be granted only to the roles
that should decide which order confirmations become projects.
```

with:

```markdown
A dedicated `manage_lexware_sync` permission gates both the order confirmation triage screen and
the invoice assignment screen. It is kept separate from Kimai's general project management
permissions, so it can be granted only to the roles that should decide which order confirmations
become projects and which invoice drafts get their combined invoice created.
```

- [ ] **Step 6: Manual verification**

```bash
bin/console kimai:reload -n
```

Read through the whole updated `README.md` once, end to end, to confirm nothing still describes milestone two as unimplemented or refers to `triage_lexware_sync` by its old name.

- [ ] **Step 7: Commit**

```bash
git add README.md
git commit -m "Document milestone two: the invoice screen, its cron entry, webhook subscription and permission"
```

---

## After this plan

Milestone two is complete once all eleven tasks are committed and the manual verification steps have been exercised once end to end against the real sandbox account: an order confirmation already converted into a project, an invoice draft pursued from it in Lexware, picked up either by the webhook or the reconciliation command, assigned a handful of real timesheets in both line shapes across two different drafts, and pushed back as a new, combined invoice, with the project completion option exercised at least once in each of its two configured modes. The plugin's roadmap has no milestone three; any further work starts with its own brainstorming pass against real usage of milestones one and two.
