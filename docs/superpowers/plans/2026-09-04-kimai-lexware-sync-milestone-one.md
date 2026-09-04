# KimaiLexwareSync Milestone One Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task by task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the KimaiLexwareSyncBundle plugin so that Lexware order confirmations flow into Kimai, either automatically or through a manual triage screen, becoming Kimai projects and activities with a recorded, auditable link back to their source order confirmation.

**Architecture:** A webhook controller and a reconciliation console command are the only two entry points, and both call one synchronous service, `OrderConfirmationSynchronizer`, which refetches the order confirmation from the Lexware API, upserts a tracking record, and, when the configured rule matches or a human decides to, delegates to `OrderConfirmationProcessor` to create the Kimai customer, project and activities, all inside one Doctrine transaction. There is no message queue: Symfony Messenger is not among the libraries Kimai ships to plugins.

**Tech Stack:** PHP 8.2+, Symfony 6.4 components already bundled with Kimai (`symfony/http-client`, `symfony/console`, `symfony/form`, `symfony/validator`), Doctrine ORM 2.20, Twig, plain PHPUnit for the parts that need neither the kernel nor a database.

**Spec:** `docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`

## Global Constraints

- Every file in this repository is written in English: code, comments, commit messages, documentation. Chat with the user stays in German, nothing else does.
- Never use an em dash or a double hyphen as punctuation in a project file. A markdown table separator row is exempt.
- Avoid abbreviations in prose and in code identifiers.
- Declare `strict_types=1` in every PHP file.
- Use constructor property promotion and typed, readonly properties where the value never changes after construction.
- Mark classes `final` unless there is a concrete, current reason for something else to extend them.
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
- Do not match Kimai customers to Lexware contacts by name similarity. An unmapped contact always produces a new Kimai customer.
- One Doctrine transaction per conversion: the tracking record and the customer, project and activity records it produces are created together.
- This Kimai installation has no plugin test harness: no `tests` directory, no `phpunit.xml.dist`, `APP_ENV=prod`, one database with no separate test environment. Automated tests are plain PHPUnit tests that need neither the kernel nor a database. Anything touching Doctrine or the kernel is verified by hand through the lint, boot, exercise loop: rebuild the cache, confirm wiring with `debug:*` commands, run the install command, exercise the real behavior through the Lexware sandbox account and the Kimai browser interface.
- The bundle directory must be named `KimaiLexwareSyncBundle` for Kimai's kernel to discover it (`Kernel::getBundleClasses()` only scans directories matching `*Bundle`). The devcontainer already mounts this repository at that name inside the running containers through the `BUNDLE_NAME` variable in `.devcontainer/.env`; every file path below is relative to the plugin root regardless of what the mount is currently named on the host.
- The target Kimai version is 2.65.0, `VERSION_ID` `26500`. Set `extra.kimai.require` to that value.
- The Lexware API base URL is `https://api.lexware.io`, rate limited to two requests per second, authenticated with `Authorization: Bearer <key>`. The key is read from the `LEXWARE_API_KEY` environment variable, never entered into Kimai's system configuration screen, which stores every value as plain text.

---

## Task 1: Scaffold the plugin bundle

**Files:**
- Create: `composer.json`
- Create: `KimaiLexwareSyncBundle.php`
- Create: `DependencyInjection/KimaiLexwareSyncExtension.php`
- Create: `Resources/config/services.yaml`
- Create: `Command/InstallCommand.php`
- Create: `phpstan.neon`
- Create: `.php-cs-fixer.dist.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: the bundle class `KimaiPlugin\KimaiLexwareSyncBundle\KimaiLexwareSyncBundle`, discoverable by Kimai's kernel. Every later task's classes live under the `KimaiPlugin\KimaiLexwareSyncBundle\` namespace, mirrored by their file path from the plugin root.

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "navilalabs/kimai-lexware-sync-bundle",
    "description": "Synchronizes a Kimai instance with its own Lexware account for order confirmations and invoices.",
    "homepage": "https://github.com/navilalabs/KimaiLexwareSyncBundle",
    "type": "kimai-plugin",
    "version": "1.0.0",
    "keywords": ["kimai", "kimai-plugin", "lexware"],
    "license": "MIT",
    "authors": [
        {
            "name": "NavilaLabs",
            "email": "steffen.konermann@navilalabs.com"
        }
    ],
    "extra": {
        "kimai": {
            "require": 26500,
            "name": "KimaiLexwareSync"
        }
    },
    "autoload": {
        "psr-4": {
            "KimaiPlugin\\KimaiLexwareSyncBundle\\": ""
        }
    },
    "config": {
        "allow-plugins": {
            "symfony/flex": false,
            "symfony/runtime": false
        },
        "platform": {
            "php": "8.2"
        },
        "preferred-install": {
            "*": "dist"
        },
        "sort-packages": true
    },
    "scripts": {
        "codestyle": "vendor/bin/php-cs-fixer fix --dry-run --verbose --show-progress=none",
        "codestyle-fix": "vendor/bin/php-cs-fixer fix",
        "phpstan": "vendor/bin/phpstan analyse . --configuration=phpstan.neon",
        "tests": "vendor/bin/phpunit"
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.0",
        "kimai/kimai": "dev-main",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-doctrine": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpstan/phpstan-symfony": "^2.0",
        "phpunit/phpunit": "^10.0"
    }
}
```

- [ ] **Step 2: Write the bundle class**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class KimaiLexwareSyncBundle extends Bundle implements PluginInterface
{
}
```

- [ ] **Step 3: Write the extension**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class KimaiLexwareSyncExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }
}
```

- [ ] **Step 4: Write `Resources/config/services.yaml`**

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    KimaiPlugin\KimaiLexwareSyncBundle\:
        resource: '../../*'
        exclude:
            - '../../Entity/'
            - '../../Migrations/'
            - '../../Resources/'

    KimaiPlugin\KimaiLexwareSyncBundle\Controller\:
        resource: '../../Controller'
        tags: ['controller.service_arguments']
```

- [ ] **Step 5: Write the install command**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use App\Command\AbstractBundleInstallerCommand;

final class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'lexware-sync';
    }

    protected function getMigrationConfigFilename(): ?string
    {
        return __DIR__ . '/../Migrations/doctrine_migrations.yaml';
    }

    protected function hasAssets(): bool
    {
        return false;
    }
}
```

This references a migration configuration file that Task 2 creates. The command will not fully succeed until that task lands; that is expected and checked again at the end of Task 2.

- [ ] **Step 6: Write `phpstan.neon`**

```neon
includes:
  - %rootDir%/../phpstan-symfony/extension.neon
  - %rootDir%/../phpstan-symfony/rules.neon
  - %rootDir%/../phpstan-doctrine/extension.neon
  - %rootDir%/../phpstan-doctrine/rules.neon
  - %rootDir%/../phpstan-strict-rules/rules.neon

parameters:
  level: 9
  excludePaths:
    - vendor/(?)
  treatPhpDocTypesAsCertain: false
  inferPrivatePropertyTypeFromConstructor: true
  doctrine:
    allowNullablePropertyForRequiredField: true
```

- [ ] **Step 7: Write `.php-cs-fixer.dist.php`**

```php
<?php

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => true,
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => false,
        'yoda_style' => false,
        'ternary_to_null_coalescing' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__])
            ->exclude([__DIR__ . '/Resources/', __DIR__ . '/vendor/', __DIR__ . '/.github/'])
    );
```

- [ ] **Step 8: Extend `.gitignore`**

Add these lines if they are not already present:

```
/vendor/
/composer.lock
/.php-cs-fixer.cache
/.phpunit.cache/
```

- [ ] **Step 9: Rebuild the cache and confirm Kimai discovers the bundle**

