---
name: kimai-lexware-sync
description: Work with the Kimai REST API and/or the Lexware (lexoffice) public accounting API when building or debugging the KimaiLexwareSync plugin — syncing customers, projects, timesheets or invoices between Kimai and Lexware. Use whenever the task involves reading/writing Kimai timesheet, customer, project or invoice data, calling api.lexware.io, mapping Kimai entities to Lexware contacts/invoices, or anything about API auth, rate limits, pagination or field mapping between the two systems.
---

# Syncing Kimai and Lexware

This plugin bridges two systems with very different API philosophies: Kimai's REST API is one
process away and mostly beside the point (use its internal services instead — see below); Lexware's
Public API is the actual network boundary this plugin has to be careful about (rate limits, no
idempotency, static keys that expire in 24 months).

For everything about *building the plugin itself* (bundle structure, extension points, permissions,
`kimai:reload`, packaging) use the sibling `kimai-plugin` skill — this skill is scoped to the two
external data sources, not plugin mechanics.

## The core architectural decision

- **Kimai side: call internal services, not the HTTP API.** The plugin runs in-process. Inject
  `App\Repository\*` and `App\*\*Service` classes directly. `references/kimai-api.md` documents the
  REST API mainly so you know Kimai's data model and field semantics precisely (it's the best
  English-documented map of what a "timesheet" or "customer" *is*) — treat it as a data dictionary,
  not a transport to actually use against this Kimai instance. One hard constraint either way:
  **Kimai's API and internal `InvoiceController`/`InvoiceService` cannot create or transition
  invoices from outside a normal admin-UI flow the same way a script would want to** — check the
  "read-only + narrow write" note in `references/kimai-api.md` before assuming you can `POST` a
  Kimai invoice.
- **Lexware side: the Public API key, over real HTTP, is the only option.** Not the OAuth2 Partner
  API — that's for multi-tenant SaaS integrators, not a single company's internal sync tool. See
  `references/lexware-api.md` for the full case for this.
- **Decide up front which side owns "the invoice."** Two legitimate designs:
  1. Kimai never generates its own `Invoice` entity for synced work — the plugin reads `Timesheet`
     rows directly and creates the Lexware invoice from them. Simpler, avoids the fact that neither
     API can drive Kimai-invoice status.
  2. Kimai generates its `Invoice` normally (via `InvoiceService`, through the admin UI or a console
     command), and the plugin pushes a *matching* Lexware invoice/voucher afterward, using the Kimai
     invoice's number/total as metadata only.
  Pick one before writing sync code — the field mapping in `references/kimai-api.md`'s "Fields that
  matter for an invoicing sync" section flags exactly where these two designs diverge.

## Workflow

1. **Model the mapping before writing code.** For each Kimai entity you sync (`Customer`, `Project`,
   `Timesheet`), decide the Lexware target (`Contact`, invoice line item) and where the
   cross-reference lives. Neither API gives you a foreign-key field for this — Lexware contacts have
   no external-id field, and nothing in Kimai's schema knows about Lexware IDs by default. Store the
   Lexware `id` (and `roles.customer.number` if useful) as a **Kimai meta field** on the `Customer`/
   `Project`/`Timesheet` entity (see the `kimai-plugin` skill for how plugin meta fields work) — that
   mapping is this plugin's responsibility, not either API's.

2. **Read `references/kimai-api.md` for what to pull.** Key lookups: `TimesheetQuery` +
   `TimesheetRepository::getPagerfantaForQuery()` filtered by `modified_after` and `exported=0` for
   incremental sync; `CustomerRepository`/`CustomerService` for the customer side, including
   `vatId`/address fields that only exist in the "Entity" serializer group (i.e. only on a
   single-record fetch, not a list) — call the getters directly in-process and this distinction
   disappears.

3. **Read `references/lexware-api.md` for what to push**, in this order of caution:
   - Contacts before invoices (an invoice's `address.contactId` must already exist).
   - Respect **2 requests/second** — pace writes, don't fire a batch concurrently.
   - Handle the **no-idempotency** gap explicitly: persist "about to create X" before the call, the
     returned Lexware `id` immediately after, and reconcile via a list-filter query on any ambiguous
     failure rather than blindly retrying `POST`.
   - Money as JSON floats, EUR only, dates as RFC 3339 with explicit offset.

4. **Verify field-level details against a live account before trusting this doc.** The Lexware
   reference was built from scraped documentation (see its header), not source code like the Kimai
   one — anything marked "could not verify" or "open question" in `references/lexware-api.md` needs a
   real sandbox/account check, especially error-body shape and Event Subscriptions, before you build
   against it.

5. **Decide sync direction and failure mode per field.** What happens when a Kimai timesheet is
   edited *after* export (`exported=1`) — does the plugin re-open the Lexware invoice (it can't —
   Lexware invoices have no update endpoint either, only credit-notes as corrections) or just flag
   the discrepancy for a human? Decide this before writing the "mark exported" step, since it's not
   reversible through either API once a Lexware invoice is finalized.

## Reference files

- `references/kimai-api.md` — Kimai 2.65.0 REST API: auth, every `src/API/*Controller` endpoint,
  pagination/serializer-group quirks, and the specific entity fields (`Customer`, `Timesheet`,
  `Invoice`) that matter for invoicing. Verified against local Kimai source.
- `references/lexware-api.md` — Lexware Public API: auth (static key vs. the OAuth2 Partner API you
  don't want), rate limits, pagination envelope, Contacts/Invoices/Credit-Notes/Vouchers field
  mapping, the idempotency gap and how to work around it. Sourced from developers.lexware.io; flags
  what's unverified.
