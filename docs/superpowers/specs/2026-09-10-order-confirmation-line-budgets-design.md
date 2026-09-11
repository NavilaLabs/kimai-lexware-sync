# Order confirmation line budgets: design specification

Status: approved for implementation.
Date: 2026-09-10

## 1. Purpose

Kimai's `Project` and `Activity` entities both carry a monetary budget, a time budget and a
budget type through `BudgetTrait`, and neither is ever populated by this plugin today. This
specification adds one opt-in feature: when an order confirmation's lines are read (that is,
`lexware_sync.read_lines_enabled` is on), derive a time budget and a monetary budget from the
lines whose unit reads as an hour, and set them on the `Activity` created for each such line and,
summed, on the `Project` itself. Material or goods lines are deliberately excluded, since a
"hours ordered" figure has no meaning for a delivered product.

This was picked from the workflow review backlog together with a second, harder question the
brainstorming surfaced along the way: what happens when the underlying order confirmation changes
in Lexware after conversion. That question turned into its own, equally important half of this
specification, section 7 onward, because the answer the user gave generalizes beyond budgets: any
Lexware-side change must be flagged and handed to a human with concrete tools to resolve it, never
silently reapplied and never allowed to touch already booked time.

## 2. What exists today

`OrderConfirmationProcessor::convertLines()` (`Service/OrderConfirmationProcessor.php`) iterates
`payload->nestedList('lineItems')` once, at conversion time only. For each line it stores a
`TrackedOrderConfirmationLine` (`type`, `name`, `description`, `matched`) and, if the line matches
`line_regex`, creates a Kimai `Activity` on the new project and links it. `TrackedOrderConfirmationLine`
today has no columns for quantity, unit or price. Nothing in the plugin reads a line's `quantity`,
`unitName` or price fields at all.

Reconciliation (`Command/ReconcileOrderConfirmationsCommand.php` via
`TrackedOrderConfirmation::updateFromLexwarePayload()`) refreshes `rawPayload` on every run and
sets `changedAfterConversion = true` the first time the raw JSON differs from what was stored at
conversion time, for an order confirmation that is already converted. It never touches the
`lines` collection. This means the stored lines silently go stale the moment Lexware changes
anything about an already converted order confirmation: today that is harmless, because nothing
reads the stale line data after conversion, but it stops being harmless the moment a budget is
derived from it, since a changed price or quantity should not be permanently wrong just because
nothing ever revisits it.

**Verified against the live sandbox account, 2026-09-10** (`GET /v1/order-confirmations/{id}`):
line items on an order confirmation carry exactly the fields the invoice example in
`references/lexware-api.md` documents, plus one more, and share the same values on the same
sandbox account:

```json
{
    "type": "custom",
    "name": "Testprodukt",
    "quantity": 3,
    "unitName": "Stück",
    "unitPrice": { "currency": "EUR", "netAmount": 33, "grossAmount": 39.27, "taxRatePercentage": 19 },
    "discountPercentage": 0,
    "lineItemAmount": 99
}
```

`lineItemAmount` is Lexware's own precomputed net total for the line (`quantity * unitPrice.netAmount`,
adjusted for `discountPercentage`, though no sandbox line with a non-zero discount was available to
confirm the exact formula). No sandbox order confirmation currently has an hour-based line
(`unitName` was `"Stück"` on every checked example), so the hour-detection regex below could not be
verified against a real "Stunden" line and should be re-checked against a real invoice once one
exists. Confirmed: line items carry no stable identifier of their own, only `type`, `name`,
`quantity`, `unitName`, `unitPrice`, `discountPercentage` and `lineItemAmount`. This matters for
section 8.

Because `lineItemAmount` is already computed by Lexware, this plugin reads it directly as the
line's net amount rather than recomputing `quantity * unitPrice.netAmount` itself, following the
same principle already applied to invoice totals in `references/lexware-api.md`: let Lexware
compute totals, read them back, never recompute and risk drifting from Lexware's own rounding or
discount handling.

## 3. Data model changes

`TrackedOrderConfirmationLine` gains four columns, all populated for every line regardless of
whether it matched `line_regex`, so the resolution screen in section 8 has full data to diff
against, not just the lines that already have an activity:

