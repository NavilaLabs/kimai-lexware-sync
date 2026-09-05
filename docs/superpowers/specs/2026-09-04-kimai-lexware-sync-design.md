# KimaiLexwareSync: design specification

Status: approved for milestone one and milestone two, both in detail.
Date: 2026-09-04, milestone two design added 2026-09-05

## 1. Purpose

`KimaiLexwareSync` is a Kimai plugin, a bundle, that keeps Kimai (time tracking) and Lexware
(accounting, formerly known as lexoffice) loosely synchronized, without either system replacing
or mirroring the other. Kimai stays the system of record for projects, activities and time
entries. Lexware stays the system of record for order confirmations and invoices. The plugin
is a bridge between the two systems, moving in one direction at each step, and it always
requires a human decision at the points where business judgement matters, such as whether a
particular order confirmation should become a project, or which timesheets belong on a
particular invoice.

Target deployment: a single company's own Kimai instance, synchronized to that same company's
own Lexware account, through the Lexware Public API, a static bearer key, not the Partner API
and its OAuth flow. See `.claude/skills/kimai-lexware-sync/references/lexware-api.md` for why
that distinction matters.

## 2. Two milestones

The full workflow the plugin supports has two halves that only share a database and a
configuration namespace, not any runtime logic.

- **Milestone one**, covered by this specification in detail: Lexware order confirmations flow
  in, become tracked records, and, either automatically or through a manual triage step, become
  Kimai projects and activities with a recorded, auditable link back to their source order
  confirmation.
- **Milestone two**, covered in detail from section 12 onward: a Lexware invoice draft, created
  by a person inside Lexware itself by "pursuing" a tracked order confirmation, surfaces inside
  Kimai. A user assigns booked timesheets onto it, and a new, combined invoice, containing both
  the draft's original lines and the newly assigned timesheet lines, is pushed back to Lexware.

## 3. Feasibility findings, verified against a real Lexware account on 2026-09-04

These are the grounding facts the design below relies on. See the two reference documents under
`.claude/skills/kimai-lexware-sync/references/` for the fuller picture.

- The endpoint `/v1/order-confirmations` exists, is populated by Lexware whenever an order
  confirmation is written, and has a free text `title` field. Its default text is generic, for
  example "Auftragsbestätigung", so this project relies on a person manually customizing the
  title of any order confirmation that should match a rule.
- Real order confirmation line items observed in the account came back typed as `custom`, even
  for a line that is conceptually a service. Lexware only types a line as `service` when it
  references an entry in the account's article catalog, never for a free text line. Rule
  matching therefore ignores the line type, except that it always skips a line typed `text`,
  since that kind of line is purely informational, and instead matches on the line's name and
  description.
- An invoice created through Lexware's "pursue" flow from an order confirmation carries a
  `relatedVouchers` entry that points back to that order confirmation's identifier and voucher
  number. That is a reliable structural link, independent of any title pattern. Milestone two
  will use this as the primary way to correlate an invoice to its order confirmation. Any
  configured regular expression is an additional, optional filter on top of that link, never
  the only path to it. This follows the project's general rule that every regular expression
  field is optional, and an empty one matches everything.
- Lexware webhooks, through `/v1/event-subscriptions`, are real. The full accepted set of event
  type values was obtained directly from the API's own validation error, and includes
  `order-confirmation.created`, `order-confirmation.changed` and
  `order-confirmation.status.changed` for milestone one, plus `invoice.created`,
  `invoice.changed` and `invoice.status.changed` for milestone two. A real subscription was
  registered against the live sandbox account on 2026-09-04, and a real delivery was captured
  after an order confirmation was saved in the Lexware interface. The delivered payload is a
  thin notification, not the full resource: `organizationId`, `eventType`, `resourceId`,
  `eventDate`, confirming the plan to always refetch the resource by its identifier rather than
  trust the payload for data. The signature arrives in the `X-Lxo-Signature` header, is RSA-SHA512
  over the exact raw request body bytes as transmitted, and verifies against the public key
  published at `https://developers.lexware.io/webhookSignature/public/public_key.pub`. Both the
  live delivery and the signature verification were confirmed end to end with the actual captured
  request and the actual published key, not assumed from a third party client's behavior alone.
