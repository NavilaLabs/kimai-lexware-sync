# Lexware API (public REST API, formerly "lexoffice public API")

Sourced from `https://developers.lexware.io/docs/`, `https://developers.lexware.io/cookbooks/public-api/`
and `https://developers.lexware.io/partner/docs/` (fetched 2026-09-04). This is third-party doc
scraped through a summarizing fetch, not read from source code like the Kimai side — treat field
lists as **directionally right, not byte-exact**; verify the exact JSON shape against a real sandbox
response before shipping a mapping. Sections below say plainly which parts come straight from the
docs vs. general REST conventions the docs didn't spell out.

## Two different APIs — use the small one

Lexware exposes two distinct products under developers.lexware.io, and picking the wrong one means
building an OAuth app-registration flow you don't need:

| | **Public API** (this plugin's target) | **Partner API** |
|---|---|---|
| Auth | one static Bearer API key, self-service | OAuth2 authorization-code flow, per-partner client id/secret |
| Audience | one company automating **its own** Lexware account | ISVs building an app that connects **many different customers'** Lexware accounts |
| Setup | generate a key at `https://app.lexware.de/addons/public-api` | register as a partner, implement OAuth2, run against a sandbox (`app.lexware-sandbox.de` / `api.lexware-sandbox.io`) |
| Docs | `developers.lexware.io/docs/`, `developers.lexware.io/cookbooks/public-api/` | `developers.lexware.io/partner/docs/` |

`KimaiLexwareSync` syncs one company's own Kimai to that same company's own Lexware account — this
is exactly the Public API's use case. The Partner API's OAuth2 dance, scopes (`articles.read`,
`vouchers.write`, …) and sandbox exist to solve a different problem (a SaaS product onboarding
third-party Lexware customers) and would be pure overhead here. **Use the Public API key.**

## Authentication (Public API)

- Generate a key at `https://app.lexware.de/addons/public-api` (requires a Lexware account with
  company details filled in). Multiple named keys can exist per account; a key's permissions can
  optionally be restricted.
- Send it as `Authorization: Bearer {accessToken}` on every request.
- **Keys expire after 24 months** and must be manually renewed from the same management page — there
  is no documented auto-renewal or pre-expiry notification. Whatever config/secret-store this plugin
  reads the key from, put an expiry reminder next to it; a silently-expiring key is the most likely
  cause of "sync started failing overnight" months from now.
- No refresh-token mechanism (there's nothing to refresh — it's a static key, not an OAuth token
  pair). Rotation is: generate a new key, swap the config value, delete the old key.

## Base URL and versioning

- Production Gateway: `https://api.lexware.io`. Docs mention a legacy gateway URL staying available
  "until December 2025" — if this project or its config predates that, check whether it's still
  pointed at the old host and migrate.
- Path-versioned: every resource sits under `/v1/...` (e.g. `/v1/contacts`, `/v1/invoices`). No other
  version currently documented.
- All requests: `Content-Type: application/json`, `Accept: application/json` (a few endpoints — see
  Files below — support `Accept: application/pdf` / `application/xml` for document downloads).

## Rate limits

**2 requests/second, API-wide.** Exceeding it returns `429`. No rate-limit-remaining response headers
were documented — you have to self-throttle rather than react to headers. A bulk sync (e.g. pushing a
month of timesheets as line items across many invoices) needs its own client-side pacing — a fixed
~500ms delay between writes or a small token-bucket, not a naive tight loop. The docs separately warn
that the *authorization* server has its own, undocumented, stricter limits, and a block there "can
last from a few seconds to a few minutes" — don't retry-storm on a 401 either.

## Pagination

List endpoints (`GET /v1/contacts?...`, `GET /v1/invoices?...`, `GET /v1/vouchers?...`, `GET
/v1/voucherlist?...`) take a zero-indexed `page` query param and return a Spring-style page envelope:

```json
{
  "content": [ /* array of resources */ ],
  "totalPages": 3,
  "totalElements": 42,
  "size": 25,
  "number": 0,
  "numberOfElements": 25,
  "first": true,
  "last": false,
  "sort": []
}
```

The docs reference a per-endpoint maximum page size ("Paging of Resources") without giving the actual
number in the fetched excerpt — check that section for the specific endpoint you're paging through
before assuming a size, and don't hardcode `size` beyond what a real response confirms as accepted.

## Error responses

Docs mention both a "regular" and a "legacy" error response shape without giving either's exact JSON
in the fetched excerpt — **inspect a real error body from the sandbox/your account before writing
error-parsing code**; don't guess the field names. Status codes that are documented and worth
branching on:

| Status | Meaning |
|---|---|
| `200` | success |
| `204` | success, no content (e.g. delete) |
| `401` | authentication failure — bad/expired key |
| `404` | resource not found |
| `406` | action not valid for this resource's current state (e.g. wrong voucher-type transition) |
| `409` | conflict (e.g. requesting a PDF for a still-draft credit note) |
| `429` | rate limit exceeded |

## Idempotency

**None documented.** No idempotency-key header, no client-supplied external-reference field on
create. This is the single biggest gap for a sync tool: if a `POST /v1/invoices` succeeds but the
response is lost (timeout, connection reset) before your plugin records the returned `id`, a naive
retry creates a duplicate invoice in Lexware with no API-level way to detect it after the fact.
**Design around this in the plugin, not in Lexware**: persist "I am about to create X for Kimai
invoice/period Y" *before* the call, store the returned Lexware `id` immediately on success, and on
any ambiguous failure check `GET /v1/invoices?...` (or your own persisted state) for an
already-created match before retrying — Lexware won't do this deduplication for you. The same applies
to `POST /v1/contacts`: no external-id field exists to correlate a Lexware contact back to a Kimai
customer, so the plugin's own database (a meta field on the Kimai `Customer`/`Project` entity, per
the `kimai-plugin` skill's meta-fields mechanism) is the only place that mapping can live.

## Dates and money

- Datetimes: RFC 3339 / ISO 8601, `yyyy-MM-ddTHH:mm:ss.SSSXXX`, e.g. `2023-02-21T00:00:00.000+01:00`
  — always carries an explicit offset, matches Kimai's own timezone-aware `DateTime` handling
  reasonably well, but confirm the offset survives round-tripping through whatever HTTP client you
  use.
- Money: plain JSON numbers (floats), **not** integer minor units and **not** strings — e.g.
  `"netAmount": 13.4`, `"totalGrossAmount": 119.00`. Totals allow up to 2 decimals, unit prices up to
  4. Since Kimai's `Timesheet::$rate` is also a PHP `float`, the type matches, but floats-as-money
  means the usual caution applies: compute totals in integer cents internally in the plugin if you
  need to guarantee no rounding drift when summing many timesheet rows into one invoice total, then
  convert to float only at the API boundary.
- Currency: **EUR only**, currently. Don't build a currency-mapping layer — Kimai's `Customer`
  entity has a `currency` field that can be non-EUR; if a Kimai customer is configured with a
  different currency, decide up front whether the sync should reject that customer or force EUR, but
  don't assume the Lexware side will ever accept anything else right now.

## Resources relevant to a timesheet → invoice sync

### Contacts (`/v1/contacts`) — Kimai `Customer` → Lexware contact

| Op | Method + path |
|---|---|
| Create | `POST /v1/contacts` |
| Read | `GET /v1/contacts/{id}` |
| Update | `PUT /v1/contacts/{id}` |
| List/filter | `GET /v1/contacts?number=…&email=…&name=…&customer=true\|false&vendor=true\|false` |
| Delete | **not available** — contacts cannot be deleted via the API |

A contact is either a `person` or a `company` object, plus a `roles` object whose mere presence of a
`customer` (and/or `vendor`) key assigns that role:

```json
{
  "version": 0,
  "roles": { "customer": {} },
  "company": {
    "name": "…",
    "taxNumber": "12345/12345",
    "vatRegistrationId": "DE123456789",
    "allowTaxFreeInvoices": true
  },
  "addresses": {
    "billing": [{ "street": "…", "zip": "…", "city": "…", "countryCode": "DE" }]
  },
  "emailAddresses": { "business": ["…"] }
}
```

Notable constraints:
- `roles.customer.number` (the human-readable customer number Lexware assigns) is **read-only** —
  you cannot set it on create, only read it back afterward. If your invoicing needs a stable
  customer-number match against Kimai, store *Lexware's* generated number back onto the Kimai
  `Customer` (meta field), not the other way around.
- At most **one** billing address and **one** shipping address per contact, and at most one entry per
  email/phone category — a contact with more than one of any of these **cannot be updated via the
  API** at all (the docs call this out explicitly). Kimai's `Customer` entity only models one address
  anyway, so this lines up naturally, but don't try to push multiple addresses expecting Lexware to
  merge them.
- `version` is an optimistic-locking counter — read it, then send it back unchanged on `PUT` unless
  you're intentionally racing another writer; a stale `version` will be rejected.
- No external-reference field — see **Idempotency** above; the Kimai↔Lexware contact mapping is the
  plugin's own responsibility to persist.
- `taxNumber` (Steuernummer) vs `vatRegistrationId` (USt-IdNr.) are two distinct German tax
  identifiers — map Kimai's `Customer::$vatId` to `vatRegistrationId`, not `taxNumber`.

### Invoices (`/v1/invoices`) — built from Kimai `Timesheet` rows

| Op | Method + path |
|---|---|
| Create (draft or final) | `POST /v1/invoices` or `POST /v1/invoices?finalize=true` |
| Read | `GET /v1/invoices/{id}` |
| Render/download | `GET /v1/invoices/{id}/document`, `GET /v1/invoices/{id}/file` |
| List/filter | `GET /v1/invoices?...` |

**There is no update and no status-change endpoint** — "the status of an invoice cannot be changed
via the API" once created. An invoice is either created as `draft` (omit `finalize`) or created
already `open`/finalized (`?finalize=true`); after that it only moves through
`open → paidoff → voided` internally within Lexware (e.g. when a human records a payment). This
mirrors the Kimai side, where `InvoiceController` also has no `POST`/status-change route — **neither
system lets an external caller drive invoice status after creation**, so don't design a sync loop
that expects to push status transitions in either direction; poll `GET /v1/invoices/{id}` (or use
Event Subscriptions, below) if you need to notice when Lexware marks something paid.

Minimal request body:

```json
{
  "voucherDate": "2026-09-01T00:00:00.000+02:00",
  "address": { "contactId": "…" },
  "lineItems": [
    {
      "type": "custom",
      "name": "Consulting — August 2026",
      "description": "Kimai timesheet export, project X",
      "quantity": 12.5,
      "unitName": "Stunden",
      "unitPrice": { "currency": "EUR", "netAmount": 90.00, "taxRatePercentage": 19 }
    }
  ],
  "taxConditions": { "taxType": "net" },
  "totalPrice": { "currency": "EUR" }
}
```

Mapping notes:
- `address.contactId` → the Lexware contact `id` you stored when syncing the Kimai `Customer`. There's
  a "one-time address" alternative (inline `name`/`street`/… with no `contactId`) for ad-hoc invoices
  with no persisted contact — irrelevant here since every invoice traces back to a Kimai `Customer`.
- One line item per Kimai `Timesheet` row (or one aggregated line item per activity/day — that's a
  product decision, not an API constraint), `type: "custom"`, `quantity` = hours (`Timesheet::
  getDuration()/3600`), `unitPrice.netAmount` = the hourly rate, `taxRatePercentage` = 19 (or 7/0 —
  see tax types below). **Max 300 line items per invoice** — if a billing period produces more rows
  than that, aggregate before sending or split into multiple invoices.
- `taxConditions.taxType` — Lexware doesn't have a dedicated "Kleinunternehmer" boolean; a VAT-exempt
  small-business invoice is `taxType: "vatfree"` (plus an explanatory `taxTypeNote`, e.g. citing
  §19 UStG). Other values exist for reverse-charge/cross-border cases
  (`intraCommunitySupply`, `thirdPartyCountryService`, …) — only relevant if Kimai customers span
  countries; a purely-domestic freelancer setup will only ever use `net`/`gross` or `vatfree`.
- `totalNetAmount`/`totalGrossAmount`/`totalTaxAmount` on the response are **computed by Lexware from
  the line items**, not something you send — don't try to pre-compute and push a total that might
  drift from the line-item sum; let Lexware compute it and read it back if you need it for
  reconciliation against Kimai's own `Invoice::$total`.

### Credit Notes (`/v1/credit-notes`) — for corrections after the fact

`POST /v1/credit-notes[?finalize=true]`, `GET /v1/credit-notes/{id}`, `GET
/v1/credit-notes/{id}/file`. Relevant if a sync ever needs to reverse an already-pushed invoice (e.g.
a Kimai timesheet gets corrected after export). Link it to the original with
`?precedingSalesVoucherId={invoiceId}` — finalizing it then reduces that invoice's open amount
automatically. No line-item discounts or payment/shipping conditions on credit notes.

### Down Payment Invoices (`/v1/down-payment-invoices`) — read-only

`GET /v1/down-payment-invoices/{id}` only. These are generated internally by Lexware when a payment
is recorded against an open invoice — not something this sync creates, only something it might read
if it needs the full payment picture.

### Vouchers (`/v1/vouchers`) and Voucherlist (`/v1/voucherlist`)

`Vouchers` is the generic accounting-document CRUD (`POST`/`GET`/`PUT` on `/v1/vouchers`,
`voucherType`/`voucherStatus`/`voucherNumber`/`contact` fields) — mainly for *incoming* documents
(purchase/expense vouchers), not the sales side this plugin cares about. `Voucherlist` is a read-only,
filterable, paginated **cross-type** listing (invoices, credit notes, vouchers together) — useful for
a reconciliation report ("what did Lexware end up with for this period") but not for writing data.
Unless the sync also needs to import Lexware's expense-tracking into Kimai (out of scope for a
timesheet→invoice sync), these two are reference-only for this project.

