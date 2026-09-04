# KimaiLexwareSync: design specification

Status: approved for milestone one in detail, milestone two at the roadmap level.
Date: 2026-09-04

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
- **Milestone two**, described only at the roadmap level, see section 12: Lexware invoices, or
  invoice drafts, linked to a tracked order confirmation surface inside Kimai. A user assigns
  booked timesheets onto one of them, and a new, combined invoice is pushed back to Lexware.
  Several questions about its user interface and pricing model are explicitly still open and
  will get their own brainstorming and specification pass once milestone one is live and in
  use.

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
  `invoice.changed` and `invoice.status.changed` for milestone two. Signature verification is
  asymmetric, based on a public key fetched from Lexware and cached, according to a third party
  open source client that implements it, rather than a shared secret. The exact header name and
  payload shape were not resolvable from the documentation pages that could be fetched, and are
  called out as an implementation spike in section 10.
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

1. `LexwareWebhookController` receives the webhook call and verifies its signature, or the
   reconciliation command notices a new or changed voucher identifier during its poll.
2. Either trigger dispatches the same Messenger message, carrying only the order confirmation's
   identifier, never any of its business data.
3. The message handler refetches the full order confirmation by identifier from the Lexware API.
   The webhook body itself is never treated as trustworthy data, only as a signal to go and
   fetch the current, authoritative state.
4. The handler upserts a `TrackedOrderConfirmation` record from that fresh response.
5. If the configured matching rules pass, the handler creates the corresponding Kimai customer,
   if one does not already exist, then the project, and then, if line reading is enabled, the
   matching activities, all inside one database transaction together with the tracking record.
6. If the rules do not pass, the record simply stays in a pending state and becomes visible in
   the triage screen described in section 7.

### Components

| Component | Responsibility |
|---|---|
| `Controller/LexwareWebhookController.php` | Receives the Lexware callback request, verifies the signature, persists the raw event as an audit trail, and dispatches a Messenger message. It always responds quickly and never performs the actual synchronization work inline. |
| `Message/ProcessOrderConfirmationEvent.php` and its handler | Refetches `GET /v1/order-confirmations/{id}`, upserts the `TrackedOrderConfirmation` record, applies the matching rules, and creates Kimai entities when applicable, all inside one Doctrine transaction. |
| `Command/ReconcileOrderConfirmationsCommand.php` | A cron triggered console command. It pages through `GET /v1/voucherlist` filtered to order confirmations, compares the results against the identifiers and modification dates already known locally, and dispatches the same message for anything new or changed. This is the safety net for missed webhook deliveries. |
| `Service/LexwareApiClient.php` | A thin, hand written client on top of the Symfony HTTP client, covering exactly the endpoints this project needs: contacts, order confirmations, invoices for milestone two, and event subscriptions. It paces its own requests to Lexware's documented limit of two requests per second. It is deliberately not a third party library; see section 9 for the licensing reason. |
| `Service/LexwareWebhookVerifier.php` | Fetches and caches Lexware's signature public key, and verifies each incoming callback before anything about it is trusted. |
| `Service/OrderConfirmationProcessor.php` | The core business logic: mapping or creating a Kimai customer for a Lexware contact, creating the project, creating one activity per matching line, and choosing a random color from Kimai's configured palette for each. Used identically by the automatic path and by the manual "convert" action in the triage screen. |
| `Controller/TriageController.php` and its templates | The manual user interface: the list of pending order confirmations shown from Kimai's project overview, and the convert and reject actions. |

## 5. Data model

Four new tables, owned entirely by this plugin, with no change to a core Kimai entity beyond
the foreign keys that reference it.