- Lexware invoices and order confirmations have no update endpoint and no documented deletion or
  void endpoint. This confirms that milestone two cannot edit an existing Lexware invoice in
  place. It must create a new invoice containing the combined line items, and can at most flag
  the original invoice for a person to delete manually inside the Lexware interface.
- The production Kimai instance does not yet have a public HTTPS endpoint with a valid
  certificate. Lexware requires a rating of grade A or better and accepts no self signed
  certificate. This is a deployment precondition for webhook delivery, tracked in section 11,
  and is not something the plugin itself can provide.

## 4. Architecture for milestone one

An order confirmation reaches the plugin through one of two triggers: a Lexware webhook call,
or a periodic reconciliation poll that acts as a safety net. Both triggers lead into the exact
same processing path, so there is only one piece of code that turns a Lexware order
confirmation into Kimai state. The trigger only decides when that path runs, never what it
does.

Symfony Messenger, which would be the obvious way to decouple the trigger from the processing,
is not among the libraries Kimai ships to its plugins, confirmed against both the local
installation and the current Kimai source. Since a plugin may not add a dependency of its own,
the processing described below runs synchronously, inside the same request that received the
webhook call, or the same console invocation that ran the reconciliation poll.

1. `LexwareWebhookController` receives the webhook call and verifies its signature, or the
   reconciliation command notices a new or changed voucher identifier during its poll.
2. Either trigger calls the same synchronizer service directly, passing only the order
   confirmation's identifier, never any of its business data.
3. The synchronizer refetches the full order confirmation by identifier from the Lexware API.
   The webhook body itself is never treated as trustworthy data, only as a signal to go and
   fetch the current, authoritative state.
4. The synchronizer upserts a `TrackedOrderConfirmation` record from that fresh response.
5. If the configured matching rules pass, the synchronizer creates the corresponding Kimai
   customer, if one does not already exist, then the project, and then, if line reading is
   enabled, the matching activities, all inside one database transaction together with the
   tracking record.
6. If the rules do not pass, the record simply stays in a pending state and becomes visible in
   the triage screen described in section 7.

Running this synchronously means a webhook call only returns once processing has finished. At
the volume one company's own order confirmations produce, this is an acceptable simplification
rather than a real bottleneck. If that assumption ever stops holding, the fallback is a plugin
owned queue table processed by its own cron triggered command, following the same pattern
`ReconcileOrderConfirmationsCommand` already uses, rather than a new runtime dependency.

### Components

| Component | Responsibility |
|---|---|
| `Controller/LexwareWebhookController.php` | Receives the Lexware callback request, verifies the signature, persists the raw event as an audit trail, and then calls `OrderConfirmationSynchronizer` directly. Any exception raised during that call is caught and recorded against the audit trail entry, rather than left to fail the response, since the reconciliation poll is the real safety net for a failed attempt. |
| `Service/OrderConfirmationSynchronizer.php` | The single entry point both triggers call. Refetches `GET /v1/order-confirmations/{id}`, upserts the `TrackedOrderConfirmation` record, applies the matching rules, and delegates to `OrderConfirmationProcessor` for the actual Kimai entity creation, all inside one Doctrine transaction. |
| `Command/ReconcileOrderConfirmationsCommand.php` | A cron triggered console command. It pages through `GET /v1/voucherlist` filtered to order confirmations, compares the results against the identifiers and modification dates already known locally, and calls the same synchronizer for anything new or changed. This is the safety net for missed webhook deliveries and for any earlier attempt that failed. |
| `Service/LexwareApiClient.php` | A thin, hand written client on top of the Symfony HTTP client, covering exactly the endpoints this project needs: contacts, order confirmations, invoices for milestone two, and event subscriptions. It paces its own requests to Lexware's documented limit of two requests per second. It is deliberately not a third party library; see section 9 for the licensing reason. |
| `Service/LexwareWebhookVerifier.php` | Fetches and caches Lexware's signature public key, and verifies each incoming callback before anything about it is trusted. |
| `Service/OrderConfirmationProcessor.php` | The core business logic: mapping or creating a Kimai customer for a Lexware contact, creating the project, creating one activity per matching line, and choosing a random color from Kimai's configured palette for each. Used identically by the automatic path and by the manual "convert" action in the triage screen. |
| `Controller/TriageController.php` and its templates | The manual user interface: the list of pending order confirmations shown from Kimai's project overview, and the convert and reject actions. |