### Countries (`/v1/countries`) and Payment Conditions (`/v1/payment-conditions`) — lookup tables

`GET /v1/countries` → `countryCode` (ISO 3166 alpha-2), `countryNameEN`/`countryNameDE`,
`taxClassification` (`de` / `intraCommunity` / `thirdPartyCountry`) — fetch once, cache, use to
validate a Kimai customer's `country` before building the invoice's `taxConditions`, since the tax
type you're allowed to pick depends on this classification. `GET /v1/payment-conditions` → the
account's configured payment-terms IDs to reference in `paymentConditions` — again, fetch/cache
rather than hardcoding IDs, since these are per-Lexware-account configuration, not fixed constants.

### Files (`/v1/files`)

`POST /v1/files` uploads a document (returns a file id you can attach elsewhere via
`documentFileId`); `GET /v1/files` downloads, with `Accept: application/pdf` or `application/xml`
selecting the format for e-invoice types (XRechnung supports XML-or-PDF, ZUGFeRD is PDF-with-embedded-
XML only, so `Accept: application/xml` on a ZUGFeRD voucher won't get you a separate XML). Only
useful here if the sync needs to attach Kimai-side export files (e.g. a rendered Kimai invoice PDF)
onto the Lexware side, or fetch the Lexware-generated invoice PDF back into Kimai.

### Event Subscriptions (`/v1/event-subscriptions`) — webhooks, for later

`POST`/`GET`/`GET {id}`/`DELETE {id}` on `/v1/event-subscriptions` let Lexware push change
notifications instead of this plugin polling. **Could not verify from the fetched docs**: the actual
event-type strings (an "Event Types" section exists but wasn't retrievable through the summarizing
fetch), the callback payload shape, and — most importantly for security — the signature/authenticity
verification mechanism ("Verify Authenticity" section exists but its content wasn't retrievable).
**Before implementing any webhook receiver, read that section directly** (fetch
`https://developers.lexware.io/docs/` and specifically ask for the "Event Types" and "Verify
Authenticity" subsections, or open the page in a browser) — don't accept unsigned webhook calls as
trusted input. One documented constraint: Lexware requires the receiving endpoint to present a
"flawless HTTPS certificate (Grade A or better)" — a self-signed or expired cert on the plugin's
webhook endpoint will silently fail delivery. For a first version, polling
`modified_after`-style on the Kimai side and `GET /v1/invoices?...` on the Lexware side is simpler
and fully specified; treat webhooks as a later optimization.

## Open questions (need a real sandbox/account to resolve, not resolvable from docs alone)

- Exact "regular" vs "legacy" error JSON body shape (field names for message/code/details).
- Per-endpoint maximum page size for `page`/paginated lists.
- Event Subscriptions: concrete event-type strings, payload shape, signature verification mechanism,
  max subscription count, retry behavior on failed webhook delivery.
- Whether an expired Public API key fails with a distinct error (vs. a generic `401`) that this
  plugin could detect and alert on before a human notices the sync silently stopped.
- Exact behavior/response when a Kimai customer's country implies a tax type (`intraCommunitySupply`
  etc.) that the plugin picked wrong — worth a deliberate sandbox test per country class actually used
  in this Kimai instance's customer base.