| Column | Type | Meaning |
|---|---|---|
| `quantity` | `float`, default `0` | The line's `quantity` field. |
| `unit_name` | `string(255)`, default `''` | The line's `unitName` field, verbatim. |
| `net_amount` | `float`, default `0` | The line's `lineItemAmount` field, read as-is, never recomputed. |
| `is_hour_line` | `boolean`, default `false` | Whether this line's `unitName` matched `lexware_sync.budget_unit_regex` **at the time it was written**. Stored rather than recomputed on every read, so that a later change to the regex setting cannot retroactively rewrite what a past conversion decided, consistent with this plugin's existing rule of never silently reapplying a changed setting to already processed data. |
| `removed_from_source` | `boolean`, default `false` | Set only by the resolution flow in section 9, when a human confirms that this line no longer exists in Lexware's current version of the order confirmation. Never set anywhere else. |

A new Doctrine migration adds these five columns to `kimai2_ext_lexware_order_confirmation_line`
with the defaults above, so existing rows need no backfill and no behavior changes for order
confirmations already converted before this feature ships, the same "future conversions only"
rule already applied to Feature B's `orderNumber`/`orderDate`/`comment`.

`TrackedOrderConfirmationLine` gains an `updateFromLexwareLine(float $quantity, string $unitName, float $netAmount, bool $isHourLine): void`
method for section 9's apply step, and a `markRemovedFromSource(): void` method. Both are the only
way these new fields ever change after construction; the constructor gains the same four values as
required arguments.

No change to `TrackedOrderConfirmation` itself, `changedAfterConversion` is reused exactly as it
exists today, see section 7.

## 4. Configuration

Two new keys, added the same way as every other key, through `LexwareSyncConfiguration` and
`SystemConfigurationSubscriber`:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `lexware_sync.derive_budget_enabled` | checkbox | off | Opt-in switch for this entire feature. Only has any effect when `lexware_sync.read_lines_enabled` is also on; if line reading is off, there are no lines to derive a budget from, so this setting is silently inert rather than validated against the other one. |
| `lexware_sync.budget_unit_regex` | text, regex | see below | Which `unitName` values count as hours. |

**Deliberate, called-out exception to the project's regex convention.** CLAUDE.md states that
every regex configuration field is optional and an empty value means it matches everything, and
that this should apply to any matching rule added later. `budget_unit_regex` does not follow that
rule as-is: an empty stored value falls back to a non-empty, hardcoded default pattern in code,
the same way `getReconcileIntervalMinutes()` already falls back to a hardcoded default number
when the stored value is unusable. This was a deliberate choice, made explicitly with the user
after weighing it against the pure convention: for `title_regex` and `line_regex`, matching
everything by default is safe, since worst case an order confirmation shows up for manual triage
that a human then rejects. For `budget_unit_regex`, matching everything by default would silently
count material and goods quantities as billable hours in a real monetary budget the moment the
feature is turned on, before anyone has had a chance to configure it, which is a worse default
than a reasonable guess. `getBudgetUnitRegex()` therefore returns the stored value if it is
non-empty, otherwise this hardcoded default:

```
/^(Stunden?|Std\.?|hours?|hrs?|h)$/i
```

Case-insensitive, anchored to match the whole `unitName` value rather than a substring, covering
the common German and English spellings for an hour of work. Matched via a new
`MatchingRuleEvaluator::matchesUnit(string $unitName, string $unitRegex): bool`, which still
follows the general "empty regex matches everything" rule internally, exactly like `matchesTitle`
and `matchesLine`, since by the time a regex reaches that method it is never actually empty in
practice, `getBudgetUnitRegex()` already guaranteed that.

Both keys are added to `SystemConfigurationSubscriber::onSystemConfiguration()` next to
`read_lines_enabled`/`line_regex`, `derive_budget_enabled` as a `CheckboxType`, `budget_unit_regex`
as a `TextType` with the existing `createRegexConstraint()`.

## 5. Budget calculation at conversion

Inside `OrderConfirmationProcessor::convertLines()`, for every line item, regardless of match:

1. Read `quantity`, `unitName`, `lineItemAmount` from the `LexwarePayload` line entry with
   `->float('quantity')`, `->string('unitName')`, `->float('lineItemAmount')`.
