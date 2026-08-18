# SureSign Feature Availability

**Status: Phase A (backend foundation), Phase B (Super Admin management UI
+ customer-facing availability experience), and Phase C (selective backend
module enforcement) are all complete. `EnsureFeatureIsAvailable` IS now
attached to 124 real production module mutation routes — see the Phase C
section below for the exact inventory and the reads-stay-ungated policy
that shaped it.**

## Phase C — selective backend module enforcement

Attaches `feature.available:{key}` directly to genuinely module-exclusive
CUSTOMER MUTATION routes only (`POST`/`PUT`/`PATCH`/`DELETE`) — reads stay
ungated by default (Phase C's locked V1 policy). **127 routes gated across
all 17 of the 17 Project features** (`project.contracts` through
`project.documents`) — `project.commercial` also covers `PaymentApplicationController`
and `FinalAccountController` action endpoints, since `FinalAccountTab.tsx`
lives inside `commercial/page.tsx`. Method breakdown: 72 `POST`, 26
`PUT`/`PUT|PATCH`, 29 `DELETE`, **0 `GET`, 0 `HEAD`** — mechanically
verified via `route:list --json`, not asserted from memory.

**Split-resource pattern**: where a route was a single
`Route::apiResource(...)->shallow()` registration (mixing reads and
writes), it was split into two registrations — `->only(['index','show'])`
ungated, `->only(['store','update','destroy'])->middleware(...)` gated —
rather than wrapping the whole resource or duplicating status logic in the
controller. This changed no controller code, no validation, no
authorization logic, and no response shape — `Active` behaves exactly as
before Phase C (verified: existing Batch3/Batch4/Drawing/PaymentApplication
regression suites still pass — see Verification below).

### The locked EOT ownership decision, proven end-to-end

`POST/PUT/DELETE /projects/{project}/eot-requests[...]` is gated by
**`project.delay_eot` only** — never `project.notices`, even though the
Notices page's own EOT tab calls the identical create endpoint. This means:
a customer creating an EOT request is blocked when Delay & EOT is in
Maintenance, **regardless of whether they used the Delay & EOT page or the
Notices page to do it** — the entity being mutated is what determines
ownership, not which page's UI triggered the request (the same reasoning
was applied to `project.rfis`/`project.qa`/`project.snagging`, whose
`store` routes are also reachable from a Drawings hotspot-link creation
flow). Conversely, `project.notices` alone being in Maintenance never
blocks EOT mutation — confirmed both by an automated regression test and a
real dev HTTP smoke test (see below).

### Generic/shared routes — confirmed and deliberately ungated

- `GET /file-uploads/{fileUpload}/download` / `/preview` and
  `GET /documents/{document}/download` / `/preview` — used across
  Contracts, Drawings, Delivery Documents, Adjudication, every evidence-
  attachment module, `GeneratePackageModal`, and the admin Documents
  explorer. Never gated — remain fully shared regardless of
  `project.documents`'s status.
- Trade-package-scoped routes (`/projects/{project}/trade-packages/{tp}/{programme,risks,delivery-documents,payment-applications,final-account,delay-events,eot-requests,loss-and-expense-claims}`)
  belong to the separate, ungated Trade Package workspace
  (`/app/projects/[id]/subcontracts/[packageId]`) — confirmed these are
  genuinely different routes from each gated project-level feature's own
  create endpoint, so gating the project-level route never affects the
  Trade Package workspace.
- `organization.commercial`, `organization.site_admin`, `organization.documents`,
  `organization.reports` are all genuinely read-only today — confirmed by
  searching every corresponding frontend page for a mutation call. No
  middleware was invented just to claim enforcement exists.
- `organization.team`'s only nominal mutation (`POST /users/invite`) is
  already `role:Super Admin` ONLY at the route layer — unreachable by any
  Client/Admin customer regardless of Feature Availability (and Super
  Admin bypasses Feature Availability anyway), so gating it would be a
  pure no-op. Left ungated, documented inline in `routes/api.php`.
- `ai.assistant` has no operational backend route at all (confirmed:
  `/ai/conversations`/`/ai/summarize`/`/ai/draft-document` aren't even
  registered in the current `routes/api.php`) — nothing to gate, no route
  was invented.
