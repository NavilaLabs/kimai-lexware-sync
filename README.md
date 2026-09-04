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
4. Planned, not yet implemented. See the roadmap section below. Once a Lexware invoice linked to
   a converted order confirmation appears, it will surface as an action on that project.
   Assigning booked timesheets to it will produce a new, combined invoice that gets pushed back
   to Lexware.

## Status

- **Milestone one**, order confirmation ingestion followed by automatic or manual project
  creation, is in development. The full design is written down in the specification linked
  below.
- **Milestone two**, invoice ingestion followed by timesheet assignment and an outbound
  invoice, is described only at the roadmap level for now. A dedicated design pass will happen
  once milestone one is in use. See the specification for exactly which parts of milestone two
  are already decided and which parts are still open questions.

Full design: [`docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`](docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md).

## Requirements

- A Kimai 2 installation. The exact supported version is pinned in `composer.json` through
  `extra.kimai.require`.
- A Lexware Public API key, not a Partner API key. Generate one at
  `https://app.lexware.de/addons/public-api`. Store it as an environment variable and reference
  it from `local.yaml` rather than entering it directly into Kimai's system configuration,
  which persists every value as plain text.
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
| `lexware_sync.reconcile_interval_minutes` | How often the reconciliation poll runs. | `30` |

Every regular expression field is optional. Leaving it empty means it matches everything,
never that it matches nothing. This rule is applied consistently across the whole plugin.

## Permissions

A dedicated `triage_lexware_sync` permission gates the manual triage screen. It is kept separate
from Kimai's general project management permissions, so it can be granted only to the roles
that should decide which order confirmations become projects.

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
