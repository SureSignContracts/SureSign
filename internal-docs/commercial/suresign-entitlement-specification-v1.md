# SureSign Entitlement Specification v1

**Status: approved in principle by the founder (2026-07-23), as a
technical/product reference to precede entitlement implementation. This
document defines the entitlement architecture SureSign's Subscription &
Billing implementation must follow. It contains no migrations, no models,
no services — those follow in a later, separate implementation checkpoint
once this specification is reviewed.**

This document translates
[SureSign Commercial Strategy v1](suresign-commercial-strategy-v1.md) into
a technical and product model. Where the two disagree, the Commercial
Strategy document is authoritative on business intent; this document is
authoritative on how that intent should be represented technically.

---

## 1. Purpose

An **entitlement** in SureSign is the resolved answer to "what is this
specific organisation's subscription actually allowed to do or consume,
right now" — a value that a service or controller can check without
needing to know anything about Stripe, the current public pricing
catalogue, or commercial history.

These terms are related but distinct and must not be conflated:

- **Plan** (`pricing_plans`) — a publicly-presented commercial offering
  (Essential/Professional/Enterprise), owned entirely by Pricing
  Management. A plan has *default* entitlement values, but a plan is not
  itself an entitlement.
- **Provider price** (`pricing_plan_provider_prices`) — a Stripe Price
  object mapped to a plan/interval/currency. Purely a payment-processing
  concern; has no entitlement meaning of its own.
- **Subscription** (`subscriptions`) — the authoritative SureSign record
  of an organisation's commercial relationship: which plan, what status,
  what billing interval, what was agreed.
