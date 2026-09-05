# Kimai 2 REST API

Verified against source at `/opt/kimai/src` and `/opt/kimai/config`, Kimai **2.65.0**
(`src/Constants.php:20`). Read the actual controller before relying on a signature here — plugins
have no BC promise and neither does this doc across Kimai versions.

## The one decision that matters first: don't call the API from inside the plugin

`KimaiLexwareSync` runs **in-process** as a Kimai bundle (see the sibling `kimai-plugin` skill). A
plugin controller/command/service can inject `App\Repository\*` and `App\*Service` classes directly
— same PHP process, same Doctrine `EntityManager`, same transaction. Going out over HTTP to
`http://localhost/api/...` and back in would be slower, would duplicate authentication you already
have (you're already an authenticated request/console context), and — critically — **cannot create
invoices at all**, because the REST API has no `POST /invoices` (see below). Use this document to
understand Kimai's data model and field semantics (it's the most precise, English-documented map of
what a "timesheet" or "customer" *is* in Kimai), not as the transport the plugin should use against
its own Kimai. The REST API matters to this project only if something *external* (a script, a second
Kimai-adjacent tool, or Lexware itself via webhook-triggered pull) needs to talk to this Kimai over
HTTP.

Direct-service equivalents for the read/write paths a sync plugin needs:

| Need | Use | Key methods |
|---|---|---|
| Read customers | `App\Repository\CustomerRepository` | `findCustomer(CustomerQuery)`, `findCustomerByNumber()`, `findCustomerByName()` |
| Create/update customers | `App\Customer\CustomerService` | `createNewCustomer(string $name)`, `saveNewCustomer()`, `updateCustomer()` |
| Read/write projects | `App\Repository\ProjectRepository`, `App\Project\ProjectService` | `findProjectByNumber()`, `saveNewProject()`, `updateProject()`, `calculateNextProjectNumber()` |
| Read timesheets for a billing period | `App\Repository\TimesheetRepository` | `getPagerfantaForQuery(TimesheetQuery)` — same query object the API controller builds |
| Mark timesheets exported (after pushing to Lexware) | `App\Timesheet\TimesheetService` | `updateTimesheet()`, or bulk `updateMultipleTimesheets(array $timesheets)` — set `Timesheet::setExported(true)` first |
| Build/generate a Kimai invoice from timesheets | `App\Invoice\InvoiceService` | `createModel(InvoiceQuery)` → `InvoiceModel`, then `createInvoice(InvoiceModel, EventDispatcherInterface)` → persists `Invoice` + renders the document |
| Change invoice status (e.g. mark paid after Lexware confirms payment) | `App\Invoice\InvoiceService` | `changeInvoiceStatus(Invoice, string $status)` |

All of these are autowireable services — constructor-inject them into your plugin's controllers,
commands or event subscribers exactly as the core `API\*Controller` classes do (they're constructed
the same way). Look at `src/API/TimesheetController.php`, `src/API/CustomerController.php` and
`src/API/InvoiceController.php` for real usage examples of every method above — they are the best
"working code" reference because they're exercised on every request.

## Authentication (only relevant for external HTTP calls)

Two mechanisms coexist on the `api` firewall (`config/packages/security.yaml`, `stateless: true`):

1. **Bearer access tokens** (current, recommended) — `Authorization: Bearer <token>` header.
   Verified by `App\API\Authentication\AccessTokenHandler`, which looks the token up via
   `AccessTokenRepository::findByToken()` and checks `AccessToken::isValid()` (there's an optional
   `expiresAt`). **Tokens can only be created through the web UI** — `ProfileController`'s "API
   Token" tab — there is no API endpoint to mint your own token; a human has to generate one first
   and paste it into whatever config your sync tool reads. `DELETE /api/users/api-token/{id}` (in
   `UserController`) revokes one.
2. **Legacy username+token headers** — `X-AUTH-USER` / `X-AUTH-TOKEN`
   (`App\API\Authentication\TokenAuthenticator::HEADER_USERNAME`/`HEADER_TOKEN`). **Deprecated since
   2.54**, actively discouraged (artificial `usleep(200–500ms)` on every request, rate-limited on bad
   credentials, logs an `E_USER_DEPRECATED`). Don't build anything new on this; Kimai's own blog post
   ("removing API passwords", linked in the class docblock) says it's going away. Only mentioned here
   so you recognize it if you find it in an older integration.

`App\API\Authentication\ApiRequestMatcher` decides per-request whether a call even hits this
firewall: it must start with `/api/` (not `/api/doc`), and either carry one of the two credential
forms above or have no existing session — an authenticated *browser* session calling `/api/...` from
the SPA frontend reuses `secured_area`, not this firewall, so it never needs a token.

