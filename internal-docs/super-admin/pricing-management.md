# Pricing Management (Super Admin / Admin)

`/admin/pricing` gives Super Admin and Admin full control over the public
marketing Pricing section (`suresigncontracts.app/pricing`) — plans, the
feature comparison table, FAQs, and global CTAs/copy — and (since Phase G2)
each Subscription Plan's entitlement defaults. The marketing site only
renders what this page publishes; nothing about pricing is hardcoded in the
marketing codebase.

Super Admin and Admin can both see this page and its API — Client users
still receive a 403. `/api/admin/pricing/*` was widened from a
`role:Super Admin` route group to `role:Super Admin|Admin` in Phase G2, per
the Phase G0 architecture review's finding that `Admin` and `Super Admin`
are both platform-wide roles (`organization_id = null`) in this app's role
model, so this carries no customer-org exposure risk (Application
Monitoring remains deliberately `Super Admin`-only — it exposes
cross-organization presence/usage data, a different sensitivity profile).
This replaces the old `/admin/billing` page, which was a static, unwired
mockup with no backend.

## What it manages

- **Plans** — name, internal code, slug, pricing (monthly/annual, currency,
  prefix/suffix), description/summary, CTA button, badge, accent colour,
  background style, icon, visibility, and which plan is the "Popular" one.
- **Feature comparison table** — feature sections (e.g. "Core Platform",
  "Commercial", "AI", "Support"), individual features within each section,
  and a per-plan/per-feature status (Included / Not Included / Limited /
  Custom, with optional override text).
- **FAQs** — question, answer, ordering, enabled/disabled.
- **Everything Included** — the flat list of items shown in the "Everything
  Included" section, independent of the comparison table.
- **Global settings & CTAs** — hero title/subtitle, section title, monthly/
  annual billing toggle visibility, the discount label, Everything Included
  section copy, the final CTA section copy, and the primary/secondary CTA
  button text + destination used by the final CTA. The Pricing hero does not
  render CTA buttons, keeping the closing panel as the page's main conversion
  point.
- **Publish status** — a single "published" flag on the settings singleton
  controls whether the whole Pricing page is live at all (see Publishing
  below).
- **Entitlement defaults** (Phase G2) — for each plan, the default value of
  every non-dormant `App\Support\Entitlements\Feature::*` key (see
  "Managing plan entitlements" below). This is a genuinely separate system
  from the feature comparison table above — see that section for why.

## Plan lifecycle

Every plan has a `status`: `draft`, `active`, or `archived`.

- **Draft** — visible only in Super Admin. Never returned by the public
  endpoint.
- **Active** — eligible for public display, but only once **all three** of
  `status = active`, `is_visible = true`, and `published_at` is set and in
  the past are true. Creating a plan starts it in `draft`.
- **Archived** — permanently hidden from the public endpoint but retained in
  the database (its comparison-matrix rows, code, and history are not
  deleted).

**Publish** (a dedicated action, not a raw field edit) moves a plan to
`active` and stamps `published_at` on first publish. **Archive** moves a
plan to `archived` and clears its "Popular" flag.

**Delete vs archive**: deleting a plan that was **never published** and has
**no comparison-table rows** hard-deletes it. Deleting a plan that has ever
been live, or that already has comparison entries, archives it instead and
tells you why — plans that have been shown publicly are never silently
erased.

**Popular plan**: at most one plan can be marked "Popular" at a time.
Marking a plan popular automatically un-marks whichever plan held it before
— enforced server-side, not just in the UI.

**Internal code**: each plan's `code` (e.g. `starter`, `professional`,
`enterprise`) is set once at creation and can never be changed afterwards —
even via a direct API call, the update endpoint does not accept it. The
marketing **name** can change freely; the code is the stable identifier a
future subscription provider (Stripe, Paddle, etc.) would key off.

## Managing plan entitlements (Phase G2)

