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

**Default-Baseline Correction (2026-08-27) — read this before assuming "full
access" means the whole catalogue.** `App\Support\Admin\AdminAccess` exposes
two explicit, separately-defined sets:

- **`catalogue()`/`keys()`** — the entire CONFIGURABLE surface, 23 keys. What
  a Super Admin may choose to grant.
- **`defaultKeys()`** — the DEFAULT baseline, 15 keys (the original catalogue,
  before the Full Parity Access Expansion). What a new Admin actually
  receives with no Super Admin decision involved.

The Full Parity Access Expansion (below) made eight formerly-permanently-
Super-Admin-only modules (Users, AI Config, Application Monitoring,
Storage, Support, Announcements, System Logs, Audit Log) configurable —
but "configurable" was never meant to mean "granted by default," and an
earlier version of that expansion conflated the two: `AdminAccessService::
grantFullAccess()` granted the ENTIRE 23-key catalogue to every new Admin,
which would have silently handed all eight sensitive modules to any newly
invited/restored/promoted Admin with no explicit Super Admin action at
all. This was caught and fixed before ever reaching production — the
method was renamed to `grantDefaultAccess()` and now grants
`defaultKeys()` only. `defaultKeys()` is hardcoded, listed key-by-key —
never derived by slicing/filtering `catalogue()` — so it can never
silently drift just because the catalogue changes again later.

- **Existing Admins** (as of the original feature shipping): granted the
  default baseline via `admin:permissions:backfill`, run once at rollout.
  Never re-run automatically; safe to re-run (skips any Admin who has
  already been initialised — including one restricted all the way to zero
  modules, and including one already sitting on exactly the default
  baseline — the sentinel is what's checked, never the permission count or
  contents).
- **New Admins** (invite, restore-via-reinvite, or a role change TO Admin):
  granted `defaultKeys()` AND the initialisation sentinel automatically the
  moment they become Admin — never start locked out of the ordinary
  modules, and never start with any of the eight sensitive ones either.
- **An Admin whose access has been restricted, including to zero modules,
  or explicitly granted one or more of the eight sensitive modules**: stays
  exactly as configured through any ordinary profile edit, through
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
  always treated as a fresh start — the default baseline + sentinel again,
  never a silent restoration of whatever was configured (including any of
  the eight sensitive modules) before the departure.
- **No migration and no new production backfill were required for this
  correction** — it only changes what future `grantDefaultAccess()` calls
  grant; no already-stored permission row was touched, and no existing
  Admin's access changed as a result of this fix.

## The catalogue

See `App\Support\Admin\AdminAccess::catalogue()` for the current list.
Full Parity Access Expansion (2026-08-26) brought the catalogue to 23 keys
total — every module a Super Admin has: Dashboard, Companies, Projects,
Documents, Appointments, Consultancy, Templates, Prompt Library, Find
Company, Pricing, Product Updates, SureSign Branding, AI Credits, AI Usage
& Cost, Google Integration, **Users, AI Config, Application Monitoring,
Storage, Support, Announcements, System Logs, Audit Log** (the eight
bolded keys are the ones this expansion added — see below for their
history and the one carve-out).

### History: the eight modules that were permanently Super-Admin-only

Before the Full Parity Access Expansion, Users, AI Config, Application
Monitoring, Storage, Support, Announcements, System Logs, and Audit Log
were deliberately excluded from the catalogue — all had `superAdminOnly:
true` on their AdminSidebar nav items, and six of them (AI Config,
Storage, Support, Announcements, System Logs, Audit Log) had also been
found, during the original catalogue build, to be reachable by any Admin
on the backend despite the frontend hiding them — the exact same class of
mismatch Pricing already had, with no code-comment evidence anywhere of a
deliberate decision to widen them (unlike Pricing's own documented Phase
G0 decision). They were tightened to `role:Super Admin` at the route
level as part of that original phase, not made configurable.