Every API controller also carries `#[IsGranted('API')]` at the class level (a coarse "can this user
use the API at all" permission) **and** fine-grained voter checks per action — `#[IsGranted('view',
'customer')]`, `#[IsGranted('edit_own_timesheet')]`, etc. — the same permission strings used
everywhere else in Kimai (roles/ACLs), not API-specific ones. A 403 from the API almost always means
a missing permission on the calling user's role, not a broken token.

Route access is additionally gated at `^/api` → `IS_AUTHENTICATED_REMEMBERED` in the
`access_control` list — meaning even a valid Bearer token still needs the underlying user account to
be active/not-locked.

## Base path, versioning, docs

- All API routes are mounted under `/api` (`config/routes.yaml:38`, prefix `/api`). No version
  segment in the path — Kimai does not version this API; breaking changes ship with major/minor
  Kimai releases, follow the changelog.
- The interactive Swagger/Stoplight UI is `GET /api/doc` — requires a logged-in **browser session**
  (cookie auth), not a bearer token; `ApiRequestMatcher` explicitly excludes `/api/doc` from the
  token-auth firewall so it falls through to the normal `secured_area` login. There is no
  `/api/doc.json` route in this version (confirmed 404) — the OpenAPI attributes (`OA\*` on every
  controller/entity) are the source of truth if you need the raw spec; render it from inside the app
  or read the attributes directly in `src/API/**` and `src/Entity/**`.
- `GET /api/ping` (auth required, any API-permitted user) and `GET /api/version` (`StatusController`)
  are cheap liveness/version checks — use `/api/ping` for a sync tool's health check instead of
  hitting a real resource collection.

## Controllers and endpoints

Every controller below lives in `src/API/`, is `final`, extends `BaseApiController`, and carries
`#[IsGranted('API')]`. `{id}` requirements are always `\d+` (numeric only — Kimai's API never takes
slugs).

| Controller | Base path | Endpoints |
|---|---|---|
| `CustomerController` | `/customers` | `GET ''` (list), `GET /{id}`, `POST ''`, `PATCH /{id}`, `DELETE /{id}`, `PATCH /{id}/meta`, `GET/POST/DELETE /{id}/rates[/{rateId}]`, `GET/POST /{id}/comments`, `PATCH/DELETE /{id}/comments/{comment}[/pin]`, `POST /{id}/team` |
| `ProjectController` | `/projects` | same shape as Customer: list/get/post/patch/delete, `/{id}/meta`, `/{id}/rates`, `/{id}/comments`, `/{id}/team` |
| `ActivityController` | `/activities` | same shape again: list/get/post/patch/delete, `/{id}/meta`, `/{id}/rates`, `/{id}/team` (no comments) |
| `TimesheetController` | `/timesheets` | `GET ''` (list, heavily filterable — see below), `GET /{id}`, `POST ''`, `PATCH /{id}`, `DELETE /{id}`, `GET /recent`, `GET /active`, `PATCH /{id}/stop`, `PATCH /{id}/restart`, `PATCH /{id}/duplicate`, `PATCH /{id}/export` (toggles the `exported` flag), `PATCH /{id}/meta` |
| `InvoiceController` | `/invoices` | **read-only + narrow write**: `GET ''` (list), `GET /{id}`, `PATCH /{id}/custom-fields`, `GET /{id}/download`. **No `POST /invoices` and no status-change endpoint.** Invoice *creation* and status transitions are internal-only (`InvoiceService`); the API can only read what already exists and tweak meta fields. |
| `UserController` | `/users` | `GET ''`, `GET /{id}`, `GET /me`, `POST ''`, `PATCH /{id}`, `PATCH /{id}/preferences`, `DELETE /api-token/{id}` |
| `TeamController` | `/teams` | `GET ''`, `GET /{id}`, `POST ''`, `PATCH /{id}`, `DELETE /{id}`, plus member/customer/project/activity association sub-resources under `/{id}/members`, `/{id}/customers`, `/{id}/projects`, `/{id}/activities` |
| `TagController` | `/tags` | `GET ''`, `GET /find` (full tag objects, not just names), `POST ''`, `DELETE /{id}` |
| `ConfigurationController` | `/config` | `GET /timesheet`, `GET /colors` — server-side config the UI needs, rarely useful to a sync tool |
| `ExportController` | `/export` | `DELETE /{id}` (export *template*, not a data export — do not confuse with the `exported` flag on timesheets) |
| `ActionsController` | `/actions` | `GET /{entity}/{id}/{view}/{locale}` — returns the UI's context-menu actions for an entity; irrelevant to a headless sync tool |
| `StatusController` | (none, top-level) | `GET /ping`, `GET /version`, `GET /plugins` |