## 5. Data model

Four new tables, owned entirely by this plugin, with no change to a core Kimai entity beyond
the foreign keys that reference it.

**The order confirmation table** holds one row per Lexware order confirmation: an internal
identifier, the Lexware identifier as a unique value, the voucher number, the title, the
voucher date, the Lexware contact identifier, the contact name copied out of the payload so the
review screen can filter on it in SQL instead of decoding every stored payload, the raw payload
as the last full response received from the API (kept for debugging and for answering audit
questions, since the webhook body itself is never trusted), a status of pending, automatically
converted, manually converted or rejected, a flag recording whether a change arrived after
conversion (surfaced as a hint icon in Kimai's project list, never applied automatically, see
section 6), a nullable reference to the Kimai project it produced, a nullable reference to the
Kimai customer involved, the timestamps for when it was first seen and last synchronized, when
it was processed, and, only when a manual triage action set it, which user processed it.

**The order confirmation line table** holds one row per line item, populated only when line
reading is enabled: an internal identifier, a reference to its order confirmation, its position,
its type, its name, its description, whether it matched the configured pattern, and a nullable
reference to the Kimai activity it produced.

**The contact mapping table** holds the link between a Lexware contact and a Kimai customer,
since a Lexware contact carries no field of its own to store an external reference: an internal
identifier, the Lexware contact identifier as a unique value, a reference to the Kimai customer,
and when the mapping was created.

**The webhook event table** is an audit log of every inbound delivery, valid or not: an internal
identifier, the event type, the resource identifier, when it was received, whether its signature
was valid, whether it was processed, and any error text. This table is not business critical on
its own, but it is what makes it possible to answer "why was this order confirmation not
processed" without searching through log files.

## 6. Configuration and matching rules