2. Compute `isHourLine = $this->matchingRuleEvaluator->matchesUnit($unitName, $this->configuration->getBudgetUnitRegex())`,
   but only when `derive_budget_enabled` is on; otherwise always `false`, so a later toggle-on
   never retroactively reclassifies old lines (no backfill, per section 3).
3. Construct the `TrackedOrderConfirmationLine` with these four extra values alongside the
   existing constructor arguments.
4. If the line also matched `line_regex` (unchanged existing behavior) and got an `Activity`
   created: when `isHourLine` is true, call `$activity->setTimeBudget((int) round($quantity * 3600))`
   and `$activity->setBudget($netAmount)` on that activity, before `saveActivity()`. A material
   line that matched `line_regex` still gets an activity as it does today, just with no budget set
   on it (`Activity` defaults to `budget = 0`, `timeBudget = 0`, which is indistinguishable from
   "no budget" in Kimai's own UI).
5. After the loop, if `derive_budget_enabled` is on, set the project's own budget as the sum:
   `$project->setTimeBudget(array_sum of every hour-line's time budget))`,
   `$project->setBudget(array_sum of every hour-line's net amount)`. `budgetType` is left at its
   default, `null`, meaning a full, non-monthly budget, since nothing about an order confirmation
   implies a recurring monthly cadence.

This happens inside the same conversion, inside the same Doctrine transaction that already exists
for the rest of `convert()`, no new transaction boundary.

## 6. Ongoing changes: flagging, never auto-applied

`TrackedOrderConfirmation::updateFromLexwarePayload()` keeps working exactly as it does today: any
difference in the raw JSON of an already converted order confirmation sets
`changedAfterConversion = true`. This plugin's philosophy, confirmed explicitly by the user while
scoping this feature, generalizes beyond budgets: **a change coming from Lexware is always
flagged, never silently reapplied, and a human is always given concrete tools to resolve it by
hand.** Section 8 and 9 are that tool, scoped to order confirmation lines specifically, since lines
are what this feature touches; resolving a changed title, address or other non-line field is
explicitly out of scope for this specification, see section 10.

## 7. The manual resolution screen

When an order confirmation is converted, `read_lines_enabled` and `derive_budget_enabled` are both
on, and `hasChangedAfterConversion()` is true, the triage screen's existing warning icon (already
rendered in `triage/index.html.twig` and `project/origin.html.twig`) becomes a link to a new
screen: `GET /admin/lexware-sync/triage/{id}/resolve-lines`, served by a new
`Controller/OrderConfirmationLineResolutionController.php`, kept separate from `TriageController`
to keep each controller responsible for one thing.

No new Lexware API call happens here. The "before" side of the diff is the stored
`TrackedOrderConfirmationLine` collection; the "after" side is
`LexwarePayload::fromJson($orderConfirmation->getRawPayload())->nestedList('lineItems')`, the same
`rawPayload` reconciliation already fetched and stored through an authenticated request. This is
consistent with the project's rule about never trusting a webhook body directly: that rule is
about the initial fetch, not about reusing data this plugin already fetched and stored itself.

**Matching old lines to new lines is positional**, by array index, the same index
`convertLines()` already assigns as `position`. Lexware's line items carry no stable identifier of
their own (confirmed in section 2), so this is the only matching key available. This is an
accepted, documented limitation: if Lexware reorders existing lines or inserts a new line in the
middle rather than at the end, the diff will show every line after the insertion point as
"changed" even though only their position shifted. Given how order confirmations are actually
edited in practice, appending or editing in place, this was judged an acceptable simplification
rather than a reason to build fuzzy content-based matching.

Each row in the diff table is one position, classified as:

- **Unchanged**: same `name`, `quantity`, `unitName` and `netAmount` on both sides. Rendered for
  context, no checkbox, nothing to apply.
- **Changed**: a position that exists on both sides but differs in any of those fields. Shows old
  and new values side by side. Checkbox defaults to checked.
- **New**: a position that only exists in the current payload, beyond how many lines were stored
  at conversion time. Checkbox defaults to checked.
- **Removed**: a position that only exists in the stored lines, missing from the current payload.
  Checkbox defaults to checked.