**The order confirmation table** holds one row per Lexware order confirmation: an internal
identifier, the Lexware identifier as a unique value, the voucher number, the title, the
voucher date, the Lexware contact identifier, the raw payload as the last full response
received from the API (kept for debugging and for answering audit questions, since the webhook
body itself is never trusted), a status of pending, automatically converted, manually converted
or rejected, a flag recording whether a change arrived after conversion (surfaced as a hint icon
in Kimai's project list, never applied automatically, see section 6), a nullable reference to
the Kimai project it produced, a nullable reference to the Kimai customer involved, the
timestamps for when it was first seen and last synchronized, when it was processed, and, only
when a manual triage action set it, which user processed it.

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

A dedicated permission, `lexware_sync.triage`, gates the manual triage feature. Only a role
granted this permission sees it. It is kept separate from Kimai's general project management
permissions on purpose.

A new button appears in Kimai's project overview, labeled to show pending order confirmations,
with a badge showing how many are currently pending. Opening it shows a list, built from Kimai's
existing table components for visual consistency, with the voucher number, the title, the
customer, the date, the amount, and a preview of its lines.

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

## 8. Error handling and integrity

An invalid or missing webhook signature is logged in the webhook event table with its validity
flag set to false, the request receives an unauthorized response, and no message is dispatched.
A delivery that is unsigned or signed incorrectly is never trusted.

The webhook body is never trusted for data. It only triggers a refetch of the resource through
the authenticated API, and only that freshly fetched, authenticated response is ever processed.

Every conversion is atomic. Upserting the tracking record and creating the customer, project and
activity records happen inside one Doctrine transaction, so there is never a partially created
Kimai project without a tracking record, or the other way around.

Deduplication is keyed on the Lexware identifier together with its modification date. An event
whose modification date is not newer than what is already stored is treated as a no-op,
regardless of whether it arrived through the webhook or through the reconciliation poll, since
both funnel through the same message type.

Messenger applies its standard bounded retry with backoff, and after that its failure queue,
rather than allowing a failure to disappear silently.

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

Unit tests cover the matching rule logic, the API client against a mocked HTTP client, and
signature verification once the real header and payload mechanism has been confirmed, see the
spike below.

Integration tests exercise the processor against a real Doctrine test database, covering
customer, project and activity creation, and the transaction rollback behavior on a partial
failure.

Functional tests exercise the webhook controller with a valid signature, an invalid one, and a
missing one, and the triage controller's permission checks and its convert and reject flows.

One manual spike happens before the "hardened" verifier is written: register one real event
subscription against the live account, trigger a real delivery, for example by pointing it at a
tool like ngrok or webhook dot site to inspect it, and confirm the exact signature header name
and payload shape before any code is written that actually enforces anything based on them.
This is tracked as the first implementation task, not something deferred indefinitely.

## 11. Open items carried into implementation

The exact webhook signature header name and payload shape remain to be confirmed by the spike
described in section 10.

A public HTTPS endpoint with a valid certificate for the production Kimai instance is an
infrastructure precondition owned by the user, not by the plugin, and webhook delivery cannot be
tested end to end until it exists. Because of the reconciliation poll, milestone one stays fully
functional even before that precondition is met, only with a delay of up to the poll interval
instead of an instant update.

## 12. Milestone two: roadmap

### Already clarified during milestone one's design

- The primary way to correlate a Lexware invoice with its originating order confirmation is the
  invoice's `relatedVouchers` entry, not a title pattern. A configured regular expression, if
  any, is an additional filter applied on top of that link, following the same rule as
  everywhere else in this project: an empty pattern matches everything.
- Because Lexware invoices offer no update endpoint, the plugin cannot add rows to an invoice
  that already exists. It must always create a new invoice containing the order confirmation's
  original lines together with the newly assigned timesheet lines.
- Because Lexware offers no documented deletion or void endpoint either, discarding the original
  invoice cannot be automated. The plugin can, at most, flag that invoice for a person to delete
  manually inside the Lexware interface.
- Finalizing the newly created invoice, and marking the related project completed, are both
  optional, user controlled actions taken at the same time as sending the invoice. Marking a
  project completed is itself configurable to either set an end date on it or simply hide its
  visibility, matching the original product idea.
- The same integrity principles used in milestone one apply here without change: never trust a
  webhook body, one database transaction per conversion, and deduplication keyed on the
  Lexware identifier together with its modification date.
- The data model mirrors milestone one's shape: a dedicated tracked invoice table and a
  dedicated processor, following the same pattern as `TrackedOrderConfirmation` and
  `OrderConfirmationProcessor`.

### Still open, to be resolved in milestone two's own brainstorming and specification pass

- Exactly how a booked timesheet becomes an invoice line. The user who requested this project
  named two candidate shapes without settling on either: one invoice line per timesheet, or
  timesheets aggregated into a quantity and a price, with either shape editable by hand before
  sending. This choice, and possibly a way to let the person pick per invoice, needs its own
  design pass.
- The exact user interface for assigning timesheets to an invoice: whether it is a popup, a
  dedicated page, and how a person selects which booked, not yet exported timesheets to include.
- Whether a timesheet, once included in a milestone two invoice, should be marked exported the
  same way Kimai's own invoicing marks it, and what happens if a timesheet is edited after being
  included.
- What happens when more than one Lexware invoice links to the same order confirmation, for
  example when a project is invoiced in several batches over time, and how the triage-equivalent
  list for milestone two should present that.
- The permission model for milestone two's user interface: whether it reuses the
  `lexware_sync.triage` permission or introduces a separate one.