- **Closeout correction**: `POST/PUT/DELETE` on the `projects.documents`
  apiResource (`App\Models\Document`, distinct from the `file_uploads`
  table every other module's attachments use) was initially left ungated
  in Phase C's first pass, reasoning "no confirmed frontend caller
  exists" — that reasoning was wrong and has been corrected. Verified
  directly against `DocumentController`: `store()`/`update()`/`destroy()`
  all use the same `authorizeProject()` shape as every other module
  controller (org-matching Client access, no extra role restriction), and
  each mutates the project-scoped `documents` table exclusively — not
  shared with any other module's own file/attachment table. "No frontend
  caller today" was never a reason to leave a customer-reachable,
  module-owned mutation ungated (a Client could still call it directly);
  now gated by `project.documents` — `index`/`show` remain ungated, as do
  `download`/`preview` above.

### Bypass, unchanged from Phase A

Super Admin and Admin continue to bypass every gated route exactly as
`FeatureAvailabilityService::isAvailableToUser()` already implemented in
Phase A — Phase C added no new bypass logic, only new routes for the
existing bypass to apply to. Management remains Super-Admin-only,
unchanged.

### Tests added

`tests/Feature/FeatureAvailabilityEnforcementTest.php` (20 tests, including
3 added at the closeout checkpoint for the corrected `projects.documents`
gating) — shared-route regression (Overview/Calendar/Dashboard reads
unaffected by Programme/Reports Maintenance), the EOT ownership proof
(both directions), Documents module-write-vs-generic-read (both the
explorer upload action and the `Document` resource's own
store/update/destroy), representative role/tenant behaviour (Client
blocked, Admin/Super Admin bypass, unauthenticated still 401s, wrong-tenant
still 403s when Active), data-integrity (no record created on a blocked
Variation/Risk/Programme/Document mutation), the `organization.team` no-op
finding, and zero AI-credit-table writes. Combined with Phase A/B's 37:
**57 total, 144 assertions, all passing.**
Existing module regression suites (Batch3/Batch4 Programme/Variations/
Notices/EOT/RFIs/Meetings/Site Reports/Adjudication/Payment Applications/
Final Accounts, Drawing Hotspot/Revision/Register, Organisation Document
Search) re-run clean — the only 3 failures across that full sweep were
independently confirmed (via `git stash`) to be pre-existing, unrelated to
Phase C: two are the already-documented root-owned `storage/framework/testing/disks`
permission artifact, one is an environment-dependent Excel-failure-injection
test that also fails on a clean Phase-B checkout.

### Real dev HTTP smoke test

Performed via genuine HTTP requests (temporary Sanctum tokens for a real
Super Admin and a real Client user against real Project 1/Contract 4 — no
fabricated data): Programme Maintenance blocked a Client's milestone
creation (503) while Project Overview/Calendar stayed reachable (200) and
Super Admin's identical request succeeded (201, then cleaned up); Notices
Maintenance blocked pay-less-notice creation (503) while the shared
EOT-requests endpoint remained reachable (422 validation, not 503) with
`project.delay_eot` Active; flipping to `project.delay_eot` Maintenance
then blocked that same EOT endpoint (503) while EOT/Notices reads stayed
available (200); Documents Maintenance blocked the explorer upload (503)
while a real, authorized file download stayed reachable (200). Every
override was restored to Active and `feature_availabilities` confirmed
back to 0 rows; temporary tokens deleted.

## Phase B — Super Admin UI + customer-facing experience

Built entirely on the Phase A contract with zero backend changes:

- **Frontend types** (`frontend/src/types/featureAvailability.ts`) mirror
  the backend response shapes exactly — no second client-side feature
  catalogue; the backend registry (via `GET /admin/feature-availability`)
  remains authoritative.
- **`useFeatureAvailability()`** (`frontend/src/hooks/useFeatureAvailability.ts`)
  — the one canonical frontend read path, mirroring `useSiteSettings.ts`'s
  fetch-once/cache/fail-safe shape exactly. A missing key, a malformed
  entry, or a request failure all resolve Active; never scatter
  `features[key]?.status === ...` across pages.
- **`FeatureUnavailableState`** (`frontend/src/components/feature-availability/`)
  — the shared Maintenance/Coming Soon customer state, built entirely from
  existing primitives (no new design system): restrained copy, no fake
  progress/percentages/dates, `available_at` rendered only when present via
  the existing `formatDateTime()`/effective-timezone convention.
- **`FeatureAvailabilityGate`** — wraps a page; Active renders normally for
  everyone; Maintenance/Coming Soon renders `FeatureUnavailableState` for a
  Client, or normal content plus a restrained internal warning banner for
  Super Admin/Admin (an ACCESS bypass only, read from the same
  `user.roles` frontend state every other presentation-only role check in
  this codebase already uses — grants no management permission; only the
  Super-Admin-only backend admin API can ever change availability).
- **`FeatureStatusBadge`** — a compact nav-item indicator (renders nothing
  for Active), wired into `ProjectSidebar`/`AppSidebar` via a new, entirely
  separate `featureKey` field on each nav item — never conflated with
  `pageKey`/`hidden_pages`. Unavailable items stay visible and clickable;
  nothing is ever hidden by this system.
- **`FeatureAvailabilityManager`** — the Super Admin management screen,
  mounted as a new "Feature Availability" tab on the existing
  `/admin/suresign` settings page (filtered out of both the tab bar and
  the content render for non-Super-Admin, matching the backend's own
  Super-Admin-ONLY authorization for both `GET`/`PUT`). Status options are
  restricted per-feature in the UI itself (`maintenance_supported`/
  `coming_soon_supported`), not just relying on backend validation. Every
  transition shows an explicit confirmation ("You are about to make
  Programme unavailable to customer users.") and requires a reason (≥10
  chars) + confirmation checkbox, mirroring `ManageAiCreditsRequest`/
  `UpdateAiCreditOperatingModeRequest`'s exact shape. A successful save
  invalidates both the management query and the customer-facing
  `['feature-availability']` query — no hard refresh needed.
- **23 pages gated** — exactly the Phase A V1 registry (17 project modules,
  5 organisation modules, `ai.assistant`), each wrapped with the smallest
  possible change: the existing default-exported page component was
  renamed (no longer exported) and a new default-exported wrapper renders
  `FeatureAvailabilityGate` around it. No business logic was touched in any
  of the 23 files. `project.overview`, `organization.dashboard`,
  `organization.projects`, `project.calendar`, and
  `organization.consultations` remain entirely ungated, per the approved
  V1 exclusions.
- **Verified with a real, controlled dev-database smoke test** (Super
  Admin/Admin/Client Sanctum tokens, real HTTP requests against the running
  dev backend — not just automated tests): `ai.assistant` Active→Coming
  Soon and `project.programme` Active→Maintenance, confirmed via the real
  customer status endpoint, confirmed unrelated endpoints (Project
  Overview, Dashboard) stayed reachable, confirmed the audit trail, then
  restored both to Active and confirmed `feature_availabilities` returned
  to 0 rows. Temporary smoke-test API tokens were deleted afterward.
- **Real bug found and fixed during this walkthrough** (a genuine
  Phase A defect, only surfaced by a live, repeated, cross-request
  walkthrough — never by the automated test suite): `allOverridesSafe()`'s
  cached array stored `available_at` as a raw `Carbon` instance. The
  `database` cache store serializes this with PHP's native `serialize()`;
  unserializing it on a later request intermittently threw "The script
  tried to call a method on an incomplete object" whenever that
  particular PHP-FPM worker hadn't yet autoloaded the `Carbon` class —
  worker-dependent, so the very first request after a write (still a
  cache miss, computed fresh) always looked correct, while subsequent
  cache-hit reads could silently fail. `allOverridesSafe()`'s own
  fail-safe `catch` swallowed this and resolved Active instead of the
  real Maintenance/Coming Soon state — safe in the "never fail toward
  unavailable" sense, but wrong for genuine customer-facing accuracy, and
  would have silently defeated Phase C's future middleware too. Invisible
  to `FeatureAvailabilityTest.php` because `phpunit.xml` forces
  `CACHE_STORE=array` (pure in-memory, no serialization pass ever
  happens). **Fixed** by storing `available_at` as an ISO 8601 string in
  the cached array instead of a `Carbon` instance (the uncached
  `fullRegistryWithState()`/Super Admin management path was never
  affected — it reads real Eloquent models directly). A new regression
  test, `test_cached_entry_survives_a_real_serialize_round_trip`, exercises
  a genuine `serialize()`/`unserialize()` round trip directly so this
  class of bug can't silently return. Re-verified stable across 5+
  repeated cache-hit reads after the fix, via real HTTP requests.

## Purpose

A centralized, Super-Admin-controlled mechanism for placing an individual
SureSign page/module into one of three states:

- **Active** — normal operation, no change in behaviour.
- **Maintenance** — the module stays visible in navigation and its route
  still resolves, but its operational content is replaced by a dedicated
  "temporarily unavailable" state. Used when a shipped feature has a
  temporary problem.
- **Coming Soon** — the module is visible but not yet released. Used only
  where truthful (see Registry below) — never as a substitute for
  properly securing an unfinished code path.

This is a **global, platform-wide** switch in V1 — not tenant-configurable,
not per-organisation. Every customer, regardless of organisation, sees the
same effective state for a given feature.

## What this is NOT

- **Not Laravel's global application maintenance mode.** That remains the
  existing infrastructure/deployment mechanism, untouched by this system.
  Feature Availability only ever affects one registered page/module at a
  time; it has no "take the whole platform down" concept at all.
- **Not `App\Support\Entitlements\Feature` / `App\Services\Entitlements\
  FeatureGate`.** Those are per-organisation COMMERCIAL entitlements
  (subscription plan, billing, usage allowances) — deliberately excluding
  platform modules by their own design. Feature Availability has no
  Subscription/Organization/billing dependency whatsoever and resolves
  identically for every organisation. Neither system reads the other; no
  code is shared between them.
- **Not `SuresignSetting::hidden_pages`.** That is a binary, existing,
  shipped visibility toggle — a hidden page is fully functional and still
  reachable via direct URL, simply absent from the sidebar. Feature
  Availability answers a different question: the nav item stays visible,
  the route still resolves, but the page's own content is replaced by a
  Maintenance/Coming Soon state. Neither system reads the other.
- **Not an AI feature.** No AI provider call, credit reservation,
  simulation, or ledger entry is ever touched by any part of this system.

## Registry: the authoritative catalogue

`App\Support\FeatureAvailability\FeatureAvailabilityRegistry` is a static,
code-defined catalogue — the ONLY place a feature key, label, description,
category, frontend route(s), and per-feature `maintenance_supported`/
`coming_soon_supported` flags are declared. Mirrors the structural
relationship between `App\Support\Entitlements\Feature` and
`pricing_plan_entitlements` (code registry = catalogue, DB rows = mutable
overrides) without sharing any code with that system.

An unregistered/unknown feature key always resolves Active — it can never
become unavailable "by accident" just because it isn't listed here.

### V1 registry (confirmed against real routes during the Phase A
route-ownership audit — not a blind transcription of an aspirational list)