Run from the Kimai root (`/opt/kimai`), once the devcontainer has been rebuilt with the corrected `BUNDLE_NAME`:

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\
```

Expected: `kimai:reload -n` completes without an exception about a missing or invalid bundle. `debug:container` lists no services yet beyond the install command, since no other classes exist so far, but it must not error.

- [ ] **Step 10: Commit**

```bash
git add composer.json KimaiLexwareSyncBundle.php DependencyInjection/KimaiLexwareSyncExtension.php Resources/config/services.yaml Command/InstallCommand.php phpstan.neon .php-cs-fixer.dist.php .gitignore
git commit -m "Scaffold the KimaiLexwareSyncBundle plugin"
```

---

## Task 2: Doctrine entities, repositories and migration for the tracking tables

**Files:**
- Create: `Enum/OrderConfirmationStatus.php`
- Create: `Entity/TrackedOrderConfirmation.php`
- Create: `Entity/TrackedOrderConfirmationLine.php`
- Create: `Entity/ContactMapping.php`
- Create: `Entity/WebhookEvent.php`
- Create: `Repository/TrackedOrderConfirmationRepository.php`
- Create: `Repository/ContactMappingRepository.php`
- Create: `Repository/WebhookEventRepository.php`
- Create: `Migrations/doctrine_migrations.yaml`
- Create: `Migrations/Version20260904120000.php`

**Interfaces:**
- Consumes: `App\Entity\Customer`, `App\Entity\Project`, `App\Entity\Activity`, `App\Entity\User` from Kimai core.
- Produces: `TrackedOrderConfirmation` with `updateFromLexwarePayload(string $voucherNumber, string $title, \DateTimeImmutable $voucherDate, string $lexwareContactId, string $rawPayload): void`, `getStatus(): OrderConfirmationStatus`, `getLexwareId(): string`, `getTitle(): string`, `getRawPayload(): string`, `setProject(?Project): void`, `setCustomer(?Customer): void`, `markAutomaticallyConverted(): void`, `markManuallyConverted(User $processedBy): void`, `markRejected(User $processedBy): void`. `TrackedOrderConfirmationRepository` with `findByLexwareId(string $lexwareId): ?TrackedOrderConfirmation`, `findPending(): array`, `countPending(): int`, `save(TrackedOrderConfirmation $entity): void`. `ContactMappingRepository` with `findByLexwareContactId(string $lexwareContactId): ?ContactMapping`, `save(ContactMapping $entity): void`. `WebhookEventRepository` with `save(WebhookEvent $entity): void`. Every later task that touches Doctrine consumes these exact names.

- [ ] **Step 1: Write the status enum**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Enum;

enum OrderConfirmationStatus: string
{
    case Pending = 'pending';
    case AutomaticallyConverted = 'automatically_converted';
    case ManuallyConverted = 'manually_converted';
    case Rejected = 'rejected';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    public function isConverted(): bool
    {
        return $this === self::AutomaticallyConverted || $this === self::ManuallyConverted;
    }
}
```

- [ ] **Step 2: Write `Entity/TrackedOrderConfirmation.php`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationStatus;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;

#[ORM\Entity(repositoryClass: TrackedOrderConfirmationRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_order_confirmation')]
class TrackedOrderConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_id', type: 'string', length: 100, unique: true)]
    private string $lexwareId;

    #[ORM\Column(name: 'voucher_number', type: 'string', length: 100)]
    private string $voucherNumber = '';

    #[ORM\Column(name: 'title', type: 'string', length: 255)]
    private string $title = '';

    #[ORM\Column(name: 'voucher_date', type: 'datetime_immutable')]
    private \DateTimeImmutable $voucherDate;

    #[ORM\Column(name: 'lexware_contact_id', type: 'string', length: 100)]
    private string $lexwareContactId = '';

    #[ORM\Column(name: 'raw_payload', type: 'text')]
    private string $rawPayload = '{}';

    #[ORM\Column(name: 'status', type: 'string', length: 30, enumType: OrderConfirmationStatus::class)]
    private OrderConfirmationStatus $status;

    #[ORM\Column(name: 'changed_after_conversion', type: 'boolean')]
    private bool $changedAfterConversion = false;

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'project_id', nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: true, onDelete: 'SET NULL')]
    private ?Customer $customer = null;

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_synchronized_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSynchronizedAt;

    #[ORM\Column(name: 'processed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $processedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'processed_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $processedBy = null;

    /**
     * @var Collection<int, TrackedOrderConfirmationLine>
     */
    #[ORM\OneToMany(targetEntity: TrackedOrderConfirmationLine::class, mappedBy: 'orderConfirmation', cascade: ['persist', 'remove'])]
    private Collection $lines;

    public function __construct(string $lexwareId)
    {
        $this->lexwareId = $lexwareId;
        $this->status = OrderConfirmationStatus::Pending;
        $this->voucherDate = new \DateTimeImmutable();
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSynchronizedAt = new \DateTimeImmutable();
        $this->lines = new ArrayCollection();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getVoucherDate(): \DateTimeImmutable
    {
        return $this->voucherDate;
    }

    public function getLexwareContactId(): string
    {
        return $this->lexwareContactId;
    }

    public function getRawPayload(): string
    {
        return $this->rawPayload;
    }

    public function getStatus(): OrderConfirmationStatus
    {
        return $this->status;
    }

    public function hasChangedAfterConversion(): bool
    {
        return $this->changedAfterConversion;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): void
    {
        $this->project = $project;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): void
    {
        $this->customer = $customer;
    }

    public function getLastSynchronizedAt(): \DateTimeImmutable
    {
        return $this->lastSynchronizedAt;
    }

    public function getProcessedAt(): ?\DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    /**
     * @return Collection<int, TrackedOrderConfirmationLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(TrackedOrderConfirmationLine $line): void
    {
        $this->lines->add($line);
    }

    public function updateFromLexwarePayload(
        string $voucherNumber,
        string $title,
        \DateTimeImmutable $voucherDate,
        string $lexwareContactId,
        string $rawPayload
    ): void {
        if ($this->status->isConverted() && ($this->title !== $title || $this->voucherNumber !== $voucherNumber)) {
            $this->changedAfterConversion = true;
        }

        $this->voucherNumber = $voucherNumber;
        $this->title = $title;
        $this->voucherDate = $voucherDate;
        $this->lexwareContactId = $lexwareContactId;
        $this->rawPayload = $rawPayload;
        $this->lastSynchronizedAt = new \DateTimeImmutable();
    }

    public function markAutomaticallyConverted(): void
    {
        $this->status = OrderConfirmationStatus::AutomaticallyConverted;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function markManuallyConverted(User $processedBy): void
    {
        $this->status = OrderConfirmationStatus::ManuallyConverted;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }

    public function markRejected(User $processedBy): void
    {
        $this->status = OrderConfirmationStatus::Rejected;
        $this->processedAt = new \DateTimeImmutable();
        $this->processedBy = $processedBy;
    }
}
```

This class is deliberately not `final`, unlike every other class in this plan. It is the target of `TrackedOrderConfirmationLine`'s lazily loaded `ManyToOne` association, and Doctrine's proxy generator subclasses a lazily loaded entity to defer loading it; a `final` class cannot be subclassed, so marking it `final` fatals the first time Doctrine needs a reference to an unloaded row. Kimai's own core entities that are `ManyToOne` targets, `Customer`, `Project`, `Activity`, `User`, avoid `final` for the same reason. `TrackedOrderConfirmationLine`, `ContactMapping` and `WebhookEvent` have no incoming association and stay `final`.

- [ ] **Step 3: Write `Entity/TrackedOrderConfirmationLine.php`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Activity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kimai2_ext_lexware_order_confirmation_line')]
final class TrackedOrderConfirmationLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrackedOrderConfirmation::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'order_confirmation_id', nullable: false, onDelete: 'CASCADE')]
    private TrackedOrderConfirmation $orderConfirmation;

    #[ORM\Column(name: 'position', type: 'integer')]
    private int $position;

    #[ORM\Column(name: 'type', type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(name: 'matched', type: 'boolean')]
    private bool $matched;

    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'activity_id', nullable: true, onDelete: 'SET NULL')]
    private ?Activity $activity = null;

    public function __construct(
        TrackedOrderConfirmation $orderConfirmation,
        int $position,
        string $type,
        string $name,
        ?string $description,
        bool $matched
    ) {
        $this->orderConfirmation = $orderConfirmation;
        $this->position = $position;
        $this->type = $type;
        $this->name = $name;
        $this->description = $description;
        $this->matched = $matched;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function isMatched(): bool
    {
        return $this->matched;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    public function setActivity(?Activity $activity): void
    {
        $this->activity = $activity;
    }
}
```

- [ ] **Step 4: Write `Entity/ContactMapping.php`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use App\Entity\Customer;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;

#[ORM\Entity(repositoryClass: ContactMappingRepository::class)]
#[ORM\Table(name: 'kimai2_ext_lexware_contact_mapping')]
final class ContactMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'lexware_contact_id', type: 'string', length: 100, unique: true)]
    private string $lexwareContactId;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false, onDelete: 'CASCADE')]
    private Customer $customer;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $lexwareContactId, Customer $customer)
    {
        $this->lexwareContactId = $lexwareContactId;
        $this->customer = $customer;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getLexwareContactId(): string
    {
        return $this->lexwareContactId;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }
}
```

- [ ] **Step 5: Write `Entity/WebhookEvent.php`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'kimai2_ext_lexware_webhook_event')]
final class WebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 100)]
    private string $eventType;

    #[ORM\Column(name: 'resource_id', type: 'string', length: 100, nullable: true)]
    private ?string $resourceId;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'signature_valid', type: 'boolean')]
    private bool $signatureValid;

    #[ORM\Column(name: 'processed', type: 'boolean')]
    private bool $processed = false;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    public function __construct(string $eventType, ?string $resourceId, bool $signatureValid)
    {
        $this->eventType = $eventType;
        $this->resourceId = $resourceId;
        $this->signatureValid = $signatureValid;
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function markProcessed(): void
    {
        $this->processed = true;
    }

    public function markFailed(string $errorMessage): void
    {
        $this->processed = false;
        $this->errorMessage = $errorMessage;
    }
}
```

- [ ] **Step 6: Write the repositories**

`Repository/TrackedOrderConfirmationRepository.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\OrderConfirmationStatus;

/**
 * @extends ServiceEntityRepository<TrackedOrderConfirmation>
 */
final class TrackedOrderConfirmationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackedOrderConfirmation::class);
    }

    public function findByLexwareId(string $lexwareId): ?TrackedOrderConfirmation
    {
        return $this->findOneBy(['lexwareId' => $lexwareId]);
    }

    /**
     * @return TrackedOrderConfirmation[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => OrderConfirmationStatus::Pending], ['voucherDate' => 'DESC']);
    }

    public function countPending(): int
    {
        return $this->count(['status' => OrderConfirmationStatus::Pending]);
    }

    public function save(TrackedOrderConfirmation $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
```

`Repository/ContactMappingRepository.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;

/**
 * @extends ServiceEntityRepository<ContactMapping>
 */
