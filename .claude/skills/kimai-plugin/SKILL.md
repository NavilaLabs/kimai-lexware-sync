---
name: kimai-plugin
description: Write, scaffold, extend, debug or package a plugin (bundle) for the Kimai time-tracking application. Use this whenever the user mentions a Kimai plugin or bundle, var/plugins/, the KimaiPlugin namespace, a *Bundle.php file, kimai:reload / kimai:bundle:*:install, or wants to extend Kimai with a menu entry, dashboard widget, invoice or export renderer, timesheet calculator, permission, system configuration, meta field, report, console command or API endpoint — even when they only say "add a feature to Kimai" without using the word plugin.
---

# Writing Kimai 2 plugins

A Kimai plugin is a Symfony bundle that lives in `var/plugins/` instead of `vendor/`. Everything you
know about Symfony bundles applies — services, event subscribers, controllers, Twig, Doctrine, forms,
console commands. On top of that Kimai layers a small set of rules that are enforced in code, not by
convention: `Kernel::getBundleClasses()` scans the plugin directory at every boot and throws on the
first violation. A broken plugin does not fail quietly — it takes the whole installation down with a
500 error, including the admin UI you would use to disable it.

That is the single most important thing to internalise: **plugin mistakes are installation-wide
outages**, so the constraints below are worth getting right the first time.

## Orient yourself before writing code

Kimai gives plugins no backwards-compatibility promise. Interfaces, events and Twig helpers change
between minor releases, and 3.0 raises PHP to 8.4 and removes fluent setters from entities. Anything
you remember about Kimai's API may be a version or two stale, so start from the installation you are
targeting rather than from memory.

The plugin normally sits at `<kimai>/var/plugins/<YourBundle>/`, which puts the readable core source
three directories up. Run these from the plugin directory:

```bash
grep -n "VERSION\b\|VERSION_ID" ../../../src/Constants.php   # target version, and the int for extra.kimai.require
ls ../../../src/Event/                                        # ~110 events, names are self-describing
grep -rln "AutoconfigureTag" ../../../src/                    # every interface Kimai auto-discovers
sed -n '/"require": {/,/},/p' ../../../composer.json          # the only libraries you may use
```

Read the actual class you are about to implement or the event you are about to subscribe to. It costs
one tool call and it is the difference between code that boots and code that fatals. When the core
source is not available, say so and work from `references/extension-points.md`, flagging signatures as
unverified.

## The five constraints that shape everything