Exposed through Kimai's system configuration screen, the standard plugin extension point for
this:

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.api_key` | The Lexware Public API key used to authenticate every request. Rendered as a password field that always displays blank; leaving it blank on save keeps the currently stored key. | empty |
| `lexware_sync.public_base_url` | The public base URL used to build webhook callback URLs when the "Connect webhooks" button registers event subscriptions with Lexware. Empty uses this instance's own configured URL. | empty |
| `lexware_sync.auto_convert_enabled` | Automatically convert an order confirmation into a project when the title rule matches. | `false` |
| `lexware_sync.title_regex` | A regular expression checked against the order confirmation's title. An empty value matches everything. | empty |
| `lexware_sync.read_lines_enabled` | Read order confirmation line items and create a matching activity for each one. | `false` |
| `lexware_sync.line_regex` | A regular expression checked against each line's name and description together. An empty value matches every line that is not purely informational. | empty |
| `lexware_sync.reconcile_interval_minutes` | How often the reconciliation poll runs. | `30` |

Every regular expression field is compiled and validated when it is saved, and rejected
immediately if invalid, rather than failing later while an event is being processed.

The flow for each event is as follows. First, the freshly fetched resource is upserted into the
tracking table, staying in the pending state if this is a new record. Second, if automatic
conversion is enabled, and the title rule is either empty or matches the title, the conversion
runs: the contact is looked up or a new customer is created for it, the project is created, and
the status becomes automatically converted. Otherwise, the record stays pending and appears in
the triage screen. Third, whenever a conversion happens, whether automatic or manual, and line
reading is enabled, every line that is not typed as purely informational, and whose name and
description are either not filtered by the line pattern or match it, produces one Kimai
activity on that project.

Customer mapping works as follows. If the order confirmation's contact has no existing row in
the contact mapping table, a new Kimai customer is always created. There is deliberately no
matching by name similarity, since silently merging into the wrong existing customer would be a
worse outcome than an occasional duplicate that a person resolves by hand. The new mapping is
then stored, so the same Lexware contact never produces a second Kimai customer later.

Colors work as follows. Both the new project and any new activities receive a color chosen at
random from Kimai's own configured color palette, the same list an administrator curates for
the rest of the interface, rather than an arbitrary color value.

## 7. Triage screen and permissions

A dedicated permission, `manage_lexware_sync`, gates the manual triage feature. Only a role
granted this permission sees it. It is kept separate from Kimai's general project management
permissions on purpose. The same permission also gates milestone two's invoice assignment screen,
see section 15; the name was chosen, and the permission renamed from its milestone one working
name of `triage_lexware_sync`, once it became clear during milestone two's design that it would
need to cover more than the order confirmation triage screen alone. Since the permission is a
static role mapping declared in the bundle's own configuration, not a per-user assignment stored
in the database, the rename is a plain code change with no migration.

A new button appears in Kimai's project overview, labeled to show pending order confirmations,
with a badge showing how many are currently pending. Opening it shows a list, built from Kimai's
existing table components for visual consistency, with the voucher number, the title, the
customer, the date, the amount, and a preview of its lines.

Milestone one ships this list with only the voucher number, the title and the date. The customer
column, the amount column and the line preview are not yet built, and the table is a plain
markup table rather than Kimai's own table component. None of the three missing columns block a
correct decision, since converting or rejecting only ever needs the voucher number and the title
to identify the right row, but they were part of the original intent and are tracked as a small
follow-up rather than quietly dropped.

Both review screens, the order confirmation triage screen and the invoice draft screen, page
their table rather than rendering every tracked row, and each offers a search field next to the
status filter. The search matches the voucher number, the contact name and, for an order
confirmation, the title, all as a substring and case insensitively, which is why the contact
name is stored as its own column rather than read back out of the raw payload. The status
counts next to the filter buttons are computed under the same search term, so the numbers always
describe the list actually being shown. The status filter, the search term and the current page
travel with every row action, so converting or rejecting a document returns to the same place in
the list.

Each row offers two actions. Converting to a project runs the identical processor logic used by
the automatic path, skipping the regular expression check since this is an explicit human
decision, sets the status to manually converted, and records which user made the decision.
Rejecting sets the status to rejected, which removes the row from the list while keeping it in
the database for later reference. There is deliberately no third button for leaving a row
alone, since doing nothing already achieves that: the row simply stays pending and keeps
appearing until someone acts on it.

Kimai's normal project list shows a small icon on any project that originated from an order
confirmation, with a tooltip carrying the voucher number and a link to the Lexware document,
plus a warning icon whenever that order confirmation changed after it was converted.

Milestone one ships a first version of this indicator on the project detail page only, showing
the voucher number and the changed-after-conversion warning, since the detail page already has
a documented extension point for it. The project list placement and the link to the Lexware
document are not yet built: putting an icon on the list itself needs a deliberate choice between
Kimai's meta field mechanism, which would make the column opt-in rather than always visible, and
some other approach, and the document link needs the real Lexware web application deep link
pattern confirmed against a live account first. Both are tracked as a small follow-up task, not
abandoned.

## 8. Error handling and integrity

An invalid or missing webhook signature is logged in the webhook event table with its validity
flag set to false, the request receives an unauthorized response, and the synchronizer is never
called. A delivery that is unsigned or signed incorrectly is never trusted.

The webhook body is never trusted for data. It only triggers a refetch of the resource through
the authenticated API, and only that freshly fetched, authenticated response is ever processed.

Every conversion is atomic. Upserting the tracking record and creating the customer, project and
activity records happen inside one Doctrine transaction, so there is never a partially created
Kimai project without a tracking record, or the other way around.

Deduplication is keyed on the Lexware identifier together with its modification date. An event
whose modification date is not newer than what is already stored is treated as a no-op,
regardless of whether it arrived through the webhook or through the reconciliation poll, since
both funnel through the same synchronizer.

There is no message queue to retry a failed attempt automatically. When the synchronizer is
called from the webhook controller and raises an exception, the error is caught, its text is
recorded against the webhook event table entry, and the request still receives a response,
rather than causing Lexware to see a failure and apply whatever retry behavior it has. The
reconciliation poll picks up the same order confirmation again on its next run, since it
re-checks every voucher's modification date regardless of why an earlier attempt did not
succeed. When the synchronizer is called from the reconciliation command itself and raises an
exception, the command logs it and continues with the next voucher, rather than letting one bad
record stop the whole poll.

Requests toward Lexware are paced client side to stay within the documented limit of two
requests per second, independent of which trigger caused them.

If a matched or mapped Kimai customer's currency is not the euro, which never happens on a
freshly created customer since new customers are always created in euros, but which could
happen if a mapping is ever pointed at an existing customer by hand, the conversion is refused
with a visible error in the triage screen, rather than silently producing a booking in the wrong
currency.

Lexware Public API keys expire after twenty four months with no automatic renewal. A low cost,
weekly health check call surfaces an authentication failure as a visible Kimai notification and
log entry, well before the synchronization would otherwise go dark without anyone noticing.

## 9. Dependencies and licensing

The Lexware API client is written from scratch on top of the Symfony HTTP client, rather than
built on the third party package `baebeca/lexware-php-api`. That package is licensed under AGPL
version three, with a paid commercial alternative. Since this plugin may be published publicly
later, taking on an AGPL dependency now would force the whole plugin into AGPL compatible
licensing. A client written from scratch, covering only the small number of endpoints actually
used, avoids that constraint entirely and keeps full control over retry behavior, request
pacing, and idempotency handling.

## 10. Testing strategy

This deployment has no test harness for plugins. It is a production release build of Kimai,
with no `tests` directory, no `phpunit.xml.dist`, `APP_ENV` set to `prod`, and a single database
with no separate test environment configured. Setting up a full kernel boot against a real
Doctrine test database, which is what an integration or functional test in the usual Symfony
sense would need, is infrastructure work that reaches outside this plugin and was deliberately
not taken on for milestone one.

Automated tests are therefore limited to plain PHPUnit tests that need neither the Symfony
kernel nor a database connection: the matching rule logic, the Lexware API client against a
mocked HTTP client, the random color selection, and, using the header and payload mechanism the
spike below confirmed, signature verification. These run against the plugin's own classes with
Kimai's `vendor/autoload.php` for its dependencies, nothing more.

Anything that touches Doctrine or the kernel, meaning the synchronizer, the processor, and the
webhook and triage controllers, is verified by hand, following the lint, boot, exercise loop the
`kimai-plugin` skill describes for plugins generally: rebuild the cache, confirm the service and
route wiring with the relevant `debug:*` commands, run the install command, and exercise the real
behavior through the Lexware sandbox account and the Kimai browser interface. Each task in the
implementation plan that touches this layer states exactly what to click through or call to
confirm it works.

One manual spike happened before the "hardened" verifier was written: a real event subscription
was registered against the live account, pointed at a disposable webhook dot site inspection
endpoint, a real order confirmation was saved to trigger a real delivery, and the captured
request confirmed the exact signature header, algorithm and payload shape before any code was
written that enforces anything based on them. The signature was independently verified against
the actual captured body and the actual published public key, not merely inferred from a third
party client's source. The disposable subscription was deleted immediately afterward. See
section 3 for the confirmed mechanism.

## 11. Open items carried into implementation

A public HTTPS endpoint with a valid certificate for the production Kimai instance is an
infrastructure precondition owned by the user, not by the plugin, and webhook delivery cannot be
tested end to end until it exists. Because of the reconciliation poll, milestone one stays fully
functional even before that precondition is met, only with a delay of up to the poll interval
instead of an instant update.

## 12. Milestone two: architecture

Milestone two mirrors milestone one's shape, moving in the opposite direction: milestone one
brings a Lexware order confirmation into Kimai, milestone two pushes Kimai timesheet data out to
a Lexware invoice. The trigger, however, does not start on the Lexware side by itself. A person
must first act inside Lexware's own interface, using its "pursue" function on a tracked order
confirmation, to create an invoice draft. Everything the plugin does happens after that draft
already exists.

1. A person opens a tracked order confirmation inside Lexware and uses "pursue" to create an
   invoice draft from it. Lexware may allow this more than once for the same order confirmation,
   for example when a project is billed in several batches over time; each resulting draft
   carries its own `relatedVouchers` entry pointing back to the same order confirmation.
2. A Lexware webhook call (`invoice.created`, `invoice.changed`, `invoice.status.changed`) or the
   periodic reconciliation poll, paging through `GET /v1/voucherlist` filtered to invoices,
   notices the draft. Exactly as in milestone one, the webhook body itself is never trusted for
   data; both triggers only ever signal that the plugin should refetch the resource by its
   identifier through `GET /v1/invoices/{id}`.
3. The freshly fetched invoice is only tracked as a `TrackedInvoice` when both of the following
   hold: its `voucherStatus` is `draft`, and its `relatedVouchers` entry points to an order
   confirmation already present in the `TrackedOrderConfirmation` table. An invoice with no such
   link, for example an unrelated invoice created directly in Lexware with no connection to a
   Kimai project, is ignored entirely and never appears anywhere in Kimai, following the same
   rule milestone one applies to an unmapped Lexware contact: an unmatched record is left alone,
   never guessed at. If a configured `lexware_sync.invoice_title_regex` is set, it is applied as
   a further, optional filter against the draft's title on top of the `relatedVouchers` match,
   following the project's usual rule that an empty pattern matches everything.
4. A previously tracked invoice draft that a person finalized directly inside Lexware, bypassing
   the plugin entirely, is detected the same way: its `voucherStatus` is no longer `draft` on the
   next refetch. Its `TrackedInvoice` record is marked superseded, a status distinct from
   rejected precisely so it is never confused with an explicit human decision, since there is
   nothing left for the plugin to do with it, and it disappears from the pending list for good.
   Lexware's own documented status transitions never move an invoice back to `draft` once it has
   left that status, so this state is permanent and the synchronizer never reconsiders it again.
   A `TrackedInvoice` already converted through the plugin is left alone the same way: the
   superseded original draft going on existing in Lexware, untouched until a person deletes it by
   hand, must never cause the synchronizer to walk a converted record backward into pending.
5. A person opens the pending list inside Kimai, described in section 15, and either converts a
   tracked invoice draft by assigning timesheets to it, or rejects it to intentionally leave it
   for manual handling entirely inside Lexware.
6. Converting calls `InvoiceProcessor`, which builds the combined line item list, calls
   `LexwareApiClient::createInvoice()` to create the new invoice in Lexware, and, only once that
   call succeeds, records the result locally in one Doctrine transaction: the `TrackedInvoice`
   status becomes converted, the selected timesheets are marked exported, and, if requested, the
   linked project is marked completed.

As in milestone one, there is no message queue available to a plugin, so this all runs
synchronously inside the same request that received the webhook call, the same console
invocation that ran the reconciliation poll, or the same request that submitted the assignment
page.

### Components

| Component | Responsibility |
|---|---|
| `Service/InvoiceSynchronizer.php` | The single entry point both triggers call, mirroring `OrderConfirmationSynchronizer`. Refetches `GET /v1/invoices/{id}`, applies the `draft` status and `relatedVouchers` checks and the optional title filter, and upserts the `TrackedInvoice` record. Never touches a record already converted or superseded; marks a still-pending or still-rejected record superseded if the draft moved past `draft` status outside the plugin. |
| `Command/ReconcileInvoicesCommand.php` | A cron triggered console command, mirroring `ReconcileOrderConfirmationsCommand`. Pages through `GET /v1/voucherlist` filtered to invoices, compares against identifiers and modification dates already known locally, and calls the synchronizer for anything new or changed. Runs on the same configured interval as `ReconcileOrderConfirmationsCommand`, as a second cron entry. |
| `Service/InvoiceProcessor.php` | The core business logic, mirroring `OrderConfirmationProcessor`. Builds the new invoice's line items from the draft's own, unchanged original lines together with the newly assigned timesheets, in the shape the person chose, calls the Lexware client to create it, and, on success, marks the affected timesheets exported and optionally marks the linked project completed. |
| `Controller/InvoiceAssignmentController.php` and its templates | The manual user interface described in section 15: the pending list, the per-draft assignment page, and the convert and reject actions. |
| `LexwareApiClient` (extended) | Gains `createInvoice()`, `getInvoice()` and a filtered `findInvoices()` lookup used only for the ambiguous-failure check described in section 16. |
| `LexwareWebhookController` (extended) | Gains a dispatch step that reads the event type and calls either `OrderConfirmationSynchronizer` or `InvoiceSynchronizer`, otherwise unchanged from milestone one. |

## 13. Milestone two: data model

One new table, following the same principle as milestone one: no change to a core Kimai entity
beyond the foreign keys that reference it.

**The tracked invoice table** holds one row per Lexware invoice draft the plugin has decided to
track: an internal identifier, the Lexware identifier as a unique value, the voucher number, the
voucher date, the contact name copied out of the payload for the same search reason as on the
order confirmation table, the raw payload as the last full response received from the API, a
reference to the `TrackedOrderConfirmation` row its `relatedVouchers` entry points to, a status
of pending, converted, rejected or superseded, the last of these meaning the draft left `draft`
status inside Lexware without ever being converted through the plugin, a nullable timestamp
recording when an invoice creation attempt started, used only for the duplicate protection
described in section 16, the Lexware identifier of the new, combined invoice once one has been
created, the timestamps for when it was first seen and last synchronized, when it was converted
or rejected, and, whichever action a person took, which user took it.

There is deliberately no separate line table for the tracked invoice, unlike the order
confirmation line table in milestone one. The draft's original lines are never matched against a
rule or turned into a separate Kimai entity; they are only read back out of the raw payload,
unchanged, when the new combined invoice is built.

Whether a converted timesheet needs an additional field of its own, to detect and warn about an
edit that happened after its invoice was created, or whether comparing its existing modification
timestamp against the `TrackedInvoice` row's converted timestamp is enough, is a small detail
left to the implementation plan rather than decided here; both are within Kimai's own `Timesheet`
entity capabilities and neither changes this design.

## 14. Milestone two: configuration

Two new keys, exposed through the same system configuration screen milestone one already uses:

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.invoice_title_regex` | A regular expression checked against an invoice draft's title, applied on top of the `relatedVouchers` match. An empty value matches everything. | empty |
| `lexware_sync.project_completion_mode` | What "mark project completed", offered as an option when converting a tracked invoice, actually does: set an end date on the project, or hide its visibility. | `end_date` |

