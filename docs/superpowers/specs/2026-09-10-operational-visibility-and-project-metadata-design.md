# Operational visibility and project metadata: design specification

Status: ready for review, not yet approved for implementation.
Date: 2026-09-10

## 1. Purpose

This specification covers two independent pieces of work, chosen together from a full workflow
review as the highest value, lowest risk improvements available right now. They share nothing
at the code level and could be implemented in either order, but are specified together because
they were scoped together.

**Part A** makes a health check failure, or a health check that has silently stopped running at
all, visible inside Kimai itself. Today `CheckLexwareApiKeyCommand` and `CheckLicenseCommand`
only write to the application log and return a command line exit code. This plugin's own
`README.md` documents that as intentional, relying on cron mail or an external monitoring system.
The original milestone one design document asked for a visible Kimai notification instead. Part A
resolves that tension by adding a lightweight, in-application warning, without taking on a new
dependency such as outbound email.

**Part B** starts using three fields Kimai's own `Project` entity already has, `orderNumber`,
`orderDate` and `comment`, which `OrderConfirmationProcessor` currently leaves empty while
overloading `name` and `start` for the same information. It also introduces one new setting so a
customer can choose what a converted project is named, since that is a visible product choice,
not an internal detail.

## Part A: health check visibility

### 2. What exists today

`CheckLexwareApiKeyCommand` calls `LexwareApiClient::listOrderConfirmationVoucherPage(0)` and logs
plus prints an error on failure. `CheckLicenseCommand` asks `LicenseStore` for a stored token,
reuses it if `LicenseEvaluator::usableToken()` says it is still current, otherwise calls
`LicenseClient::fetch()` and stores the result through `LicenseStore`. Neither command persists
the fact that it ran, only the license command persists an outcome, and only for a successful
fetch. Nothing either command does is visible from inside Kimai. The existing license banner,
`Resources/views/license/banner.html.twig`, already renders a warning on the triage screen, the
invoice list and the invoice assignment screen whenever `LicenseGate::verdict()` is not licensed,
by reading `LicenseStore` fresh on every request. That mechanism already solves visibility for an
actively refused license. It does not solve two related problems: an API key that stops
authenticating has no equivalent at all, and neither check has any way to notice that the cron
entry running it has stopped firing, since a stale but once successful result looks identical to
a current one.

### 3. Components

| Component | Responsibility |
|---|---|
| `Service/HealthCheck/HealthCheckResult.php` | Value object: check name, when it was checked, whether it passed, and an optional message. |
| `Service/HealthCheck/HealthCheckResultStore.php` | Reads and writes the latest `HealthCheckResult` for a named check, one row per check name, following the exact pattern `Service/License/LicenseStore.php` already uses: a row in Kimai's own `Configuration` table, read and written directly through `ConfigurationRepository`, never through the cached `SystemConfiguration` service, for the same reason `SettingReader` avoids it. No new database table and no migration. |
| `Service/HealthCheck/HealthCheckStatusFormatter.php` | Turns the stored result for a check, together with the configured expected interval, into zero or one translated warning sentences: nothing if the last result was a success within the expected interval, a "check failed" sentence if the last result was a failure, or a "this has not run in a while" sentence if the last result, successful or not, is older than the configured interval allows. Mirrors `LicenseVerdictMessageFormatter` in shape. |
| `CheckLexwareApiKeyCommand` (extended) | Writes a `HealthCheckResult` through the store at the end of every run, success or failure, instead of only logging. |
| `CheckLicenseCommand` (extended) | Writes a `HealthCheckResult` through the store at the end of every run, success or failure, in addition to its existing `LicenseStore` interaction. This is purely for staleness detection, the pass or fail message for the license itself keeps coming from the existing `LicenseGate`, not from this new store, so the two mechanisms are never in conflict about whether the license is currently valid. |
| `TriageController`, `InvoiceAssignmentController` (extended) | Each already fetches a `LicenseGate::verdict()` for the existing banner. Each additionally asks `HealthCheckStatusFormatter` for the API key check's message and the license check's staleness message, and passes the resulting list to the template. |
| `Resources/views/health_check/banner.html.twig` (new) | A small partial, included next to the existing license banner in the same three templates, rendering zero or more warning rows from the message list above. |

### 4. Configuration