### Timesheet list filters (`GET /api/timesheets`) — the one you'll use most

Query params, all optional (`src/API/TimesheetController.php:73-96`):

| Param | Meaning |
|---|---|
| `user` | user ID, or `all` (needs `view_other_timesheet`); default = current user only |
| `users[]`, `customers[]`, `projects[]`, `activities[]` | filter by multiple IDs at once |
| `customer`, `project`, `activity` | filter by a single ID |
| `tags[]` | filter by tag **names** |
| `begin`, `end` | ISO-ish local datetime (`Y-m-d\TH:i:s`) bounds on the *start* time |
| `modified_after` | same format but **UTC** — the one filter based on `modifiedAt`, not `begin`; use this for incremental sync polling |
| `exported` | `0`/`1` — filter by the export flag your sync should be setting |
| `billable` | `0`/`1` |
| `active` | `0`/`1` — running/not-running |
| `full` | `0`/`1`/`true`/`false` — expand `user`/`activity`/`project` to full nested objects instead of bare IDs (see serializer groups below) |
| `term` | free-text search |
| `page`, `size` | pagination; `size` capped at `BaseApiController::MAX_PAGE_SIZE = 500` |
| `orderBy` (`id\|begin\|end\|rate`), `order` (`ASC\|DESC`) | default `begin`/`DESC` |

For a sync job, `modified_after` + `exported=0` is the natural incremental-pull query; then flip
`exported` (via `PATCH /{id}/export`, or directly through `TimesheetService` if calling in-process)
once the row is safely in Lexware.

### Pagination and response shape