`lexware_sync.reconcile_interval_minutes`, already defined in section 6, is reused unchanged for
`ReconcileInvoicesCommand`'s cadence; there is no reason for the two reconciliation polls to run
on different schedules.

## 15. Milestone two: invoice assignment screen

The same `manage_lexware_sync` permission introduced in section 7 gates this screen, alongside
the existing triage screen.

A pending list, structured the same way as the order confirmation triage list, shows every
`TrackedInvoice` still in the pending state: its voucher number, its title, its voucher date, and
the Kimai project its related order confirmation produced. Each row offers two actions,
convert and reject, with the same meaning as the equivalent triage actions: converting opens the
assignment page described below, rejecting marks the row rejected and removes it from the list,
with no third button needed, since leaving a row untouched already keeps it pending. A row that
was rejected reappears automatically if the underlying draft in Lexware changes again afterward,
detected the same way as everywhere else in this project, by its modification date advancing
past what is already stored.

Opening a pending row leads to a dedicated assignment page, not a popup, for that one draft. It
shows the draft's original lines, read only, exactly as fetched from Lexware, and below them a
table of every timesheet booked on the linked project that is not yet exported, each with a
checkbox. A person picks a line item shape for the timesheets they select, either one invoice
line per timesheet or one aggregated line per activity, and the page previews the resulting new
line items, which stay editable by hand before submission. The original lines are never
editable on this page; only the newly generated timesheet lines are.

