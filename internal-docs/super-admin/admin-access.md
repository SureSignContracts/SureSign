# Super Admin Configurable Admin Access

## What this is

A Super Admin can control which SureSign Admin modules a specific Admin user
is allowed to use — both what they see in the sidebar and what their API
calls are actually allowed to do. This is backend-authoritative: hiding a
sidebar item was never enough on its own, and still isn't — the same
restriction is enforced on the API itself.

This is a separate system from:

- **Client tenant authorization** (`authorize()`/`authorizeProject()` — see
  the main AGENTS.md) — completely untouched by this feature. Client never
  needs, holds, or is checked against any `admin.module.*` permission.
- **Feature Availability** (`App\Support\FeatureAvailability`) — a global,
  platform-wide Active/Maintenance/Coming-Soon switch, the same for every
  viewer regardless of role. This feature differentiates *between*
  individual Admin operators on a page that Feature Availability already
  says is Active. The two combine (a module can be both feature-unavailable
  *and* permission-restricted) but are never coupled in code or storage.
- **Organisation entitlements** (`App\Support\Entitlements\Feature`/
  `FeatureGate`) — commercial, per-organisation, Client-facing. Unrelated.

## Architecture

Built entirely on the `spatie/laravel-permission` package already installed
in this codebase — no second permission framework, no bespoke JSON column.

- **`App\Support\Admin\AdminAccess`** — the one authoritative catalogue of
  configurable module keys (`admin.module.*`), each with a label and a
  display group, plus `INITIALIZED_SENTINEL` (`admin.access.initialized`)
  — an internal marker permission, never part of the catalogue, the UI, or
  any accepted configuration payload. Nothing else defines the module list
  independently.
- **`App\Services\Admin\AdminAccessService`** — `ensurePermissionsExist()`
  (idempotent, lazy — mirrors this codebase's existing `Role::firstOrCreate()`
  convention, never run from a global boot hook), `grantFullAccess(User)`,
  `removeManagedAccess(User)`, `isInitialized(User)`.
- **`Gate::before()`** (`AppServiceProvider::boot()`) — the one place Super
  Admin bypasses every permission check platform-wide. Super Admin access
  never depends on any stored permission row.