final class ContactMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMapping::class);
    }

    public function findByLexwareContactId(string $lexwareContactId): ?ContactMapping
    {
        return $this->findOneBy(['lexwareContactId' => $lexwareContactId]);
    }

    public function save(ContactMapping $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
```

`Repository/WebhookEventRepository.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\WebhookEvent;

/**
 * @extends ServiceEntityRepository<WebhookEvent>
 */
final class WebhookEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookEvent::class);
    }

    public function save(WebhookEvent $entity): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($entity);
        $entityManager->flush();
    }
}
```

- [ ] **Step 7: Write the migration configuration**

`Migrations/doctrine_migrations.yaml`:

```yaml
table_storage:
    table_name: 'bundle_migration_lexware_sync'
migrations_paths:
    'KimaiLexwareSyncBundle\Migrations': 'var/plugins/KimaiLexwareSyncBundle/Migrations'
```

- [ ] **Step 8: Write the migration**

`Migrations/Version20260904120000.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiLexwareSyncBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260904120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the KimaiLexwareSync tracking tables';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('kimai2_ext_lexware_order_confirmation')) {
            $table = $schema->createTable('kimai2_ext_lexware_order_confirmation');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('voucher_number', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('voucher_date', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('lexware_contact_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('raw_payload', 'text', ['notnull' => true]);
            $table->addColumn('status', 'string', ['notnull' => true, 'length' => 30]);
            $table->addColumn('changed_after_conversion', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('project_id', 'integer', ['notnull' => false]);
            $table->addColumn('customer_id', 'integer', ['notnull' => false]);
            $table->addColumn('first_seen_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('last_synchronized_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('processed_at', 'datetime_immutable', ['notnull' => false]);
            $table->addColumn('processed_by_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_id']);
            $table->addForeignKeyConstraint('kimai2_projects', ['project_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'SET NULL']);
            $table->addForeignKeyConstraint('kimai2_users', ['processed_by_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_order_confirmation_line')) {
            $table = $schema->createTable('kimai2_ext_lexware_order_confirmation_line');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('order_confirmation_id', 'integer', ['notnull' => true]);
            $table->addColumn('position', 'integer', ['notnull' => true]);
            $table->addColumn('type', 'string', ['notnull' => true, 'length' => 50]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('description', 'text', ['notnull' => false]);
            $table->addColumn('matched', 'boolean', ['notnull' => true]);
            $table->addColumn('activity_id', 'integer', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
            $table->addForeignKeyConstraint('kimai2_ext_lexware_order_confirmation', ['order_confirmation_id'], ['id'], ['onDelete' => 'CASCADE']);
            $table->addForeignKeyConstraint('kimai2_activities', ['activity_id'], ['id'], ['onDelete' => 'SET NULL']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_contact_mapping')) {
            $table = $schema->createTable('kimai2_ext_lexware_contact_mapping');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('lexware_contact_id', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('customer_id', 'integer', ['notnull' => true]);
            $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['lexware_contact_id']);
            $table->addForeignKeyConstraint('kimai2_customers', ['customer_id'], ['id'], ['onDelete' => 'CASCADE']);
        }

        if (!$schema->hasTable('kimai2_ext_lexware_webhook_event')) {
            $table = $schema->createTable('kimai2_ext_lexware_webhook_event');
            $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('event_type', 'string', ['notnull' => true, 'length' => 100]);
            $table->addColumn('resource_id', 'string', ['notnull' => false, 'length' => 100]);
            $table->addColumn('received_at', 'datetime_immutable', ['notnull' => true]);
            $table->addColumn('signature_valid', 'boolean', ['notnull' => true]);
            $table->addColumn('processed', 'boolean', ['notnull' => true, 'default' => false]);
            $table->addColumn('error_message', 'text', ['notnull' => false]);
            $table->setPrimaryKey(['id']);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'kimai2_ext_lexware_order_confirmation_line',
            'kimai2_ext_lexware_order_confirmation',
            'kimai2_ext_lexware_contact_mapping',
            'kimai2_ext_lexware_webhook_event',
        ] as $tableName) {
            if ($schema->hasTable($tableName)) {
                $schema->dropTable($tableName);
            }
        }
    }
}
```

- [ ] **Step 9: Rebuild the cache and install**

```bash
bin/console kimai:reload -n
bin/console kimai:bundle:lexware-sync:install
```

Expected: the install command reports the migration as applied, and `SHOW TABLES LIKE 'kimai2_ext_lexware%'` against the configured database shows all four tables.

- [ ] **Step 10: Commit**

```bash
git add Enum/OrderConfirmationStatus.php Entity/ Repository/ Migrations/
git commit -m "Add the Doctrine entities, repositories and migration for KimaiLexwareSync tracking data"
```

---

## Task 3: Register the triage permission

**Files:**
- Modify: `DependencyInjection/KimaiLexwareSyncExtension.php`

**Interfaces:**
- Produces: the permission string `triage_lexware_sync`, granted by default to `ROLE_SUPER_ADMIN` and `ROLE_ADMIN`. Task 12 checks this permission on the triage controller.

- [ ] **Step 1: Make the extension implement `PrependExtensionInterface`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class KimaiLexwareSyncExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['triage_lexware_sync'],
                    'ROLE_ADMIN' => ['triage_lexware_sync'],
                ],
            ],
        ]);
    }
}
```

- [ ] **Step 2: Rebuild the cache and confirm the permission is registered**

```bash
bin/console kimai:reload -n
```

Then open Kimai's browser interface as a super admin, go to the user role permission screen, and confirm `triage_lexware_sync` appears in the permission list for `ROLE_SUPER_ADMIN` and `ROLE_ADMIN`, already checked.

- [ ] **Step 3: Commit**

```bash
git add DependencyInjection/KimaiLexwareSyncExtension.php
git commit -m "Register the triage_lexware_sync permission"
```

---

## Task 4: Plugin configuration for the system configuration keys

**Files:**
- Create: `Configuration/LexwareSyncConfiguration.php`
- Create: `EventSubscriber/SystemConfigurationSubscriber.php`
- Create: `Resources/translations/system-configuration.en.xlf`
- Create: `Resources/translations/system-configuration.de.xlf`

**Interfaces:**
- Consumes: `App\Configuration\SystemConfiguration::find(string $key): string|int|bool|float|null`.
- Produces: `LexwareSyncConfiguration` with `isAutoConvertEnabled(): bool`, `getTitleRegex(): string`, `isReadLinesEnabled(): bool`, `getLineRegex(): string`, `getReconcileIntervalMinutes(): int`. Task 6, 7, 8 and 9 all read the plugin's settings exclusively through this class, never through `App\Configuration\SystemConfiguration` directly.

- [ ] **Step 1: Write `Configuration/LexwareSyncConfiguration.php`**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

use App\Configuration\SystemConfiguration;

final class LexwareSyncConfiguration
{
    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    public function isAutoConvertEnabled(): bool
    {
        return (bool) ($this->configuration->find('lexware_sync.auto_convert_enabled') ?? false);
    }

    public function getTitleRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.title_regex');

        return \is_string($value) ? $value : '';
    }

    public function isReadLinesEnabled(): bool
    {
        return (bool) ($this->configuration->find('lexware_sync.read_lines_enabled') ?? false);
    }

    public function getLineRegex(): string
    {
        $value = $this->configuration->find('lexware_sync.line_regex');

        return \is_string($value) ? $value : '';
    }

    public function getReconcileIntervalMinutes(): int
    {
        $value = $this->configuration->find('lexware_sync.reconcile_interval_minutes');

        return \is_int($value) && $value > 0 ? $value : 30;
    }
}
```

- [ ] **Step 2: Write the system configuration subscriber**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration as SystemConfigurationModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SystemConfigurationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [SystemConfigurationEvent::class => ['onSystemConfiguration', 200]];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $event->addConfiguration(
            (new SystemConfigurationModel('lexware_sync_configuration'))
                ->setConfiguration([
                    (new Configuration('lexware_sync.auto_convert_enabled'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.title_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.read_lines_enabled'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(CheckboxType::class)
                        ->setRequired(false),
                    (new Configuration('lexware_sync.line_regex'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(TextType::class)
                        ->setRequired(false)
                        ->setConstraints([$this->createRegexConstraint()]),
                    (new Configuration('lexware_sync.reconcile_interval_minutes'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(IntegerType::class)
                        ->setRequired(false),
                ])
        );
    }

    private function createRegexConstraint(): Callback
    {
        return new Callback(function (mixed $value, ExecutionContextInterface $context): void {
            if ($value === null || $value === '') {
                return;
            }

            if (!\is_string($value) || @preg_match($value, '') === false) {
                $context->addViolation('This is not a valid regular expression.');
            }
        });
    }
}
```

- [ ] **Step 3: Write the translation files**

`Resources/translations/system-configuration.en.xlf`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="en" datatype="plaintext" original="system-configuration.en.xlf">
        <body>
            <trans-unit id="lexware_sync.auto_convert_enabled" resname="lexware_sync.auto_convert_enabled">
                <source>lexware_sync.auto_convert_enabled</source>
                <target>Automatically convert a matching order confirmation into a project</target>
            </trans-unit>
            <trans-unit id="lexware_sync.title_regex" resname="lexware_sync.title_regex">
                <source>lexware_sync.title_regex</source>
                <target>Title pattern an order confirmation must match to convert automatically, empty matches every title</target>
            </trans-unit>
            <trans-unit id="lexware_sync.read_lines_enabled" resname="lexware_sync.read_lines_enabled">
                <source>lexware_sync.read_lines_enabled</source>
                <target>Create one activity per matching order confirmation line</target>
            </trans-unit>
            <trans-unit id="lexware_sync.line_regex" resname="lexware_sync.line_regex">
                <source>lexware_sync.line_regex</source>
                <target>Pattern a line's name or description must match to produce an activity, empty matches every line</target>
            </trans-unit>
            <trans-unit id="lexware_sync.reconcile_interval_minutes" resname="lexware_sync.reconcile_interval_minutes">
                <source>lexware_sync.reconcile_interval_minutes</source>
                <target>Minutes between reconciliation polls</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

`Resources/translations/system-configuration.de.xlf`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="de" datatype="plaintext" original="system-configuration.de.xlf">
        <body>
            <trans-unit id="lexware_sync.auto_convert_enabled" resname="lexware_sync.auto_convert_enabled">
                <source>lexware_sync.auto_convert_enabled</source>
                <target>Passende Auftragsbestätigung automatisch in ein Projekt umwandeln</target>
            </trans-unit>
            <trans-unit id="lexware_sync.title_regex" resname="lexware_sync.title_regex">
                <source>lexware_sync.title_regex</source>
                <target>Titelmuster, das eine Auftragsbestätigung für die automatische Umwandlung erfüllen muss, leer bedeutet jeder Titel passt</target>
            </trans-unit>
            <trans-unit id="lexware_sync.read_lines_enabled" resname="lexware_sync.read_lines_enabled">
                <source>lexware_sync.read_lines_enabled</source>
                <target>Für jede passende Position der Auftragsbestätigung eine Aktivität anlegen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.line_regex" resname="lexware_sync.line_regex">
                <source>lexware_sync.line_regex</source>
                <target>Muster, das Name oder Beschreibung einer Position erfüllen muss, um eine Aktivität zu erzeugen, leer bedeutet jede Position passt</target>
            </trans-unit>
            <trans-unit id="lexware_sync.reconcile_interval_minutes" resname="lexware_sync.reconcile_interval_minutes">
                <source>lexware_sync.reconcile_interval_minutes</source>
                <target>Minuten zwischen den Abgleichläufen</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

- [ ] **Step 4: Rebuild the cache and confirm the screen renders**

```bash
bin/console lint:xliff Resources/translations/system-configuration.en.xlf Resources/translations/system-configuration.de.xlf
bin/console kimai:reload -n
```

Then open the System Configuration screen in the browser, confirm the five new fields appear with their labels, save a deliberately invalid regular expression such as `[` into the title pattern field, and confirm the save is rejected with the validation message rather than silently accepted.

- [ ] **Step 5: Commit**

```bash
git add Configuration/LexwareSyncConfiguration.php EventSubscriber/SystemConfigurationSubscriber.php Resources/translations/system-configuration.en.xlf Resources/translations/system-configuration.de.xlf
git commit -m "Add the KimaiLexwareSync system configuration screen"
```

---

## Task 5: Matching rule evaluator

**Files:**
- Create: `Service/MatchingRuleEvaluator.php`
- Create: `Tests/bootstrap.php`
- Create: `phpunit.xml.dist`
- Create: `Tests/Service/MatchingRuleEvaluatorTest.php`

**Interfaces:**
- Produces: `MatchingRuleEvaluator` with `matchesTitle(string $title, string $titleRegex): bool` and `matchesLine(string $lineType, string $lineName, ?string $lineDescription, string $lineRegex): bool`. Task 7 and 8 use these two methods exclusively for rule evaluation, never a raw `preg_match` call of their own.

This task also sets up the plugin's own PHPUnit configuration, since none exists yet in this installation. It is the only task that needs to.

- [ ] **Step 1: Write the failing test**

`Tests/Service/MatchingRuleEvaluatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\MatchingRuleEvaluator;
use PHPUnit\Framework\TestCase;

final class MatchingRuleEvaluatorTest extends TestCase
{
    public function testEmptyTitleRegexMatchesEverything(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesTitle('Auftragsbestätigung', ''));
    }

    public function testTitleRegexMustMatch(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesTitle('Projekt Alpha', '/^Projekt/'));
        self::assertFalse($evaluator->matchesTitle('Auftragsbestätigung', '/^Projekt/'));
    }

    public function testTextTypeLineNeverMatches(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertFalse($evaluator->matchesLine('text', 'irrelevant', 'irrelevant', ''));
    }

    public function testEmptyLineRegexMatchesEveryNonTextLine(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesLine('custom', 'Beratung', null, ''));
    }

    public function testLineRegexChecksNameAndDescription(): void
    {
        $evaluator = new MatchingRuleEvaluator();

        self::assertTrue($evaluator->matchesLine('custom', 'Beratung', null, '/Beratung/'));
        self::assertTrue($evaluator->matchesLine('custom', 'Position', 'enthält Beratung', '/Beratung/'));
        self::assertFalse($evaluator->matchesLine('custom', 'Lieferung', 'Versandkosten', '/Beratung/'));
    }
}
```

`Tests/bootstrap.php`:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';
```

`phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="Tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="KimaiLexwareSyncBundle">
            <directory>Tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/MatchingRuleEvaluatorTest.php
```

Expected: FAIL, `Class "KimaiPlugin\KimaiLexwareSyncBundle\Service\MatchingRuleEvaluator" not found`.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class MatchingRuleEvaluator
{
    public function matchesTitle(string $title, string $titleRegex): bool
    {
        if ($titleRegex === '') {
            return true;
        }

        return preg_match($titleRegex, $title) === 1;
    }

    public function matchesLine(string $lineType, string $lineName, ?string $lineDescription, string $lineRegex): bool
    {
        if ($lineType === 'text') {
            return false;
        }

        if ($lineRegex === '') {
            return true;
        }

        if (preg_match($lineRegex, $lineName) === 1) {
            return true;
        }

        return $lineDescription !== null && preg_match($lineRegex, $lineDescription) === 1;
    }
}
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/MatchingRuleEvaluatorTest.php
```

Expected: PASS, 5 tests, 6 assertions.

- [ ] **Step 5: Commit**

```bash
git add Service/MatchingRuleEvaluator.php Tests/bootstrap.php phpunit.xml.dist Tests/Service/MatchingRuleEvaluatorTest.php
git commit -m "Add the matching rule evaluator with plain PHPUnit tests"
```

---

## Task 6: Lexware API client

**Files:**
- Create: `Service/LexwareApiException.php`
- Create: `Service/LexwareApiClient.php`
- Create: `Resources/config/services.yaml` (modify)
- Create: `Tests/Service/LexwareApiClientTest.php`

**Interfaces:**
- Consumes: `Symfony\Contracts\HttpClient\HttpClientInterface`, autowired from Kimai's own `http_client` service.
- Produces: `LexwareApiClient` with `getOrderConfirmation(string $lexwareId): array` and `listOrderConfirmationVoucherPage(int $page): array`. Task 8 and 9 call exactly these two methods and no others. `getOrderConfirmation()` returns the decoded response documented in the spec's feasibility section: `id`, `voucherNumber`, `voucherDate`, `title`, `address` (with `contactId` and `name`), `lineItems` (each with `type`, `name`, and optionally `description`). `listOrderConfirmationVoucherPage()` returns the Spring style page envelope: `content` (each item with `id`, `voucherNumber`, `updatedDate`), `last`.

This mapping was confirmed directly against the live sandbox account on 2026-09-04 with `GET /v1/order-confirmations/{id}` and `GET /v1/voucherlist?voucherType=orderconfirmation&voucherStatus=...`, not guessed from documentation.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LexwareApiClientTest extends TestCase
{
    public function testGetOrderConfirmationSendsBearerTokenAndDecodesResponse(): void
    {
        $seenRequest = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequest) {
            $seenRequest = [$method, $url, $options];

            return new MockResponse('{"id":"abc","voucherNumber":"AB0001"}', ['http_code' => 200]);
        });

        $client = new LexwareApiClient($httpClient, 'test-key');
        $result = $client->getOrderConfirmation('abc');

        self::assertSame('AB0001', $result['voucherNumber']);
        self::assertSame('GET', $seenRequest[0]);
        self::assertStringContainsString('/v1/order-confirmations/abc', $seenRequest[1]);
        self::assertSame('Bearer test-key', $seenRequest[2]['normalized_headers']['authorization'][0]);
    }

    public function testErrorStatusThrowsException(): void
    {
        $httpClient = new MockHttpClient(fn () => new MockResponse('{"message":"not found"}', ['http_code' => 404]));
        $client = new LexwareApiClient($httpClient, 'test-key');

        $this->expectException(LexwareApiException::class);
        $client->getOrderConfirmation('missing');
    }
}
```

- [ ] **Step 2: Run the test to confirm it fails**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/LexwareApiClientTest.php
```

Expected: FAIL, the classes do not exist yet.

- [ ] **Step 3: Write the exception class**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class LexwareApiException extends \RuntimeException
{
}
```

- [ ] **Step 4: Write the client**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LexwareApiClient
{
    private const BASE_URL = 'https://api.lexware.io';
    private const MINIMUM_INTERVAL_SECONDS = 0.5;
    private const ORDER_CONFIRMATION_STATUSES = 'draft,open,accepted,rejected,voided';

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
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $query = []): array
    {
        $this->pace();

        try {
            $response = $this->httpClient->request($method, self::BASE_URL . $path, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Accept' => 'application/json',
                ],
                'query' => $query,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
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

- [ ] **Step 5: Run the test to confirm it passes**

```bash
../../../vendor/bin/phpunit --bootstrap Tests/bootstrap.php Tests/Service/LexwareApiClientTest.php
```

Expected: PASS, 2 tests.

- [ ] **Step 6: Wire the API key from the environment**

Add to `Resources/config/services.yaml`, inside the existing `services:` key, replacing the blanket `KimaiPlugin\KimaiLexwareSyncBundle\:` resource block with one that also binds the client's scalar argument:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    KimaiPlugin\KimaiLexwareSyncBundle\:
        resource: '../../*'
        exclude:
            - '../../Entity/'
            - '../../Migrations/'
            - '../../Resources/'
            - '../../Tests/'

    KimaiPlugin\KimaiLexwareSyncBundle\Controller\:
        resource: '../../Controller'
        tags: ['controller.service_arguments']

    KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient:
        arguments:
            $apiKey: '%env(LEXWARE_API_KEY)%'
```

Document in `README.md`, under the existing Requirements section, that administrators add `LEXWARE_API_KEY=...` to `.env.local` or the deployment's own secret store; this repository's own `.env` already does this for the development sandbox.

- [ ] **Step 7: Rebuild the cache and confirm the service wires**

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\Service\\LexwareApiClient
```

Expected: the command prints the service definition with the `$apiKey` argument resolved, no missing argument error.

- [ ] **Step 8: Commit**

```bash
git add Service/LexwareApiException.php Service/LexwareApiClient.php Resources/config/services.yaml Tests/Service/LexwareApiClientTest.php README.md
git commit -m "Add the Lexware API client with rate pacing and mocked HTTP client tests"
```

---

## Task 7: Order confirmation processor

**Files:**
- Create: `Service/OrderConfirmationProcessor.php`
- Create: `Service/CustomerCurrencyMismatchException.php`

**Interfaces:**
- Consumes: `App\Customer\CustomerService::createNewCustomer(string $name): Customer` and `saveCustomer(Customer $customer): Customer`; `App\Project\ProjectService::createNewProject(?Customer $customer): Project` and `saveProject(Project $project): Project`; `App\Activity\ActivityService::createNewActivity(?Project $project): Activity` and `saveActivity(Activity $activity): Activity`; `App\Configuration\SystemConfiguration::getThemeColors(): array<string, string>`; `ContactMappingRepository`, `MatchingRuleEvaluator` from earlier tasks.
- Produces: `OrderConfirmationProcessor` with `convert(TrackedOrderConfirmation $orderConfirmation, array $payload, ?User $processedBy, string $lineRegex, bool $readLinesEnabled): void`, throwing `CustomerCurrencyMismatchException` when the resolved customer's currency is not the euro, per the spec's error handling section. Task 8 calls this for automatic conversion and must catch that exception rather than let it escape; Task 12 calls it for manual conversion and must turn it into a visible error instead of a silent failure. `$payload` is the same decoded array `LexwareApiClient::getOrderConfirmation()` returns.

This task touches Doctrine, so it has no automated test; it is verified manually in Step 3.

- [ ] **Step 1: Write the currency mismatch exception**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

final class CustomerCurrencyMismatchException extends \RuntimeException
{
}
```

- [ ] **Step 2: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use App\Activity\ActivityService;
use App\Configuration\SystemConfiguration;
use App\Customer\CustomerService;
use App\Entity\Customer;
use App\Entity\User;
use App\Project\ProjectService;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmationLine;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\ContactMappingRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\ContactMapping;

final class OrderConfirmationProcessor
{
    public function __construct(
        private readonly ContactMappingRepository $contactMappingRepository,
        private readonly CustomerService $customerService,
        private readonly ProjectService $projectService,
        private readonly ActivityService $activityService,
        private readonly SystemConfiguration $systemConfiguration,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function convert(
        TrackedOrderConfirmation $orderConfirmation,
        array $payload,
        ?User $processedBy,
        string $lineRegex,
        bool $readLinesEnabled
    ): void {
        $address = $payload['address'] ?? [];
        $contactId = (string) ($address['contactId'] ?? '');
        $contactName = (string) ($address['name'] ?? $orderConfirmation->getTitle());

        $customer = $this->resolveCustomer($contactId, $contactName);

        $project = $this->projectService->createNewProject($customer);
        $project->setName($orderConfirmation->getVoucherNumber());
        $project->setStart(\DateTime::createFromImmutable($orderConfirmation->getVoucherDate()));
        $project->setColor($this->pickRandomColor());
        $this->projectService->saveProject($project);

        $orderConfirmation->setCustomer($customer);
        $orderConfirmation->setProject($project);

        if ($processedBy !== null) {
            $orderConfirmation->markManuallyConverted($processedBy);
        } else {
            $orderConfirmation->markAutomaticallyConverted();
        }

        if ($readLinesEnabled) {
            $this->convertLines($orderConfirmation, $payload['lineItems'] ?? [], $project, $lineRegex);
        }
    }

    private function resolveCustomer(string $contactId, string $contactName): Customer
    {
        $mapping = $this->contactMappingRepository->findByLexwareContactId($contactId);
        if ($mapping !== null) {
            $customer = $mapping->getCustomer();

            if ($customer->getCurrency() !== Customer::DEFAULT_CURRENCY) {
                throw new CustomerCurrencyMismatchException(\sprintf(
                    'Customer "%s" is mapped to Lexware contact "%s" but uses currency "%s" instead of "%s".',
                    $customer->getName(),
                    $contactId,
                    $customer->getCurrency(),
                    Customer::DEFAULT_CURRENCY,
                ));
            }

            return $customer;
        }

        $customer = $this->customerService->createNewCustomer($contactName);
        $this->customerService->saveCustomer($customer);

        $this->contactMappingRepository->save(new ContactMapping($contactId, $customer));

        return $customer;
    }

    /**
     * @param array<int, array<string, mixed>> $lineItems
     */
    private function convertLines(TrackedOrderConfirmation $orderConfirmation, array $lineItems, $project, string $lineRegex): void
    {
        foreach ($lineItems as $position => $lineItem) {
            $type = (string) ($lineItem['type'] ?? 'custom');
            $name = (string) ($lineItem['name'] ?? '');
            $description = isset($lineItem['description']) ? (string) $lineItem['description'] : null;

            $matched = $this->matchingRuleEvaluator->matchesLine($type, $name, $description, $lineRegex);

            $line = new TrackedOrderConfirmationLine($orderConfirmation, $position, $type, $name, $description, $matched);
            $orderConfirmation->addLine($line);

            if (!$matched) {
                continue;
            }

            $activity = $this->activityService->createNewActivity($project);
            $activity->setName($name);
            $activity->setColor($this->pickRandomColor());
            $this->activityService->saveActivity($activity);

            $line->setActivity($activity);
        }
    }

    private function pickRandomColor(): string
    {
        $colors = array_values($this->systemConfiguration->getThemeColors());
        if (\count($colors) === 0) {
            return '#c0c0c0';
        }

        return $colors[array_rand($colors)];
    }
}
```

- [ ] **Step 3: Wire nothing extra**

`CustomerService`, `ProjectService`, `ActivityService`, `SystemConfiguration` and the repositories are all autowired core or plugin services already registered; no `services.yaml` change is needed.

- [ ] **Step 4: Manual verification**

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\Service\\OrderConfirmationProcessor
```

Expected: the service resolves with no missing argument. Full behavioral verification happens together with Task 8, once a real order confirmation can be run through the synchronizer end to end.

- [ ] **Step 5: Commit**

```bash
git add Service/OrderConfirmationProcessor.php Service/CustomerCurrencyMismatchException.php
git commit -m "Add the order confirmation processor that creates the Kimai customer, project and activities"
```

---

## Task 8: Order confirmation synchronizer

**Files:**
- Create: `Service/OrderConfirmationSynchronizer.php`

**Interfaces:**
- Consumes: `LexwareApiClient::getOrderConfirmation()`, `TrackedOrderConfirmationRepository`, `MatchingRuleEvaluator::matchesTitle()`, `OrderConfirmationProcessor::convert()`, `LexwareSyncConfiguration` from earlier tasks; `Doctrine\ORM\EntityManagerInterface`; `Psr\Log\LoggerInterface`, Kimai's own autowired logger.
- Produces: `OrderConfirmationSynchronizer` with `synchronize(string $lexwareId): void`. Task 9 (the reconciliation command) and Task 11 (the webhook controller) call exactly this one method and nothing else on this class. It never lets `CustomerCurrencyMismatchException` from `OrderConfirmationProcessor` escape; it logs it and leaves the record pending instead, matching the spec's requirement that a currency mismatch produces a visible, refused conversion rather than a crash.

- [ ] **Step 1: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Psr\Log\LoggerInterface;

final class OrderConfirmationSynchronizer
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly MatchingRuleEvaluator $matchingRuleEvaluator,
        private readonly OrderConfirmationProcessor $processor,
        private readonly LexwareSyncConfiguration $configuration,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function synchronize(string $lexwareId): void
    {
        $payload = $this->client->getOrderConfirmation($lexwareId);
        $existing = $this->repository->findByLexwareId($lexwareId);

        if ($existing !== null && isset($payload['updatedDate'])) {
            $remoteUpdatedAt = new \DateTimeImmutable((string) $payload['updatedDate']);
            if ($remoteUpdatedAt <= $existing->getLastSynchronizedAt()) {
                return;
            }
        }

        $this->entityManager->beginTransaction();

        try {
            $orderConfirmation = $existing ?? new TrackedOrderConfirmation($lexwareId);

            $address = $payload['address'] ?? [];

            $orderConfirmation->updateFromLexwarePayload(
                (string) ($payload['voucherNumber'] ?? ''),
                (string) ($payload['title'] ?? ''),
                new \DateTimeImmutable((string) ($payload['voucherDate'] ?? 'now')),
                (string) ($address['contactId'] ?? ''),
                json_encode($payload, \JSON_THROW_ON_ERROR),
            );

            $this->repository->save($orderConfirmation);

            if ($orderConfirmation->getStatus()->isPending()
                && $this->configuration->isAutoConvertEnabled()
                && $this->matchingRuleEvaluator->matchesTitle($orderConfirmation->getTitle(), $this->configuration->getTitleRegex())
            ) {
                try {
                    $this->processor->convert(
                        $orderConfirmation,
                        $payload,
                        null,
                        $this->configuration->getLineRegex(),
                        $this->configuration->isReadLinesEnabled(),
                    );
                    $this->repository->save($orderConfirmation);
                } catch (CustomerCurrencyMismatchException $exception) {
                    $this->logger->error(\sprintf(
                        'Order confirmation %s was not converted automatically: %s',
                        $lexwareId,
                        $exception->getMessage(),
                    ));
                }
            }

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }
    }
}
```

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console debug:container KimaiPlugin\\KimaiLexwareSyncBundle\\Service\\OrderConfirmationSynchronizer
```

Then, with `lexware_sync.auto_convert_enabled` left at its default `false`, write a short throwaway script or use `bin/console debug:autowiring` plus a temporary console command to call `$synchronizer->synchronize('9ad37e93-9b9a-4238-a2fc-5fef3301a4be')` against the real sandbox order confirmation used during design, then check `SELECT * FROM kimai2_ext_lexware_order_confirmation` shows one row with `status = 'pending'`. Delete the throwaway script afterward; Task 9 provides the real, permanent entry point.

- [ ] **Step 3: Commit**

```bash
git add Service/OrderConfirmationSynchronizer.php
git commit -m "Add the order confirmation synchronizer that ties refetching, upserting and conversion together"
```

---

## Task 9: Reconciliation command

**Files:**
- Create: `Command/ReconcileOrderConfirmationsCommand.php`

**Interfaces:**
- Consumes: `LexwareApiClient::listOrderConfirmationVoucherPage()`, `TrackedOrderConfirmationRepository::findByLexwareId()`, `OrderConfirmationSynchronizer::synchronize()`.
- Produces: the console command `kimai:lexware-sync:reconcile`, intended to run on the interval configured through `lexware_sync.reconcile_interval_minutes`, by a cron entry the deploying administrator sets up outside this plugin.

- [ ] **Step 1: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationSynchronizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:reconcile', description: 'Poll Lexware for order confirmations that a webhook delivery might have missed')]
final class ReconcileOrderConfirmationsCommand extends Command
{
    public function __construct(
        private readonly LexwareApiClient $client,
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationSynchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = 0;
        $synchronized = 0;
        $isLastPage = false;

        while (!$isLastPage) {
            $result = $this->client->listOrderConfirmationVoucherPage($page);
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
                    $io->error(\sprintf('Failed to synchronize order confirmation %s: %s', $lexwareId, $exception->getMessage()));
                }
            }

            $isLastPage = (bool) ($result['last'] ?? true);
            $page++;
        }

        $io->success(\sprintf('Reconciled %d order confirmation(s).', $synchronized));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $voucher
     */
    private function needsSynchronization(string $lexwareId, array $voucher): bool
    {
        $existing = $this->repository->findByLexwareId($lexwareId);
        if ($existing === null) {
            return true;
        }

        $updatedDate = $voucher['updatedDate'] ?? null;
        if ($updatedDate === null) {
            return true;
        }

        return $existing->getLastSynchronizedAt() < new \DateTimeImmutable((string) $updatedDate);
    }
}
```

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console kimai:lexware-sync:reconcile
```

Expected: the command completes with a success message, and the sandbox order confirmation used throughout design now has a matching row in `kimai2_ext_lexware_order_confirmation`. Run it a second time immediately afterward and confirm it reports zero newly synchronized records, proving the modification date check skips already current rows.

- [ ] **Step 3: Commit**

```bash
git add Command/ReconcileOrderConfirmationsCommand.php
git commit -m "Add the reconciliation command that polls Lexware as a safety net for missed webhooks"
```

---

## Task 10: Webhook signature verification spike and verifier

**Files:**
- Create: `Service/LexwareWebhookVerifier.php`
- Create: `Tests/Service/LexwareWebhookVerifierTest.php` (only once the spike below confirms the mechanism; see Step 4)

**Interfaces:**
- Produces: `LexwareWebhookVerifier` with `verify(string $rawBody, array $headers): bool`. Task 11 calls exactly this method, passing the raw request body and the request's header bag as a plain associative array, and trusts its boolean result completely.

This is the manual spike the spec's testing strategy section requires before any code enforces a signature. It needs the user's participation for one step, since it involves creating or editing an order confirmation in the real Lexware account.

- [ ] **Step 1: Register a real event subscription pointed at a capture endpoint**

Open `https://webhook.site` in a browser and copy the unique URL it assigns. Then, using the sandbox key already in `.env`:

```bash
source .env
curl -s -X POST \
  -H "Authorization: Bearer $LEXWARE_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"eventType":"order-confirmation.changed","callbackUrl":"<the webhook.site URL>"}' \
  https://api.lexware.io/v1/event-subscriptions
```

Expected: a `200` or `201` response with an `id`, confirming the subscription was created.

- [ ] **Step 2: Ask the user to trigger a real delivery**

Ask the user to open the Lexware web interface now, open the order confirmation `AB0001` used throughout this project's design, and save it again with no changes, or make a small edit and save it. This is the one step in this whole plan that only a human can perform, since it requires acting inside Lexware's own interface, not the API.

- [ ] **Step 3: Inspect the captured delivery**

Reload the webhook.site page and inspect the captured request. Record, in a short note appended to this plan file or the spec's open items section:

- The exact header name carrying the signature (the third party client research during design pointed at an asymmetric, public key based scheme rather than a shared secret, so look for a header naming a signature and, separately, a header or field identifying which key signed it).
- Whether the payload is the full resource or a thin notification requiring a refetch, confirming or correcting the spec's assumption that it is always treated as the latter regardless.
- Whether Lexware retries a failed delivery, and on what schedule, if the capture tool exposes that.

- [ ] **Step 4: Write the verifier using what was found**

The exact shape depends entirely on Step 3's findings, so this is deliberately the last step in this task rather than pre-written. Implement `LexwareWebhookVerifier::verify()` to fetch Lexware's public key once, cache it for the process lifetime, and verify the signature header found in Step 3 against the raw body using that key, returning `false` for a missing header, an unparseable signature, or a key mismatch, never throwing for those cases. Once implemented, write `Tests/Service/LexwareWebhookVerifierTest.php` covering a valid signature, a tampered body, and a missing header, following the same plain PHPUnit pattern as Task 5 and Task 6, using a fixed key pair generated for the test rather than the real Lexware key.

- [ ] **Step 5: Delete the capture subscription**

```bash
source .env
curl -s -X DELETE -H "Authorization: Bearer $LEXWARE_API_KEY" https://api.lexware.io/v1/event-subscriptions/<the id from Step 1>
```

This avoids leaving a stale subscription pointed at a throwaway inspection tool once Task 11 registers the real one.

- [ ] **Step 6: Commit**

```bash
git add Service/LexwareWebhookVerifier.php Tests/Service/LexwareWebhookVerifierTest.php docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md
git commit -m "Add the webhook signature verifier, confirmed against a real Lexware delivery"
```

---

## Task 11: Webhook controller

**Files:**
- Create: `Controller/LexwareWebhookController.php`
- Create: `Resources/config/routes.yaml`

**Interfaces:**
- Consumes: `LexwareWebhookVerifier::verify()`, `WebhookEventRepository::save()`, `OrderConfirmationSynchronizer::synchronize()`.
- Produces: the route `lexware_sync_webhook_order_confirmation`, `POST /webhook/lexware/order-confirmation`, the URL registered as the real callback in Task 10's Step 1 pattern, this time permanently.

- [ ] **Step 1: Write `Resources/config/routes.yaml`**

```yaml
lexware_sync_webhook:
    resource: '@KimaiLexwareSyncBundle/Controller/LexwareWebhookController.php'
    type: attribute
```

- [ ] **Step 2: Write the controller**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use KimaiPlugin\KimaiLexwareSyncBundle\Entity\WebhookEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\WebhookEventRepository;
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
        private readonly OrderConfirmationSynchronizer $synchronizer,
    ) {
    }

    #[Route(path: '/order-confirmation', name: 'lexware_sync_webhook_order_confirmation', methods: ['POST'])]
    public function orderConfirmation(Request $request): Response
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
            $this->synchronizer->synchronize($resourceId);
            $webhookEvent->markProcessed();
        } catch (\Throwable $exception) {
            $webhookEvent->markFailed($exception->getMessage());
        }

        $this->webhookEventRepository->save($webhookEvent);

        return new JsonResponse(['message' => 'Accepted']);
    }
}
```

The exact key read from the decoded body for the resource identifier depends on what Task 10's spike found the real payload to contain; adjust the `resourceId` extraction above to match that shape before this task is considered complete. The response is still returned successfully to Lexware even when synchronization fails, since the webhook event record and the reconciliation poll are the recovery path, not a Lexware side retry this plugin depends on.

- [ ] **Step 3: Register the real event subscription**

```bash
source .env
curl -s -X POST \
  -H "Authorization: Bearer $LEXWARE_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"eventType":"order-confirmation.changed","callbackUrl":"https://<the real public host>/webhook/lexware/order-confirmation"}' \
  https://api.lexware.io/v1/event-subscriptions
