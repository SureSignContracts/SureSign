# SureSign Feature Availability

**Status: Phase A (backend foundation) complete. No customer-facing UI
exists yet (Phase B). No production module route has the enforcement
middleware attached yet (Phase C).**

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
- Phase B (Super Admin management UI, customer-facing Maintenance/Coming
  Soon states, sidebar status badges) and Phase C (route-ownership
  enforcement rollout) are both still to come.