Submitting the page offers two optional checkboxes: finalize the new invoice immediately
(`?finalize=true` on creation, rather than leaving it as a further draft), and mark the linked
project completed, using whichever behavior
`lexware_sync.project_completion_mode` configures. Neither checkbox affects whether the new
invoice is created at all; both only change what happens once it has been.

On success, a confirmation is shown carrying a link to the superseded original draft, so a
person can go delete it inside Lexware. The link points at Lexware's own filtered voucher list,
`https://app.lexware.de/vouchers#!/VoucherList/?filter=invoice&sort=sortByVoucherDate&sortDirection=desc&query={voucherNumber}`,
substituting the original draft's voucher number, confirmed directly against the live account
during this design's brainstorming rather than assumed. Nothing about the superseded draft is
changed automatically; deleting it stays a manual action, since Lexware offers no deletion
endpoint at all.

## 16. Milestone two: error handling and integrity

The same integrity principles milestone one established apply here without change: the webhook
body is never trusted for data, only ever a signal to refetch; deduplication is keyed on the
Lexware identifier together with its modification date; and every locally recorded outcome of a
conversion, the `TrackedInvoice` status, the timesheets marked exported, and an optional project
completion, happens together inside one Doctrine transaction.

Creating a Lexware invoice is the one step in this whole plugin that is not naturally
idempotent: unlike every other Lexware call this plugin makes, `POST /v1/invoices` has a
real, visible side effect with no way to detect after the fact whether an ambiguous failure,
such as a timeout or a dropped connection, still went through on Lexware's side. To guard
against that, the `TrackedInvoice` row's creation-attempted timestamp is written and committed
by itself, before the `POST /v1/invoices` call happens, outside the transaction described above.