```

This step only fully succeeds once the public HTTPS endpoint precondition from the spec's open items section is met. Until then, register it against a placeholder host and revisit once the real one exists; the reconciliation command from Task 9 keeps milestone one fully functional in the meantime.

- [ ] **Step 4: Rebuild the cache and confirm the route is wired**

```bash
bin/console kimai:reload -n
bin/console debug:router | grep lexware_sync_webhook
```

Expected: the route appears with `POST` as its method.

- [ ] **Step 5: Exercise it by hand**

```bash
curl -s -X POST -H "Content-Type: application/json" -d '{}' https://<host>/webhook/lexware/order-confirmation
```

Expected: `401` with the invalid signature message, and a new row in `kimai2_ext_lexware_webhook_event` with `signature_valid = 0`.

- [ ] **Step 6: Commit**

```bash
git add Controller/LexwareWebhookController.php Resources/config/routes.yaml
git commit -m "Add the webhook controller for Lexware order confirmation callbacks"
```

---

## Task 12: Triage screen

**Files:**
- Create: `Controller/TriageController.php`
- Modify: `Resources/config/routes.yaml`
- Create: `Resources/views/triage/index.html.twig`
- Create: `EventSubscriber/TriageActionSubscriber.php`
- Create: `Resources/translations/messages.en.xlf`
- Create: `Resources/translations/messages.de.xlf`

**Interfaces:**
- Consumes: `TrackedOrderConfirmationRepository::findPending()`, `countPending()`; `OrderConfirmationProcessor::convert()`; `LexwareSyncConfiguration`.
- Produces: the routes `lexware_sync_triage`, `lexware_sync_triage_convert`, `lexware_sync_triage_reject`, gated by the `triage_lexware_sync` permission from Task 3.

- [ ] **Step 1: Extend `Resources/config/routes.yaml`**

```yaml
lexware_sync_webhook:
    resource: '@KimaiLexwareSyncBundle/Controller/LexwareWebhookController.php'
    type: attribute

