# KimaiLexwareSync — Design Spec

Status: approved (Milestone 1 detailed, Milestone 2 roadmap-level)
Date: 2026-09-04

## 1. Purpose

`KimaiLexwareSync` is a Kimai plugin (bundle) that keeps Kimai (time tracking) and Lexware
(accounting, formerly lexoffice) loosely synchronized without either system replacing or
mirroring the other. Kimai stays the system of record for projects, activities and time
entries; Lexware stays the system of record for order confirmations (Auftragsbestätigungen,
"AB") and invoices. The plugin is a one-directional-per-step bridge between the two, always
requiring a human decision at the points where business judgement matters (is this AB a
project? which timesheets belong on this invoice?).

Target deployment: a single company's own Kimai instance synced to that same company's own
Lexware account, via the Lexware **Public API** (static Bearer key, not the Partner API/OAuth
flow — see `.claude/skills/kimai-lexware-sync/references/lexware-api.md`).

## 2. Two milestones

The full workflow the plugin supports has two halves that only share a database and a config
namespace, not runtime logic:

- **Milestone 1** (this spec, in detail): Lexware order confirmations flow in, become tracked
  records, and — automatically or via manual triage — become Kimai projects/activities with a
  recorded, auditable link back to their source AB.
- **Milestone 2** (roadmap only, see §12): Lexware invoices (or invoice drafts) linked to a
  tracked AB surface in Kimai; a user assigns booked timesheets onto them; a new combined
  invoice is pushed back to Lexware. Several UI/pricing-model questions are explicitly still
  open and will get their own brainstorming/spec pass once Milestone 1 is live and in use.

## 3. Feasibility findings (verified against a real Lexware account on 2026-09-04)

These are grounding facts the design below relies on; see the two reference docs under
`.claude/skills/kimai-lexware-sync/references/` for the fuller picture.

- `/v1/order-confirmations` exists, is created by Lexware when an AB is written, and has a
  free-text `title` field (default text is generic, e.g. "Auftragsbestätigung" — this project
  relies on a human manually customizing the title per AB that should match a rule).
- Real order-confirmation line items observed in the account came back as `type: "custom"`
  even for a line that is conceptually a service — Lexware only sets `type: "service"` when a
  line references an Article-catalog entry, not for free-text lines. Rule-matching therefore
  ignores `type` (except always skipping `type: "text"`, which is purely informational) and
  matches on line name/description instead.
- An invoice created via Lexware's "pursue" (Beleg verfolgen) flow from an AB carries a
  `relatedVouchers` entry pointing back to that AB's id/voucherNumber — a reliable structural
  link, independent of any title regex. Milestone 2 will use this as the primary correlation
  mechanism; any configured regex is an additional, optional filter on top, never the only
  path (this project's general rule: **every regex field is optional; empty = matches
  everything**).
- Lexware webhooks (`/v1/event-subscriptions`) are real. The full accepted `eventType` enum was
  obtained directly from the API's own validation error and includes
  `order-confirmation.created`, `order-confirmation.changed`, `order-confirmation.status.changed`
  (Milestone 1) and `invoice.created`, `invoice.changed`, `invoice.status.changed` (Milestone 2).
  Signature verification is asymmetric (a public key fetched from Lexware and cached, per a
  third-party OSS client that implements it), not a shared HMAC secret — the exact header name
  and payload shape were not resolvable from the fetched docs and are called out as an
  implementation spike in §7.
- Lexware invoices and order confirmations have **no update endpoint and no documented
  delete/void endpoint**. This confirms Milestone 2 cannot edit an existing Lexware invoice in
  place — it must create a new invoice with the combined line items and can, at most, flag the
  original for a human to delete manually in the Lexware UI.
- The production Kimai instance does **not yet** have a public HTTPS endpoint with a valid
  certificate (Lexware requires "Grade A or better", no self-signed certs). This is a
  deployment precondition for webhook delivery, tracked in §7, not something the plugin itself
  provides.