- **Subscription snapshot** — the frozen commercial terms captured on a
  subscription at creation/commercial-change time
  (`plan_code_snapshot`, `plan_name_snapshot`, `commercial_terms_json`,
  and the subscription's own amount fields) — protects a subscription from
  a later edit to the live plan.
- **Entitlement** — a specific, named capability or allowance resolved for
  a subscription (e.g. `max_active_projects = 15`). Distinct from the
  snapshot above: the snapshot captures commercial *terms* (price,
  interval, plan identity); entitlements capture *what the subscription
  can do*. They are related (entitlements are usually derived from the
  plan captured in the snapshot) but are a separate concept with their own
  storage (Section 8).
- **Usage allowance** — the specific subtype of entitlement that is a
  quantity with a period (e.g. AI analyses per month), as opposed to a
  static feature flag.
- **Negotiated override** — an entitlement value that differs from what
  the subscription's plan would normally resolve to, recorded with a
  reason and provenance (Section 9).
- **Commercial term** — a broader concept than an entitlement: a
  negotiated or agreed detail about the commercial relationship that may
  or may not translate into an enforceable entitlement (e.g. a payment-
  terms note is a commercial term but not an entitlement).
- **Feature flag** — the specific subtype of entitlement that is binary or
  enumerated, not a quantity (e.g. `custom_branding = true`).
- **Billing status** — the subscription's lifecycle state
  (`SubscriptionStatus`, e.g. `active`, `past_due`, `suspended`) — a
  different axis from entitlements; see Section 16 for how the two
  interact.
- **Enforcement state** — whether/how a given entitlement is currently
  being enforced (informational, warning, soft limit, hard limit,
  unavailable — Section 13) — distinct from the entitlement's *value*,
  which is what is allowed; enforcement state is *how strictly* that value
  is applied.

---

## 2. Entitlement Principles

1. **SureSign is the entitlement source of truth.** No entitlement
   decision is ever made by querying Stripe directly at check-time.
2. **Stripe must not decide application access.** Stripe reports payment
   outcomes via webhook; SureSign's own services translate that into
   subscription status and, separately, into entitlement values. This
   mirrors the existing "webhooks are authoritative, SureSign decides
   access" principle already established for billing generally.
3. **Entitlements are snapshotted when a subscription is created or
   commercially changed** — not read live from the current
   `pricing_plans` row on every check. This is what makes grandfathering
   and historical correctness possible at all (Section 7).
4. **Later pricing-plan changes must not silently alter existing
   subscriptions.** Editing a plan's default entitlements in Pricing
   Management must never retroactively change what an existing
   subscription is entitled to — only new subscriptions (or subscriptions
   explicitly migrated, a deliberate action) pick up new defaults.
5. **Negotiated changes must be explicit and auditable.** Any entitlement
   value that differs from the standard plan default must be traceable to
   a reason, an actor, and (where applicable) an agreement reference — see
   Section 9.
6. **Enforcement must be centralised.** Every entitlement check flows
   through one service boundary (the future `EntitlementService`) — never
   duplicated ad hoc in a controller, a frontend component, or a Stripe
   adapter.
7. **Compliance-critical workflows must degrade gracefully where
   possible.** An organisation exceeding an allowance must not lose access
   to existing compliance records, audit history, or the ability to view/
   download what they've already created — see Section 15.
8. **Customer access must not disappear because of a temporary provider
   API failure.** If Stripe is unreachable, SureSign's own stored
   subscription/entitlement state (not a live Stripe call) governs access
   — access decisions must be resilient to Stripe being temporarily down.
9. **Usage limits and feature flags must not be treated identically.** A
   feature flag is a binary "included or not"; a usage limit is a
   quantity with nuanced enforcement (warnings, soft limits, grace) — see
   Sections 3 and 13. Collapsing them into one mental model leads to
   either over-strict feature gating or under-strict usage enforcement.
10. **Dormant future entitlement keys must not create current commercial
    promises.** A reserved key like `max_users` existing in the technical
    vocabulary must never be interpreted, displayed, or documented as an
    active commercial commitment — see Section 3.

---

## 3. Entitlement Categories

### Feature entitlements

Boolean or enumerated capabilities — either included or not (or, for an
enum, one of a small fixed set of states). Examples: `custom_branding`,
`advanced_reporting`, `priority_support`, `accounting_exports`,
`api_access`.

### Usage entitlements

Quantitative allowances, generally with a reset period (Section 12).
Examples: `max_active_projects`, `ai_analyses_per_month`, `storage_gb`.

### Enterprise or negotiated entitlements

Not a separate storage category from the two above — an Enterprise
subscription's entitlements are still feature or usage entitlements in
shape, but their *values* are individually negotiated rather than inherited
unmodified from a standard plan default, and every such value must be
recorded as a negotiated override (Section 9), never silently set.

### Reserved dormant entitlements

Keys that exist in the technical vocabulary today with **no current
commercial meaning**:

- `max_users` — reserved for possible future seat-based licensing. **Must
  be explicitly marked**: dormant, unenforced, not currently sold, not
  part of any current plan, not visible in customer-facing pricing or UI.
  Its presence in the registry (Section 4) is solely so that if seat
  licensing is ever approved, there is a slot already defined rather than
  a fresh schema/vocabulary decision made under time pressure.
- `max_organisations` — reserved for possible future organisation-group/
  subsidiary support (flagged as plausible in the Commercial Strategy,
  Section 20). Same dormant status as `max_users`.

**These two keys must never be enforced, never be checked by any service,
never appear in a plan's sold entitlement set, and never appear in any
customer-facing pricing or account page during this checkpoint or the
implementation phases that immediately follow it.**

---

## 4. Entitlement Key Registry

All allowance figures below are **indicative recommendations only**,
carried over from the Commercial Strategy document, not approved values —
see that document's Section 21 and this document's Section 24.

| Key | Display name | Description | Category | Value type | Unit | Default enforcement | Currently sold | Currently enforced | Customer-visible | Overrideable | Reset period | Essential (rec.) | Professional (rec.) | Enterprise treatment | Notes / risks |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `max_active_projects` | Active Projects | Maximum number of projects in a non-archived/active state | Usage | integer (`is_unlimited` supported) | projects | soft_limit | Yes | Not yet (foundation only) | Yes | Yes | None (a point-in-time cap, not period-based) | Modest (indicative) | Higher (indicative) | Negotiated, commonly `unlimited` | "Active" must be precisely defined against the existing project status model before enforcement is built — see Section 24 |
| `ai_analyses_per_month` | AI Analyses per Month | Number of AI contract/trade-package analyses permitted per period | Usage | integer (`is_unlimited` supported) | analyses | soft_limit | Yes | Not yet | Yes | Yes | Calendar month (recommended) or billing cycle — see Section 12, open decision | Small (indicative) | Larger (indicative) | Negotiated | Directly tied to real Anthropic API cost — the one entitlement where cost exposure is a real business risk if under-enforced |
| `storage_gb` | Storage Allowance | Total stored document/generated-file volume permitted | Usage | decimal (`is_unlimited` supported) | GB | warning-only (see Section 13) | Yes | Not yet | Yes | Yes | None (a running total, not period-based) | Generous (indicative) | Higher (indicative) | Typically `unlimited` in practice | Must never trigger deletion or block access to existing compliance records — see Section 15 |
| `custom_branding` | Custom Branding | Organisation's own logo/letterhead/colours applied to generated documents | Feature | boolean | — | hard (on/off) | Yes — included from Essential | Not yet | Yes | Rarely (only as a negotiated exception) | n/a | true | true | true | Deliberately included at every tier per Commercial Strategy §6 — not a differentiator |
| `advanced_reporting` | Advanced Reporting | Cross-project reporting (e.g. consolidated upcoming-deadline views) | Feature | boolean | — | hard (on/off) | Yes | Not yet | Yes | Yes | n/a | false | true | true | The main feature differentiator between Essential and Professional |
| `priority_support` | Priority Support | Faster support response expectation, possible named contact | Feature | boolean | — | informational (a service-level expectation, not a technical gate) | Yes | Not yet | Yes | Yes | n/a | false | true | true (negotiated SLA) | Enforcement here is a support-process commitment, not a technical restriction — see Section 13 |
| `accounting_exports` | Accounting Exports | Export or integration with accounting software (Xero/QuickBooks/Sage) | Feature | boolean | — | hard (on/off) | No — not yet built | No | No | Yes | n/a | false | false (initially) | negotiated | Reserved key for a feature that doesn't exist yet — do not enable/sell until built, per Commercial Strategy §20 |
| `api_access` | API Access | Access to a public SureSign API | Feature | boolean | — | hard (on/off) | No — no public API exists | No | No | Yes | n/a | false | false | negotiated | Reserved key only; no public API exists in this codebase today |
| `max_users` | Max Users *(dormant)* | Reserved for possible future seat-based licensing | Reserved / dormant | integer (`is_unlimited` supported) | users | **none — unenforced** | **No** | **No** | **No** | n/a | n/a | n/a | n/a | n/a | **Must never be enforced, sold, or shown. Exists only as a vocabulary placeholder.** |
| `max_organisations` | Max Organisations *(dormant)* | Reserved for possible future organisation-group/subsidiary support | Reserved / dormant | integer (`is_unlimited` supported) | organisations | **none — unenforced** | **No** | **No** | **No** | n/a | n/a | n/a | n/a | n/a | **Same dormant status as `max_users`.** |

**No entitlement keys beyond these ten are proposed.** Per the brief's own
constraint, keys should only be added for a credible, named commercial
requirement — the existing role/permission system (Spatie roles,
per-module `canWrite`-style flags already in the codebase) must **not**
be turned into billing entitlements. A user's ability to edit a Site
Instruction, for example, is an authorization concern, not a commercial
entitlement — conflating the two would mean every future permission
change risks becoming an accidental billing change.

### 4a. Registry Amendment — `ai_credits_per_month` (2026-07-27)

An eleventh key, added deliberately as a recorded amendment rather than a
silent addition, because Section 4 above states the registry is closed:

| Key | Display name | Description | Category | Value type | Unit | Default enforcement | Currently sold | Currently enforced | Customer-visible | Overrideable | Reset period | Essential (provisional) | Professional (provisional) | Enterprise treatment | Notes / risks |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `ai_credits_per_month` | AI Credits per Month | Weighted monthly AI-credit allowance, distinct from `ai_analyses_per_month`'s flat analysis count — see below | Usage | integer | credits | soft_limit | **No — provisional, not founder-approved** | Not yet | **No — raw value never shown to a customer** | Yes | Calendar month (UTC), matching `ai_analyses_per_month`'s own existing convention | 100 (provisional) | 1,000 (provisional) | Negotiated/custom | See G4C.3E in the AI Credit Policy document for the full presentation design |

**Why this coexists with `ai_analyses_per_month` rather than replacing
it** (the entitlement migration this document's Section 20 and the AI
Credit Policy document both previously anticipated): analysis count and
weighted AI-credit consumption are different measurements — a 5-page and
a 300-page contract count identically toward `ai_analyses_per_month` but
would consume very different amounts of a future banded credit policy.
Both keys remain live simultaneously; the migration described in Section
20 (deprecating `ai_analyses_per_month` in favour of
`ai_credits_per_month`) remains a distinct, still-blocked, future
decision — not something this amendment performs.

**`customer_visible: Yes` above refers to the existence of a usage
*percentage* derived from this entitlement — not the raw allowance
value itself, which is never shown.** This is a narrower visibility
contract than every other entitlement in Section 4's table, and is
enforced structurally (the presentation API never returns the raw
number), not merely by convention.

**Provisional values, not commercial commitments**: 100/1,000 are
internal product configuration for calibrating the customer-facing
percentage's meaningfulness, explicitly not a per-credit price, not a
marketing claim, and not founder-approved — see the AI Credit Policy
document's existing "no approved commercial rate" discipline, unchanged
by this amendment. The customer-facing meter is additionally gated
behind its own feature flag (`ai_credit_shadow.customer_meter_enabled`),
independent of enforcement, until its wording and release are separately
approved.

---

## 5. Value Types

The entitlement store must not be an ambiguous untyped `key`/`value` pair.
**Recommended supported types:**

- `boolean` — feature flags.
- `integer` — countable allowances (projects, analyses).
- `decimal` — measured allowances (storage in GB, where fractional values
  are meaningful).
- `string` — free-text values where genuinely needed (rare — most
  candidates belong in `commercial_terms_json` instead, per Section 10).
- `enum` — a fixed, named set of values (e.g. a future `support_tier` with
  values `standard`/`priority`/`dedicated`, if that ever becomes its own
  entitlement rather than folded into `priority_support`).
- `null` / `not_applicable` — carefully defined: represents "this
  entitlement key does not apply to this subscription at all" (e.g.
  `accounting_exports` before the feature exists), distinct from `false`
  (which means "applies, and is explicitly not included").