- **`App\Listeners\GrantBaselineAdminAccessOnRoleAttached`** — listens to
  Spatie's `RoleAttachedEvent` (`config('permission.events_enabled')` is
  `true`) and grants the full baseline the moment ANY code path assigns the
  Admin role to a user who has never been initialised. This exists as a
  catch-all in addition to the explicit call sites in `UserController`
  (`inviteOneUser()`/`update()`) and `DatabaseSeeder` (the platform's own
  Admin demo seed) — built after discovering, empirically, that dozens of
  pre-existing test fixtures assign the Admin role directly and would
  otherwise never receive the baseline. Deliberately does NOT listen to
  `RoleDetachedEvent` — see its own docblock for why that would be unsafe
  (Spatie's `syncRoles()` always detaches-then-reattaches even when the
  role is unchanged, so a detach-side listener would strip a restricted
  Admin's custom permissions on every such no-op resubmission).

  **Fixed (Admin Access Configuration — Final Initialisation phase,
  2026-08-26)**: this previously keyed off "holds none of the catalogue's
  `admin.module.*` permissions" — which wrongly treated a Super Admin's
  deliberate "Clear All" (zero modules) the same as an uninitialised
  Admin, and would have silently restored the full baseline the next time
  the Admin role was attached for that user (including a no-op role
  resubmission). It now keys off `AdminAccess::INITIALIZED_SENTINEL`
  absence instead — see "The initialisation sentinel" below.

  **Scope correction (Admin Access Configuration — Final Deployment /
  Cold-Start Hardening phase, 2026-08-26)**: this listener protects only
  requests that themselves assign/re-assign a role (invite, restore,
  role-change, a future console command/seeder, or a test fixture's
  `assignRole()` call) — it fires on `RoleAttachedEvent`, which Spatie
  only raises from those specific calls. It does **not** fire on, and
  provides no protection for, an ORDINARY request from an existing legacy
  Admin (a plain `GET`/`PUT` against any module route) — that request
  touches no role assignment at all, so this listener never runs for it.
  Initialising every pre-existing legacy Admin before permission
  enforcement goes live is the deployment rollout's job
  (`admin:permissions:backfill`, run as a required release step — see the
  Deployment section below), never this listener's. Do not read this
  listener as a substitute for that rollout step.
- **`App\Http\Controllers\Api\AdminUserAccessController`** — the
  configuration endpoints, `GET`/`PUT /users/{id}/permissions`, sitting
  inside the existing `role:Super Admin` ONLY user-management route group
  (same group as ban/set-password/etc.) — an Admin can never reach this
  controller at all.
- **`App\Console\Commands\BackfillAdminAccess`** (`admin:permissions:backfill`)
  — the one-time, manual, idempotent rollout command for Admins who existed
  before this feature shipped. Never run automatically.

## The initialisation sentinel

`AdminAccess::INITIALIZED_SENTINEL` (`admin.access.initialized`) is an
internal Spatie permission that marks "this Admin's access has been
explicitly initialised" — granted whenever `admin.module.*` access is
initialised (full baseline or a deliberate restriction, including all the
way to zero modules), and removed whenever the account transitions away
from Admin. It is structurally separate from the 15-key catalogue:

- Never appears in `AdminAccess::catalogue()`/`keys()`, so it can never
  render in the Access UI's checkbox list.
- Never accepted by `PUT /users/{id}/permissions` — that endpoint's
  validation only accepts `Rule::in(AdminAccess::keys())`, which excludes
  it categorically.
- Never checked by any route's `permission:` middleware — it protects
  nothing.

Its only purpose is to answer one question, correctly, that permission
*count* alone cannot: "has this Admin's access ever been deliberately set
(even to zero), or has it never been touched at all?"
`AdminAccessService::isInitialized(User $user): bool` is the one place
this is read from. See `App\Listeners\GrantBaselineAdminAccessOnRoleAttached`
and `App\Console\Commands\BackfillAdminAccess` below for the two places
that previously got this wrong by checking permission count instead.

## Default access policy

- **Existing Admins** (as of this feature shipping): granted full access to
  every configurable module via `admin:permissions:backfill`, run once at
  rollout. Never re-run automatically; safe to re-run (skips any Admin who
  has already been initialised — including one restricted all the way to
  zero modules).
- **New Admins** (invite, restore-via-reinvite, or a role change TO Admin):
  granted the full baseline AND the initialisation sentinel automatically
  the moment they become Admin — never start locked out.
- **An Admin whose access has been restricted, including to zero modules**:
  stays restricted through any ordinary profile edit, through
  re-submitting the same role unchanged, and through a re-run of the
  backfill command. Only an explicit `PUT /users/{id}/permissions` call
  changes it. `PUT /users/{id}/permissions` uses a scoped
  `givePermissionTo()`/`revokePermissionTo()` diff — never
  `syncPermissions()`, which would wholesale-replace the account's entire
  permission set and destroy the sentinel.
- **A transition AWAY from Admin** (to Client or Super Admin): the
  catalogue-managed permissions AND the initialisation sentinel are both
  removed (never a blind `syncPermissions([])`, which would also strip any
  unrelated permission this account might hold for a different reason).
  Super Admin never needs these permissions anyway — `Gate::before()`
  bypasses everything.
- **A later transition back into Admin**, after any prior departure, is
  always treated as a fresh start — full baseline + sentinel again, never
  a silent restoration of whatever was configured before the departure.

## The catalogue

See `App\Support\Admin\AdminAccess::catalogue()` for the current list
(Dashboard, Companies, Projects, Documents, Appointments, Consultancy,
Templates, Prompt Library, Find Company, Pricing, Product Updates, SureSign
Branding, AI Credits, AI Usage & Cost, Google Integration).

### Permanently Super-Admin-only (never configurable)

Users, AI Config, Application Monitoring, Storage, Support, Announcements,
System Logs, Audit Log. These already had `superAdminOnly: true` on their
AdminSidebar nav items before this feature. Building the catalogue's own
route-topology map found that six of them (AI Config, Storage, Support,
Announcements, System Logs, Audit Log) were actually reachable by any Admin
on the backend — the exact same class of mismatch Pricing already had — with
no code-comment evidence anywhere of a deliberate decision to widen them
(unlike Pricing's own documented Phase G0 decision). These were tightened to
`role:Super Admin` at the route level as part of this phase, not made
configurable — the frontend's restriction was correct; the backend's was the
bug.

### The Pricing resolution

Pricing's backend has deliberately allowed `Super Admin|Admin` since an
earlier, explicitly documented decision (see `routes/api.php`'s own comment
on the Pricing route group). The frontend's `superAdminOnly: true` flag on
the Pricing nav item was the stale side — removed. Pricing is now genuinely
configurable (`admin.module.pricing`).

### Deliberately left ungated (re-classified, Admin Access Configuration —
Final Initialisation phase, 2026-08-26)

Every edge previously flagged as "left untouched, no confirmed caller" has
now been explicitly classified:

- **GENERAL PLATFORM SETTINGS (ungated, confirmed intentional)**:
  `AdminController::settings()`/`updateSettings()` (platform name, support
  email, max upload size, global feature flags) and
  `SuresignSettingController::updateNotifications()`/`updateAppointments()`
  (global notification-event allowlist; Appointments' platform-wide
  reminder timing/TTL/ICS config) — all reached via the profile popover's
  own "Settings" link, not any AdminSidebar module, and none is scoped to
  a single one of the 15 catalogue modules (`updateAppointments()` in
  particular configures platform infrastructure timing, not the
  Appointments module's own resource-management surface, which is already
  gated). Unchanged scope; still reachable by every Admin.
- **PERSONAL OPERATOR SELF-SERVICE (ungated, confirmed intentional)**:
  `/appointment-availability/me/*` AND `/appointment-availability/{user}/*`
  — both share one controller (`AppointmentAvailabilityController`) whose
  own `resolveTarget()` already enforces "an Admin may only ever target
  themselves; only Super Admin may target another user" internally,
  regardless of the route parameter. An Admin restricted from
  `admin.module.appointments` was never able to touch anyone else's
  availability anyway (that was always Super-Admin-only), and every admin
  operator may need to set their own availability regardless of whether
  they otherwise manage the Appointments module. No gap.
- **REMOVED (was dead code, not a real capability)**:
  `Route::apiResource('organizations', OrganizationController::class)->except(['show', 'update'])`
  — registered `index`/`store`/`destroy` for `/organizations`, but
  `OrganizationController` has no `index()`/`store()`/`destroy()` methods
  at all. Any call would have thrown a 500 `BadMethodCallException`, never
  exposed or bypassed anything, and no frontend caller exists (confirmed
  in this phase's audit and the one before it). This was not a Companies
  bypass — it was inert route registration left over from an earlier,
  never-completed plan. Removed outright, rather than gated, since there
  was no real capability behind it to protect. The genuine, functional
  Companies-listing endpoint is `GET /admin/organizations`
  (`AdminController::organizations()`), already gated behind
  `admin.module.companies`, and `OrganizationController::show()`/
  `update()` remain routed separately via the singular self-service
  `/organization` routes (unaffected).

No unexplained bypass route remains for Companies or any other module —
an Admin without `admin.module.companies` cannot reach any working
Organisation list/create/delete capability by any route, and no route
exists that would let a restricted Admin reach Companies data through a
Client-facing endpoint (Client self-service uses only the singular
`/organization` routes, scoped to the authenticated user's own
organisation, and shares no controller method with any Admin-facing
listing/management action).

## Configuration UI

Super Admin → Users → open an Admin → **Access** section (only rendered when
the target's saved role is Admin). Checkbox list grouped by module, driven
entirely by the backend catalogue (`GET /users/{id}/permissions` returns both
the full module list and this Admin's current grants — the frontend never
hardcodes the list). Select all / Clear all / Save.

## Live permission changes

Spatie's `givePermissionTo()`/`revokePermissionTo()` clear its own permission
cache immediately (same as `syncPermissions()`, which this endpoint no
longer calls — see Phase 5 above), so backend enforcement takes effect on
the very next request. A restricted Admin's already-open browser tab keeps
its stale sidebar until the normal `/auth/me` refresh/reload/next-login
cycle — the backend is authoritative regardless, so a stale sidebar showing
an item does not mean the API call behind it will succeed.

## Production rollout (deployment-safety, confirmed)

**SureSign has no automated pre-cutover release-command gate.** Confirmed
directly from `backend/docker/entrypoint.sh` and `docker-compose.prod.yml`:
the backend runs as a single container (`replicas: 1`, `restart:
unless-stopped`); on every start it runs `exec php artisan serve` directly,
with no wait on any release command. `migrate --force` is explicitly
documented as "an explicit, one-off release step... run this deliberately
before/after a deploy... never automatically" — i.e. an operator-run manual
step, not something the deployment tooling enforces the ordering of. No
CI/CD pipeline exists in this repository that would enforce this ordering
either. This means a one-step rollout (deploy the code, including live
`permission:admin.module.*` enforcement, and separately remember to run
the backfill) is **not** provably safe — the backfill's completion relative
to the first request the new container serves depends entirely on operator
timing, not on anything the system guarantees.

**Required rollout: TWO-STAGE.**

- **Stage 1** (safe to deploy and run immediately, no lockout risk of any
  kind, since no route yet requires any `admin.module.*` permission):
  `App\Support\Admin\AdminAccess`, `App\Services\Admin\AdminAccessService`,
  `App\Listeners\GrantBaselineAdminAccessOnRoleAttached` +
  `config('permission.events_enabled')` + its `AppServiceProvider`
  registration, the `UserController`/`DatabaseSeeder` lifecycle-hook calls,
  `App\Console\Commands\BackfillAdminAccess`,
  `App\Http\Controllers\Api\AdminUserAccessController` (the configuration
  API), the frontend Access UI, and the dead-route removal
  (`organizations` apiResource) — none of this attaches any
  `permission:admin.module.*` middleware to any route. Deploy this stage,
  then run `php artisan admin:permissions:backfill --dry-run` followed by
  `php artisan admin:permissions:backfill` against the live production
  database, and verify every existing Admin now holds
  `admin.access.initialized` plus the full catalogue (or, for any Admin a
  Super Admin has since deliberately configured via the now-live
  configuration API, their intended configuration) before proceeding.
- **Stage 2** (deploy only after Stage 1's backfill has been verified
  complete): the `permission:admin.module.*` middleware attachments in
  `routes/api.php` — the actual enforcement layer. The `role:Super Admin`
  tightening of Storage/Support/Announcements/System Logs/Audit
  Log/AI Config (removing `Admin` from those six routes' allowed roles
  entirely) is independent of the sentinel/backfill mechanism — it is a
  plain role check, never conditioned on any Admin's permission state — and
  may ship in either stage without contributing to this lockout question;
  it was already a deliberate, previously-reported security fix closing an
  unauthorized backend gap the frontend already hid.

Never implement "no permissions/no sentinel found → allow" as a runtime
fallback to paper over ordering — restriction must always fail closed. See
the AdminAccessTest.php cold-start tests for the direct regression proof
that Stage 1 alone (catalogue + sentinel + backfill) is safe against a
genuinely empty permissions table before Stage 2 ever ships.