Two new keys, exposed through the same system configuration screen as everything else, both
purely documentation for whoever sets up the cron entry, exactly the same role
`lexware_sync.reconcile_interval_minutes` already plays for the reconciliation commands. The
plugin never schedules anything itself.

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.check_api_key_interval_days` | How often `kimai:lexware-sync:check-api-key` is expected to run. A stored result older than this many days produces the "has not run in a while" warning, independent of whether that last result was a pass or a fail. | `7` |
| `lexware_sync.check_license_interval_days` | The same, for `kimai:lexware-sync:check-license`. | `1` |

### 5. Banner behaviour

The banner partial can show up to three independent messages, in a fixed order: the existing
license refusal message first (unchanged), then an API key failure or staleness message, then a
license check staleness message. Each is its own alert row, since they can be true independently
of each other, for example a currently valid license whose check has nonetheless not run in ten
days.

A health check result is purely informational. Unlike the license verdict, it never blocks
`OrderConfirmationProcessor::convert()` or `InvoiceProcessor::convert()`. Converting an order
confirmation does not call the Lexware API at all, it operates on the already fetched payload, so
gating it on API key health would be both incorrect and pointless. There is deliberately no way
to dismiss or snooze a shown warning, consistent with the rest of this plugin, which never offers
a way to silently ignore a flagged row, see the triage screen's deliberate lack of a third "leave
alone" button.

### 6. Explicitly out of scope for Part A

A global, always visible notification using Kimai's Tabler theme header bell
(`KevinPapst\TablerBundle\Event\NotificationEvent`) was considered and declined in favour of
extending the existing banner, which only appears on the plugin's own three screens. Placing the
banner on the system configuration screen itself was also considered and declined. Outbound email
delivery of a health check failure, including running Mailhog in the devcontainer to test it, was
raised independently and deferred as its own, separate piece of work.

## Part B: project metadata mapping

### 7. What exists today

`OrderConfirmationProcessor::convert()`, in `Service/OrderConfirmationProcessor.php`, sets
`Project::name` to the order confirmation's voucher number and `Project::start` to its voucher
date. `Project::orderNumber`, `Project::orderDate` and `Project::comment` are never touched and
stay empty on every converted project. Nothing else in the plugin, and nothing in its tests,
relies on `Project::name` being the voucher number, confirmed by searching the codebase for any
comparison against it.

### 8. Configuration

One new key, a choice setting mirroring the existing `lexware_sync.project_completion_mode`
pattern in shape.

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.project_title_source` | What a converted project's `name` is set to. `voucher_number` keeps today's behaviour. `order_confirmation_title` uses the order confirmation's own title. `customer_and_title` combines the resolved customer's name and the title. | `voucher_number` |

`LexwareSyncConfiguration` gains matching constants, `PROJECT_TITLE_VOUCHER_NUMBER`,
`PROJECT_TITLE_ORDER_CONFIRMATION_TITLE` and `PROJECT_TITLE_CUSTOMER_AND_TITLE`, and a
`getProjectTitleSource()` accessor, read the same uncached way as every other key in that class.

### 9. Field assignment

Regardless of which title source is configured, `OrderConfirmationProcessor::convert()` always
sets all three of the following, in addition to the existing `start`:

- `orderNumber` = the voucher number.
- `orderDate` = the voucher date.
- `comment` = the order confirmation's title, so the human readable title is visible somewhere in
  Kimai's own interface even when `voucher_number` is the chosen title source and `name` stays
  cryptic.

`name` is resolved from the configured `project_title_source`.

### 10. Validation and fallback

Kimai's `Project` entity constrains these fields more tightly than the order confirmation data is
guaranteed to satisfy, confirmed by reading `src/Entity/Project.php` directly rather than assumed:

- `name` must be 2 to 150 characters and must not contain `<`, `>`, `"` or `=`
  (`App\Validator\Constraints\NoSpecialCharacters`, Kimai's own XSS and DDE guard).
- `orderNumber` must be at most 50 characters, tighter than `name`'s 150.

The existing guard in `convert()` that throws `UnprocessableOrderConfirmationException` for a
voucher number outside 2 to 150 characters must tighten to 2 to 50, since the voucher number now
always has to fit into `orderNumber` as well, not only into `name` when `voucher_number` is
chosen as the title source.

For the two title sources that are not the voucher number, a resolved title that is empty, too
long for `name`, or contains a disallowed character is not a reason to fail the whole conversion,
since this only affects how the project is labelled, not the correctness of the tracked record.
`convert()` falls back to the voucher number as the name in that case, the same defensive
philosophy `resolveActivityName()` already applies to line item names further down in the same
class.

### 11. No backfill

This applies to conversions from the moment it ships onward. Already converted projects keep
whatever `name` they have today and keep empty `orderNumber`, `orderDate` and `comment`. No
migration reads existing `TrackedOrderConfirmation` rows to backfill them.

## 12. Testing strategy

Both parts follow the split this project already uses, described in the `kimai-plugin-testing`
skill. `HealthCheckResult`, `HealthCheckResultStore` and `HealthCheckStatusFormatter` need neither
Doctrine nor the kernel beyond what `LicenseStore`'s own tests already demonstrate is workable for
a `Configuration` repository, and get plain PHPUnit coverage: a fresh check name with no stored
result, a stored success within the interval, a stored success outside it, and a stored failure.
The `project_title_source` resolution logic, including the fallback path for an unusable title, is
equally plain PHP with no kernel dependency. The command and controller changes, and the new
banner partial, are verified by hand through the lint, boot, exercise loop, the same way the
existing license banner and the two commands already were.

## 13. Open items carried into implementation

Whether `HealthCheckResultStore` should live under `Service/HealthCheck/` as its own namespace, as
proposed above, or inside `Service/License/` alongside the license store it mirrors, is a small
naming detail left to the implementation plan. The exact translated wording for each banner
message, and where the new translation keys land in `messages.de.xlf` and `messages.en.xlf`, is
also left to the implementation plan rather than decided here.
