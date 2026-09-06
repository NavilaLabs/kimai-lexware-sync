# Test infrastructure: design specification

Status: implemented on 2026-09-05. The scaffold and one worked example per category are in
place, the skill is written, and both workflows exist. Static analysis reached zero findings on
the same day, so the continuous integration workflow blocks on it. What remains is filling in the remaining
processors, controllers and commands.
Date: 2026-09-05

## 1. Purpose

The plugin is about to be sold rather than only used in house. That changes what the automated
tests are for. Until now they existed to tell the author whether a class does what it is meant
to do. From now on they exist to answer a different question, and to answer it without anyone
asking: does this plugin still work, against the Kimai version it claims to support, against
the Kimai version that is coming, and against the Lexware API as it actually behaves today.

A paying customer buys the promise that the plugin keeps working. Only continuous, unattended
verification can back that promise up.

Two things are explicitly out of scope here and belong to their own specification: the license
check, and any browser driven end to end testing.

## 2. Where the plugin stands today

Nine test classes hold 42 tests. All of them are pure unit tests that need neither the Symfony
kernel nor a database: the matching rule evaluator, the Lexware API client against a mocked
HTTP client, the webhook signature verifier, the webhook subscription connector, the invoice
line builder, the timesheet rate resolver, the document summary factory, the deep link builder,
and the invoice relation logic.

Nothing else is covered. Specifically untested are both processors, the order confirmation
synchronizer, all six controllers, all four console commands, and both database migration
paths. That is precisely the layer where a Kimai upgrade breaks a plugin, because that is the
layer that touches Kimai's own services, entities, routing, security and Doctrine mapping.

Section 10 of the milestone one design specification recorded that this layer was left to
manual verification because the deployment had no test harness. That reasoning has expired: the
development container is now built from `kimai/kimai2:dev`, which does ship Kimai's development
dependencies, so the harness is buildable without adding a single Composer package of our own.

## 3. Findings, verified in the development container on 2026-09-05

Every statement in this section was confirmed by running it, not inferred from documentation.

**Kimai does not load plugins in the `test` environment.** `App\Kernel::registerBundles()`
returns early when the environment is `test`, before the loop that discovers bundles in
`var/plugins`. A plugin therefore cannot be tested by simply booting Kimai's own kernel with
`APP_ENV=test`. A kernel subclass that yields the plugin bundle after delegating to the parent
solves this completely. Verified: the bundle appears in the boot list, all fifteen plugin
routes are present in the router, and `OrderConfirmationProcessor`, `InvoiceProcessor`,
`LexwareApiClient` and `TriageController` are all resolvable, alongside Kimai's own services
such as `CustomerRepository`.

**The kernel subclass must override `getProjectDir()`.** Symfony derives the project directory
from the location of the kernel class by walking up until it finds a `composer.json`. A kernel
class living inside the plugin finds the plugin's own `composer.json` first and then looks for
Kimai's configuration in the wrong place. The override must return Kimai's root.

**`framework.test` is active in the `test` environment.** `config/packages/framework.yaml`
enables it under `when@test`, together with a mock session storage. The test client and the
special test service container are therefore available, which is what makes controller tests
possible at all.

**`kimai.data_dir` points at a directory that does not exist here.** Under `when@test`,
`config/packages/kimai.yaml` sets it to `%kernel.project_dir%/tests/_data`. A Kimai source
checkout has that directory; the container image does not, because it ships without Kimai's own
test suite. Booting the test environment fails with "Data directory does not exist" until it
exists. `/opt/kimai` is not writable by the container user, so this cannot be fixed from inside
a running session. See section 9.

**Overriding that path in configuration is sufficient for everything except one test.** A
`kimai.data_dir` pointing at a writable directory under Kimai's `var` lets the test kernel boot
and was verified to work. The single thing it does not fix is the plugin's install command:
`AbstractBundleInstallerCommand` runs `bin/console doctrine:migrations:migrate` as a child
process through `PhpSubprocess`, and that child boots Kimai's real kernel with the ambient
environment, where the override does not apply. Exercising the install command itself therefore
only works where `tests/_data` genuinely exists, which is the case in every continuous
integration checkout and not in the development container. The plan at the time was for one test
exercising the install command to skip itself locally with an explicit message and run only in
continuous integration. That test was never written; section 12 describes what was built
instead.

