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
2. Any order confirmation that did not convert automatically shows up in a list under the
   Lexware entry of Kimai's main menu, where a user decides for each row whether to convert it
   into a project, reject it, or leave it for later. Leaving it for later simply means doing
   nothing, since the row stays in the list until a decision is made. The list opens on the open
   documents and can be switched to the converted or the rejected ones, so a rejected order
   confirmation can still be converted later if it was rejected by mistake. Every row carries the
   customer, the net total and the number of line items, a link into Lexware and a link to the
   document's PDF, which the plugin fetches through the API and serves from Kimai.
3. Time is booked in Kimai exactly as it always is. This plugin does not add a timesheet screen
   of its own.
4. Once a person pursues a tracked order confirmation into an invoice draft inside Lexware, the
   plugin picks it up the same way, through a webhook with a reconciliation poll as a safety
   net, and lists it in a second screen with the same status filter. Assigning open, not yet
   exported times to it, in either one line per time record or one aggregated line per activity,
   produces a new invoice containing both the draft's original lines and the new time lines,
   pushed back to Lexware. The assignment screen shows the hourly rate and the amount of every
   time record, the running total of the selection and the resulting invoice total, and it warns
   about records without an hourly rate, since those would end up on the invoice at zero. Since
   Lexware offers no update or deletion endpoint for invoices, the original draft stays in
   Lexware afterward; the screen links directly to it so a person can delete it by hand.

## Status

- **Milestone one**, order confirmation ingestion followed by automatic or manual project
  creation, is implemented, including the triage screen's customer, amount and line item
  columns. One small piece is still missing: the icon on Kimai's project list that would mark a
  project as originating from an order confirmation. It is already shown on the project detail
  page; only its placement on the list itself and the direct link to the Lexware document from
  there are outstanding. The full design is written down in the specification linked below.
- **Milestone two**, invoice ingestion followed by timesheet assignment and an outbound
  invoice, is implemented. The full design is written down in the specification linked below,
  sections 12 through 18.
- **Budget derivation**, `lexware_sync.derive_budget_enabled`, is implemented. When an order
  confirmation changes in Lexware after it was already converted, the triage screen offers a
  "resolve changes" button next to that row, leading to a screen that lists exactly what changed
  line by line and lets a person choose which of those changes to apply. The converted project's
  own detail screen offers the same button next to the order confirmation it came from, so the
  change is visible from either direction; there it appears only for a user who also holds
  `manage_lexware_sync`, since the project screen itself is open to a wider audience than the
  Lexware screens are. Kimai's project list gets a "Lexware" column carrying the same warning,
  so a pending change is visible without opening anything. Kimai builds that list's columns in
  its own controller, where a plugin cannot add one, so this rides on a project meta field and
  therefore starts hidden behind the column picker (the icon above the table); switch it on once
  and Kimai remembers it. The marker is written whenever the reconcile command sees the order
  confirmation and whenever a change is resolved, and every reconcile run also repairs any
  project whose marker drifted, so an installation that predates this column fills in on the
  next run rather than needing a backfill. The button and the screen need `lexware_sync.read_lines_enabled`, since
  without stored lines there is nothing to compare; with budget derivation switched off the screen
  still reconciles the line data and clears the changed flag, it just leaves every budget alone. A removed line's activity, and any
  time already booked against it, is never deleted or reassigned by this plugin, only its derived
  budget is reset to zero; Kimai's own timesheet edit screen already covers moving booked time to
  a different activity by hand.
- The **license check** is present but switched off until the licensing service exists. Two
  things in `Resources/config/services.yaml` switch it on, and both are needed: the
  `KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate` alias has to point at
  `ServiceBackedLicenseGate`, and the `lexware_sync.license_public_keys` parameter has to carry
  the licensing service's public key, which ships as an empty list. Change only the alias and
  the plugin refuses every license, including a genuine one, though it does say so plainly
  rather than blaming the network.

Full design: [`docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md`](docs/superpowers/specs/2026-09-04-kimai-lexware-sync-design.md).

## Requirements

- A Kimai 2 installation. The exact supported version is pinned in `composer.json` through
  `extra.kimai.require`.