**1. Path equals namespace, and the layout is flat.** Kimai's own `composer.json` maps PSR-4
`KimaiPlugin\` → `var/plugins/`. So `KimaiPlugin\FooBundle\Service\Sync` must live at
`var/plugins/FooBundle/Service/Sync.php`. There is no `src/` layout. The `autoload` block in your
plugin's `composer.json` is never read at runtime — it exists only so your own PHPStan and CS-Fixer
can resolve classes.

**2. No dependencies of your own.** Nothing loads `var/plugins/*/vendor/autoload.php`. You may use
only what Kimai already ships: Symfony 6.4 (including `symfony/http-client`, `symfony/process`,
`symfony/validator`, `symfony/serializer`), Doctrine ORM 2.x, Twig, `league/csv`, `openspout`,
`phpoffice/phpspreadsheet`, `phpoffice/phpword`, `mpdf`, `jms/serializer`, `nelmio/api-doc`,
`friendsofsymfony/rest-bundle`, `endroid/qr-code`, `erusev/parsedown`. Check
`../../../composer.json` for the full list. If you need something else, vendor the code into your
plugin as plain classes or drop the requirement.

**3. The directory name is functional.** It must end in `Bundle`, must not contain a version number,
and must match the class inside it: `var/plugins/FooBundle/FooBundle.php` holding
`KimaiPlugin\FooBundle\FooBundle`. The Kernel throws a dedicated exception for `*Bundle-1.2.3`
directories because that is what users get when they unzip a release without renaming.

**4. `composer.json` is mandatory metadata, not packaging.** `PluginMetadata::createFromPath()`
requires `extra.kimai.require` (an integer `VERSION_ID`, e.g. `26500` for 2.65.0) and
`extra.kimai.name` (the display name in the admin UI). If `require` is higher than the running
Kimai, the Kernel refuses to boot. Set it to the lowest version you actually tested against and raise
it deliberately when you start using a newer API.

**5. Only routes load themselves.** Kimai auto-imports `Resources/config/routes.{php,yaml}` (or
`config/routes.*`). Services do not load automatically — your `DependencyInjection/FooExtension.php`
must load `services.yaml` explicitly. This is the most common reason a freshly written subscriber
appears to be ignored.

## Workflow

### 1. Pick the extension point

Kimai offers four mechanisms, and choosing the wrong one produces code that works but never runs.
`references/extension-points.md` is the catalogue with verified signatures; consult it before writing
the class.

| You want to… | Use | Wiring |
|---|---|---|
| React to something Kimai does (timesheet saved, menu built, theme rendered, invoice created) | an event subscriber on one of `App\Event\*` | autoconfigured |
| Add a pluggable strategy Kimai looks up by ID (widget, invoice calculator, invoice number generator, export renderer, timesheet calculator, validation constraint) | implement the interface — they carry `#[AutoconfigureTag]` | autoconfigured |
| Declare permissions, extra invoice/export template directories, or Nelmio/JMS metadata | `prependExtensionConfig()` in an extension implementing `PrependExtensionInterface` | compile time |
| Add your own screens, API endpoints or CLI | controllers with `#[Route]`, `AbstractBundleInstallerCommand`, plain Symfony commands | routes.yaml / autoconfigure |

Prefer an existing event over a new controller: staying inside Kimai's UI, permissions and menu costs
far less than owning a page.

### 2. Scaffold

`references/skeleton.md` has every boilerplate file ready to copy — `composer.json`, bundle class,
extension (with and without configuration and prepend), `services.yaml`, `routes.yaml`, install
command, Doctrine migration setup, `phpstan.neon`, `.php-cs-fixer.dist.php`, CI workflow, README.

Start from the minimum (bundle class + extension + `services.yaml` + `composer.json`) and add pieces
only when you actually need them. A plugin that only subscribes to an event needs nothing else.

### 3. Implement

Follow the conventions Kimai enforces on itself (its `AGENTS.md`), because reviewers and future
Kimai versions assume them: English identifiers and comments, strict `===`/`!==`, constructor
property promotion, 4-space indent, single-quoted strings, PHP attributes for routing and mapping,
FontAwesome 6 icon names, `|trans` instead of hardcoded UI text, business logic in services rather
than controllers.

Kimai-specific naming that matters:

- **Permissions** are plain lowercase strings (`view_lexware_sync`). Register them via `prepend()` or
  no role can ever be granted them.
- **Database tables** get a plugin prefix: `kimai2_ext_<something>`. Never write to core tables from a
  migration — use meta fields or your own table joined by ID.
- **Twig namespace** is the bundle name minus `Bundle`: `FooBundle` → `@Foo/index.html.twig`, with
  templates in `Resources/views/`.
- **Translations** live in `Resources/translations/<domain>.<locale>.xlf`. Domains match Kimai's
  (`messages`, `system-configuration`, `actions`, `validators`, `invoice-calculator`, …). Each unit
  needs a `resname`; `bin/console kimai:translations --resname` fixes that for you.
- **User preference names must not contain dots** — the entity sanitises them and 2.0 broke stored
  values that had them.
- **Configuration**: user-editable settings belong in the System Configuration screen
  (`SystemConfigurationEvent` + `registerBundleConfiguration()`), read back through
  `App\Configuration\SystemConfiguration::find('foo.setting')`. Reserve `local.yaml` defaults for
  values administrators should not change at runtime.
- **Files** go into the configured data directory via `App\Utils\FileHelper::getDataDirectory()`,
  never next to your plugin — the plugin directory is deleted on every update.

Secrets such as API tokens are a special case: system configuration persists every value into a plain
`TEXT` column (`kimai2_configuration.value`) with no encryption anywhere in core. A `PasswordType`
field masks the value in the form but changes nothing about storage, so it ends up readable in
database dumps and backups. For third-party credentials prefer an environment variable referenced
from `local.yaml`, and say so in the README.

### 4. Verify

There is no test harness for plugins, so the loop is lint, boot, exercise. Run it from the Kimai
root, not from the plugin directory:

```bash
bin/console kimai:reload -n                    # lints config + xliff, rebuilds cache — must pass
bin/console debug:container KimaiPlugin\\FooBundle\\  # your services registered?
bin/console debug:event-dispatcher <EventClass>       # your listener attached?
bin/console debug:router | grep foo                   # routes imported?
bin/console kimai:bundle:foo:install                  # migrations applied
```

`kimai:reload` runs `lint:yaml` and `lint:xliff` first, so a malformed translation file blocks the
cache rebuild — that is a feature, it catches the error before users do.

For static analysis, add `kimai/kimai: dev-main` as a dev dependency in your plugin and run PHPStan
(level 9 is what the DemoBundle holds) plus PHP-CS-Fixer. Both configs are in
`references/skeleton.md`.

After any change to services, routes, translations or configuration, the cache must be rebuilt before
the change is visible. If a change seems to have no effect, rebuild before debugging further.

## When something breaks

| Symptom | Cause |
|---|---|
| 500 on every page, directory name has a version suffix (`FooBundle-1.2.3`) | `Kernel::getBundleClasses()` scans for `*Bundle-*` in a separate pass that runs *before* it ever looks for `.disabled`, and throws unconditionally. `.disabled` cannot save you here — rename the directory to drop the version suffix, then rebuild the cache. |
| 500 on every page, directory name is already correct | Version mismatch (`extra.kimai.require` > core) or missing/invalid `composer.json`. These are only detected while loading the *correctly-named* bundle, after its `.disabled` check — so `touch var/plugins/FooBundle/.disabled` + cache rebuild does get the site back here, as a rollback lever while you fix the real problem. Read `var/log/prod.log` for the exact cause. |
| Class not found for a class that exists | File path does not mirror the namespace under `var/plugins/`, or you used a `src/` layout. |
| Subscriber or service never runs | `services.yaml` not loaded by the extension, or the class is in an `exclude` pattern. |
| Route 404 | Routes only load from `Resources/config/routes.yaml` (or `config/routes.yaml`); check the resource path uses `@FooBundle/...`. |
| Permission always denied | Permission not registered through `prependExtensionConfig('kimai', ['permissions' => ...])`, or not assigned to the role. |
| Migration never applied | Missing `doctrine_migrations.yaml`, or an install command that does not return its path. Entities are auto-mapped, schema changes are not. |
| Works locally, fatals after a Kimai update | You used an API without a BC promise. Pin `extra.kimai.require`, read the release notes, retest. |

## Packaging and release

Ship a ZIP named `FooBundle-<version>.zip` with a single top-level directory. Users extract it into
`var/plugins/` and rename it to drop the version, then run `kimai:reload -n` and the install command;
`./kimai.sh plugins` automates this for ZIPs dropped into `var/packages/`. Keep a `CHANGELOG.md` that
states the minimum Kimai version per release — that mapping is what lets users on older instances
pick a working version. Deactivation is a `.disabled` file in the plugin directory; removal is
deleting it. Both need a cache rebuild.

## Reference files

- `references/skeleton.md` — every boilerplate file, ready to copy, with the minimal set marked.
- `references/extension-points.md` — catalogue of events, auto-discovered interfaces and config
  hooks, with signatures verified against Kimai 2.65.0.
