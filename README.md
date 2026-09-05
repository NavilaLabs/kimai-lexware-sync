# KimaiLexwareSync

A [Kimai](https://www.kimai.org/) plugin that bridges Kimai (time tracking) and
[Lexware](https://lexware.de/) (accounting, formerly lexoffice), without either system
replacing or mirroring the other. Kimai stays the system of record for projects, activities and
time entries. Lexware stays the system of record for order confirmations and invoices. The
plugin connects the two systems at the points where a human decision is needed, and otherwise
stays out of the way.

## The workflow

1. An order confirmation is created in Lexware. The plugin is notified through a Lexware
   webhook, with a periodic reconciliation poll as a safety net, and stores a record of it. If
   the order confirmation matches a configurable rule, the plugin automatically creates a
   matching Kimai project, and optionally one Kimai activity per matching line item, creating
   the Kimai customer first if it does not exist yet.
2. Any order confirmation that did not convert automatically shows up in a triage list inside
   Kimai's project overview, where a user decides for each row whether to convert it into a
   project, reject it, or leave it for later. Leaving it for later simply means doing nothing,
   since the row stays in the list until a decision is made.
3. Time is booked in Kimai exactly as it always is. This plugin does not add a timesheet screen
   of its own.
4. Once a person pursues a tracked order confirmation into an invoice draft inside Lexware, the
   plugin picks it up the same way, through a webhook with a reconciliation poll as a safety
   net, and lists it in a second screen. Assigning open, not yet exported timesheets to it, in
   either one line per timesheet or one aggregated line per activity, produces a new invoice
   containing both the draft's original lines and the new timesheet lines, pushed back to
   Lexware. Since Lexware offers no update or deletion endpoint for invoices, the original draft
   stays in Lexware afterward; the screen links directly to it so a person can delete it by hand.

## Status

- **Milestone one**, order confirmation ingestion followed by automatic or manual project
  creation, is in development. The full design is written down in the specification linked
  below.
- **Milestone two**, invoice ingestion followed by timesheet assignment and an outbound
  invoice, is in development. The full design is written down in the specification linked
  below, sections 12 through 18.

Full design: [`docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`](docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md).

## Requirements

- A Kimai 2 installation. The exact supported version is pinned in `composer.json` through
  `extra.kimai.require`.
- A Lexware Public API key, not a Partner API key. Generate one at
  `https://app.lexware.de/addons/public-api`. Store it as an environment variable and reference
  it from `local.yaml` rather than entering it directly into Kimai's system configuration,
  which persists every value as plain text. Add `LEXWARE_API_KEY=...` to `.env.local` or the
  deployment's own secret store, since the plugin's service configuration reads the key from
  the `LEXWARE_API_KEY` environment variable. This repository's own `.env` already does this
  for the development sandbox.
- For webhook delivery, the Kimai instance must be reachable over public HTTPS with a valid
  certificate. Lexware requires a rating of grade A or better and silently rejects a
  self-signed certificate. Until that is in place, the reconciliation poll still keeps
  everything in sync, only with a delay of up to the configured poll interval instead of an
  instant update.

## Configuration

Once installed, the plugin exposes the following keys through Kimai's system configuration
screen.

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.auto_convert_enabled` | Automatically convert an order confirmation into a project when the title rule matches. | `false` |
| `lexware_sync.title_regex` | Regular expression checked against the order confirmation's title. An empty value matches every order confirmation. | empty |
| `lexware_sync.read_lines_enabled` | Read order confirmation line items and create a matching Kimai activity for each one. | `false` |
| `lexware_sync.line_regex` | Regular expression checked against each line's name and description. An empty value matches every line that is not purely informational. | empty |
| `lexware_sync.reconcile_interval_minutes` | How often an administrator should schedule the reconciliation poll to run. The plugin does not enforce this interval itself, it only reads it back as documentation for the cron entry described below. | `30` |
| `lexware_sync.invoice_title_regex` | Regular expression checked against an invoice draft's title, applied on top of its `relatedVouchers` link to a tracked order confirmation. An empty value matches every title. | empty |
| `lexware_sync.project_completion_mode` | What marking a project completed, offered when converting a tracked invoice, actually does: `end_date` sets an end date on the project, `hidden` hides its visibility. | `end_date` |

Every regular expression field is optional. Leaving it empty means it matches everything,
never that it matches nothing. This rule is applied consistently across the whole plugin.

## Running it

Installing the plugin, with `bin/console kimai:reload -n` followed by
`bin/console kimai:bundle:lexware-sync:install`, only makes its code and database tables
available. Two further steps are needed before it actually keeps Kimai and Lexware in sync.

First, two console commands need a cron entry, since the plugin has no scheduler of its own:

- `bin/console kimai:lexware-sync:reconcile` polls Lexware for order confirmations that a
  webhook delivery might have missed. Schedule it to run as often as the
  `lexware_sync.reconcile_interval_minutes` setting above says, since that setting exists to
  tell whoever sets up this cron entry what interval to use, not to make the plugin schedule
  itself.
- `bin/console kimai:lexware-sync:check-api-key` confirms the configured Lexware API key still
  authenticates. Schedule it about once a week, well ahead of the key's twenty four month
  expiry, so an administrator notices a key that needs renewing instead of finding out when
  synchronization silently stops.
- `bin/console kimai:lexware-sync:reconcile-invoices` polls Lexware for invoice drafts that a
  webhook delivery might have missed, on the same interval as the order confirmation
  reconciliation poll above.

Second, the real Lexware webhook subscription has to be registered by hand, once this Kimai
instance is reachable at the public HTTPS endpoint described in the Requirements section above.
Send a `POST` request to `https://api.lexware.io/v1/event-subscriptions`, authenticated with the
Lexware API key as a bearer token, with an `eventType` of `order-confirmation.changed` and a
`callbackUrl` pointing at this instance's `/webhook/lexware/order-confirmation` route.

Register a second event subscription the same way, once for each of `invoice.created`,
`invoice.changed` and `invoice.status.changed`, all with a `callbackUrl` pointing at this
instance's `/webhook/lexware/invoice` route, so an invoice draft pursued from a tracked order
confirmation, and any later change to its status, is picked up the same way order confirmations
themselves are.

## Permissions

A dedicated `manage_lexware_sync` permission gates both the order confirmation triage screen and
the invoice assignment screen. It is kept separate from Kimai's general project management
permissions, so it can be granted only to the roles that should decide which order confirmations
become projects and which invoice drafts get their combined invoice created.

## Design principles worth knowing before touching the code

- Never trust the content of a webhook payload. An incoming webhook only triggers a fresh
  request for that resource against the Lexware API. Only that freshly retrieved, authenticated
  response is ever processed.
- Do not match customers by name similarity. If a Lexware contact has no existing mapping to a
  Kimai customer, a new Kimai customer is always created. Merging into the wrong existing
  customer silently would be worse than an occasional duplicate that a person resolves by hand.
- One database transaction per conversion. A tracking record and the Kimai customer, project and
  activity records it produces are created together, so there is never a half created project
  without a tracking record, or the other way around.
- No third party Lexware client library. The Lexware HTTP client is written from scratch on top
  of the Symfony HTTP client that Kimai already ships, because plugins may not add their own
  Composer dependencies. Writing it from scratch also avoids the AGPL version three license of
  the one third party PHP client considered during design, which would not be compatible with
  this plugin's MIT license.

## License

MIT. See [`LICENSE`](LICENSE).