- A Lexware Public API key, not a Partner API key. Generate one at
  `https://app.lexware.de/addons/public-api` and enter it as `lexware_sync.api_key` in Kimai's
  system configuration screen, described below. Kimai's system configuration persists every
  value as plain text in the database, so this key is only as protected as the rest of that
  table, the same as any other credential stored there.
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
| `lexware_sync.license_key` | The license key from the purchase confirmation. Once the license check described below is switched on, an order confirmation without a confirmed license is not converted into a project and an invoice is not written back to Lexware. Shipped with the check switched off, so an empty value here changes nothing yet. Rendered as a password field the same way as the API key below. | empty |
| `lexware_sync.api_key` | The Lexware Public API key used to authenticate every request. Rendered as a password field that always displays blank; leaving it blank on save keeps the currently stored key, entering a value replaces it. | empty |
| `lexware_sync.public_base_url` | The public base URL Lexware should call, used by the "Connect webhooks" button below the API key field. Empty uses this Kimai instance's own configured URL, which is right in production behind a real domain but wrong in local development, where this should be set to a tunnel's public HTTPS URL (see "Running it" below). | empty |
| `lexware_sync.auto_convert_enabled` | Automatically convert an order confirmation into a project when the title rule matches. | `false` |
| `lexware_sync.title_regex` | Regular expression checked against the order confirmation's title. An empty value matches every order confirmation. | empty |
| `lexware_sync.read_lines_enabled` | Read order confirmation line items and create a matching Kimai activity for each one. | `false` |
| `lexware_sync.line_regex` | Regular expression checked against each line's name and description. An empty value matches every line that is not purely informational. | empty |
| `lexware_sync.reconcile_interval_minutes` | How often an administrator should schedule the reconciliation poll to run. The plugin does not enforce this interval itself, it only reads it back as documentation for the cron entry described below. | `30` |
| `lexware_sync.invoice_title_regex` | Regular expression checked against an invoice draft's title, applied on top of its `relatedVouchers` link to a tracked order confirmation. An empty value matches every title. | empty |
| `lexware_sync.project_completion_mode` | What marking a project completed, offered when converting a tracked invoice, actually does: `end_date` sets an end date on the project, `hidden` hides its visibility. | `end_date` |
| `lexware_sync.project_title_source` | What a converted project's name is set to: the voucher number, the order confirmation's own title, or the customer's name combined with the title. `orderNumber`, `orderDate` and `comment` are always populated from the voucher number, voucher date and title regardless of this choice. | `voucher_number` |
| `lexware_sync.check_api_key_interval_days` | How often an administrator should schedule the API key health check to run. Like `lexware_sync.reconcile_interval_minutes`, the plugin does not enforce this itself, it only compares a stored check result's age against this value to decide whether to show a "this has not run in a while" warning on the triage and invoice screens. | `7` |
| `lexware_sync.check_license_interval_days` | The same, for the license health check. | `1` |
| `lexware_sync.derive_budget_enabled` | Derive a time and cost budget for each hour based order confirmation line, set on its Kimai activity and summed onto the project. Only has an effect together with `lexware_sync.read_lines_enabled` above. | `false` |
| `lexware_sync.budget_unit_regex` | Regular expression checked against a line's unit name to decide whether it counts as hours for the budget above. Unlike every other regex field in this plugin, an empty value here does **not** match everything, it falls back to a hardcoded default (`/^(Stunden?\|Std\.?\|hours?\|hrs?\|h)$/i`), so material and goods lines are not silently counted as billable hours the moment this feature is turned on. | the hardcoded default above |

Every regular expression field is optional. Leaving it empty means it matches everything,
never that it matches nothing, with one deliberate exception:
`lexware_sync.budget_unit_regex` above falls back to a sensible default instead, since matching
every unit by default would defeat the point of separating hours from material.

## Running it

Installing the plugin, with `bin/console kimai:reload -n` followed by
`bin/console kimai:bundle:lexware-sync:install`, only makes its code and database tables
available. Two further steps are needed before it actually keeps Kimai and Lexware in sync.

First, three console commands need a cron entry, since the plugin has no scheduler of its own,
and a fourth joins them once the license check described in the Status section above is switched
on:

- `bin/console kimai:lexware-sync:reconcile` polls Lexware for order confirmations that a
  webhook delivery might have missed. Schedule it to run as often as the
  `lexware_sync.reconcile_interval_minutes` setting above says, since that setting exists to
  tell whoever sets up this cron entry what interval to use, not to make the plugin schedule
  itself.
- `bin/console kimai:lexware-sync:check-api-key` confirms the configured Lexware API key still
  authenticates. Schedule it about once a week, well ahead of the key's twenty four month
  expiry, so an administrator notices a key that needs renewing instead of finding out when
  synchronization silently stops. Besides logging and a cron exit code, its result is also
  recorded and shown as a warning banner on the triage and invoice screens, both when the check
  actively fails and when it has not run at all in longer than
  `lexware_sync.check_api_key_interval_days` allows, for example because the cron entry itself
  was removed.
- `bin/console kimai:lexware-sync:reconcile-invoices` polls Lexware for invoice drafts that a
  webhook delivery might have missed, on the same interval as the order confirmation
  reconciliation poll above.
- `bin/console kimai:lexware-sync:check-license` confirms the configured license with the
  licensing service, but only once the license check is switched on: shipped, nobody has a
  license key, and running this command daily against the shipped state means a cron entry that
  fails every day and a daily error mail for nothing. Schedule it once a day, and only from the
  day the check is switched on. It usually does nothing, because it only asks again when the
  stored confirmation says it is time. It exits with a failure code when the license is refused,
  so that a cron mail or a monitoring system notices before a user does. Like the API key check,
  every run also records a result shown as a warning banner on the triage and invoice screens
  once it has not run in longer than `lexware_sync.check_license_interval_days` allows.

Second, the real Lexware webhook subscriptions have to be registered, once this Kimai instance is
reachable at the public HTTPS endpoint described in the Requirements section above. Click
"Connect webhooks" below the API key field on the system configuration screen: it registers all
six event subscriptions needed for both milestones in one step (`order-confirmation.created`,
`order-confirmation.changed` and `order-confirmation.status.changed` pointing at
`/webhook/lexware/order-confirmation`, plus `invoice.created`, `invoice.changed` and
`invoice.status.changed` pointing at `/webhook/lexware/invoice`), skipping any that already exist
with the same callback URL, so clicking it again after some already succeeded is harmless.

In local development, where this instance has no public HTTPS endpoint of its own, expose it
through a tunnel (for example `ngrok http <port>` or a Cloudflare Tunnel) and put that tunnel's
public HTTPS URL into `lexware_sync.public_base_url` before clicking the button, since Lexware
needs somewhere it can actually reach to deliver the webhook. A tool like
[webhook.site](https://webhook.site) only captures and displays what Lexware sends, it does not
forward the request into this Kimai instance, so it is useful for inspecting a payload once but
not a substitute for a tunnel. Either way, the reconciliation poll described above keeps
milestone one and two fully functional even before any webhook is connected, only with a delay of
up to the configured poll interval instead of an instant update.

## Permissions

A dedicated `manage_lexware_sync` permission gates both the order confirmation triage screen and
the invoice assignment screen. It is kept separate from Kimai's general project management
permissions, so it can be granted only to the roles that should decide which order confirmations
become projects and which invoice drafts get their combined invoice created. The Lexware
entry in the main menu, including the badge that counts the open documents of each screen, is
only rendered for users who hold that permission.

## The license signing key

The license check verifies a signed artefact against a fixed list of Ed25519 public keys,
`lexware_sync.license_public_keys` in `Resources/config/services.yaml`, which ships empty because
the check is switched off and the licensing service does not exist yet. Once that service is
ready to sign real licenses, generate its key pair with:

```bash
php -r '$pair = sodium_crypto_sign_keypair(); echo "public: ", base64_encode(sodium_crypto_sign_publickey($pair)), PHP_EOL, "secret: ", base64_encode(sodium_crypto_sign_secretkey($pair)), PHP_EOL;'
```

Put the printed public key into `lexware_sync.license_public_keys` here. The printed secret key
belongs to the licensing service project, never to this repository: whoever holds it can sign a
license. Run the command somewhere its output does not end up in a stored transcript, such as a
local shell rather than an assistant session or a logged terminal, and move the secret key
straight into the licensing service's own configuration.

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