## 4. Architecture (Milestone 1)

```
Lexware (your account)
   |  webhook (order-confirmation.*)         cron poll (safety net, every 15-30 min)
   v                                                   v
[LexwareWebhookController] --verify signature--> [Messenger message]
                                                       |
                                                       v
                                        [ProcessOrderConfirmationHandler]
                                        (refetch by id — never trust the webhook body)
                                                       |
                                        +--------------+--------------+
                                        v                             v
                              TrackedOrderConfirmation          rules match?
                              (own DB table, upserted)                |
                                                         +------------+------------+
                                                       yes: auto                no: pending
                                                         v                          v
                                        Customer/Project/Activity           Triage UI list
                                        (Kimai core services,                (project overview,
                                         same DB transaction)                 new permission)
```

Both triggers (webhook and reconciliation poll) dispatch the exact same Messenger message type,
so there is exactly one code path that turns a Lexware order confirmation into Kimai state —
the trigger source only decides *when* that path runs, never *what* it does.

### Components

| Component | Responsibility |
|---|---|
| `Controller/LexwareWebhookController.php` | Receives the Lexware callback POST, verifies the signature, persists the raw event (audit trail), dispatches a Messenger message. Always responds fast; never does the actual sync work inline. |
| `Message/ProcessOrderConfirmationEvent.php` + handler | Refetches `GET /v1/order-confirmations/{id}`, upserts `TrackedOrderConfirmation`, applies the matching rules, creates Kimai entities when applicable — all inside one Doctrine transaction. |
| `Command/ReconcileOrderConfirmationsCommand.php` | Cron-triggered console command. Pages through `GET /v1/voucherlist?voucherType=orderconfirmation&voucherStatus=any`, compares against locally known ids/`updatedDate`, dispatches the same message for anything new or changed. The webhook-delivery safety net. |
| `Service/LexwareApiClient.php` | Thin, hand-written HTTP client (Symfony `HttpClient`) covering exactly the endpoints this project needs: Contacts, Order Confirmations, Invoices (Milestone 2), Event Subscriptions. Self-paced to Lexware's 2 req/s limit. Deliberately not a third-party SDK — see §9 (licensing). |
| `Service/LexwareWebhookVerifier.php` | Fetches/caches Lexware's signature public key, verifies each incoming callback before it is trusted. |
| `Service/OrderConfirmationProcessor.php` | Core business logic: contact→customer mapping/creation, project creation, line-item→activity creation, random color selection from Kimai's configured palette. Used identically by the automatic path and the manual triage "convert" action. |
| `Controller/TriageController.php` + templates | The manual UI: pending-AB list in the Kimai project overview, convert/reject actions. |

## 5. Data model