## 8. Applying selected changes

`POST /admin/lexware-sync/triage/{id}/resolve-lines`, gated by the same CSRF pattern
`TriageController` already uses, takes the list of checked positions and applies exactly those,
inside one Doctrine transaction:

- **Changed**, checked: call the new `updateFromLexwareLine()` on the stored line with the new
  `quantity`/`unitName`/`netAmount` and a freshly evaluated `isHourLine`. If the line already has
  an `Activity`, overwrite that activity's budget and time budget from the new values, exactly as
  section 5 does at initial conversion. A **changed but unchecked** row is left entirely alone,
  including its activity's existing budget, so an admin can choose to accept some line changes and
  postpone others.
- **New**, checked: run the same activity-creation logic `convertLines()` already uses
  (`resolveActivityName()`, `pickRandomColor()`, `isUsableAsActivityName()`), evaluate `line_regex`
  and `budget_unit_regex` against it, create the `TrackedOrderConfirmationLine` at that position,
  set its activity's budget if it is an hour line.
- **Removed**, checked: call `markRemovedFromSource()` on the stored line. **Its `Activity`, and
  any `Timesheet` rows already booked against that activity, are never touched, never deleted, and
  never reassigned by this plugin.** The only effect is that the activity's budget and time budget
  are reset to `0`, since the order this line represented no longer exists. If a human wants to
  move already booked time onto a different activity after this, Kimai's own timesheet edit screen
  already supports changing a timesheet's activity, this plugin adds no new tooling for that, it
  was confirmed unnecessary since the capability already exists natively.
- After applying every checked row, `$project->setBudget()`/`setTimeBudget()` are recomputed as the
  sum over every line that is currently `isHourLine` and not `removed_from_source`, the same
  formula as section 5, just re-run.
- `changedAfterConversion` is set back to `false` once the transaction commits, regardless of how
  many rows were checked, including submitting with nothing checked at all. Opening this screen and
  submitting it, even to consciously apply nothing, is the deliberate human act that resolves the
  flag, consistent with this plugin's philosophy of never offering a silent "leave alone" that
  looks the same as never having looked at all: submitting is the equivalent of that look, the
  checkboxes are what was decided.

**Before the request is sent**, a small confirmation prompt, "Save these changes?", guards the
submit button, a plain `window.confirm()` triggered from a `data-confirm` attribute on the submit
button, the same lightweight inline-script-plus-data-attribute style the plugin already uses for
the "connect webhooks" button in `SystemConfigurationSubscriber::buildConnectWebhooksHelpHtml()`,
rather than pulling in Kimai's heavier modal-embed form machinery built for delete confirmations.

## 9. Explicitly out of scope

- Resolving a change to anything other than lines, title, contact, address and any other field on
  an order confirmation keeps only today's generic warning triangle, with no detail view and no
  resolution tool. Generalizing section 7 and 8 into a resolve-everything screen was considered and
  deferred, this specification stays scoped to lines and their budget effect, since that is what
  this feature already needs to touch.
- No tooling for moving already booked `Timesheet` rows between activities. Kimai's own timesheet
  edit screen already covers this.
- No backfill for order confirmations converted before this feature ships. Their lines simply have
  no `quantity`/`unitName`/`netAmount`/`isHourLine` data and no budget derived from this feature,
  exactly as Feature B's project metadata fields already work.
- `budgetType` is never set to `month`. Nothing about an order confirmation implies a recurring
  budget.
- No change to how invoice draft detection or invoice line items work, `InvoiceSynchronizer` and
  `InvoiceProcessor` are untouched by this specification.

## 10. Open risks to verify during implementation

- The hour-detection default regex could not be checked against a real "Stunden" or "hours" line
  item on the sandbox account, since none currently exists there. Verify against a real one before
  or during implementation, per the project's own rule of checking a new mapping against a real
  account before shipping it.
- `lineItemAmount`'s exact interaction with a non-zero `discountPercentage` was not observed on the
  sandbox account, every existing test line has `discountPercentage: 0`. If a discounted line ever
  needs testing, create one on the sandbox account first to confirm `lineItemAmount` already
  reflects the discount, which this specification assumes.