**Full Parity Access Expansion (2026-08-26)** reversed that decision at
the explicit request of the platform owner ("put everything that the
super admin have also, so I can configure if an admin can have that or
not") — every one of these eight is now a genuine `admin.module.*`
catalogue key, each independently gated at the route level
(`permission:admin.module.storage`, `permission:admin.module.support`,
etc. — no longer bundled under one shared `role:Super Admin` group).

### The Users module's one deliberate carve-out

Users is the one module where "configurable" does not mean full parity
with Super Admin in every respect. Before offering this expansion, the
platform owner was asked explicitly whether granting `admin.module.users`
should let an Admin create/promote/manage a Super Admin account, and
chose the safer option: **carve out the risky actions.** A follow-up
review then went further still: Super Admin accounts are excluded from
the ordinary Admin user-management surface entirely, not merely
non-mutable.

- An Admin granted `admin.module.users` can invite, edit, ban/unban,
  deactivate, force-password-reset, set-password, revoke-tokens,
  reset-tours, and remove ordinary Admin/Client accounts — the module
  gate alone governs this, exactly like any other module.
- An Admin — even one granted `admin.module.users` — can **never** act on
  an existing Super Admin account in ANY way (update, ban, unban,
  deactivate, remove, force-password-reset, set-password, revoke-tokens,
  reset-tours, verify/unverify-email), and can **never** create a new
  Super Admin account or promote an existing Admin/Client to Super Admin
  (invite, bulk-invite, or a role-change). A genuine Super Admin actor is
  completely unaffected — every one of these checks is a no-op for them.
- **An Admin also cannot SEE a Super Admin account at all** (Users Module
  Super Admin Exclusion, 2026-08-27): `UserController::index()` excludes
  any user holding the Super Admin role from the list entirely for a
  non-Super-Admin actor (`whereDoesntHave('roles', ...)`, not a
  post-filter — the row is never fetched), and `show()`/`subscription()`
  both call `SuperAdminGuard::assertActorMayActOnTarget()` (previously
  only wired into mutating methods) so a direct id fetch fails with this
  codebase's normal generic 403 (`'Access denied.'`) — never a distinct
  message, and never a 404, that would confirm to the caller that the
  target specifically is a Super Admin account. A genuine Super Admin
  actor sees every row and can fetch any id, unaffected.
- This is enforced **only** at the controller level
  (`App\Support\Auth\SuperAdminGuard::assertActorMayActOnTarget()`/
  `assertActorMayAssignRole()`, called from every mutating method AND now
  `show()`/`subscription()` in `UserController`, plus the `index()` query
  scope above) — it cannot be expressed as route middleware alone, since
  the target user/intended role is only known once the request body
  and/or route-bound model are available. `bulkRemove()`'s per-row loop
  uses the boolean form (`actorMayActOnTarget()`) to report a Super Admin
  target as a normal per-row failure rather than aborting the whole
  batch, preserving that endpoint's existing partial-success contract;
  every single-target endpoint uses the hard-aborting
  `assertActorMayActOnTarget()`/`assertActorMayAssignRole()` instead.
- The Access-configuration endpoints (`GET`/`PUT /users/{id}/permissions`)
  remain `role:Super Admin` ONLY regardless of `admin.module.users` — an
  Admin can never configure any Admin's Access, full parity or not. This
  was true before the expansion and is unchanged by it.
- The frontend mirrors this (never as the real boundary — the backend
  guard above is authoritative): `app/admin/users/page.tsx` gates the
  whole page on `admin.module.users`, hides the "Super Admin" option from
  the role picker unless the viewer IS Super Admin, hides the Access
  section entirely unless the viewer is Super Admin, and renders a
  read-only notice in place of every mutating control when the target is
  a Super Admin and the viewer isn't. Since the backend `index()` now
  excludes Super Admin rows for a non-Super-Admin viewer, that viewer's
  Users list never contains a Super Admin row to click on in the first
  place — the modal's own restricted-state rendering remains as
  defense-in-depth (e.g. a stale client-side row reference), not the
  primary mechanism.
- `removeAndDetach()` needs no special handling — its own pre-existing
  eligibility check already restricts it to a Client target only, so a
  Super Admin target is structurally impossible there.

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

## Production rollout (deployment-safety, confirmed — COMPLETE, 2026-08-26)

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

**Executed sequence (2026-08-26):**

1. Stage 1 committed (`dede8a3`) and pushed to `origin/main`.
2. Deployed to production.
3. `php artisan admin:permissions:backfill --dry-run` run against the
   production container — reported exactly one legacy Admin
   (`graham@suresigncontracts.com`, id 2) would be granted the full
   baseline, zero already initialised, no errors.
4. `php artisan admin:permissions:backfill` (real run) — granted the full
   baseline to that same Admin, matching the dry-run prediction exactly.
5. `php artisan admin:permissions:backfill --dry-run` run again —
   confirmed zero remaining legacy Admins (`0 Admin(s) granted... 1
   already initialised and were left unchanged`), proving the backfill
   took effect and is idempotent.
6. Only after that confirmation was Stage 2 (the `permission:admin.module.*`
   middleware attachments and `AdminSidebar.tsx`'s permission-aware
   hiding, held uncommitted in the working tree since Stage 1) committed
   and enforcement went live. No legacy Admin experienced a lockout
   window — every existing Admin held `admin.access.initialized` before
   enforcement shipped.