- A clear, synchronous failure response from Lexware, such as a validation error, is treated as
  the call never having happened: the attempted timestamp is cleared, the selected timesheets
  stay available for selection, and the person sees the actual error Lexware returned.
- An ambiguous failure, a timeout or a dropped connection, leaves the attempted timestamp in
  place and shows a visible message instead of silently retrying. The assignment page then
  offers a "check status" action in place of the normal submit button, which searches for a
  plausible match with `LexwareApiClient::findInvoices()`, filtered by the linked contact and a
  recent date window, and compared against the total amount the failed attempt tried to create.
  This is a heuristic, not a guaranteed answer, since Lexware assigns its own identifier with no
  client supplied idempotency key to look up directly; if a plausible match is found, it is
  surfaced to the person to confirm by eye before anything is recorded as converted, and if none
  is found, the person may retry the original submission.

A timesheet that stopped being eligible between the assignment page being opened and being
submitted, because it was exported by some other action in the meantime, such as Kimai's own
invoicing running concurrently in a second browser tab, is rechecked at submission time and
dropped from the selection with a visible note, rather than silently included or allowed to
overwrite the other action's result.

If the linked Kimai customer's currency does not match the Lexware contact's, the same check
milestone one already performs blocks the conversion with a visible error, for the same reason:
producing a booking in a currency nobody intended is worse than requiring a person to resolve
the mismatch by hand first.