**Only one Doctrine migration configuration can be used per PHP process.** Running Kimai's
migrations and then the plugin's migrations in the same process fails with "The dependencies
are frozen and cannot be edited anymore", because the migrations bundle builds its dependency
factory once and refuses to be reconfigured. Preparing a schema therefore takes two separate
processes, which is what the plugin's own install command does anyway.

**Schema preparation does not need the test environment at all.** Running the two migration
commands through `bin/console` with `APP_ENV=dev` and `DATABASE_URL` pointing at the test
database works without any container change, because the data directory constraint only exists
in the test environment. Verified end to end: Kimai's 35 tables plus all six
`kimai2_ext_lexware_*` tables were created this way.

**The whole chain was verified against the prepared database.** With the test kernel booted
against it, the rollback isolation bundle is active, a Kimai `Customer` entity was written
through the entity manager, a plugin repository was queried, and the connection was confirmed
to point at the test database rather than the development one.

**A separate environment name was considered and rejected.** Naming the environment something
other than `test`, for example `plugintest`, would sidestep the early return in
`registerBundles()` and the data directory entirely. It would also silently discard seven
`when@test` configuration blocks that Kimai maintains: the test client and mock session in
`framework.yaml`, the deliberately cheap password hashing in `security.yaml`, the disabled
query logging and enabled savepoints in `doctrine.yaml`, the relaxed limits in
`rate_limiter.yaml`, plus the blocks in `monolog.yaml`, `routing.yaml` and `web_profiler.yaml`.
Reproducing those in our own file means maintaining a copy that drifts with every Kimai
release, which is the opposite of what this whole effort is for.

**The database user cannot create its own test database.** The container's MySQL user `kimai`
holds privileges on the `kimai` database only. The root account is reachable from the
development container with the password from `docker-compose.yaml`, so a setup step can create
the test database and grant access. This has already been applied for the pattern `kimai\_%`,
so a database named `kimai_test` will be usable by the `kimai` user without any further
privilege work.

**Kimai's own migrations take about 38 seconds and create 35 tables.** That is the unavoidable
setup cost of a functional test run and the reason section 6 caches the resulting schema rather
than migrating on every run.

**The development dependencies needed for functional testing are present in Kimai's vendor
directory**, namely `dama/doctrine-test-bundle`, `doctrine/doctrine-fixtures-bundle`,
`symfony/browser-kit` and `symfony/phpunit-bridge`. They are absent from the plugin's own
vendor directory, which does not matter, because `Tests/bootstrap.php` already loads Kimai's
autoloader. This keeps the standing constraint that the plugin adds no Composer dependencies of
its own.

## 4. Test levels

Four levels, each with a distinct job and a distinct trigger.

| Level | What it covers | Needs | Runs |
|---|---|---|---|
| Unit | Pure logic: rules, builders, resolvers, signature verification | Nothing | Every push |
| Functional | Processors, synchronizers, controllers, commands, against a real kernel and a real database | Kimai checkout, MySQL | Every push |
| Migration | Fresh installation and the upgrade path across released versions | Kimai checkout, MySQL | Every push |
| Contract | The real Lexware API, reading and writing | A separate Lexware test account | Weekly, and on demand |

The first three answer "did we break it". The fourth answers "did someone else break it", and
it is the only one that costs real money in the sense of leaving traces in an external system.

## 5. The test kernel

A single class, `Tests/TestKernel.php`, extending `App\Kernel`:

- `registerBundles()` yields everything the parent yields, then the plugin bundle. The parent's
  early return in the test environment means the parent yields only Kimai's own bundles from
  `config/bundles.php`, which is exactly what is wanted: this plugin, and no other plugin that
  happens to sit in `var/plugins` on the developer's machine. Test runs are therefore
  reproducible regardless of what else is installed locally.
