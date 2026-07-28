# Demo Environment

This is developer documentation for the SureSign demo environment — a
deliberately-authored fictional company (**Halden Grove Construction Ltd.**)
used for marketing screenshots, documentation, sales demonstrations,
onboarding, and training. It is not test fixture data and not part of the
public product; nothing here is customer-facing.

The full design (company profile, story philosophy, project portfolio,
module coverage, screenshot style guide, roadmap) was agreed as a planning
document before any implementation started. This page documents what has
actually been *built*, phase by phase, and how to operate it.

## Status

**Permanently deployed at `demo.suresigncontracts.app`.** See
[deployment.md](deployment.md) for the full infrastructure: its own
frontend/backend/queue containers, Production's MySQL/Redis reused with
logical isolation, a fully separate storage volume, no scheduler, and the
Dokploy/Cloudflare setup. This section (below) covers the seeder/data
architecture, which is identical whether run against this dev sandbox or
the permanent deployment.

**Phase 1 complete: demo company foundation.** Organization, branding,
users, roles/permissions.

**Phase 2 complete: the flagship project.** One fully-detailed project —
**Riverside Wharf — Block C Residential** — 9 months into an 18-month
programme, covering every currently-implemented module that applies to it:
contract + confirmed AI analysis, 10 trade packages across every lifecycle
stage, contract-level programme milestones, 6 risks (open/mitigated/closed),
3 variations, 6 monthly payment applications with a live pay-less notice
dispute on the latest one, a weather delay event → EOT request → agreed
loss & expense claim, RFIs/site instructions/site diaries/progress
meetings/snags/QA reports, a document register (including two real
generated payment application workbooks), and 6 appointments spanning
completed/confirmed/cancelled/rescheduled. See
`database/seeders/Demo/Data/RiversideWharfStory.php` for the full authored
story and `config/demo.php`'s `feature_coverage` array for the
authoritative, machine-readable status of what exists.

**Phase 3 complete: the two projects that complete the lifecycle.**
**Coldfield Retail Park — Unit 4 Fit-Out** (near completion — Practical
Completion just achieved, retention still pending release, Final Account
still in draft, active snagging and close-out) and **Priory Court
Apartments** (fully completed — agreed Final Account, both retention
moieties released, a closed adjudication case, archived documentation).
Together with Riverside Wharf these three projects now demonstrate every
stage of a project's commercial journey from mid-project through
completion. See `database/seeders/Demo/Data/ColdfieldStory.php` and
`PrioryCourtStory.php` for their authored stories.

**Phase 4 complete: the full seven-project portfolio.** The remaining four
projects from the approved blueprint are now built, completing every stage
of the construction lifecycle:

- **Kingsmill Logistics Hub** — recently awarded, contract drafted but
  *unsigned*. Deliberately minimal: no trade packages, programme, or
  commercial activity, because none of that is genuine before a contract
  is in force.
- **Elmsworth Care Home Extension** — pre-construction. Contract executed,
  site commencement three weeks away, trade packages still in procurement,
  and — uniquely among all seven projects — a Contract AI Analysis
  deliberately left `processing` (unconfirmed), showing what that screen
  looks like before an admin has reviewed it.
- **Northgate Business Units — Phase 2** — early construction, month 2 of
  12. One payment application, still `submitted` (nothing certified yet).
- **Aldermere Distribution Centre — Phase 1** — operationally difficult
  but professionally managed: a genuinely overdue payment application (no
  Pay Less Notice issued), two RFIs past their response window, an open
  `act_now` risk, a disputed variation, and an EOT decision under review
  for months — every one of them visibly logged or escalated, with the
  most recent meeting minutes explicitly discussing recovery measures.

Together with Riverside Wharf, Coldfield Retail Park, and Priory Court
Apartments, **the full construction lifecycle is now represented start to
finish**: award → pre-construction → early construction → mid-project →
operationally difficult → near completion → completed.

**Demo Stabilisation complete — v1.0.0.** The two architectural risks
Phase 4 surfaced (anchor-date staleness in the validation tooling;
storage-disk isolation for generated files) are now resolved — see
**Anchor date strategy** and **Storage isolation** below. The environment
also gained a **Demo Manifest** (`php artisan demo:manifest`) — a
versioned snapshot of exactly what's seeded, used to "freeze" a known-good
state before a screenshot capture session and detect drift afterwards.
`config/demo.php`'s `version` is now `1.0.0`: the full seven-project
portfolio, clean `demo:validate`, and both isolation mechanisms in place
together define what v1.0 means — see **Demo Environment v1.0** below.