## 17. Milestone two: testing strategy

The same split milestone one uses applies here. Line item aggregation, the title regular
expression filter, the `relatedVouchers` correlation logic, and the webhook event type dispatch
between the order confirmation and invoice paths are all plain PHP logic with no Doctrine or
kernel dependency, and get real PHPUnit tests. `InvoiceSynchronizer`, `InvoiceProcessor`, and the
assignment controller all depend on Doctrine and the kernel, and are verified by hand through the
lint, boot, exercise loop, exactly as milestone one's equivalent components were.

One additional manual spike happens before `LexwareApiClient::createInvoice()` is written: a
real `POST /v1/invoices` call against the sandbox account, to confirm the actual response shape
Lexware returns on success, the same way the webhook signature mechanism was confirmed against a
real delivery before milestone one's verifier was written, rather than assumed from the
documentation alone.

## 18. Open items carried into milestone two implementation

Resolved during implementation, by a live spike against the sandbox account on 2026-09-05: a
Lexware invoice created through `POST /v1/invoices` cannot carry an explicit `relatedVouchers`
entry of its own; one included in the request body is silently dropped. Only an invoice created
through Lexware's own "pursue" flow carries that entry, confirmed by a fresh pursue action during
the same spike. The same spike also found that `GET /v1/invoices` has no bare list endpoint, the
way order confirmations already didn't, and that creating an invoice requires a `shippingConditions`
object milestone one's order confirmations never needed. See the implementation plan's Task 4 and
Task 9 for the corrected client and processor code these findings produced.

The exact field used to detect and warn about a timesheet edited after its invoice was created,
noted as an open detail in section 13, was settled in the implementation plan: a dedicated
`TrackedInvoiceTimesheet` join row per included timesheet, storing a snapshot of its modification
timestamp at the moment of inclusion.