- `getProjectDir()` returns Kimai's root, discovered by walking up from the plugin directory
  until a directory containing both `src/Kernel.php` and `config/bundles.php` is found, so the
  same class works in the development container and in a continuous integration checkout
  without configuration.
- `getCacheDir()` returns a plugin specific path under Kimai's `var/cache`, so a test run never
  invalidates the cache of the running development instance.
- Exactly one configuration override, `kimai.data_dir`, pointing at a directory under Kimai's
  `var` that the test run creates if it is missing. Nothing else is overridden: everything else
  the tests need is already in Kimai's own `when@test` blocks, and every line we add here is a
  line that has to be maintained against future Kimai releases.

Two base test case classes build on it, both thin. They live in `Tests/Support`, not in
`Tests/Functional` as an earlier draft of this section placed them, because they are shared
infrastructure that the functional suite depends on rather than a test belonging to that suite
itself:

- `Tests/Support/FunctionalTestCase`, extending Symfony's `KernelTestCase` with the test
  kernel, for processors, synchronizers, repositories and commands.
- `Tests/Support/WebTestCase`, extending Symfony's `WebTestCase`, for the six controllers.
  Because it goes through the real HTTP kernel, it also renders the Twig templates, which
  covers the templates without any separate browser tooling.

## 6. The test database

Name: `kimai_test`, on the existing `sqldb` service. No new container service, so no rebuild is
needed for this part.

This section originally planned a two stage process built around a schema dump: a preparation
step would write the migrated schema to `var/test-schema.sql`, the PHPUnit bootstrap would import
that dump into an empty database in about a second, and a continuous integration cache keyed on
the Kimai version and on a hash of the plugin's own migration files would mean the 38 seconds of
Kimai migrations were paid once per cache key rather than on every run.

None of that dump or cache was built. What exists instead:

1. `Tests/prepare-database.sh` creates the database, then migrates it directly: Kimai's
   migrations and then the plugin's migrations, as two separate `bin/console` invocations in the
   development environment, because a single process can only carry one migration configuration.
   It is a `just` recipe locally and a plain step in continuous integration, with no caching, so
   both matrix entries pay the full migration time on every run.
2. `Tests/bootstrap.php` does not prepare anything. It only checks that
   `kimai2_ext_lexware_order_confirmation` exists in the configured database and throws a
   `RuntimeException` naming `just test-database` as the fix if it does not. A contributor who
   never ran that recipe gets a clear message instead of a confusing failure deeper in the suite,
   but nothing runs the migration for them.

The dump and cache idea is still worth doing. Continuous integration currently spends the full
migration time on every push against every matrix entry, which is exactly the cost this section
set out to avoid. It was left out of the first implementation pass rather than abandoned; an
implementation plan reintroducing it should key the cache on the Kimai version and on a hash of
the plugin's migration files, as originally planned here.

Isolation between tests comes from `dama/doctrine-test-bundle`, which Kimai already registers
in the test environment. It wraps every test in a transaction and rolls it back afterwards, so
tests never see each other's data and no cleanup code is needed. Its one constraint is that a
test must not commit a transaction of its own. That matters here, because the design mandates
one Doctrine transaction per conversion: the processors must therefore be written and tested
with an injected transaction boundary rather than committing directly, or the test must assert
through the same connection. The implementation plan settles which, based on what the current
processor code actually does.

## 7. Test data

Kimai's own fixtures live in its `tests` directory and are not shipped in the package, so they
are unavailable to us. Rather than pulling in Kimai's development fixtures by checking out its
source, the plugin gets its own small set of factories, `Tests/Support/KimaiEntityFactory`,
building the Kimai entities a test needs: a user with a given role, a customer, a project, an
activity, a timesheet with or without an hourly rate. A plain class with named constructors, no
fixtures bundle, no Faker, no randomness. A test that needs a timesheet without a rate asks for
exactly that, and the reader of the test sees why it matters.