**Refinement approved 2026-07-23, superseding this document's original
treatment of "unlimited" as its own value type**: `unlimited` is **not** a
value type. It is a separate boolean flag (`is_unlimited`) layered on top
of whichever underlying `value_type` genuinely applies to the key (e.g.
`max_active_projects` stays `value_type = 'integer'` whether or not a
given subscription is unlimited on it). This keeps "what kind of thing is
this entitlement" (its type) and "is it capped or not" (its unlimited
status) as two independent questions, rather than unlimited silently
erasing the type information a reader would otherwise rely on. See Section
6 for the full representation and the worked example.

### Validation

Every entitlement row must carry its own `value_type` alongside its value,
and the resolving service must validate the stored value against that
declared type before returning it to a caller — a `boolean`-typed key
should never be readable as `"1"` in one place and `true` in another. When
`is_unlimited = true`, `value` is expected to be `null` — the resolving
service must still know the entitlement is fundamentally an `integer` (or
whichever type), it simply has no finite value to enforce.

### Recommended persistence approach

**A canonical string value column plus an explicit `value_type` column,
with strict validation at the write boundary** — not raw untyped JSON, and
not a fully normalized column-per-type schema. Trade-offs considered:

- **Separate typed columns** (a `boolean_value`, `integer_value`,
  `decimal_value`, `string_value` column, only one populated per row) —
  the most type-safe option, but adds schema friction for a currently-
  small, currently-stable set of 10 keys, and makes adding new value
  shapes (e.g. a structured value later) awkward.
- **A canonical string value plus `value_type`** (recommended) — one
  column stores the value's canonical string representation (e.g.
  `"true"`, `"15"`, `"unlimited"`), a sibling `value_type` column declares
  how to interpret it, and a single, shared parsing/validation function
  (the future `EntitlementService`'s responsibility, not duplicated per
  caller) is the only place that interprets it. This keeps the schema
  simple while still guaranteeing every reader interprets a value
  identically, because there is exactly one parser.
- **JSON with strict validation** — reasonable for `metadata`-shaped
  extras, but a poor fit for the *primary* value, since it invites the
  ambiguity ("is `"true"` a string or a boolean here?") this section
  exists specifically to prevent.

**The resolved value must never be interpreted differently by different
services** — this is the central requirement driving the recommendation
above: a single, shared resolution function, not per-caller parsing
logic.

---

## 6. Unlimited Values

