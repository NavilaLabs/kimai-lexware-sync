---
name: kimai-plugin-testing
description: Write, run or debug automated tests for the KimaiLexwareSync plugin. Use whenever a task involves adding a test, changing the test infrastructure, understanding why a test fails to boot Kimai, preparing the test database, running the suites locally or in continuous integration, extending the migration tests after a release, or adding a contract test for a new Lexware endpoint.
---

# Testing this plugin

The design and the reasoning behind it live in
`docs/superpowers/specs/2026-09-05-test-infrastructure-design.md`. This skill is the working
manual: what to do, and which traps to avoid, when adding a test.

## Which suite does a new test belong to?

| Suite | Directory | Use it when | Needs a database |
|---|---|---|---|
| `unit` | `Tests/Service` | The logic is pure: a rule, a builder, a resolver, a signature check | No |
| `functional` | `Tests/Functional` | Kimai's services, entities, routing, security or Doctrine are involved | Yes |
| `migration` | `Tests/Migration` | A schema is created or changed | Yes, its own scratch one |
| `contract` | `Tests/Contract` | The question is whether the real Lexware API still behaves as assumed | Yes, plus credentials |

Prefer a unit test whenever the behaviour can be reached without Kimai. Reach for a functional
test when the interesting part is the wiring, which is exactly what breaks on a Kimai upgrade.

## Running things

```
just test-database          # once, and again after adding a migration
just test                   # everything
just test-unit              # fast, no database needed
just test-functional
just test-migration
just test-coverage          # writes var/coverage
```

The contract suite skips itself unless `LEXWARE_TEST_API_KEY` is set. Locally the plugin's
`.env` already holds a key for the Lexware test account under `LEXWARE_API_KEY`, which the
contract tests also accept:

```
set -a; . ./.env; set +a
LEXWARE_TEST_API_KEY="$LEXWARE_API_KEY" just test-contract
```

## Writing a functional test

Extend `Tests\Support\FunctionalTestCase`. It boots `TestKernel` and gives you:

- `service(SomeClass::class)` returns a typed service, and throws a readable error rather than
  handing back an `object` that static analysis cannot check. Always use it instead of
  `container()->get()`.
- `entityManager()`
- `factory()` builds Kimai entities: `createCustomer()`, `createProject()`, `createActivity()`,
  `createUser()`, `createTimesheet()`.
- `lexware()` is the fake HTTP client, see below.
- `configure('lexware_sync.some_key', $value)` sets a system configuration value in memory.

Extend `Tests\Support\WebTestCase` for a controller. It adds `browser()`,
`browserLoggedInAs($name, $roles)` and `url('route_name')`.

**Always generate a URL with `url()`, never write the path out.** Several plugin routes carry a
`/{_locale}` prefix that a test has no business knowing about.

## Faking Lexware

```php
$this->lexware()->willRespondWith('GET', '/v1/order-confirmations/abc', ['id' => 'abc', ...]);
```

The fake is strict on purpose: a request that no stub matches fails the test with the method and
URL it saw. That is a feature, not an obstacle. If a test fails with
`UnexpectedHttpRequest`, the code under test made a call you did not expect, and the right
first question is whether it should have.

`recordedRequests()` returns what was sent, including `decodedBody()` for a request body.

## Traps that cost time, all of them already paid for

- **Kimai does not register plugins in the `test` environment.** `App\Kernel::registerBundles()`
  returns early. `TestKernel` adds the bundle back. Never boot `App\Kernel` directly in a test.
- **`APP_SECRET` is empty in a fresh Kimai.** Anything touching remember me or signatures dies
  with "A non-empty secret is required". `Tests/bootstrap.php` sets a fixed test value.
- **The firewall is called `secured_area`, not `main`.** `loginUser()` needs that name, which is
  why `browserLoggedInAs()` exists.
- **A fresh user is redirected into Kimai's onboarding wizard**, so every request answers 302
  instead of the page you wanted. `KimaiEntityFactory::createUser()` marks the wizards as seen.
- **`kimai.data_dir` points at `tests/_data` in the test environment**, which a packaged Kimai
  does not have. `Tests/config/test_environment.yaml` redirects it into `var`.
- **Only one Doctrine migration configuration fits in one PHP process.** A second one fails with
  "The dependencies are frozen". This is why schema preparation runs two separate console
  processes and why migration tests shell out.
- **`LexwareApiClient` paces itself at one request every half second.** In the test container it
  is wired with a pacing of zero. In a contract test, build one client and reuse it, and leave
  the pause between tests in place, or the real account answers 429.

## Isolation, and its one rule

Every functional test runs inside a transaction that is rolled back afterwards, through
`dama/doctrine-test-bundle`. Nothing needs cleaning up, and the test database stays empty.

The plugin opens its own transaction in `OrderConfirmationSynchronizer`, `InvoiceProcessor` and
`TriageController`. That is fine: Kimai enables savepoints in the test environment, so those
nest inside the rollback. Verified on 2026-09-05. Do not add code to work around this, and do
not disable the isolation for a test that merely commits.

## After adding a migration

1. Run `just test-database` so the regular test database matches the entities again.
2. Add a case to `Tests/Migration/PluginMigrationTest`: migrate to the version before yours,
   insert a row that the new migration has to survive, migrate to `latest`, assert the row is
   still there and correct. The existing upgrade test is the template.

The fresh installation test asserts the full list of tables. A new table means adding it there,
which is also the guard for the mandatory `kimai2_ext_` prefix.

## After using a new Lexware endpoint

Add a reading contract test asserting the fields your mapping actually reads, using
`assertFieldTypes()` with a dotted path. Assert only what the plugin depends on: a new field
appearing on Lexware's side is not a failure, a field you rely on changing type is.

If the endpoint writes, remember that Lexware cannot delete invoices. Title every created
document with `marker()`, and keep in mind that Lexware rejects a title longer than 25
characters.

## Continuous integration

`.github/workflows/ci.yml` runs on every push against the pinned Kimai version and, without
failing the build, against Kimai's `main` branch as an early warning.
`.github/workflows/contract.yml` runs weekly against the Lexware test account.

Static analysis blocks the build. PHPStan runs at level 9 with zero findings, so anything new
is a regression and has to be fixed rather than tolerated.

## Reading a Lexware response

Never index into a decoded Lexware response directly. Wrap it in `LexwarePayload` and ask for
the type you need:

```php
$payload = new LexwarePayload($this->client->getInvoice($lexwareId));
$number = $payload->string('voucherNumber');
$date = $payload->dateTime('voucherDate');
$contact = $payload->nested('address')->string('contactId');
foreach ($payload->nestedList('lineItems') as $line) { ... }
```

A missing field or a field of an unexpected type gives the default instead of a cast that
happens to work until it does not. `rawList()` returns plain arrays for the places that hand
Lexware's own structures straight back to Lexware.