**Not yet implemented**: org-wide notifications (should emerge from
`NotificationEngineService` reading seeded deadlines/statuses rather than
being hand-seeded), and `AdjudicationDocument`/`AdjudicationDeadline` rows
for Priory Court's adjudication case. Run `php artisan demo:status` at any
time for a live, read-only summary of exactly what's seeded per project,
and `php artisan demo:validate` to check the whole environment — including
cross-project business consistency — makes sense (see **Business signals**
below).

## Anchor date strategy

`config('demo.anchor_date')` (env `DEMO_ANCHOR_DATE`, default
`2026-07-22`) is the demo environment's own notion of "today" —
`App\Support\Demo\DemoClock` is the single place this is read from.

Every authored Story class was written against this fixed point in time —
"Riverside Wharf is 9 months into an 18-month programme," "Aldermere's
Payment Application 7 is overdue" — and those claims are only true
*relative to the anchor*, not to whenever someone happens to run
`demo:seed` or view the demo. Phase 4 discovered this the hard way:
Riverside Wharf's own RFI 4 and EOT request (deliberately left "in
progress" as a snapshot of a live workflow) started showing up as
"overdue" in `demo:validate`'s Business signals purely because real time
had passed since the anchor — nothing in the story had changed.

The fix: `demo:validate`'s Business signals section (and any future
`NotificationEngineService` wiring) must compare against
`DemoClock::anchorDate()`, never `Carbon::now()`/real wall-clock time.
This makes the reported signals **deterministic and reproducible** — the
same `demo:validate` run reports the same overdue items whether it's run
the day the environment was seeded or six months later, which is exactly
what a screenshot capture session needs: the environment should look the
same in July as it does when someone reviews a screenshot in December.

`demo:status`/`demo:validate` both report `DemoClock::daysSinceAnchor()`
and warn once it exceeds 30 days, as a nudge that the environment's story
is aging — not because anything breaks (the anchor-based comparisons stay
correct forever), but because "9 months into an 18-month programme,
commenced 2025-10-20" starts reading oddly once real time has moved far
enough past 2026-07-22 that a human glancing at both dates would notice
the gap. Rolling the anchor forward (re-authoring every Story class's
dates relative to a new "today") is a future exercise, not implemented
here — this phase only fixes the tooling's *evaluation* of the existing
dates, not the dates themselves.

## Storage isolation

Generated files (the real payment application workbooks — see
**Idempotency** below) now write to their own isolated disk root,
`config('demo.storage_root')` (env `DEMO_STORAGE_ROOT`, default
`storage_path('app/demo-private')`), via `App\Support\Demo\DemoStorage::isolate()`.

The problem this fixes: `ExcelGenerationService`/`DocumentGenerationService`
(production code, shared with real customers — never modified for this)
write via the hard-coded `Storage::disk('local')`, to a path keyed only on
numeric project ID (`projects/{id}/generated/...`) — not scoped by
database connection. The demo connection's project IDs (1-7) can and do
collide with unrelated real-database project IDs on the same filesystem.
No incorrect file was ever actually served (generated filenames carry a
timestamp, and each connection's `Document` rows only ever reference files
that same seeder run just wrote), but the isolation was accidental, not
structural.

`DemoStorage::isolate()` fixes this the same way `demo:seed` already
isolates the database connection — not by modifying the production
services, but by temporarily repointing what the `'local'` disk *resolves
to* for the current process: `config(['filesystems.disks.local.root' =>
...])` followed by `Storage::forgetDisk('local')` to clear the cached
resolved instance. Every command that writes or reads generated files
(`demo:seed`, `demo:validate`, `demo:status`, `demo:manifest`) calls this
first. Real customer files under `storage/app/private/` are now never
touched by anything in this tree.

## Demo Manifest & Demo Freeze

`php artisan demo:manifest` generates a JSON snapshot of exactly what's
seeded: demo version, anchor date, organisation, every project's
id/code/status, and record counts across every module table.

```bash
# Freeze the current state — run this immediately before a screenshot,
# documentation, or sales-recording capture session.
php artisan demo:manifest --write

# Later: compare the live environment against the last frozen snapshot.
# Reports version/anchor/project/count drift, or "No drift" if unchanged.
php artisan demo:manifest
```