**Do not use magic numbers** (`999999`, `-1`, PHP's integer maximum) to
represent "unlimited" — these are fragile (a future real limit could
accidentally exceed them), ambiguous in reports (does `999999` mean
"unlimited" or "a customer really has 999999 of something"), and easy to
compare incorrectly (`current_usage > -1` is almost always true, silently
breaking a limit check that assumed `-1` meant "no limit").

**Approved representation (refined 2026-07-23)**: `is_unlimited` as its own
boolean field, separate from `value_type` and `value` — **not** `unlimited`
as a value type in its own right (this document's original recommendation,
now superseded). `value_type` always stays the entitlement's real
underlying type (`integer` for `max_active_projects`, for example),
`is_unlimited` says whether a cap currently applies at all, and `value` is
`null` exactly when `is_unlimited = true` (there is nothing to enforce, so
nothing to store). This preserves the entitlement's type information even
when it's unlimited — a report asking "which entitlements are ever
integers" doesn't need to special-case "unless it's unlimited, in which
case it's a different type."

**Example:**

```text
entitlement_key: max_active_projects
value_type: integer
value: null
is_unlimited: true
```

### How this should appear across contexts

- **Storage**: `value_type = 'integer'`, `value = null`,
  `is_unlimited = true` — never a large integer standing in for
  "unlimited," and never a bare `unlimited` string with no type
  information alongside it.
- **Service logic**: `EntitlementService::isUnlimited($key)` reads
  `is_unlimited` directly; `getLimit()`/`getRemaining()` should never be
  called against an unlimited entitlement expecting a number back without
  the caller having checked `isUnlimited()` first — the service's contract
  should make this the obvious, natural calling pattern (Section 14).
- **API output**: `{"max_active_projects": {"value": null, "is_unlimited": true}}`
  (shape illustrative, not a final API contract) — never a sentinel number
  like `999999`, and never a bare string the frontend has to
  pattern-match against.
- **UI**: rendered as "Unlimited," never as a specific large number that
  could confuse a customer into thinking it's a real, if generous, cap.
- **Subscription snapshots**: an unlimited value snapshotted at
  subscription-creation time keeps `value_type`, `value = null`, and
  `is_unlimited = true` all frozen together — the snapshot is exactly as
  stable and well-defined as a finite value would be.
- **Reporting**: any report aggregating usage-versus-limit (Section 23)
  must explicitly exclude or separately flag rows where `is_unlimited =
  true` rather than attempting arithmetic against a `null` value (e.g. "%
  of allowance used" is meaningless for an unlimited entitlement and must
  not be computed as if `value` were a very large number).

---

## 7. Plan Defaults

A plan (`pricing_plans`) should define the **commercial default**
entitlement values for every applicable key — e.g. Essential's default
`max_active_projects`, Professional's default `ai_analyses_per_month`.
These defaults live conceptually alongside the plan (their exact storage
location is a Phase 5+ implementation decision, not fixed by this
document, but the *concept* — a plan has named defaults — is fixed here).

**A subscription receives a complete resolved snapshot of those defaults
at creation time** (Section 8) — every applicable entitlement key gets an
explicit row on the subscription, copied from the plan's defaults at that
moment. **No entitlement check should ever fall back to reading the
current live `pricing_plans` row** — the snapshot, not the live plan, is
what every check reads.

### Why this matters

- **Grandfathering**: an existing subscription's snapshot is untouched by
  a later change to the plan's defaults — exactly the mechanism the
  Commercial Strategy's grandfathering policy (Section 10 there) depends
  on.
- **Historical correctness**: a report asking "what was this customer
  entitled to in March" has a real, stable answer, because the snapshot
  from that subscription's creation (or last commercial change) is
  preserved, not overwritten.
- **Plan price changes**: changing Professional's price does not need to
  touch a single existing subscription's entitlements — the two are
  decoupled by design.
- **Plan restructuring**: if the three-plan structure itself changes in
  the future (a fourth plan, a renamed plan, a different dimension added),
  existing subscriptions are unaffected until a deliberate migration
  action is taken on them.
- **Deprecated plans**: a plan no longer offered to new customers can be
  archived in Pricing Management (already-supported behaviour) while
  existing subscriptions on it continue exactly as entitled, since their
  snapshot doesn't depend on the plan still being active/published.

---

## 8. Subscription Entitlement Snapshot

**Conceptual structure of `subscription_entitlements`** (no migration
created in this checkpoint):

| Field | Purpose |
|---|---|
| `subscription_id` | Which subscription this resolved value belongs to |
| `entitlement_key` | One of the registry keys (Section 4) |
| `value` | Canonical string representation (Section 5); `null` when `is_unlimited = true` |
| `value_type` | Declares how to interpret `value` — always the entitlement's real type, even when unlimited (Section 6) |
| `is_unlimited` | Whether this entitlement is currently uncapped — see Section 6's refined representation |
| `unit` | Human-readable unit for display (e.g. "projects", "GB") — essential for presentation, not for logic |
| `source` | Where this value came from — see valid sources below |
| `is_negotiated_override` | Whether this differs from the plan's standard default |
| `effective_from` | When this resolved value became active |
| `effective_until` | When (if ever) this resolved value stops applying — nullable, most rows have none |
| `metadata` | Only where genuinely needed (e.g. a short free-text note) — not a dumping ground |

### Essential versus deferrable fields

- **Essential**: `subscription_id`, `entitlement_key`, `value`,
  `value_type`, `is_unlimited`, `source`, `is_negotiated_override`.
- **Reasonable to include from the start given how cheap they are**:
  `unit`, `effective_from` — both are simple, low-risk, and avoid a
  near-term follow-up migration.
- **May be deferred**: `effective_until` and `metadata` could be added in
  a later migration if the initial implementation doesn't yet need
  time-bounded entitlement values — but including them from the start is
  low-cost and avoids a schema change once real negotiated overrides with
  expiry exist (e.g. a promotional entitlement boost with an end date).

### Valid `source` values

- `plan_default` — copied unmodified from the subscription's plan at
  creation.
- `negotiated_override` — set via an explicit Enterprise/negotiated
  action, recorded in `subscription_overrides` (Section 9).
- `migration` — set because an administrative migration moved the
  subscription from one plan/entitlement structure to another.
- `manual_correction` — a Super Admin fix to an incorrect value (distinct
  from a negotiated override — this is "we got it wrong, we're fixing it,"
  not "we agreed something different commercially").
- `promotion` — set as part of a temporary promotional offer (Section 9 of
  the Commercial Strategy).
- `trial` — set specifically for a trial subscription's entitlement
  profile (Section 17 below).

**Every subscription should have a complete row for every applicable
entitlement key** — not a sparse table where a missing row is
interpreted as "use the plan default" (which would reintroduce the exact
live-plan-dependency problem this snapshot design exists to avoid).
Reserved dormant keys (`max_users`, `max_organisations`) are the one
exception — they should not receive rows at all until/unless they become
sold entitlements, since giving them rows today would imply an active,
resolved value where none is commercially intended.

---

## 9. Subscription Override History

**Conceptual purpose of `subscription_overrides`**: an append-only record
of *why* a subscription's resolved entitlement or commercial value differs
from what its plan would normally produce — the audit trail behind
`subscription_entitlements`' current-value rows, not a duplicate of them.

| Field | Purpose |
|---|---|
| `subscription_id` | Which subscription this override applies to |
| `entitlement_key` | Which entitlement was changed |
| `previous_value` | What it was before |
| `new_value` | What it became |
| `value_type` | Same typing discipline as Section 5 |
| `reason` | Human-readable — required, not optional |
| `approved_by_user_id` | Who approved this (Super Admin) |
| `effective_at` | When the override took effect |
| `expires_at` | When (if ever) the override should be reviewed/reverted — nullable |
| `related agreement/commercial reference` | A free-text or reference field pointing to the underlying negotiation/agreement (e.g. "per email agreement dated 2026-08-01" or a future contract-reference field) |
| `metadata` | Only where genuinely needed |

**This is an append-only history, not a current-value store** —
`subscription_entitlements` holds "what is true now"; `subscription_overrides`
holds "what changed, when, why, and who approved it." A given entitlement
key may have zero override rows (never touched from its plan default) or
several (renegotiated more than once over the subscription's life) — the
current resolved value always lives in `subscription_entitlements`, never
reconstructed by replaying the override history.

---

## 10. `commercial_terms_json`

**Narrowed responsibilities, per the Phase 0 architecture review's
original concern and the founder's confirmed decision to keep it but
constrain it:**

`commercial_terms_json` must **not** become the storage location for:

- Project limits, AI limits, storage limits — these belong in
  `subscription_entitlements` (Section 8).
- Support tier — this is a feature entitlement (`priority_support`), not
  free text.
- Discount percentages — these need to be queryable (Section 23's
  reporting requirements depend on it) and belong in a structured place
  (most naturally as an override record, Section 9, or a future dedicated
  discount field — not decided in this document, but explicitly not JSON).
- Structured billing dates — these already have real columns on
  `subscriptions` (`current_period_ends_at`, etc.) or belong on override
  records with `effective_at`/`expires_at`.
- Entitlement values of any kind.
- Any field that needs reporting or filtering — the defining test for
  "does this belong in `commercial_terms_json`" is: **if you can imagine a
  future report or filter needing this field, it does not belong here.**

`commercial_terms_json` may retain:

- External agreement references (e.g. "see signed MSA, ref XYZ").
- Narrative payment notes not captured by a structured field (e.g. "first
  invoice delayed at customer's request pending their internal PO
  approval — see email thread").
- Special contractual wording that needs to be preserved verbatim but has
  no structured meaning to SureSign's own logic.
- One-off context that is genuinely not system-enforced and never will be
  queried in aggregate.

### Should it be renamed or retained?

**Retained, not renamed, during this checkpoint.** A rename is a
low-value, purely cosmetic change that would touch existing migrations/
models for no functional benefit — the field's *scope* is what needed
fixing (via this specification), not its name. If a future review finds
the name itself causes confusion once its narrower scope is well
understood, a rename can be considered then, but it is not warranted now.

---

## 11. Usage Measurement

**Entitlements and usage are different concepts and must not be
conflated**: an entitlement is the allowed quantity; usage is the current
actual quantity being consumed against it.

| Entitlement (allowed) | Usage (actual) |
|---|---|
| 20 AI analyses per month | 13 AI analyses used this month |
| 10 active projects | 7 currently active projects |
| 100 GB storage | 42.6 GB currently stored |

### Likely source of truth per metric

- **Active projects**: a direct `COUNT()` query against the existing
  `projects` table, filtered by organisation and active status — no new
  counter needed; the underlying data already exists and is
  authoritative.
- **AI analyses per period**: likely needs a **counted/aggregated record**
  rather than a live count each time, since "how many analyses in the
  current period" requires knowing the period boundary (Section 12) and
  potentially needs to be checked frequently (before allowing a new
  analysis) — a running counter (incremented per analysis, reset per
  period) is a reasonable future approach, though a direct count against
  `contract_ai_analyses`/`trade_package_ai_analyses` filtered by date range
  may be sufficient at current expected volume.
- **Storage**: **not a simple direct count** — likely needs a periodically
  computed or incrementally maintained aggregate (sum of file sizes across
  `file_uploads`/generated documents for the organisation), since summing
  file sizes live on every check would be comparatively expensive at
  scale, though may be entirely fine at current expected customer volume.

**No counters are implemented in this checkpoint** — this section
identifies the *shape* of the problem for the future `EntitlementService`
to solve, not a solution.

### Concurrency and consistency risks

- **Active projects**: essentially no risk — a `COUNT()` query at
  decision time is accurate and cheap; the only edge case is two
  concurrent "create a project" requests both passing a "below limit"
  check simultaneously and both succeeding, taking the organisation one
  over its limit — a minor, low-stakes race (unlike the "duplicate
  subscription" concern in the billing foundation, exceeding a project cap
  by one is not a correctness/financial risk, just a soft enforcement
  edge case), acceptable to leave un-locked given the soft-limit
  enforcement policy recommended in Section 13.
- **AI analyses**: a similar minor race is possible if usage is checked
  via a live count rather than an atomic counter; given the real cost
  exposure per analysis (Section 4's notes), this is the one usage metric
  where a genuinely atomic increment-and-check (similar to the existing
  `DocumentNumberSequence`/`BillingReferenceSequence` lock pattern) is
  more likely to be worth the extra design effort when this is
  eventually implemented.
- **Storage**: no meaningful concurrency risk for the *limit check* itself
  (storage doesn't need split-second accuracy the way a duplicate-
  subscription or duplicate-payment concern does) — the risk is instead
  about **staleness** of a periodically-computed aggregate, not
  concurrent-write correctness.

### When direct counting suffices versus when aggregation is needed

**Direct database counting is likely sufficient** for active projects at
any realistic customer volume. **Aggregated/cached usage records are more
likely eventually needed** for AI analyses (cost-sensitive, needs a clear
period boundary) and storage (expensive to sum live at scale) — but this
is a Phase 5+ implementation decision to make when `EntitlementService` is
actually built, informed by real data volumes at that time, not decided
speculatively now.

---

## 12. Reset Periods

### Types of reset behaviour

- **Per calendar month** — resets on the 1st of each calendar month
  regardless of when the subscription started or its billing date.
- **Per subscription billing cycle** — resets on the subscription's own
  renewal date (which may not align with a calendar month, especially for
  annual subscriptions).
- **Annual allowance** — resets once per year, relevant mainly for
  annual-billed subscriptions if a yearly-scoped allowance is ever needed.
- **Non-resetting/lifetime allowance** — never resets (most naturally
  applies to `max_active_projects` and `storage_gb`, which are point-in-
  time caps, not period-based consumption).

### Approved approach (confirmed 2026-07-23, superseding this document's
original organisation-timezone-aware recommendation)

**AI analyses reset by UTC calendar month** — not the organisation's
effective timezone, and not the Stripe subscription's billing anniversary:

- The measurement period begins at `00:00:00 UTC` on the first day of the
  month.
- It ends at `00:00:00 UTC` on the first day of the following month.
- It is entirely independent of Stripe subscription billing anniversaries
  — a subscription billed on the 15th still has its AI allowance reset on
  the 1st, not the 15th.
- Annual subscriptions still receive **monthly** AI allowance periods, not
  a single annual allowance — a customer paying annually resets twelve
  times a year, the same as a monthly-billed customer.
- **No first-month proration is required initially** — a subscription
  activated mid-month receives its full monthly allowance for that partial
  first month, not a prorated fraction of it.

This is a deliberate simplification versus this document's original
draft recommendation (which proposed organisation-timezone-aware
boundaries, consistent with the codebase's general `TimezoneResolver`
architecture for business-logic dates): AI usage-period boundaries are
**not** a `TimezoneResolver`-governed business date in the same sense as a
statutory payment deadline — they are an internal cost-control mechanism,
where a fixed, simple, globally-uniform UTC boundary is judged preferable
to per-organisation timezone complexity for this specific entitlement.
This remains an **entitlement implementation decision for the later
entitlement checkpoint** — recorded here as the current recommendation to
build against, not yet implemented (no counters exist yet — see Section
11).

**Active projects and storage: non-resetting** — these are running totals/
caps, not period consumption, so "reset period" does not apply to them at
all.

### Relationship between periods

- **Stripe billing period**: the interval Stripe actually charges on
  (`current_period_starts_at`/`current_period_ends_at` on `subscriptions`).
- **Subscription period**: conceptually the same as the Stripe billing
  period for a standard subscription, but may diverge for a negotiated
  Enterprise arrangement with unusual billing terms.
- **Usage measurement period**: the period an allowance actually resets
  against — per the approved approach above, a fixed UTC calendar month
  for AI analyses, deliberately **not** the same as the Stripe billing
  period.
- **Timezone**: deliberately **not** organisation-timezone-aware for AI
  usage periods specifically (see above) — this is the one exception to
  the codebase's general `TimezoneResolver` convention for business dates,
  and the exception is scoped narrowly to this one entitlement's reset
  boundary, not a precedent for entitlement dates generally.
- **UTC storage**: the underlying instants (when an analysis was run, when
  a period boundary was crossed) are stored in UTC, per the existing
  platform-wide convention — consistent with the boundary itself also
  being defined in UTC for this entitlement.

**Avoid ambiguous "monthly" definitions** — any future implementation must
state explicitly which of the above "monthly" means, not leave it
implicit. For `ai_analyses_per_month` specifically, "monthly" now means
exactly the UTC calendar-month definition above.

---

## 13. Soft Limits and Hard Limits

### Enforcement levels

- `informational` — displayed, never restricts anything (e.g.
  `priority_support`'s SLA expectation — a process commitment, not a
  technical gate).
- `warning` — the customer/operator sees a clear warning, nothing is
  blocked.
- `soft_limit` — the customer/operator sees a warning and may be required
  to take an explicit action (e.g. confirm) to proceed, but is not
  outright blocked.
- `approval_required` — a Super Admin action is required to proceed
  further (e.g. exceeding an allowance requires manual override).
- `hard_limit` — the action is blocked outright.
- `unavailable` — the feature simply isn't present for this
  subscription (used for feature flags that are off, not for usage
  allowances).

### Recommended enforcement per entitlement

**Active projects**: `soft_limit`. Prefer warnings and an upgrade
conversation over blocking. **Existing active projects must never be
disabled** if a subscription later finds itself over its allowance (e.g.
after a downgrade) — only the *creation of a new project* may eventually
require `approval_required` once genuinely over the limit; nothing about
an existing project's accessibility should change. This is a Phase 5+
implementation decision on the exact mechanism, but the *policy* — never
disable existing projects — is fixed here.

**AI analyses**: `warning` as the customer approaches the allowance,
moving toward `soft_limit`/`approval_required` once actually exceeded —
the specific behaviour among "remain available temporarily," "require
Super Admin override," "consume an approved overage," or "block only new
analyses after a grace threshold" is an open implementation decision
(Section 24), not settled here, but the constraint is fixed: **whichever
mechanism is chosen must never interrupt unrelated contract
administration functionality** — a customer over their AI allowance
should still be able to create payment applications, generate notices, and
do everything not itself an AI analysis.

**Storage**: `warning`, progressively (e.g. at 80%, 95%, 100%+ of
allowance). **Never** `hard_limit` in the sense of forcing deletion of
existing records or blocking access to them — storage enforcement, if it
ever restricts anything, may only restrict *new uploads* after extensive
warning, and only with a defined exception process (e.g. a temporary
Super Admin override while a customer arranges an upgrade) — this follows
directly from the Commercial Strategy's storage/defensibility principle
(Section 6 there) and is treated as close to non-negotiable given
SureSign's compliance-record positioning.

**Feature flags**: `unavailable` when not included — but must be
communicated clearly (e.g. "Advanced Reporting is available on
Professional" rather than a silent absence or a confusing error) — see
Section 21 on presentation.

---

## 14. Entitlement Enforcement Boundaries

**Recommended future `EntitlementService` responsibilities** (not
implemented in this checkpoint):

- `hasFeature(string $key): bool`
- `getLimit(string $key): int|float|string` (returning the `unlimited`
  token where applicable, per Section 6)
- `isUnlimited(string $key): bool`
- `getUsage(string $key): int|float`
- `getRemaining(string $key): int|float|string` (again, `unlimited`-aware)
- `isNearLimit(string $key, float $threshold = 0.8): bool`
- `canConsume(string $key, int $amount = 1): bool`
- `requireFeature(string $key): void` (throws if not entitled — the
  service-layer equivalent of an authorization check)
- `explainDecision(string $key): array` (returns *why* a decision was
  made — the source, whether it's an override, the current usage/limit —
  for support/debugging and for Super Admin-facing presentation, Section
  21)

### Where enforcement must occur

- **A backend service or policy boundary** — `EntitlementService` itself,
  called from controllers/services, following exactly the same pattern
  the codebase already uses for authorization (`authorize()` methods) and
  for Pricing Management's own service-layer discipline.
- **Never only in frontend UI.** The frontend may reflect an entitlement
  decision (e.g. disabling a "New Project" button when at the limit) for
  good UX, but this is never the enforcement itself — the backend must
  independently refuse the action regardless of what the frontend allowed
  the user to attempt.
- **Controllers must not duplicate entitlement logic.** A controller asks
  `EntitlementService` a question and acts on the answer; it must never
  reimplement "is this organisation over its project limit" inline.
- **The Stripe provider adapter must never enforce entitlements** — this
  restates and reinforces the existing `BillingProviderInterface`
  constraint from the Phase 1–4 checkpoint: the provider layer creates/
  retrieves provider objects and verifies signatures, nothing about
  access or entitlements.

### Testability and auditability

Centralising enforcement in one service makes every entitlement decision
independently unit-testable (given a subscription's entitlement snapshot
and current usage, assert the expected decision) without needing a real
or faked Stripe interaction at all — entitlement logic is pure business
logic over SureSign's own data, which is precisely why it must never leak
into the provider adapter.

---

## 15. Graceful Degradation

**Priority order when a customer exceeds an allowance**: contractual
continuity, document access, audit integrity, compliance deadlines,
customer trust — in that order, ahead of strict allowance enforcement.

### Must always remain accessible, regardless of allowance state

- Viewing existing projects.
- Viewing existing contracts.
- Viewing existing documents.
- Downloading existing generated records (payment applications, notices,
  certificates).
- Viewing payment and notice history.
- Accessing audit records (`ActivityLog`, project activity).

**None of the above may ever be restricted by an entitlement check** —
these are exactly the compliance/defensibility guarantees SureSign is sold
on (Commercial Strategy, Section 2); restricting them over a billing
technicality would directly contradict the product's core value
proposition.

### May be limited (with the soft-limit policy from Section 13)

- Creating additional active projects beyond the allowance.
- Starting additional AI analyses beyond the allowance.
- Uploading additional non-critical files after a severe, sustained
  storage overage — and only after extensive warning and with a defined
  exception process, never as an immediate consequence of crossing a
  threshold.

**The system must never hold customer compliance records hostage** — this
is a hard constraint, not a guideline: no entitlement enforcement
mechanism, however implemented, may ever result in a customer being unable
to view or retrieve a record they already created, regardless of billing
or subscription status (this remains true even into `suspended`/
`cancelled`/`expired` states — see Section 16, though the *policy* for
those states requires its own separate review before any enforcement is
built there).

---

## 16. Billing Status Versus Entitlements

Subscription status, billing health, and entitlements are related but
distinct axes — a status does not automatically determine every
entitlement value, though it strongly influences the overall access
picture:

- `active` — normal, full plan entitlements apply.
- `trialing` — trial-specific entitlements apply (Section 17), not
  necessarily identical to any standard plan's defaults.
- `past_due` — entitlements may reasonably continue unchanged during a
  defined grace period (see Commercial Strategy, Section 21's open
  grace-period-duration decision) — the rationale being a temporary
  payment hiccup should not immediately disrupt compliance work.
- `unpaid` — restrictions may begin according to policy, but exactly what
  restricts (full suspension versus a reduced/read-only entitlement set)
  is an open decision, not fixed here.
- `suspended` — creation/modification of new records may be restricted;
  existing-record access (Section 15) must still be preserved regardless.
- `cancelled` — access policy depends on the cancellation's effective date
  and any data-retention policy — not fixed in this document.
- `expired` — read-only/archival access may apply, preserving Section
  15's guarantees even here.
- `draft` — no paid entitlement activation at all; the subscription
  doesn't yet represent a live commercial relationship.
- `pending_payment` / `incomplete` — no full paid activation until a
  verified webhook confirms payment, consistent with the existing
  "webhooks are authoritative" billing principle.

**This document does not finalise destructive enforcement behaviour for
`unpaid`/`suspended`/`cancelled`/`expired`** — that requires a separate,
dedicated lifecycle and access-policy review (flagged as an open decision
in Section 24), precisely because getting this wrong risks violating
Section 15's non-negotiable compliance-record-access guarantee.

---

## 17. Trial Entitlements

**Recommendation: a dedicated trial entitlement profile, not simply
Essential or Professional defaults reused.**

Trade-offs considered:

- **Reusing Essential defaults** — simplest, but may under-demonstrate the
  product's value (a prospect evaluating SureSign should see enough
  capability to judge Professional-level value if that's the plan being
  proposed).
- **Reusing Professional defaults** — better demonstrates full value, but
  risks uncontrolled AI cost exposure during a trial period if the
  Professional AI allowance is generous, and risks a prospect anchoring on
  entitlements they haven't actually paid for.
- **A dedicated trial profile (recommended)** — a deliberately-designed
  entitlement set: generous enough on active-project and feature-flag
  dimensions to let a prospect experience the real workflow (per the
  "first real workflow" checkpoint in the Commercial Strategy), but
  **capped conservatively on AI analyses specifically**, since that's the
  one dimension with real, uncontrolled cost exposure per Section 4's
  notes. This lets the trial demonstrate genuine value on the workflows
  that matter most (contracts, payment applications, notices, branding)
  without exposing SureSign to open-ended AI cost during an unpaid period.

The trial profile's exact allowance numbers are not proposed here — only
the *shape* of the recommendation (dedicated profile, AI capped more
tightly than any standard plan) is fixed.

---

## 18. Enterprise Overrides

**Required for every Enterprise entitlement override**:

- An explicit value (never inferred or left blank).
- An effective date.
- An expiry date where applicable (many Enterprise terms are tied to a
  contract period and should be reviewed at its end, not silently
  perpetual).
- Approval (a named Super Admin actor).
- A reason (human-readable, required).
- A related agreement reference (Section 9's `subscription_overrides`
  fields).
- Clear indication in Super Admin that a given value is negotiated, not
  standard (Section 21).
- A renewal review trigger — an approaching `expires_at` on an override
  should surface as a clear operator signal (mirroring the Commercial
  Strategy's "expiring negotiated terms" requirement).

**Avoid treating Enterprise as automatically unlimited for every
entitlement.** "Negotiated" is the correct default framing — an Enterprise
subscription's entitlements are individually agreed values (which may or
may not be `unlimited` per key), not a blanket "Enterprise = no limits"
assumption. Some Enterprise customers may have a specific negotiated
project cap well above standard plans but still a real number, not
`unlimited` — the entitlement model must support that precisely, not
default every Enterprise entitlement to `unlimited` for convenience.

---

## 19. Plan Changes

### Upgrade

- Create a new resolved snapshot (or version) of `subscription_entitlements`
  reflecting the new plan's defaults, recorded with `source = migration`
  or a dedicated upgrade-specific source if warranted.
- Apply new entitlements at the approved effective time — per the
  Commercial Strategy, immediately for standard-plan upgrades.
- Retain historical values — the prior entitlement snapshot rows are not
  deleted, only superseded (via `effective_until` on the old rows, if the
  schema tracks it that way — an implementation decision for Phase 5+).
- Record the commercial reason — even a standard self-initiated upgrade
  should have a legible "why" (e.g. "upgraded from Essential to
  Professional, requested via [channel], approved by [Super Admin user]").

### Downgrade

- Schedule the entitlement reduction for the next renewal, per the
  Commercial Strategy's downgrade policy.
- Evaluate current usage against the future plan's limits **before** the
  downgrade takes effect, and warn the operator/customer if current usage
  already exceeds what the new plan would allow.
- Do not strand existing projects or records — per Section 15's
  guarantees, regardless of the new plan's lower allowance.
- Require a defined, safe transition plan for any case where usage
  genuinely exceeds the new allowance (e.g. "you currently have 12 active
  projects; Essential allows 5 — these will not be disabled, but you won't
  be able to create new ones until you're back under the limit or
  upgrade again").

### Enterprise amendment

- Recorded as a commercial amendment (via `subscription_overrides`), not
  processed through the standard upgrade/downgrade mechanism at all —
  avoids pretending a negotiated contract change is an ordinary
  self-service plan switch.

### Should entitlement snapshots need explicit versioning?

**Recommendation: yes, in concept, via `effective_from`/`effective_until`
on `subscription_entitlements` rows rather than a separate version-number
scheme.** This achieves the same practical outcome (a full history of what
was entitled when) without introducing a second versioning concept
alongside the override history already in `subscription_overrides` — but
this is flagged as an open technical decision (Section 24), not settled
as a final schema design, since it has real implications for query
complexity that should be weighed against actual reporting needs once
Phase 5+ implementation begins.

---

## 20. Grandfathering and Deprecated Entitlements

Existing customers retain their resolved entitlements even when:

- **Plan defaults change** — because entitlements are snapshotted per
  subscription (Section 7), not read live from the plan.
- **An entitlement key is renamed** — this must never happen silently; see
  the deprecation policy below.
- **A plan is retired** — Pricing Management's existing archive mechanism
  already handles this for the plan's public visibility; existing
  subscriptions' entitlement snapshots are unaffected regardless.
- **Allowances increase or decrease** for new customers — again, no effect
  on existing snapshots.
- **A feature is repackaged** (e.g. what was one entitlement becomes two,
  or vice versa) — requires the deprecation policy below, not an implicit
  reinterpretation.

### Recommended entitlement-key deprecation policy

**Never silently reuse an old entitlement key for a different meaning.**
If a key's meaning must change:

1. Introduce a **new** key with the new meaning.
2. Mark the old key as deprecated in the registry (Section 4), retaining
   its original meaning for any subscription still snapshotted against it.
3. Provide an explicit, deliberate migration path for moving existing
   subscriptions to the new key, if and when that's commercially
   warranted — never an automatic, silent reinterpretation.
4. Retain the deprecated key's historical rows for as long as any
   subscription references it, for audit/reporting integrity.

This mirrors the same discipline already applied to `pricing_plans`
(archived, not deleted or repurposed) and to database migrations generally
(additive, non-destructive) elsewhere in this codebase.

### Executed: `ai_analyses_per_month` → `ai_credits_per_month` (2026-07-27)

This exact policy was followed for the one real migration this
specification has had so far — see the AI Credit Policy document's
G4C.3G (Part Eleven) for the full record. Summary against the four steps
above: (1) `ai_credits_per_month` is the new key (§4a), added in G4C.3E,
not a rename; (2) `Feature::AI_ANALYSES_PER_MONTH`'s registry entry now
carries `deprecated_in_favor_of: ai_credits_per_month`
(`Feature::isDeprecated()`), its original meaning and values untouched;
(3) no subscriptions have been migrated between keys — there is no
production customer base yet for this to be commercially warranted for,
so this step is correctly not performed, not skipped; (4) no historical
rows were touched, deleted, or reinterpreted.

---

## 21. Presentation and Customer Messaging

### Super Admin view should show

- Plan default value.
- Current resolved value.
- Current usage.
- Remaining allowance.
- Override source (plan default vs. negotiated vs. migration vs. manual
  correction, per Section 8's `source` field).
- Reason (where an override exists).
- Effective period.
- Renewal implications (e.g. an expiring override or upcoming plan
  renewal that will reset a value).

### Customer view should show

- A clear allowance (in plain terms, not a raw key name).
- Current usage.
- Warnings (per the soft-limit policy, Section 13) — framed constructively
  ("You're approaching your project allowance — contact us to discuss
  Professional" rather than an alarming or punitive tone).
- An upgrade contact path — consistent with the sales-assisted philosophy,
  this should point to a conversation, not a self-service upgrade button
  (at this stage).
- **No confusing provider terminology** — never a raw Stripe status
  string, a Stripe customer/subscription ID, or Stripe-specific language
  in anything a customer sees.
- **No raw internal entitlement key names** — `max_active_projects` should
  render as "Active Projects," never as the literal key.
- **No ambiguous "unlimited"** unless it is genuinely, contractually
  agreed — an entitlement that merely hasn't been capped yet (e.g. because
  enforcement isn't built) must never be presented to a customer as
  "Unlimited," since that could be read as an implied commercial promise.

### Warnings should be useful, not threatening

A warning's purpose is to prompt a conversation or a natural upgrade
decision, not to alarm a customer mid-workflow about a compliance tool
they're relying on for exactly the opposite feeling (confidence, not
anxiety) — tone matters here more than in a typical SaaS product, given
SureSign's positioning (Commercial Strategy, Section 2).

---

## 22. Security and Data Exposure

Entitlement and billing presenters (extending the `BillingPresenter`
discipline already established in the Phase 1–4 checkpoint) must exclude:

- Provider secrets (already covered by the existing `BillingPresenter`
  design, restated here for completeness).
- Raw webhook payloads.
- Internal failure details (e.g. a raw Stripe API error message).
- Sensitive commercial notes not intended for the customer (e.g. an
  internal note about why a discount was granted, or an internal
  assessment of a customer's negotiating position).
- Hidden negotiation metadata (e.g. a Super Admin's internal notes on a
  deal's history).
- Internal approval commentary (who approved an override and any internal
  discussion around it).

### Customer-visible versus internal-only commercial information

- **Customer-visible**: their own resolved entitlement values, their own
  usage, their own plan name, their own renewal date, their own invoice
  history.
- **Internal-only**: override reasons where they reference internal
  commercial strategy or another customer's terms, approval chains,
  negotiation history, and anything in `commercial_terms_json` by default
  (it should be treated as internal-only unless a specific field is
  deliberately whitelisted for customer display — the opposite of an
  opt-out model).

---

## 23. Reporting Requirements

A typed, snapshotted entitlement model (Sections 5, 7, 8) directly enables
the following future reports, which an untyped JSON-based approach would
make substantially harder or impossible to build reliably:

- Subscriptions by plan.
- Customers approaching their project limit (a direct query:
  `usage >= threshold * limit`, only possible because both usage and
  limit are typed, comparable values).
- AI usage by plan (aggregating typed usage records against typed
  allowances).
- Storage usage by customer.
- Negotiated overrides (a direct query against `subscription_overrides`
  by date range, key, or reason).
- Expiring overrides (a direct query against `subscription_overrides.expires_at`).
- Grandfathered subscriptions (subscriptions whose snapshot differs from
  their plan's current live defaults).
- Deprecated plan or price usage (subscriptions referencing an archived
  plan or a deactivated `pricing_plan_provider_prices` row).
- Trial conversion (subscriptions that moved from `trialing` to `active`,
  and the inverse — trials that expired without converting).
- Customers with low adoption (per the Commercial Strategy's Customer
  Health signals, Section 16 there).
- Customers at renewal risk.
- Customers with billing risk (per Billing Health, Section 17 there).
- MRR and ARR by plan (a direct aggregation over `subscriptions.total_amount`
  grouped by plan and status).

**None of these reports are built in this checkpoint** — this section
exists to demonstrate why the typed, snapshotted design (over an
untyped JSON alternative) was chosen, by showing the concrete future
capability it preserves.

---

## 24. Open Technical Decisions

| Decision | Recommendation | Status | Implementation impact | Blocks Phase 5 (`BillingCustomerService`/`PlanPriceMappingService`)? | Blocks Checkout? | Blocks enforcement only, or live launch? |
|---|---|---|---|---|---|---|
| Exact entitlement persistence structure (columns, indexes) | Canonical string value + `value_type`, per Section 5 | Recommended, not finalised | Determines the eventual migration shape | No | No | Blocks entitlement implementation (a later phase), not Phase 5/Checkout |
| Supported value types | boolean, integer, decimal, string, enum, null/not_applicable — plus the separate `is_unlimited` flag (Section 6) | Recommended | Determines validation logic | No | No | Blocks entitlement implementation |
| Unlimited representation | `is_unlimited` boolean separate from `value_type`/`value` (never a magic number) — see Section 6 | **Decided** (confirmed 2026-07-23) | Affects every entitlement-reading service | No | No | Blocks entitlement implementation |
| Entitlement versioning (`effective_from`/`effective_until` vs. a separate version scheme) | `effective_from`/`effective_until` on `subscription_entitlements` rows | Recommended, flagged as needing validation against real reporting needs | Affects query complexity for historical reports | No | No | Blocks entitlement implementation |
| Reset period definition for `ai_analyses_per_month` | UTC calendar month, independent of Stripe billing anniversaries, no first-month proration — see Section 12 | **Decided** (confirmed 2026-07-23) — still an implementation task for the entitlement checkpoint, not yet built | Affects usage-counting implementation | No | No | Blocks entitlement implementation |
| Usage aggregation strategy (live count vs. maintained counter) | Live count for projects; counter or aggregate likely needed for AI/storage — deferred to real implementation | Open | Affects performance and consistency design | No | No | Blocks entitlement implementation |
| Soft-limit grace thresholds (e.g. warn at 80%/95%) | Not proposed with exact numbers | Open | Cosmetic/UX only | No | No | Blocks entitlement implementation |
| Trial entitlement profile (exact values) | Dedicated profile, AI capped more tightly than any standard plan — exact numbers not proposed | Open | Affects trial cost exposure | No | No | Blocks live trial usage only |
| Downgrade handling when usage exceeds the future plan's limit | Warn before confirming; do not strand existing records; block only new creation above the limit | Recommended in policy, not in exact mechanism | Affects downgrade UX | No | No | Blocks entitlement implementation |
| Suspended/cancelled/expired access policy | Not finalised — requires a dedicated lifecycle/access-policy review | Open, explicitly deferred | Significant — touches access enforcement broadly | No | No | Blocks access-enforcement phase specifically, not Phase 5/Checkout |
| Customer-visible entitlement fields (exact API/UI shape) | General principles set (Section 21), exact shape not designed | Open | Affects future API/UI design | No | No | Blocks UI implementation |
| Role of `commercial_terms_json` | Narrowed scope, retained name (Section 10) | Decided in this document | None — already resolved | No | No | Not blocking |
| Whether `subscription_items` remain unused for the initial commercial model | Yes — the current three-plan, no-seat-licensing model needs only one primary item per subscription; `subscription_items`' multi-item support stays dormant, matching its original "foundation for later" design intent | Decided in this document | None — no schema change needed | No | No | Not blocking |

**None of the open decisions above block continuing with
`BillingCustomerService` or `PlanPriceMappingService`** — both operate on
Stripe customer mapping and plan-to-price mapping respectively, neither of
which depends on the entitlement persistence details still open here.
**Checkout is also unblocked by these specific open items**, though
Checkout's own design should reference the settled plan/entitlement
*shape* (Sections 3–4) when deciding what a checkout session is actually
selling. The items genuinely requiring resolution before real enforcement
or live launch are the lifecycle/access-policy review (suspended/
cancelled/expired handling) and the exact allowance figures from the
Commercial Strategy document — neither of which need resolving to
continue the next implementation checkpoint.