lexware_sync_triage:
    resource: '@KimaiLexwareSyncBundle/Controller/TriageController.php'
    type: attribute
    prefix: /{_locale}
    requirements:
        _locale: '%app_locales%'
    defaults:
        _locale: '%locale%'
```

- [ ] **Step 2: Write the controller**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\CustomerCurrencyMismatchException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/lexware-sync/triage')]
#[IsGranted('triage_lexware_sync')]
final class TriageController extends AbstractController
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly OrderConfirmationProcessor $processor,
        private readonly LexwareSyncConfiguration $configuration,
    ) {
    }

    #[Route(path: '', name: 'lexware_sync_triage', methods: ['GET'])]
    public function index(): Response
    {
        $page = new PageSetup('lexware_sync.triage.title');
        $page->setTranslationDomain('messages');

        return $this->render('@KimaiLexwareSync/triage/index.html.twig', [
            'page_setup' => $page,
            'orderConfirmations' => $this->repository->findPending(),
        ]);
    }

    #[Route(path: '/{id}/convert', name: 'lexware_sync_triage_convert', methods: ['POST'])]
    public function convert(int $id): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation !== null) {
            $payload = json_decode($orderConfirmation->getRawPayload(), true);

            try {
                $this->processor->convert(
                    $orderConfirmation,
                    \is_array($payload) ? $payload : [],
                    $this->getUser(),
                    $this->configuration->getLineRegex(),
                    $this->configuration->isReadLinesEnabled(),
                );
                $this->repository->save($orderConfirmation);
            } catch (CustomerCurrencyMismatchException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('lexware_sync_triage');
    }

    #[Route(path: '/{id}/reject', name: 'lexware_sync_triage_reject', methods: ['POST'])]
    public function reject(int $id): RedirectResponse
    {
        $orderConfirmation = $this->repository->find($id);
        if ($orderConfirmation !== null) {
            $orderConfirmation->markRejected($this->getUser());
            $this->repository->save($orderConfirmation);
        }

        return $this->redirectToRoute('lexware_sync_triage');
    }
}
```