Collection endpoints build a `Pagerfanta` object from the repository (e.g.
`TimesheetRepository::getPagerfantaForQuery($query)`) and hand it straight to the `View` — the
serialized response body is a **plain JSON array of items for the current page**, no wrapping
envelope (`{data: [...], meta: {...}}`) and no `X-Total-Count`-style header was found in the
controller code. If you need total counts, do a `HEAD`-style trick or just track `page` until you get
a short/empty page. Requesting a `page` beyond the last one is documented ("renders a 404 if not
found" — see the `#[Route]` docblock) rather than returning an empty array.

### Serializer groups — why the JSON shape changes with `?full=1`

Kimai uses JMS Serializer groups (`#[Serializer\Groups([...])]` on every entity property) to produce
different "views" of the same entity from the same endpoint. `TimesheetController` defines, and every
other controller follows the same pattern:

```
GROUPS_COLLECTION       = ['Default', 'Collection', 'Timesheet', 'Not_Expanded']
GROUPS_COLLECTION_FULL  = ['Default', 'Collection', 'Timesheet', 'Expanded']
GROUPS_ENTITY           = ['Default', 'Entity',     'Timesheet', 'Timesheet_Entity', 'Not_Expanded']
GROUPS_ENTITY_FULL      = ['Default', 'Entity',     'Timesheet', 'Timesheet_Entity', 'Expanded']
GROUPS_FORM             = ['Default', 'Entity',     'Timesheet', 'Not_Expanded']   # validation-error responses
```

- `Not_Expanded` → `activity`/`project`/`user` on a `Timesheet` are serialized as **bare integer IDs**
  (via `VirtualProperty`s like `ActivityAsId`).
- `Expanded` → those same fields become full nested objects (`ActivityExpanded`,
  `ProjectExpanded` schemas) — this is what `?full=1` switches on.
- `Entity`-only fields (present on single-record responses, absent from collections) include things
  like `fixedRate`/`hourlyRate` on `Timesheet`, or `vatId`/`address`/`email` on `Customer` (group
  `Customer_Entity`) — **the customer list endpoint does not return VAT ID or address**, only
  `GET /customers/{id}` does. If your sync needs VAT IDs for Lexware contacts, you must fetch each
  customer individually (or, better, use `CustomerRepository` in-process and just call the getters —
  the group split only matters for the HTTP serialization layer).

The full alias→group mapping for every entity is in
`config/packages/nelmio_api_doc.yaml` (`nelmio_api_doc.models.names`) — grep it for the entity you
care about rather than guessing which fields survive to which response shape.

### Fields that matter for an invoicing sync

**`Customer`** (`src/Entity/Customer.php`) — group `Default` (always present):
`id`, `name`, `number`, `comment`, `visible`, `billable`, `company`, `country`, `language`,
`currency` (ISO 4217, default from `Customer::DEFAULT_CURRENCY`), `phone`, `fax`, `mobile`,
`homepage`, `timezone`, `metaFields`. Group `Customer_Entity`/`Customer_Details` (single-record only):
`vatId`, `contact`, `address` (legacy free-text block), `email`, `addressLine1/2/3`, `postCode`,
`city`, `invoiceEmail`, `buyerReference`. → Map `vatId` + `addressLine*`/`postCode`/`city`/`country` +
`company`/`name` directly onto a Lexware contact; `currency` matters for invoice line items.

**`Project`** — standard `id`/`name`/`number`/`comment`/`visible`/`billable`, a `customer`
relation, `globalActivities` flag, budget/time-budget fields, `color`. Nothing invoicing-specific
beyond the customer link and billable flag.

**`Timesheet`** (`src/Entity/Timesheet.php:83-225`) — `id`, `begin`/`end` (⚠️ **must** read via the
`getBegin()`/`getEnd()` accessors — the raw Doctrine columns are stored/serialized in a way that
loses the user's timezone offset if you bypass the accessor; the entity even has a code comment
warning about this), `duration` (seconds, int), `break` (seconds), `description`, `rate` (computed
total, `float`), `internalRate`, `fixedRate`/`hourlyRate` (Entity group only), `exported` (bool — the
export-tracking flag), `billable` (bool), `tags` (array of names via `TagsAsArray`), `metaFields`.
`user`/`activity`/`project` are IDs or expanded objects depending on group, as above. → `duration` +
`rate`/`hourlyRate`/`fixedRate` + `description` is the raw material for a Lexware invoice line item;
`billable`/`exported` are exactly the two flags a sync loop should filter and then flip.

**`Invoice`** (`src/Entity/Invoice.php`) — `id`, `invoiceNumber`, `comment`, `customer` (relation),
`user` (who generated it), `total`, `tax`, `currency`, `dueDays`, `vat`, `status` (one of
`Invoice::STATUS_NEW|STATUS_PENDING|STATUS_PAID|STATUS_CANCELED`), `invoiceFilename`, `paymentDate`,
`metaFields`. Since there's no create/status-change endpoint over HTTP, treat this entity as
**read-only reference data** from the API's point of view — anything about *generating* a Kimai
invoice or marking one paid belongs to the in-process `InvoiceService` path documented above. A
common integration shape: let Lexware be the invoice of record (create the Lexware invoice from
`Timesheet` data directly, skip Kimai's own `Invoice` entity entirely), or generate the Kimai invoice
normally via `InvoiceService` and use its `Invoice::$total`/`$invoiceNumber` only as metadata pushed
into a matching Lexware voucher — decide which one Lexware Sync is doing before writing sync code,
since the two designs pull from different places.

## Error responses

No dedicated API exception-formatting subscriber was found in `src/EventSubscriber` — Kimai relies on
FOSRestBundle's default view/exception handling for the `api` firewall. Observed shapes:
- Auth failure (`TokenAuthenticator::onAuthenticationFailure`): `403` with
  `{"message": "..."}`.
- Unmatched route (confirmed by probing this instance): `404` with
  `{"code":404,"message":"No route found for \"...\""}`.
- Form validation failure on `POST`/`PATCH`: `200` with the submitted form serialized under
  `GROUPS_FORM` — **not a 4xx** — inspect the response body for form errors rather than relying on
  status code alone; check `$form->isValid()` handling in e.g. `TimesheetController::postAction()`
  for the exact pattern every write endpoint follows.
- Permission denial from `#[IsGranted]`: standard Symfony `403`.

## Rate limiting

The only rate limiter found is `oldApiTokensLimiter` in the deprecated
`TokenAuthenticator::rateLimitInvalidLogin()` — it throttles repeated *invalid* legacy-auth attempts
per client IP, returning `400` ("Too many API requests with invalid username. Possible attack?").
No general request-rate limiting was found on authenticated Bearer-token API traffic; don't assume
that means unlimited — batch/backoff politely regardless, since this is a shared self-hosted
instance, not a rate-limited SaaS.

## Open questions not resolvable from source alone

- Whether `AccessToken::expiresAt` is ever set automatically (e.g. an expiry policy) or is always
  null unless a human picks one in the Profile UI — check the `ProfileController` form if token
  lifetime matters for your ops story.
- Real total-count/pagination behavior on a large dataset was reasoned from the controller code
  (`Pagerfanta` handed to `View`), not observed against a live paginated response — verify against a
  real multi-page dataset before writing pagination-loop code that assumes "short page = last page".