**Project** (14 modules; `project.overview` and `project.calendar` are
deliberately excluded — see below):
`project.contracts`, `project.commercial`, `project.variations`,
`project.notices`, `project.programme`, `project.delay_eot`,
`project.risks`, `project.rfis`, `project.meetings`, `project.qa`,
`project.snagging`, `project.site_reports`, `project.delivery_documents`,
`project.drawings`, `project.closeout`, `project.adjudication`,
`project.documents`.

**Organization** (`organization.dashboard`/`organization.projects` are
deliberately excluded — see below; `organization.consultations` is
deferred, a legitimate future module not required for V1):
`organization.commercial`, `organization.site_admin`,
`organization.documents`, `organization.reports`, `organization.team`.

**Platform**: `ai.assistant`.

### Deliberately excluded from V1 (entry-point/cross-cutting — must remain
continuously reachable)

- `project.overview` — the landing page after entering a project; gating
  it would strand the user with no other way in.
- `organization.dashboard` (`/app`) and `organization.projects`
  (`/app/projects`) — the platform's own entry points.
- `project.calendar` — aggregates deadlines sourced from Programme,
  Delay & EOT, Notices, and other modules; genuinely cross-cutting, not a
  single module's own content.

### Coming Soon — truthful only

`coming_soon_supported = true` for exactly two features in V1:

- **`ai.assistant`** — CLAUDE.md's AI Workflow Context section confirms
  `AiController` has no corresponding chat methods; the routed page is
  genuinely non-functional today. The cleanest, fully truthful Coming Soon
  case in the platform.
- **`organization.reports`** — the Report Library already ships some
  reports while others carry a hardcoded, uncentralized `(coming soon)`
  label in `reports/page.tsx`'s own `report.available` flag (left
  unmigrated in this phase — see Technical Debt below).

Every other already-shipped module is `coming_soon_supported = false` —
being recently shipped (e.g. `project.drawings`) is explicitly NOT
sufficient justification for a Coming Soon state (Phase A instruction).
Only definitive repository evidence of genuinely unreleased functionality
would justify adding a new exception later.

## Status vocabulary

`App\Support\FeatureAvailability\FeatureAvailabilityStatus` — a plain class
with string constants (`ACTIVE`/`MAINTENANCE`/`COMING_SOON`), matching this
codebase's existing vocabulary convention (no native PHP enum is used
anywhere in this codebase yet). `ACTIVE` is both the default and the
fail-safe target for anything unrecognised.

## Persistence: "no row = Active"

`feature_availabilities` (migration
`2026_08_18_000002_create_feature_availabilities_table.php` — originally
created/run under a future-dated filename, `2026_09_06_000001`, then
corrected via a targeted `migrate:rollback --step=1` + rename + re-migrate
once confirmed to be the only migration in its batch, before this phase's
checkpoint; the table was empty throughout, so no data was ever at risk)
— one row per feature key that currently has a non-default override:

```
id
feature_key    string, unique — validated in-app against the registry
status         string, default 'active'
message        nullable text
available_at   nullable timestamp (UTC) — informational only, no scheduler
updated_by     nullable FK to users, nullOnDelete
timestamps
```

Deliberately global — no `organization_id`/`project_id`/tenant FK of any
kind. Pure additive migration; no existing table touched; no seed rows.
Confirmed run against the real dev database (batch 39) — table created
empty, so every registered feature resolved Active immediately, and every
existing SureSign workflow was unaffected.

**Restoring a feature to Active deletes its override row** rather than
keeping a redundant "Active" row — this preserves the "no row = Active"
invariant rather than accumulating rows that mean the same thing as having
none. The full transition is still captured in `ActivityLog` regardless.

## Service: the authoritative read/evaluation layer

`App\Services\FeatureAvailability\FeatureAvailabilityService` —
`statusFor()`, `entryFor()`, `allEffective()`, `isActive()`/
`isMaintenance()`/`isComingSoon()`, `fullRegistryWithState()`,
`isAvailableToUser()`, `requireAvailable()`.

Fail-safe resolution order (mirrors `App\Support\AI\AiCreditOperatingMode`'s
"one authoritative accessor, fail safe to the safe default" shape):

1. Unregistered/unknown feature key → **Active** (logged as a warning).
2. No DB row for a registered key → **Active**.
3. A row with a valid status → that status.
4. A row with a corrupt/unrecognised status string → **Active** (logged).
5. The underlying lookup (DB/cache) throws → **Active for every feature**,
   never Maintenance. A broken availability subsystem must never
   accidentally take the whole platform offline.

### Bypass vs. management — two separate permissions