`$this->getUser()` returns `App\Entity\User` under Kimai's own security configuration; the manual conversion path from Task 7's interface expects exactly that type.

- [ ] **Step 3: Write the template**

```twig
{% extends 'base.html.twig' %}

{% block main %}
    {% embed '@theme/embeds/card.html.twig' %}
        {% block box_title %}{{ 'lexware_sync.triage.title'|trans }}{% endblock %}
        {% block box_body %}
            <table class="table">
                <thead>
                    <tr>
                        <th>{{ 'lexware_sync.triage.voucher_number'|trans }}</th>
                        <th>{{ 'lexware_sync.triage.title_column'|trans }}</th>
                        <th>{{ 'lexware_sync.triage.voucher_date'|trans }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {% for orderConfirmation in orderConfirmations %}
                        <tr>
                            <td>{{ orderConfirmation.voucherNumber }}</td>
                            <td>{{ orderConfirmation.title }}</td>
                            <td>{{ orderConfirmation.voucherDate|date('Y-m-d') }}</td>
                            <td>
                                <form method="post" action="{{ path('lexware_sync_triage_convert', {id: orderConfirmation.id}) }}" style="display: inline">
                                    <button type="submit" class="btn btn-sm btn-primary">{{ 'lexware_sync.triage.convert'|trans }}</button>
                                </form>
                                <form method="post" action="{{ path('lexware_sync_triage_reject', {id: orderConfirmation.id}) }}" style="display: inline">
                                    <button type="submit" class="btn btn-sm btn-secondary">{{ 'lexware_sync.triage.reject'|trans }}</button>
                                </form>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        {% endblock %}
    {% endembed %}
{% endblock %}
```