**"Demo Freeze" is a workflow, not a database flag**: freezing means
running `--write` once you're satisfied with the environment's state,
which saves `manifest.json` to the isolated storage root. That file *is*
the permanent record of what a given batch of screenshots/assets was
captured against. `demo:status` always shows the last frozen manifest's
timestamp (or "none yet") so it's obvious whether a freeze has ever
happened. There is deliberately no enforcement that stops `demo:seed` from
running after a freeze — the manifest is a diagnostic and audit trail, not
a lock; if screenshots need to be reproducible for months, re-run
`demo:manifest` before each capture session to confirm nothing has
drifted, and re-freeze (`--write`) if a deliberate change is being
accepted as the new baseline.

## Isolation

The demo environment runs on its own Laravel database connection, `demo`
(`config/database.php`), configured via `DEMO_DB_*` env vars — it defaults
to reusing the same MySQL server/credentials as the main app but a
**different schema** (`suresign_demo`), so no extra infrastructure is needed
today. Pointing `DEMO_DB_HOST`/`DEMO_DB_DATABASE` at a genuinely separate
server later (a dedicated demo deployment) requires no code change.

This connection is never the default. `demo:seed` and `demo:reset` always
pass `--database=demo` explicitly to Laravel's own `db:seed` /
`migrate:fresh` commands, which is what lets Eloquent models
(`Organization::create()`, `User::firstOrCreate()`, ...) write to the demo
schema without any model needing a hard-coded `$connection` override —
Laravel's `SeedCommand` swaps the connection resolver's default for the
duration of the seed run only, and restores it immediately afterwards.

**The real platform database is never touched by anything in this tree.**

## Creating / resetting the environment

```bash
# First time (or to rebuild from scratch): drops and re-migrates the demo
# schema, then seeds it. Prompts for confirmation unless --force is passed.
php artisan demo:reset --force

# Re-seed without dropping the schema — safe to run repeatedly, never
# duplicates the demo company or Riverside Wharf (see Idempotency below).
php artisan demo:seed

# Read-only health check — never writes data. Reports demo/platform
# version, database reachability, per-module record counts, a per-project
# module coverage table (✔/✖ per project per module), and warnings (e.g.
# more than one organisation, or a module config/demo.php marks as covered
# but has zero rows).
php artisan demo:status

# Read-only internal-consistency check — never writes data. Checks
# chronology (dates in the right order), orphaned/dangling relationships,
# duplicate entities, invalid commercial chains (e.g. a locked Final
# Account with no snapshot totals, retention_releases not summing to what
# the Final Account records), programme milestone/status disagreements,
# generated-file existence, and portfolio-wide consistency (a child
# record's organization_id must agree with its own project's; an unsigned
# contract's project must have no trade packages/payment applications).
# Exits non-zero if any error-level issue is found. Also prints a
# "Business signals" section — informational, not pass/fail — listing
# every currently-overdue payment application, past-due RFI, act-now open
# risk, and stalled EOT decision across the whole portfolio, computed
# fresh against today's real date every time it runs.
php artisan demo:validate
```

**IMPORTANT — a known gotcha `demo:reset` works around:** `migrate:fresh`
drops and recreates every table on the `demo` connection, but the PHP
process seeding it keeps running with the same, now-stale database
connection. Without forcing a fresh connection, a query moments later in
that same process — in particular the "does this document already exist"
guard before generating a real payment application workbook — can silently
miss rows the seed run just inserted, producing a duplicate real `.xlsx`
file with only one of the two ever linked to a `Document` row. `demo:reset`
calls `DB::purge($connection); DB::reconnect($connection);` immediately
after `migrate:fresh` and before seeding to eliminate this window. This was
caught during Phase 3 development by `demo:validate` disagreeing with a
manual file count — if you ever see more files on disk under
`config('demo.storage_root')` (default `storage/app/demo-private/projects/*/generated/`
— see **Storage isolation** below) than `Document` rows with a matching
`documentable_id`, this is the first thing to suspect.

Before the first run, the `suresign_demo` database itself must exist on the
MySQL server (Laravel does not create databases, only schemas within one):

```sql
CREATE DATABASE IF NOT EXISTS suresign_demo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON suresign_demo.* TO 'suresign'@'%';
```

## Seeder architecture