Each plan has an **Entitlements** editor (`GET`/`PUT /admin/pricing/plans/{plan}/entitlements`)
that manages its `pricing_plan_entitlements` rows — the database-backed
plan defaults introduced in Phase G1 and consumed everywhere via
`App\Services\Entitlements\PlanEntitlementRepository`. This is **not** the
feature comparison table above: the comparison table is marketing copy
(`pricing_features`/`pricing_plan_features`), independently maintained and
deliberately kept separate (Phase G0's recommendation); the entitlement
editor manages the actual commercial defaults (`App\Support\Entitlements\Feature`'s
ten-key catalogue) that a real subscription's entitlement snapshot is
built from. Never conflate the two, and never add a "fill from entitlement"
sync between them without a deliberate future decision (Phase G3).

The editor is **generated dynamically** from the `Feature` registry — every
non-dormant key (`max_users`/`max_organisations` are reserved/dormant and
never appear) is always present, in registry order, so a future key added
to `Feature::ALL` appears automatically with no UI change required. Each
row shows read-only registry metadata (display name, category, value type,
unit, enforcement level — informational only, nothing enforces it yet —
customer visibility, whether it's currently sold, whether it's
overrideable) alongside three editable fields:

- **Applicable** — whether this entitlement applies to this plan at all.
  Off for a feature that isn't sold/built yet (e.g. `accounting_exports`/
  `api_access` today) — distinct from a feature flag that's simply
  switched off.
- **Unlimited** — usage entitlements only; when on, no finite value is
  stored.
- **Value** — a boolean toggle, integer, or decimal input depending on the
  key's declared `value_type`; hidden whenever not applicable or unlimited.

A `PUT` always replaces the **entire** set for a plan — a partial patch is
rejected. Both client- and server-side validation reject: an unknown or
reserved feature key, a duplicate row, a missing row (the set must exactly
match the non-dormant registry), a value whose type doesn't match
`Feature::valueType()`, a negative usage value, and any value/unlimited
combination that isn't one of `EntitlementValue`'s three valid shapes
(applicable+finite, applicable+unlimited, not-applicable).

### Category grouping and reserved features (Stage X)

The editor's `GET` response is `{ categories, entitlements }`, not a flat
list. `categories` is generated entirely from
`App\Support\Entitlements\EntitlementCategory::ALL`/`label()`/`description()`
— **not** hardcoded in the frontend — so a future approved category
(a later Entitlement Specification version) appears automatically, correctly
labelled, with zero UI change. `entitlements` includes **every** key,
including reserved/dormant ones (`max_users`/`max_organisations`) — unlike
the editable `PUT` set, which still excludes them entirely (they never get
a `pricing_plan_entitlements` row). Each entitlement row carries
`category`, so the UI groups it under the right section without any
per-feature hardcoding.

Reserved entries are visually distinguished, not shown as if they were
active commercial features: a lock icon, a "Reserved — not sold" badge, a
dashed border, reduced opacity, and explanatory copy ("reserved for a
possible future platform capability — never enforced, sold, or shown to
customers today"). Their `is_applicable`/`is_unlimited`/`value` are always
`false`/`false`/`null` and no editable control is rendered for them at all
— not merely disabled, since Entitlement Specification v1 §2 principle 10
requires a dormant key never imply an active commercial commitment.

The editor also supports search (by display name, key, or description),
a category filter, three quick toggles (Enabled only / Unlimited only /
Configurable only — the last excluding reserved and not-yet-sold keys),
and a "Hide/Show reserved" toggle — all composable, and all operating on
the same dynamically-grouped section layout without breaking it.

**Editing a plan's entitlements only affects future commercial events** —
the next activation, upgrade, downgrade, or trial start for a subscription
on that plan. It never touches an existing `billing_entitlement_snapshots`
row (those are immutable by design — see `EntitlementSnapshotService`) and
never touches `FeatureGate`, `SubscriptionAccessPolicy`, or Stripe. No
enforcement of any entitlement exists yet regardless of what's configured
here (see Phase G0/G1).

## Copying and creating plans (Phase G2)

**Blank plan** (`POST /admin/pricing/plans`, unchanged since Phase G1):
starts with the conservative, most-restrictive entitlement baseline
(`PlanEntitlementRepository::initializeDefaultsForPlan()` — feature flags
off, usage allowances at 0) — never a guess at commercial intent.

**Copy existing plan** (`POST /admin/pricing/plans/{plan}/copy`): the new
plan's code, slug, and name are always supplied fresh by the caller (never
inherited); every commercial/presentation field (pricing, currency,
description, summary, CTA, badge/accent/background/icon) and **every**
entitlement default row are duplicated from the source plan. Deliberately
**never copied**: `is_popular` (at most one plan is ever popular),
`status`/`published_at` (a copy always starts as an unpublished draft),
and anything Stripe-related — no such field exists on `PricingPlan`
itself, and a copied plan always starts with zero
`pricing_plan_provider_prices` rows, requiring its own new Stripe
Product/Price mapping via `PlanPriceMappingService` before it can be sold.