- [ ] **Step 4: Write the action subscriber that adds the button to the project overview**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TriageActionSubscriber extends AbstractActionsSubscriber
{
    public function __construct(
        private readonly TrackedOrderConfirmationRepository $repository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getActionName(): string
    {
        return 'projects';
    }

    public function onActions(PageActionsEvent $event): void
    {
        if (!$this->isGranted('triage_lexware_sync')) {
            return;
        }

        $pendingCount = $this->repository->countPending();
        $label = $this->translator->trans('lexware_sync.triage.action') . ' (' . $pendingCount . ')';

        $event->addAction('lexware_sync_triage', [
            'url' => $this->path('lexware_sync_triage'),
            'class' => '',
            'title' => $label,
            'icon' => 'fas fa-file-import',
        ]);
    }
}
```

- [ ] **Step 5: Write the translation files**

`Resources/translations/messages.en.xlf`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="en" datatype="plaintext" original="messages.en.xlf">
        <body>
            <trans-unit id="lexware_sync.triage.title" resname="lexware_sync.triage.title">
                <source>lexware_sync.triage.title</source>
                <target>Pending order confirmations</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.action" resname="lexware_sync.triage.action">
                <source>lexware_sync.triage.action</source>
                <target>Pending order confirmations</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.voucher_number" resname="lexware_sync.triage.voucher_number">
                <source>lexware_sync.triage.voucher_number</source>
                <target>Voucher number</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.title_column" resname="lexware_sync.triage.title_column">
                <source>lexware_sync.triage.title_column</source>
                <target>Title</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.voucher_date" resname="lexware_sync.triage.voucher_date">
                <source>lexware_sync.triage.voucher_date</source>
                <target>Date</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.convert" resname="lexware_sync.triage.convert">
                <source>lexware_sync.triage.convert</source>
                <target>Convert to project</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.reject" resname="lexware_sync.triage.reject">
                <source>lexware_sync.triage.reject</source>
                <target>Reject</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

`Resources/translations/messages.de.xlf`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="de" datatype="plaintext" original="messages.de.xlf">
        <body>
            <trans-unit id="lexware_sync.triage.title" resname="lexware_sync.triage.title">
                <source>lexware_sync.triage.title</source>
                <target>Offene Auftragsbestätigungen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.action" resname="lexware_sync.triage.action">
                <source>lexware_sync.triage.action</source>
                <target>Offene Auftragsbestätigungen</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.voucher_number" resname="lexware_sync.triage.voucher_number">
                <source>lexware_sync.triage.voucher_number</source>
                <target>Belegnummer</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.title_column" resname="lexware_sync.triage.title_column">
                <source>lexware_sync.triage.title_column</source>
                <target>Titel</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.voucher_date" resname="lexware_sync.triage.voucher_date">
                <source>lexware_sync.triage.voucher_date</source>
                <target>Datum</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.convert" resname="lexware_sync.triage.convert">
                <source>lexware_sync.triage.convert</source>
                <target>In Projekt umwandeln</target>
            </trans-unit>
            <trans-unit id="lexware_sync.triage.reject" resname="lexware_sync.triage.reject">
                <source>lexware_sync.triage.reject</source>
                <target>Ablehnen</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

- [ ] **Step 6: Rebuild the cache and exercise the screen**

```bash
bin/console lint:xliff Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
bin/console kimai:reload -n
bin/console debug:router | grep lexware_sync_triage
```

Then, logged in as a user with `triage_lexware_sync`, open the project overview, confirm the new button shows the current pending count, open it, and convert or reject the sandbox order confirmation created in Task 8 or 9. Confirm the resulting Kimai project appears under Projects with the expected name and start date, and that the row disappears from the triage list either way.

- [ ] **Step 7: Commit**

```bash
git add Controller/TriageController.php Resources/config/routes.yaml Resources/views/triage/index.html.twig EventSubscriber/TriageActionSubscriber.php Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
git commit -m "Add the manual triage screen for pending order confirmations"
```

---

## Task 13: Project list indicator for converted projects

**Files:**
- Create: `EventSubscriber/ProjectDetailSubscriber.php`
- Modify: `Resources/translations/messages.en.xlf`
- Modify: `Resources/translations/messages.de.xlf`

**Interfaces:**
- Consumes: `App\Event\ProjectDetailControllerEvent`, `TrackedOrderConfirmationRepository`.
- Produces: nothing further tasks depend on; this is the spec's closing visual detail.

- [ ] **Step 1: Write the subscriber**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber;

use App\Event\ProjectDetailControllerEvent;
use KimaiPlugin\KimaiLexwareSyncBundle\Repository\TrackedOrderConfirmationRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ProjectDetailSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TrackedOrderConfirmationRepository $repository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProjectDetailControllerEvent::class => ['onProjectDetail', 100]];
    }

    public function onProjectDetail(ProjectDetailControllerEvent $event): void
    {
        $orderConfirmation = $this->repository->findOneBy(['project' => $event->getProject()]);
        if ($orderConfirmation === null) {
            return;
        }

        $event->getController()->addInfoBox(
            'lexware_sync.origin',
            $orderConfirmation->getVoucherNumber(),
            $orderConfirmation->hasChangedAfterConversion() ? 'fas fa-exclamation-triangle text-warning' : 'fas fa-file-invoice'
        );
    }
}
```

`ProjectDetailControllerEvent`'s exact info box method name and signature carries no backward compatibility promise; before writing this, run `grep -n "class ProjectDetailControllerEvent" -A 30 ../../../src/Event/ProjectDetailControllerEvent.php` from the plugin root and adjust the call in Step 1 to whatever method that class actually exposes in this installation, rather than assuming `addInfoBox()` is correct.

- [ ] **Step 2: Add the translation entries**

Add to both `Resources/translations/messages.en.xlf` and `Resources/translations/messages.de.xlf`, inside the existing `<body>` element:

English:

```xml
            <trans-unit id="lexware_sync.origin" resname="lexware_sync.origin">
                <source>lexware_sync.origin</source>
                <target>Lexware order confirmation</target>
            </trans-unit>
```

German:

```xml
            <trans-unit id="lexware_sync.origin" resname="lexware_sync.origin">
                <source>lexware_sync.origin</source>
                <target>Lexware Auftragsbestätigung</target>
            </trans-unit>
```

- [ ] **Step 3: Rebuild the cache and exercise it**

```bash
bin/console lint:xliff Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
bin/console kimai:reload -n
```

Open the project created in Task 12's verification step in the browser and confirm the voucher number now shows on its detail page.

- [ ] **Step 4: Commit**

```bash
git add EventSubscriber/ProjectDetailSubscriber.php Resources/translations/messages.en.xlf Resources/translations/messages.de.xlf
git commit -m "Show the originating order confirmation on a converted project's detail page"
```

---

## Task 14: Lexware API key expiry health check

**Files:**
- Create: `Command/CheckLexwareApiKeyCommand.php`

**Interfaces:**
- Consumes: `LexwareApiClient::listOrderConfirmationVoucherPage()` as a cheap, already existing call to prove the key still authenticates.
- Produces: the console command `kimai:lexware-sync:check-api-key`, intended to run weekly by a cron entry the deploying administrator sets up, matching the spec's requirement for a health check well before the key's twenty four month expiry goes unnoticed.

- [ ] **Step 1: Write the command**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

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

            return Command::FAILURE;
        }

        $io->success('The Lexware API key still authenticates.');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Manual verification**

```bash
bin/console kimai:reload -n
bin/console kimai:lexware-sync:check-api-key
```

Expected: success against the real sandbox key. To confirm the failure path, temporarily set `LEXWARE_API_KEY` to an invalid value in the environment, run the command again, confirm it reports failure and logs an error, then restore the real key.

- [ ] **Step 3: Commit**

```bash
git add Command/CheckLexwareApiKeyCommand.php
git commit -m "Add the weekly Lexware API key expiry health check"
```

---

## After this plan

Milestone one is complete once all fourteen tasks are committed and the manual verification steps have been exercised once end to end against the real sandbox account: an order confirmation created or edited in Lexware, picked up either by the webhook or by the reconciliation command, converted into a Kimai project either automatically or through the triage screen, with the correct customer, activities and visual indicator. Milestone two, invoice ingestion and timesheet assignment, gets its own brainstorming and specification pass at that point, per the design spec's section 12.