This section originally planned a second half of test data: the recorded Lexware responses moving
into a `Tests/Fixtures/Lexware` directory as named JSON files, one per scenario, so the same
recorded order confirmation could drive both a unit test of the summary factory and a functional
test of the whole conversion. That directory was never created. Every test that needs a Lexware
payload builds it as an inline array in the test itself, and the sharing this section anticipated
between the unit and functional level has not happened: the two levels each construct their own
payload. Named fixture files remain worth doing once a third or fourth test wants to share the
same payload; nothing about the design below prevents adding them later.

## 8. Faking Lexware in functional tests

Functional tests must never reach the real Lexware API. The plugin's API client takes a Symfony
HTTP client through its constructor, so a test only needs to replace that one service with a
`MockHttpClient` configured with the recorded responses. This happens through a test only
service definition file that the test kernel does not load and the test case does instead,
which keeps the fake out of any child process such as the install command, which has no
business talking to Lexware anyway.

The mock is strict: an unexpected request fails the test rather than returning an empty
response. A silent extra call to Lexware is exactly the kind of defect this level of testing
exists to catch.

## 9. No change to the development container

An earlier draft of this specification required a line in `.devcontainer/Dockerfile` to create
`/opt/kimai/tests/_data`, and with it a rebuild and restart of the development container. That
turned out to be avoidable, and avoiding it matters more than it first appears, because the
development session itself runs inside that container and a rebuild ends it.

Two findings removed the need. The data directory is only demanded by the test environment, and
the test kernel can point it somewhere writable. Schema preparation, the one part that has to
run outside the test environment because it uses `bin/console`, does not need the test
environment at all and runs in the development one.

The plan at the time was that a single behavioural difference would remain between a developer's
machine and continuous integration: a test exercising the install command through its real child
process, running only where `tests/_data` exists and skipping locally with a message saying so.
That test was never written. What runs identically in both places instead is `Tests/Migration/`,
described in section 12, which migrates directly rather than through the install command, so
there is currently no behavioural difference between the two environments to describe, and no
coverage of the install command's own child process anywhere in the suite.

## 10. Continuous integration

Two workflows, both under `.github/workflows`, in a private repository where GitHub sends a
notification to the account owner on a failed run. No further alerting is built.

**`ci.yml`**, on every push and pull request. A MySQL 8.3 service, PHP 8.3, and a matrix over
Kimai versions:

- `2.65.0`, the version pinned in `composer.json` through `extra.kimai.require`. Blocking. A
  failure here means the plugin is broken for the version it claims to support.
- Kimai's current `main`. Non blocking, allowed to fail without turning the run red, but
  clearly reported. This is the early warning: it tells you that the next Kimai release will
  break you, weeks before a customer finds out.

Each matrix entry checks out Kimai at its version, installs Kimai's dependencies including the
development ones, places this plugin into `var/plugins/KimaiLexwareSyncBundle`, installs the
plugin's own tooling, and then runs code style, static analysis, and the unit, functional and
migration suites. Coverage is collected with `--coverage-text` and appears in the step's log,
rather than attached to the run summary as an earlier draft of this section planned; nothing
currently parses or surfaces that number outside the log. No threshold is enforced: the number is
there to show which classes are still uncovered, not to be gamed. Static
analysis blocks the build, because the findings that predated this work were cleared on
2026-09-05 by introducing `LexwarePayload`, a typed reader for Lexware responses, so anything
new is a regression.

**`contract.yml`**, weekly on a schedule and manually triggerable. It runs the contract suite
against the separate Lexware test account, with the API key held as a repository secret. It
does not run on pushes, both because it is slow and because every write it performs leaves a
permanent document in that account.

The workflow files depend on the plugin's development tooling resolving identically every time,
which is why `composer.lock` is committed as of 2026-09-05 rather than ignored as it was
before. It pins only the linting and testing tools, never anything shipped to a customer, and
without it a new release of PHPStan could turn the build red on a day nobody touched the code.

## 11. Contract tests against Lexware

These are the tests that answer whether Lexware changed something. They live in their own
PHPUnit test suite so that a normal run never touches the network, and they skip themselves
with a clear message when the API key environment variable is absent, so that a contributor
without an account still gets a green local run.