## Managing the comparison table

Feature sections and features are managed independently of plans. When a
new feature is created, a "Not Included" row is automatically added for
every currently **active** plan, so the table is never missing a cell for
an existing plan. Editing the matrix (via the Comparison tab, or the bulk
`PUT /admin/pricing/matrix` endpoint) applies every cell change in one
database transaction — either the whole batch lands or none of it does.

Feature sections, features, and Everything Included items each have their
own `is_visible` flag, so any of them can be temporarily hidden without
deleting the underlying data.

Reordering (plans, feature sections, features, FAQs, Everything Included
items) always takes a **complete** ordered ID list for that entity — a
partial list, a duplicate, or a foreign ID is rejected and the existing
order is left untouched.

## Managing FAQs and CTAs

FAQs are a flat, ordered list with an `is_enabled` toggle — disabled FAQs
stay in Super Admin but never appear on the public page. The primary and
secondary CTA fields (text, destination, open-in-new-tab) control the closing
CTA section. They remain part of the existing public payload for backwards
compatibility, but the Pricing hero intentionally does not render them.

## How changes appear on the marketing site

The public endpoint (`GET /api/pricing`, no auth) is cached for one hour.
**Every** write made through Pricing Management (a plan edit, a matrix
change, an FAQ update, a settings save — anything) busts that cache
immediately, so the very next visit to the marketing Pricing page reflects
the change. No rebuild or redeploy of the marketing site is required.

The marketing section uses the payload across three presentation layers:
`/pricing` for overview and selection, `/pricing/[slug]` for a reusable plan
deep dive, and `/pricing/compare` for the complete matrix. The plan route,
navigation dropdown, metadata, and sitemap all derive their names and slugs
from the same public plan collection. There is no separate frontend plan
configuration.

## Marketing page fallback

If the public endpoint is unreachable, returns no active plans, or the
Global Settings "published" flag is off, the marketing Pricing page renders
a graceful fallback ("Pricing is currently available through our sales
team" + Book a Demo / Contact Sales) instead of a broken or blank page.
Toggling "published" off is the intended way to take the whole page down
temporarily (e.g. while re-working plans) without it ever looking broken to
a visitor.

## Rollback

There is no dedicated "undo" — recovery is via the same primitives used
everywhere else: re-publish a plan you archived by mistake (its data and
comparison rows were retained), or edit a field back to its previous value.
Every pricing change is recorded in the platform Audit Log
(`/admin/audit-log`, reusing the existing `ActivityLog` model — actions like
`pricing_plan.updated`, `pricing_plan.published`, `pricing_plan.archived`,
`pricing_matrix.updated`) with before/after values, so what changed and by
whom is always visible even without a one-click revert.

## Security

- `/admin/pricing` and every `/api/admin/pricing/*` endpoint require the
  `Super Admin` or `Admin` role (Phase G2) — Client receives 403,
  unauthenticated requests receive 401.
- The public endpoint never serializes internal fields — `code`, `status`,
  `published_at`, and `deleted_at` are not present in its response.
- Badge colour, accent colour, background style, and icon are validated
  against a fixed allow-list, not free text. CTA/link URL fields must be
  either a relative path or an `https://` URL — `javascript:`, `data:`, and
  any other scheme are rejected.