- **Bypass (access only)**: Super Admin AND Admin always resolve as
  available via `isAvailableToUser()`/`requireAvailable()`, regardless of
  the stored status — both are platform-wide roles in this codebase (see
  CLAUDE.md's Authorization conventions). Client never bypasses.
- **Management (mutation)**: ONLY Super Admin may view or change Feature
  Availability configuration through the admin API — Admin is explicitly
  excluded from both `GET` and `PUT /admin/feature-availability...`,
  deliberately stricter than this codebase's usual "Super Admin OR Admin
  may read" convention (e.g. AI Credits/AI Telemetry). Bypass permission
  never implies management permission.

## Cache

`feature-availability:all` (`App\Support\FeatureAvailability\
FeatureAvailabilityCacheInvalidator::CACHE_KEY`), 5-minute TTL, via the
existing `Cache` facade (no Redis-specific requirement — matches the
platform's existing convention). `FeatureAvailabilityCacheInvalidator::
forget()` mirrors `BrandingCacheInvalidator`'s contract exactly:
best-effort, never throws, called immediately after every successful
Super Admin write so the very next customer request observes the change
with no deployment or manual cache clear.

## API

**Customer-facing** (`GET /feature-availability`, any authenticated user —
matches `SuresignSettingController::publicShow()`'s existing "public read —
all authenticated users" convention):

```json
{
  "features": {
    "project.programme": { "status": "maintenance", "message": "...", "available_at": "2026-08-18T08:00:00+00:00" }
  }
}
```

Only non-Active override entries appear — a missing key means Active.
Never exposes `updated_by`, audit reason, actor identity, or cache
internals. Fails safe to `{"features": {}}` (never a 500) if the
underlying lookup throws.

**Super Admin management** (`role:Super Admin` ONLY for both endpoints):

- `GET /admin/feature-availability` — full registry + effective state per
  feature, sufficient for the future Phase B management screen.
- `PUT /admin/feature-availability/{feature_key}` — `status`, `message`
  (nullable), `available_at` (nullable), `reason` (required, min 10 chars),
  `confirmed` (required, `accepted`) — mirrors `ManageAiCreditsRequest`/
  `UpdateAiCreditOperatingModeRequest`'s shape exactly. Rejects (422) an
  unregistered `feature_key` or a `status` the registry entry doesn't
  support (e.g. `coming_soon` for `project.contracts`, `maintenance` for
  `ai.assistant`).

## Audit

Reuses `ActivityLog` directly — no new audit table. Event:
`feature_availability.status_changed`, with metadata capturing
`feature_key`/`previous_status`/`new_status`/`previous_message`/
`new_message`/`previous_available_at`/`new_available_at`/`reason`/
`changed_by`/`changed_at`. A null `subject` is used (an Active-restore
deletes the row, so there's no natural Eloquent subject to reference by
that point) — the full transition is still captured in metadata regardless.

## Middleware — built, tested, NOT yet attached to any route

`App\Http\Middleware\EnsureFeatureIsAvailable`, aliased `feature.available`
in `bootstrap/app.php`. Used as `feature.available:{key}`. Returns a
deterministic `503` with `{message, code: 'feature_maintenance'|
'feature_coming_soon', feature}` for a blocked Client; continues unchanged
for Active or for a Super Admin/Admin bypass; fails open (continues) for an
unregistered key. Tested directly against a throwaway test route in
`tests/Feature/FeatureAvailabilityTest.php` — **deliberately not attached
to any real project/organization module route in this phase.** Attaching
it is a separate, dedicated enforcement-rollout phase, gated on finishing
the full route-ownership audit for every write endpoint (which reads are
genuinely module-exclusive vs. shared with Dashboard/Overview/Calendar/
notifications — see the Phase A discovery report for the audit performed
so far, including the confirmed EOT-requests cross-ownership between
`project.delay_eot` and `project.notices` that still needs a resolution
before any middleware is attached to that specific endpoint).

## Technical debt / follow-up

- `reports/page.tsx`'s existing hardcoded per-report `report.available`
  flag is NOT migrated onto this registry in this phase — recorded as
  follow-up debt only, per explicit Phase A instruction.
- The EOT-requests endpoint is shared between `project.delay_eot` and
  `project.notices`'s own UI — a future enforcement phase must resolve
  which key (if either) actually gates that specific write endpoint before
  attaching middleware to it.
- Phase B is complete (Super Admin management UI, customer-facing
  Maintenance/Coming Soon states, sidebar status badges, 23 pages gated).
  **Phase C (backend route-ownership enforcement rollout — actually
  attaching `feature.available` to real module API routes) has not
  started.** `EnsureFeatureIsAvailable` remains built, independently
  tested, and unattached to any production route.