Reading side: fetch an order confirmation, an invoice, a contact and a document file, and
assert that every field the plugin's mapping actually reads is present and has the expected
type. The assertion is deliberately about our mapping, not about Lexware's full schema. A new
field appearing is not a failure; a field we depend on disappearing or changing type is.

Writing side: create a contact, create an order confirmation, and create an invoice, then
assert the response shape. This is the half that cannot be skipped even though it leaves
traces, because the two nastiest surprises of the project so far both came from the write path:
an explicitly supplied `relatedVouchers` entry being silently dropped, and `shippingConditions`
being required where order confirmations never needed it. Both were found by hand during a
spike. A contract test is what turns that kind of discovery from luck into routine.

Lexware offers no endpoint to delete an invoice, so these documents accumulate. At one run per
week that is roughly fifty documents a year in an account that exists for nothing else, which
is acceptable. Every document a test creates is titled so that it is recognisable as machine
generated.

## 12. Migration tests

Two tests, and the second is the one that matters. Both live in their own PHPUnit test suite and
run in their own process against a scratch database, because a process that has already loaded
one migration configuration cannot load another, and because these tests deliberately change
the schema rather than rolling their changes back.

- A fresh installation: on an empty database, the plugin's migrations run directly through
  `doctrine:migrations:migrate` in a child process, the same way `Tests/prepare-database.sh`
  does it, and afterwards every table the migrations created carries the `kimai2_ext_` prefix.
  This section originally planned a second, continuous integration only variant of this test that
  went through the actual install command, `AbstractBundleInstallerCommand`, so that its own child
  process path was covered somewhere. That variant was never written. The install command's child
  process invocation of the migration command is therefore not exercised by any test today; only
  the migration itself is.
- The upgrade path: starting from the schema of an earlier released version, all newer
  migrations run in order, and the data that was present beforehand is still present and
  correct afterwards. This is where a customer loses data during a plugin update, and it cannot
  be undone once shipped.

Since only one version exists so far, the upgrade path test is built now with a single recorded
starting schema and gains a case per release. Recording the schema of a version at the moment
it is released is part of the release procedure, not an afterthought.

## 13. What the first implementation pass delivers

The scaffold, plus exactly one worked example per category, so that the shape is proven before
it is repeated:

1. The database preparation from section 6.
2. `TestKernel` and the two base test cases.
3. The fixture factories and the Lexware payload fixtures.
4. One functional test of a processor, covering a full conversion from a Lexware payload
   through to the created Kimai customer, project and activity.
5. One controller test, covering the triage screen including its permission check.
6. One command test, covering the reconciliation poll.
7. Both migration tests.
8. One reading and one writing contract test.
9. Both workflow files and the `just` recipes that run the same things locally.

Filling in the remaining processors, controllers and commands happens afterwards, guided by the
skill described next.

## 14. The testing skill

Written after the first pass, not before, so that it describes a practice that has been proven
rather than one that has been imagined. It goes to `.claude/skills/kimai-plugin-testing` and
covers: which level a new test belongs to and why, how to boot the test kernel, how to build
Kimai entities with the fixture factories, how to fake Lexware, how the transaction rollback
constrains what a test may do, how to add a case to the migration upgrade test when a release
happens, and how to add a contract test when a new Lexware endpoint is used.

Its purpose is that a later session extends the suite in the established shape instead of
inventing a second one alongside it.

## 15. Open items carried into implementation

Resolved on 2026-09-05, before the implementation plan was written, by running it. The plugin
opens its own Doctrine transaction in three places: `OrderConfirmationSynchronizer`,
`InvoiceProcessor` and `TriageController`. Kimai enables `use_savepoints` on the default
connection under `when@test`, precisely so that nested transactions become savepoints rather
than a second real transaction. Verified against the test database: with an outer transaction
open, the plugin's inner begin and commit made the row visible, and rolling the outer
transaction back removed it again. The rollback based isolation and the mandated one
transaction per conversion therefore coexist with no change to production code and no test that
needs to opt out.

No open items remain.