Four new tables, owned entirely by this plugin (no changes to core Kimai entities beyond the
foreign keys they're referenced by):

**`lexware_sync_order_confirmation`** — one row per Lexware AB.
`id` (PK), `lexware_id` (UUID, unique), `voucher_number`, `title`, `voucher_date`, `contact_id`
(Lexware UUID), `raw_payload` (JSON — the last full API response; kept for debugging and
auditability since the webhook body itself is never trusted), `status`
(`pending` | `auto_converted` | `converted` | `rejected`), `changed_since_conversion` (bool —
set when a `.changed` event arrives after conversion; surfaced as a hint icon in Kimai's
project list, never auto-applied — see §6), `kimai_project_id` (nullable FK),
`kimai_customer_id` (nullable FK), `first_seen_at`, `last_synced_at`, `processed_at`,
`processed_by_user_id` (nullable — set only on a manual triage action).

**`lexware_sync_order_confirmation_line`** — one row per AB line item, only populated when line
reading is enabled. `id`, `order_confirmation_id` (FK), `line_index`, `type`, `name`,
`description`, `matched_regex` (bool), `kimai_activity_id` (nullable FK).

**`lexware_sync_contact_mapping`** — the Lexware-contact ↔ Kimai-customer link, since Lexware
contacts have no external-reference field to store this on the Lexware side. `id`,
`lexware_contact_id` (UUID, unique), `kimai_customer_id` (FK), `created_at`.

**`lexware_sync_webhook_event`** — audit log of every inbound delivery, valid or not. `id`,
`event_type`, `resource_id`, `received_at`, `signature_valid` (bool), `processed` (bool),
`error` (nullable text). Not business-critical but necessary to answer "why wasn't AB X
processed" without grepping log files.

## 6. Configuration & matching rules

Exposed through Kimai's System Configuration UI (standard plugin extension point):

| Key | Meaning | Default |
|---|---|---|
| `lexware_sync.auto_convert_enabled` | Auto-convert an AB into a project when the title rule matches | `false` |
| `lexware_sync.title_regex` | PCRE against the AB's `title` field. **Empty matches everything.** | empty |
| `lexware_sync.read_lines_enabled` | Read AB line items and create matching activities | `false` |
| `lexware_sync.line_regex` | PCRE against line `name` + `description`. Empty matches every non-`text` line. | empty |
| `lexware_sync.reconcile_interval_minutes` | Reconciliation cron interval | `30` |

Regex fields are validated (compiled) on save, rejected immediately if invalid rather than
failing later at event-processing time.

**Per-event flow:**
1. Upsert `TrackedOrderConfirmation` from the freshly-fetched resource (status stays `pending`
   if this is a new row).
2. If `auto_convert_enabled` AND (`title_regex` empty OR it matches `title`): run the
   conversion (contact→customer lookup/create, project creation) → status `auto_converted`.
3. Otherwise: stays `pending`, appears in the Triage UI.
4. On any conversion (automatic or manual) with `read_lines_enabled`: for every line with
   `type != "text"` where (`line_regex` empty OR it matches name+description) → create a
   Kimai activity on that project.

**Customer mapping:** if the AB's `contact_id` has no existing row in
`lexware_sync_contact_mapping`, a new Kimai `Customer` is always created (no fuzzy name
matching — deliberately, to avoid silently merging into the wrong existing customer); the
mapping is then persisted so the same Lexware contact never creates a second Kimai customer.

**Colors:** both the new project and any new activities get a color chosen at random from
Kimai's own configured color palette (the same list the admin curates for the rest of the UI),
not an arbitrary hex value.

## 7. Triage UI & permissions

- New permission `lexware_sync.triage` — only roles granted this permission see the feature.
  Deliberately separate from Kimai's general project-management permissions.
- A new button in Kimai's project overview ("Pending Order Confirmations"), badge showing the
  count of `pending` rows.
- Opens a list (Kimai's existing datatable components, for visual consistency): voucher number,
  title, customer, date, amount, line preview.
- Two actions per row:
  - **Convert to project** — runs the identical `OrderConfirmationProcessor` logic as the
    automatic path (skipping the regex check, since this is an explicit human decision),
    status → `converted`, `processed_by_user_id` recorded.
  - **Reject** — status → `rejected`, disappears from the list (kept in the DB for audit).
  - There is deliberately no third "leave it" button — doing nothing *is* that action; the row
    simply stays `pending` and keeps appearing.
- Kimai's normal project list shows a small Lexware icon on projects that originated from an AB
  (tooltip: voucher number, deeplink to the Lexware document) plus a warning icon when
  `changed_since_conversion` is true.

## 8. Error handling & integrity

- **Invalid/missing webhook signature** → logged to `lexware_sync_webhook_event` with
  `signature_valid=false`, HTTP 401 returned, **no** message dispatched. Unsigned or
  wrongly-signed deliveries are never trusted.
- **Never trust the webhook body for data** — it only triggers a refetch of the resource via
  the authenticated API; only that freshly-fetched, authenticated response is ever processed.
- **Atomicity**: upserting the tracking row and creating the Customer/Project/Activity all
  happen inside one Doctrine transaction — no partially-created Kimai project without a
  tracking row, or vice versa.
- **Deduplication**: keyed on `lexware_id` + `updatedDate`. An event whose `updatedDate` is not
  newer than what's already stored is a no-op, regardless of whether it arrived via webhook or
  reconciliation poll (both funnel through the same message type).
- **Messenger retry**: standard bounded retry with backoff, then the failure queue
  (`messenger:failed:show`/`:retry`) rather than silent data loss.
- **API pacing**: client-side token-bucket capped at Lexware's documented 2 req/s, independent
  of trigger source.
- **Non-EUR customer guard**: if a mapped/matched Kimai customer's currency isn't EUR (never
  happens on fresh creation, since new customers are always created as EUR, but relevant if a
  mapping is ever pointed at an existing customer manually), conversion is refused with a
  visible error in the Triage UI rather than silently producing a wrong-currency booking.
- **API key expiry**: Lexware Public API keys expire after 24 months with no auto-renewal. A
  low-cost weekly health-check call surfaces a `401` as a visible Kimai
  notification/log entry well before the sync silently goes dark.

## 9. Dependencies & licensing

The Lexware API client is hand-written (Symfony `HttpClient`) rather than built on the
third-party `baebeca/lexware-php-api` package. That package is AGPLv3-licensed (with a paid
commercial alternative); since this plugin may be published publicly later, taking on an AGPL
dependency now would force the whole plugin into AGPL-compatible licensing. A hand-written
client covering only the ~4 endpoints actually used avoids that constraint entirely and keeps
full control over retry/pacing/idempotency behavior.

## 10. Testing strategy

- **Unit**: regex-matching logic, `LexwareApiClient` (mocked HTTP client), signature
  verification (once the real header/payload mechanism is confirmed — see spike below).
- **Integration**: `OrderConfirmationProcessor` against a real Doctrine test DB — customer/
  project/activity creation, transaction-rollback behavior on partial failure.
- **Functional**: webhook controller (valid/invalid/missing signature), triage controller
  (permission checks, convert/reject flows).
- **One manual spike before writing the "hardened" verifier**: register one real event
  subscription against the live account, trigger a real delivery (e.g. via ngrok/webhook.site
  to inspect it), and confirm the exact signature header name and payload shape before that
  code is written to actually enforce anything. Tracked as the first implementation task, not
  deferred indefinitely.

## 11. Open items carried into implementation

- Exact webhook signature header name and payload shape (spike, §10).
- Public HTTPS endpoint with a valid certificate for the production Kimai instance — an
  infrastructure precondition owned by the user, not the plugin, but webhook delivery cannot be
  tested end-to-end until it exists. The reconciliation poll means Milestone 1 is still fully
  functional (with a delay up to the poll interval) even before that's in place.

## 12. Milestone 2 — roadmap (not detailed here)

Once Milestone 1 is live: Lexware invoices linked (via `relatedVouchers`, optionally narrowed by
a regex) to a tracked, converted AB will surface as an action/icon per project row. Clicking it
lists that project's unprocessed linked invoices; picking one lets the user assign booked,
un-exported timesheets onto it. Still open, to be resolved in their own brainstorming pass:
exactly how timesheets become invoice line items (one row per timesheet vs. aggregated
quantity+price, both user-editable), and the UI for that assignment step. Confirmed already:
the plugin builds a **new** Lexware invoice (create-only API, no update endpoint) containing the
original AB-derived lines plus the newly-assigned timesheet lines; optionally finalizes it;
optionally marks the project completed (configurable: set an end date, or just hide it);
optionally flags the original Lexware invoice for manual deletion (no delete API exists, so this
can only be a flag/reminder, never an automated action). Own tracked-invoice table, own
processor, same integrity principles (never trust webhook bodies, transactional writes,
dedupe by id+updatedDate) as Milestone 1.
