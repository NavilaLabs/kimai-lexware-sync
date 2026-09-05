# Plugin skeleton — copy-paste files

Verified against Kimai 2.65.0, `kimai/DemoBundle` and the community plugins ApprovalBundle,
CustomCSSBundle, ImportBundle, EasyBackupBundle.

Throughout, replace `Foo`/`FooBundle`/`foo` with your plugin's name. The directory must end in
`Bundle`, the class inside must match it, and every file path must mirror the namespace below
`var/plugins/` — Kimai's PSR-4 map is `KimaiPlugin\` → `var/plugins/`.

## Contents

- [Minimal plugin](#minimal-plugin) — the four files nothing works without
- [composer.json](#composerjson)
- [Bundle class](#bundle-class)
- [Extension](#extension)
- [services.yaml](#servicesyaml)
- [Optional: bundle configuration](#optional-bundle-configuration)
- [Optional: controllers and routes](#optional-controllers-and-routes)
- [Optional: Doctrine entities and migrations](#optional-doctrine-entities-and-migrations)
- [Optional: install command](#optional-install-command)
- [Optional: console command](#optional-console-command)
- [Optional: translations](#optional-translations)
- [Optional: public assets](#optional-public-assets)
- [Tooling: PHPStan, CS-Fixer, CI](#tooling)
- [README and CHANGELOG](#readme-and-changelog)

## Minimal plugin

```
var/plugins/FooBundle/
├── composer.json
├── FooBundle.php
├── DependencyInjection/
│   └── FooExtension.php
└── Resources/
    └── config/
        └── services.yaml
```

That is enough for a plugin that only registers services and event subscribers. Add the optional
pieces below when you need them.

## composer.json

`extra.kimai.require` and `extra.kimai.name` are read by `App\Plugin\PluginMetadata` at boot and are
mandatory. `require` is an integer `VERSION_ID` — `major * 10000 + minor * 100 + patch`, so 2.65.0 is
`26500`. Set it to the lowest version you tested. The `autoload` block is only used by your own dev
tooling; Kimai never reads it. There is deliberately no runtime `require` section — plugins cannot
ship dependencies.

```json
{
    "name": "acme/foo-bundle",
    "description": "What this plugin does, one sentence",
    "homepage": "https://github.com/acme/FooBundle",
    "type": "kimai-plugin",
    "version": "1.0.0",
    "keywords": ["kimai", "kimai-plugin"],
    "license": "MIT",
    "authors": [
        {
            "name": "Your Name",
            "email": "you@example.com"
        }
    ],
    "extra": {
        "kimai": {
            "require": 26500,
            "name": "Foo"
        }
    },
    "autoload": {
        "psr-4": {
            "KimaiPlugin\\FooBundle\\": ""
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
        "codestyle-check": "vendor/bin/php-cs-fixer fix --dry-run --verbose --using-cache=no --show-progress=none --format=checkstyle",
        "phpstan": "vendor/bin/phpstan analyse . --configuration=phpstan.neon",
        "linting": [
            "composer validate --strict --no-check-version",
            "@codestyle-check",
            "@phpstan"
        ]
    },
    "require-dev": {
        "friendsofphp/php-cs-fixer": "^3.0",
        "kimai/kimai": "dev-main",
        "phpstan/phpstan": "^2.0",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/phpstan-doctrine": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpstan/phpstan-symfony": "^2.0"
    }
}
```

## Bundle class

`var/plugins/FooBundle/FooBundle.php`. `PluginInterface` carries `#[AutoconfigureTag]`, which is how
`PluginManager` finds your plugin for the admin plugin screen. Without it the Kernel throws.

```php
<?php

namespace KimaiPlugin\FooBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class FooBundle extends Bundle implements PluginInterface
{
}
```

## Extension

`var/plugins/FooBundle/DependencyInjection/FooExtension.php`. Symfony derives the class name from the
bundle name (`FooBundle` → `FooExtension`) and the config alias from that (`foo`).

Minimal version — loads services, nothing else:

```php
<?php

namespace KimaiPlugin\FooBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class FooExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }
}
```

Full version — own configuration tree plus permissions and template directories. Extend
`App\Plugin\AbstractPluginExtension` (not Symfony's `Extension`) whenever you call
`registerBundleConfiguration()`; that is what publishes your values into `kimai.bundles.config` so
the System Configuration screen can display and persist them.

```php
<?php

namespace KimaiPlugin\FooBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;

class FooExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->registerBundleConfiguration($container, $config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['view_foo', 'edit_foo'],
                    'ROLE_TEAMLEAD' => ['view_foo'],
                ],
            ],
            // only if you ship invoice templates
            'invoice' => [
                'documents' => ['var/plugins/FooBundle/Resources/invoices/'],
            ],
        ]);
    }
}
```

## services.yaml

`var/plugins/FooBundle/Resources/config/services.yaml`. Kimai runs with strict autowiring, so scalar
arguments need an explicit bind or argument. Exclude anything that is not a service — Doctrine
entities and migrations especially, since instantiating them as services fails or wastes compile
time.

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false
        bind:
            $dataDirectory: "%kimai.data_dir%"

    KimaiPlugin\FooBundle\:
        resource: '../../*'
        exclude:
            - '../../Entity/'
            - '../../Migrations/'
            - '../../Resources/'

    KimaiPlugin\FooBundle\Controller\:
        resource: '../../Controller'
        tags: ['controller.service_arguments']

    # only when you expose API endpoints
    KimaiPlugin\FooBundle\API\:
        resource: '../../API'
        tags: ['controller.service_arguments']
```

## Optional: bundle configuration

`var/plugins/FooBundle/DependencyInjection/Configuration.php`. The tree root must equal the extension
alias (`foo`). Administrators can override these in `config/packages/local.yaml`, and
`registerBundleConfiguration()` makes them available to the System Configuration screen.

```php
<?php

namespace KimaiPlugin\FooBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('foo');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('api_url')->defaultValue('https://api.example.com')->end()
                ->booleanNode('enabled')->defaultFalse()->end()
            ->end()
        ->end();

        return $treeBuilder;
    }
}
```

Read the values back through Kimai's configuration service rather than injecting parameters, so that
changes made in the UI take effect:

```php
<?php

namespace KimaiPlugin\FooBundle\Configuration;

use App\Configuration\SystemConfiguration;

final class FooConfiguration
{
    public function __construct(private SystemConfiguration $configuration)
    {
    }

    public function getApiUrl(): string
    {
        $value = $this->configuration->find('foo.api_url');

        return \is_string($value) ? $value : '';
    }
}
```

## Optional: controllers and routes

`var/plugins/FooBundle/Resources/config/routes.yaml` — this file is the only thing Kimai imports
automatically. `type: attribute` is required since 2.1; older examples using `annotation` silently
load nothing.

```yaml
controllers:
    resource: '@FooBundle/Controller/'
    type: attribute
    prefix: /{_locale}
    requirements:
        _locale: '%app_locales%'
    defaults:
        _locale: '%locale%'

foo.api:
    resource: '@FooBundle/API/'
    type: attribute
    prefix: /api
```

`var/plugins/FooBundle/Controller/FooController.php`. Extending `App\Controller\AbstractController`
gives you Kimai's helpers (`getDateTimeFactory()`, `createFormForGetRequest()`); `PageSetup` renders
the page title and action bar so your screen matches the rest of the UI.

```php
<?php

namespace KimaiPlugin\FooBundle\Controller;

use App\Controller\AbstractController;
use App\Utils\PageSetup;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/foo')]
#[IsGranted('view_foo')]
final class FooController extends AbstractController
{
    #[Route(path: '', name: 'foo', methods: ['GET'])]
    public function index(): Response
    {
        $page = new PageSetup('foo.title');
        $page->setTranslationDomain('messages');

        return $this->render('@Foo/index.html.twig', [
            'page_setup' => $page,
        ]);
    }
}
```

Templates live in `Resources/views/` and are addressed as `@Foo/...` — the namespace is the bundle
name without the `Bundle` suffix.

```twig
{% extends 'base.html.twig' %}
{% import "macros/widgets.html.twig" as widgets %}

{% block main %}
    {% embed '@theme/embeds/card.html.twig' %}
        {% block box_title %}{{ 'foo.title'|trans }}{% endblock %}
        {% block box_body %}
            <p>Hello from FooBundle</p>
        {% endblock %}
    {% endembed %}
{% endblock %}
```

## Optional: Doctrine entities and migrations

Entities in `Entity/` are picked up automatically — Kimai's default entity manager runs with
`auto_mapping: true`, so any registered bundle with an `Entity/` directory and attribute mapping is
mapped without extra configuration. The schema is *not* created automatically; that is what the
migration below is for.

Prefix tables with `kimai2_ext_` and never modify core tables from a migration.

```php
<?php

namespace KimaiPlugin\FooBundle\Entity;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\FooBundle\Repository\FooRepository;

#[ORM\Entity(repositoryClass: FooRepository::class)]
#[ORM\Table(name: 'kimai2_ext_foo')]
class Foo
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 100)]
    private string $externalId = '';

    public function getId(): ?int
    {
        return $this->id;
    }
    // ... getters and setters; do not return $this, 3.0 drops fluent setters
}
```

Plugins keep their own migration table so core updates and plugin updates never collide.
`var/plugins/FooBundle/Migrations/doctrine_migrations.yaml`:

```yaml
# Generate a new migration:
#   bin/console doctrine:migrations:diff --em=default --configuration=var/plugins/FooBundle/Migrations/doctrine_migrations.yaml
# Apply:
#   bin/console kimai:bundle:foo:install

table_storage:
    table_name: 'bundle_migration_foo'
migrations_paths:
    'FooBundle\Migrations': 'var/plugins/FooBundle/Migrations'
```

Note the migration namespace is `FooBundle\Migrations`, not `KimaiPlugin\FooBundle\Migrations` — that
is the convention Doctrine's config uses here and the classes declare it literally. Extend
`App\Doctrine\AbstractMigration` and guard every operation so re-running is safe:

```php
<?php

namespace FooBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the foo table';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_foo')) {
            return;
        }

        $table = $schema->createTable('kimai2_ext_foo');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('external_id', 'string', ['notnull' => true, 'length' => 100]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['external_id']);
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('kimai2_ext_foo')) {
            $schema->dropTable('kimai2_ext_foo');
        }
    }
}
```

`kimai:plugins --install` (used by `./kimai.sh plugins`) also discovers `migrations/doctrine_migrations.yaml`
or `Migrations/doctrine_migrations.yaml` on its own, so migrations run during a scripted update even
if the administrator forgets your install command.

## Optional: install command

`var/plugins/FooBundle/Command/InstallCommand.php`. Gives users the documented
`bin/console kimai:bundle:foo:install`. Return `true` from `hasAssets()` if you ship
`Resources/public/`, which makes the command run `assets:install`.

```php
<?php

namespace KimaiPlugin\FooBundle\Command;

use App\Command\AbstractBundleInstallerCommand;

final class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'foo';
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

## Optional: console command

Ordinary Symfony commands are autoconfigured. Useful for scheduled work such as syncing with an
external system via cron.

```php
<?php

namespace KimaiPlugin\FooBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:foo:sync', description: 'Synchronise data with Foo')]
final class SyncCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->success('Done');

        return Command::SUCCESS;
    }
}
```

## Optional: translations

`var/plugins/FooBundle/Resources/translations/messages.en.xlf`. The `resname` attribute is what
Kimai looks up; `bin/console kimai:translations --resname` adds it for you, and
`bin/console lint:xliff` (run automatically by `kimai:reload`) rejects malformed files.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
    <file source-language="en" target-language="en" datatype="plaintext" original="messages.en.xlf">
        <body>
            <trans-unit id="foo.title" resname="foo.title">
                <source>foo.title</source>
                <target>Foo</target>
            </trans-unit>
        </body>
    </file>
</xliff>
```

Use the domain that matches the context: `messages` for UI, `system-configuration` for settings
labels, `actions` for table dropdowns, `validators` for constraint messages,
`invoice-calculator` / `invoice-numbergenerator` for those extension points.

## Optional: public assets

Put files in `Resources/public/`, set `hasAssets(): true` in the install command, and reference them
with Twig's `asset()` under `bundles/foo/...`. For a single stylesheet or script, a `ThemeEvent`
subscriber that echoes inline CSS/JS is simpler and needs no asset installation step.

## Tooling

`phpstan.neon` — mirrors the DemoBundle's configuration (level 9 with `kimai/kimai: dev-main`
installed as a dev dependency, so core classes resolve):

```neon
includes:
  - %rootDir%/../phpstan-symfony/extension.neon
  - %rootDir%/../phpstan-symfony/rules.neon
  - %rootDir%/../phpstan-doctrine/extension.neon
  - %rootDir%/../phpstan-doctrine/rules.neon
  - %rootDir%/../phpstan-deprecation-rules/rules.neon
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

`.php-cs-fixer.dist.php` — keep it minimal unless you are contributing to a plugin that already has
one; Kimai's own ruleset is long and its exact contents matter less than being consistent:

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

`.github/workflows/linting.yaml`:

```yaml
name: CI
on:
    pull_request: null
    push:
        branches: [main]
jobs:
    linting:
        runs-on: ubuntu-latest
        strategy:
            matrix:
                php: ['8.2', '8.3', '8.4']
        name: Linting - PHP ${{ matrix.php }}
        steps:
            - uses: actions/checkout@v4
            - uses: shivammathur/setup-php@v2
              with:
                  php-version: ${{ matrix.php }}
                  coverage: none
                  extensions: intl
            - run: composer install --no-progress
            - run: composer validate --strict --no-check-version
            - run: composer codestyle-check
            - run: composer phpstan
```

`.gitignore`:

```
/vendor/
/composer.lock
/.php-cs-fixer.cache
```

## README and CHANGELOG

Users pick a plugin version by matching it to their Kimai version, so a compatibility table is the
single most useful thing in the README:

```markdown
# FooBundle

One sentence on what it does.

## Installation

| Bundle version | Minimum Kimai version |
|----------------|-----------------------|
| 1.0            | 2.65.0                |

Extract the release into `var/plugins/`, so that `var/plugins/FooBundle/FooBundle.php` exists,
then rebuild the cache and install the database:

    bin/console kimai:reload -n
    bin/console kimai:bundle:foo:install

## Permissions

- `view_foo` — see the Foo screen (default: `ROLE_SUPER_ADMIN`, `ROLE_TEAMLEAD`)
- `edit_foo` — change Foo data (default: `ROLE_SUPER_ADMIN`)
```

Keep `CHANGELOG.md` entries headed by version with the minimum Kimai version stated per release.