```
database/seeders/Demo/
  DemoEnvironmentSeeder.php      Entry point — orders and calls every phase's seeder.

  # Phase 1 — company foundation
  DemoRoleSeeder.php             Roles/permissions (mirrors DatabaseSeeder's 3-role model).
  DemoOrganizationSeeder.php     Halden Grove organization + branding.
  DemoUserSeeder.php             The six personas, Client role, org-scoped.

  # Phase 2 — Riverside Wharf flagship project
  DemoProjectSeeder.php          Project record, project_users, project_contacts.
  DemoContractSeeder.php         Main contract + a confirmed Contract AI Analysis.
  DemoTradePackageSeeder.php     10 trade packages spanning every lifecycle stage.
  DemoProgrammeSeeder.php        Contract-level programme milestones.
  DemoRiskSeeder.php             6 risks (open/mitigated/closed).
  DemoCommercialSeeder.php       Variations, 6 payment applications, pay-less notice
                                 dispute, delay event -> EOT -> loss & expense chain.
  DemoSiteManagementSeeder.php   RFIs, site instructions, site diaries, progress
                                 meetings, snags, QA reports.
  DemoDocumentSeeder.php         Document register + 2 real generated payment
                                 application workbooks (via ExcelGenerationService).
  DemoAppointmentSeeder.php      6 appointments (completed/confirmed/cancelled/
                                 rescheduled).

  # Phase 3 — the projects that complete the lifecycle
  DemoColdfieldSeeder.php        Coldfield Retail Park, end to end (project through
                                 documents) — see class comment for why this project
                                 is one consolidated seeder rather than Phase 2's
                                 one-class-per-module-family split.
  DemoPrioryCourtSeeder.php      Priory Court Apartments, end to end — including
                                 the agreed Final Account, both retention moieties,
                                 the closed adjudication case, and archived documents.

  # Phase 4 — the remaining portfolio
  DemoNorthgateSeeder.php        Northgate Business Units, end to end — early
                                 construction, one submitted-not-certified application.
  DemoElmsworthSeeder.php        Elmsworth Care Home Extension, end to end —
                                 pre-construction, AI analysis left 'processing'.
  DemoKingsmillSeeder.php        Kingsmill Logistics Hub, end to end — deliberately
                                 minimal (project, draft contract, one meeting, two
                                 documents; no trade packages/programme/commercial).
  DemoAldermereSeeder.php        Aldermere Distribution Centre, end to end — the
                                 operationally-difficult project (see class comment).

  Data/
    DemoCompanyProfile.php       Org fields, branding, every persona's name/role/email.
    RiversideWharfStory.php      The full authored Riverside Wharf story (Phase 2).
    ColdfieldStory.php           The full authored Coldfield Retail Park story (Phase 3).
    PrioryCourtStory.php         The full authored Priory Court Apartments story (Phase 3).
    NorthgateStory.php           The full authored Northgate Business Units story (Phase 4).
    ElmsworthStory.php           The full authored Elmsworth Care Home Extension story (Phase 4).
    KingsmillStory.php           The full authored Kingsmill Logistics Hub story (Phase 4).
    AldermereStory.php           The full authored Aldermere Distribution Centre story (Phase 4).
    DemoActivityLogger.php       Helper for backdating project_activities rows to
                                 match the date of whatever real event created them.

app/Console/Commands/Demo/
  SeedDemoEnvironment.php        `demo:seed`
  ResetDemoEnvironment.php       `demo:reset`
  DemoStatus.php                 `demo:status` — read-only health check, never writes.
  DemoValidate.php               `demo:validate` — read-only consistency check
                                 (including portfolio-wide business signals), never writes.
  DemoManifest.php               `demo:manifest` — the Demo Manifest / Demo Freeze command.

app/Support/Demo/
  DemoClock.php                  The environment's anchor-date strategy.
  DemoStorage.php                Isolates generated files onto their own disk root.

config/demo.php                 Connection, storage root, anchor date, and
                                 version/feature-coverage metadata.
```

**Why Coldfield and Priory Court are each one consolidated seeder class,
unlike Riverside Wharf's nine-file split:** both projects' datasets are a
fraction of Riverside Wharf's size and commercial complexity — splitting
them the same way would have produced several near-trivial files (e.g. a
whole file just for 2 risks) for no real maintainability benefit. Riverside
Wharf's one-class-per-module-family split remains the pattern to follow for
a project of comparable size; a smaller project is better served by one
class with clearly-named private methods per module, as done here.

**Why a separate `Data/DemoCompanyProfile.php` class instead of inlining
values in each seeder:** every future phase (projects, contracts, trade
packages, ...) needs to reference the same organization and the same named
people — a project's `project_users` rows need Daniel Okafor's user record,
a payment application's approver needs to be a real persona, etc. Keeping
the authored facts in one place means later phases read from it rather than
re-declaring names, and prevents the story drifting inconsistent across
seeders.

**Why `DemoRoleSeeder` duplicates `DatabaseSeeder`'s role/permission
bootstrap rather than reusing it:** the demo connection starts with an
empty `spatie/permission` schema (roles/permissions are per-connection
data, not shared), so the demo environment needs its own bootstrap of the
same three-role model (Super Admin, Admin, Client). This is a deliberate,
documented duplication — if the real role/permission model changes, both
`database/seeders/DatabaseSeeder.php` and `database/seeders/Demo/DemoRoleSeeder.php`
need updating. There was no lower-risk alternative that didn't involve
either sharing a connection (defeats the isolation requirement) or extracting
shared logic into the platform's own seeder in a way that risks touching a
seeder that runs against real data.

## Idempotency

Every demo seeder is safe to re-run:

- `DemoOrganizationSeeder` keys on the organization's `slug`
  (`halden-grove-construction`, defined in `DemoCompanyProfile::ORGANIZATION`)
  via `updateOrCreate` — re-running updates the same row, never creates a
  second Halden Grove.
- `DemoUserSeeder` keys on each persona's `email` via `firstOrCreate` — an
  already-existing user is left alone (including their password); only a
  genuinely new persona gets created and printed.
- `DemoRoleSeeder` uses `firstOrCreate` for every role/permission.
- `DemoProjectSeeder` keys on `(organization_id, code)` — Riverside Wharf's
  `code` is `RW-BLC`.
- `DemoContractSeeder`/`DemoTradePackageSeeder`/`DemoCommercialSeeder`/etc.
  each key on a stable natural identifier scoped to the project or contract
  (contract `reference_number`, trade package `package_code`, payment
  application `application_number`, variation `variation_number`, risk
  `title`, RFI/site instruction/meeting `..._number`, snag/site diary
  `title`/`diary_date`) via `updateOrCreate`.
- `DemoDocumentSeeder` and `DemoColdfieldSeeder`'s real generated workbooks
  are the one exception to "always re-run cleanly": each checks whether a
  `Document` already exists for that `PaymentApplication` (via the
  `documentable` morph) and skips regenerating it if so — re-running
  `demo:seed` does not create a growing pile of duplicate `.xlsx` files on
  disk. See the `demo:reset` gotcha above for the one situation (immediately
  after `migrate:fresh`, in the same process) where this guard needs a
  fresh DB connection to see its own prior writes.
- `DemoPrioryCourtSeeder`'s adjudication case keys on `case_number`; its two
  `RetentionRelease` rows key on `(project_id, moiety)` — a project only
  ever has a half_1 and a half_2 moiety, so this is a safe natural key.
- `DemoKingsmillSeeder` is the smallest seeder in the environment — project
  by `(organization_id, code)`, contract by `reference_number`, meeting by
  `meeting_number`, documents by `reference_number` — the same pattern as
  everything else, just fewer of them.

None of this depends on truncating tables first — `demo:seed` alone (no
`migrate:fresh`) is the normal, safe way to pick up seeder changes.
`demo:reset` is for when the schema itself has changed or the data needs a
genuinely clean slate.

## Passwords

No password is ever hard-coded. Each newly-created persona gets a random
generated password (`Str::random(20)`), printed once to the console at
creation time only, with `must_change_password` forced `true`. Re-running
the seeder against an already-created user never reprints or resets it.
This mirrors the existing convention for the platform Super Admin/Admin
accounts in `database/seeders/DatabaseSeeder.php`.

## The primary demo account

`DemoCompanyProfile::primaryDemoUserEmail()` returns
`daniel.okafor@haldengroveconstruction.com` — a Senior Quantity Surveyor.
This is the account that should be used by default for screenshots, docs,
and sales demos going forward, since a QS-level user sees both the
commercial module (payment applications, variations, final accounts,
retention) and the project/document/programme views, without the
platform-administration framing an Admin account would carry.

## Assumptions / known limitations

**Phase 1:**

- **No real branding assets exist yet.** `logo_path`, `favicon_path`,
  `cover_image_path`, and the letterhead/header/footer template paths are
  all `null` in `DemoCompanyProfile::BRANDING`. A follow-up task should add
  real placeholder assets before any screenshot work begins — a PDF/Excel
  export or a logo-bearing page will currently render with no logo.
- **`organizations.country` defaults to `GB`** for Halden Grove specifically
  (head office is Birmingham), which is a location detail, not a product
  claim — see the approved blueprint's "global platform" direction: nothing
  in the company's *name*, copy, or branding should describe it as
  UK-exclusive.
- **This documentation is developer-only.** Do not link it from
  `docs/` (the public User Guide) or reference it in customer-facing
  Release Notes.

**Phase 2:**

- **The Contract AI Analysis is not produced by a live Anthropic API
  call.** `DemoContractSeeder` inserts a `confirmed_data_json` row shaped
  like genuine `ContractAnalysisService` output directly — seeding must not
  depend on a configured API key or incur real API cost, and CLAUDE.md
  disallows new AI integrations. The AI Analysis review screen renders
  identically either way; only the network call is skipped.
- **The uploaded-document rows (contract PDF, drawings, specifications)
  have no real binary behind them.** `file_path` is a plausible-looking
  placeholder path, not an actual file — see the approved blueprint's
  Sample File Strategy, which deferred sourcing genuine specimen files to a
  separate task. The two payment application workbooks are the exception:
  those are real, generated by actually calling
  `ExcelGenerationService::generatePaymentApplicationWorkbook()`.
- **No Final Account, retention release, or Notification rows exist for
  Riverside Wharf.** Mid-project (month 9 of 18), none of these are due
  yet in the story — this is a deliberate story-accuracy choice, not a gap.
  Notifications specifically should emerge from `NotificationEngineService`
  reading the seeded deadlines/statuses rather than being hand-seeded; that
  hasn't been wired up yet (tracked as `notifications: false` in
  `config/demo.php`).
- **`AppointmentTypeSeeder` (the platform's own, non-demo-specific seeder)
  is called directly from `DemoEnvironmentSeeder`.** It's org-agnostic and
  idempotent, so this doesn't risk touching real data — but it means
  `appointment_types` on the demo connection will always mirror whatever
  that seeder currently defines, not a demo-specific list.

**Phase 3:**

- **Coldfield Retail Park deliberately has zero appointments** — the
  approved blueprint's module list for this project didn't call for any,
  and adding some purely to avoid a `✖` in `demo:status`'s per-project
  table would be exactly the "data to increase counts" the blueprint warns
  against. A `✖` next to Appointments for Coldfield is correct, not a bug.
- **Coldfield's Final Account is a genuinely bare `draft`** — no snapshot
  columns (`original_contract_sum` etc.) are set, since those only get
  populated once an account is agreed (see `FinalAccount::isSnapshotted()`).
  `demo:validate`'s commercial-chain check would flag a locked-status
  account with empty snapshot columns as an error, but a `draft` one is
  correctly exempt.
- **Priory Court's adjudication case is historical, not live** — its
  `notice_of_dispute_date` (2025-06-20) through `enforcement_deadline`
  (2025-08-29) all fall well before the project's Practical Completion
  (2025-12-22), so the case reads as "this got resolved during
  construction," not as an open dispute contradicting "this project
  finished cleanly."
- **Priory Court's two `RetentionRelease` rows are authored to sum exactly
  to the Final Account's `retention_released` figure** (£90,279 total) —
  `demo:validate` checks this arithmetic agreement on every locked Final
  Account, so if either number is ever edited independently, running
  `demo:validate` will catch the drift.

**Phase 4:**

- **Aldermere's problems are all logged, never silent.** The delay event,
  EOT request, disputed variation, and both past-due RFIs all exist as
  real rows a user would actually see in their respective modules — none
  of this is a "hidden" state only visible via `demo:validate`. The most
  recent meeting minutes (`RiversideWharfStory`-style authored prose, see
  `AldermereStory::RECOVERY_MEETING_MINUTES`) explicitly discuss recovery
  measures and the overdue payment, which is what keeps the project
  reading as managed rather than abandoned.
- **Elmsworth and Northgate deliberately have gaps `demo:status` will show
  as `✖`.** Elmsworth has no Risks/Commercial (nothing built, nothing to
  risk yet); Northgate's first payment application is `submitted`, not
  certified. These are accurate reflections of each project's stage, not
  incomplete seeding.
- **Kingsmill is the logical floor of "empty but professional"** — a
  project, an unsigned draft contract, one meeting, two documents, and a
  single-person team. `demo:validate`'s portfolio-consistency check
  actively enforces that this stays true: it errors if any project with an
  unsigned (`draft`, no `execution_date`) contract ever gains trade
  packages or payment applications, so an accidental future edit that
  gives Kingsmill commercial activity while its contract stays unsigned
  would be caught, not just left inconsistent.
- **A genuine, unplanned finding from `demo:validate`'s new "Business
  signals" section (Phase 4)**: Riverside Wharf's own RFI 4 and EOT
  request — both deliberately left "in progress" in Phase 2 as a snapshot
  of a live workflow — started showing up as "overdue" alongside
  Aldermere's genuinely-intended ones, purely because real time had passed
  since the environment's fixed anchor date. **Resolved in Demo
  Stabilisation** — see **Anchor date strategy** above: Business signals
  now compare against `DemoClock::anchorDate()`, not real wall-clock time,
  so this can't recur regardless of how much real time passes before the
  environment is next viewed.
- **Storage was not connection-isolated the way the database is (Phase 4
  finding).** `ExcelGenerationService` writes generated files to
  `Storage::disk('local')` at a path keyed only on numeric project ID —
  the demo connection's project IDs (1-7) could collide with unrelated
  real-database project IDs on the same filesystem. **Resolved in Demo
  Stabilisation** — see **Storage isolation** above: generated files now
  land under `config('demo.storage_root')`, structurally separate from
  real customer files, not merely protected by timestamp-collision luck.

**Demo Stabilisation (v1.0.0):**

- **The anchor date only fixes the tooling's evaluation of dates, not the
  dates themselves.** `DemoClock::daysSinceAnchor()` will keep growing as
  real time passes — `demo:status`/`demo:validate` warn past 30 days, but
  nothing currently re-authors Story class dates relative to a rolled-forward
  anchor. If this environment is still in active use significantly more
  than a month after 2026-07-22, treat the warning as a prompt to plan a
  deliberate story refresh, not as something the tooling will silently
  paper over.
- **The Demo Manifest tracks drift; it doesn't prevent it.** `demo:seed`/
  `demo:reset` will happily run after a freeze — there's no lock. The
  manifest is a diagnostic and audit trail: run `php artisan demo:manifest`
  before trusting that previously-captured screenshots still match the
  live environment, and re-freeze (`--write`) deliberately when a change is
  accepted as the new baseline.
- **Branding assets and sample files remain unresolved** (`config/demo.php`
  marks `branding_assets` and `sample_files` as `false` in
  `feature_coverage`) — real logo/favicon/letterhead images and genuine
  specimen documents (drawings, O&M manuals, photographs) still don't
  exist. This is the one concrete blocker before screenshot capture that
  Demo Stabilisation does not resolve, because it requires sourcing actual
  image/document assets, not code. See the Marketing Asset Production
  Guide's "Remaining blockers" section for what's needed before capture
  can begin.

## Extension points for future phases

Each future phase should add its own seeder class under
`database/seeders/Demo/` and append it to `DemoEnvironmentSeeder::run()`'s
`$this->call([...])` list, in dependency order. Earlier phases' seeders
should not need to change — only be extended from.

**The full seven-project portfolio from the approved blueprint is complete,
and the environment is stabilised at v1.0.0** — there is no further project
or architectural work queued. Remaining known gaps:

- Org-wide notifications (`NotificationEngineService` reading seeded
  deadlines/statuses rather than being hand-seeded).
- `AdjudicationDocument`/`AdjudicationDeadline` rows for Priory Court's
  adjudication case (only the case and its 8 steps are seeded).
- Real branding assets and sample files (see "Demo Stabilisation" above) —
  not a code task, needs actual image/document assets sourced.
- A rolling-anchor mechanism, if the environment is still in active use
  long enough that `DemoClock::daysSinceAnchor()`'s warning threshold is
  regularly being hit.

When a phase is implemented, update `config/demo.php`'s `version` block
(`version`, `story_timeline`, `feature_coverage`, `last_updated`) and this
page's **Status** section — this is what lets anyone check which version of
the demo a given screenshot or doc page was captured against, and whether
it has since gone stale as new platform features shipped without a
matching demo update.
