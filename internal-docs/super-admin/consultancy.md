# Consultancy — Phase C1 (Foundation)

Consultancy is a new commercial vertical giving customers access to a real
construction professional (not AI), built entirely on top of the existing
Appointments scheduling engine — see
[Appointments & Scheduling](appointments.md) for the engine this reuses
unmodified, and
[internal-docs/commercial/suresign-consultancy-specification-v1.md](../commercial/suresign-consultancy-specification-v1.md)
for the full phased specification (C1–C6) this implementation follows.

**This document covers Phase C1 (Foundation) only.** No payment, Google
Meet, document upload, or consultant-specific tooling exists yet — see the
specification for what each later phase (C2–C6) adds.

## Architecture: a catalogue wrapping the Appointments engine

A Consultancy Service is a commercial/presentation wrapper around exactly
one `AppointmentType` — never a second scheduling concept:

```
consultancy_services                    appointment_types (existing, unmodified)
-------------------------------   1:1    ------------------------------------------
code, display_name, description  ────►   duration_minutes, buffers,
enabled, publicly_bookable,              min_notice_hours, max_advance_days,
available_to_existing_customers,         assignment_mode, default_assigned_user_id,
price_minor_units, currency,             requires_confirmation, meeting_method,
display_order, is_introductory,          cancellation/reschedule_notice_hours
max_bookings_per_day (reserved)
```

`App\Services\Consultancy\ConsultancyCatalogueService` is the only place
both are written together (`create()`/`update()`, each in one DB
transaction) — the two can never drift out of sync. Scheduling fields
(duration, buffers, notice/advance windows, assignment mode, default
assignee, meeting method, confirmation requirement, cancellation/reschedule
notice) live exclusively on `AppointmentType`; everything commercial/
presentational lives exclusively on `ConsultancyService`. `code` is
immutable after creation — `ConsultancyCatalogueService::update()` never
accepts it.

This is what lets a future service (Contract Review, Commercial Review,
Project Health Check, Training Session, NEC Workshop, Bespoke Consultancy)
be added later purely as a new `consultancy_services` + `appointment_types`
row pair — zero change to `AppointmentSchedulingService`,
`AppointmentAvailabilityService`, or `AppointmentWorkflowService`.

## Seeded default services

`ConsultancyServiceSeeder` (run via `ConsultancyCatalogueService::create()`,
never a direct `AppointmentType::create()` — keeps the seeder on the same
code path as the admin editor) seeds three services, all `enabled`,
`publicly_bookable`, and `available_to_existing_customers`:

| Code | Display Name | Duration | Price | `is_introductory` |
|---|---|---|---|---|
| `quick-consultation` | Quick Consultation | 15 min | £1 | `true` |
| `standard-consultation` | Standard Consultation | 30 min | £40 | `false` |
| `extended-consultation` | Extended Consultation | 60 min | £75 | `false` |

These are default configuration, not hardcoded business rules — editable
like any `consultancy_services` row via the admin catalogue endpoints.

### Quick Consultation

Flagged `is_introductory = true` and positioned (via its `description`/
`public_description` copy, not code) as a low-friction introduction to
Consultancy — platform familiarisation and determining whether a longer
consultation is appropriate, not a substitute for a paid consultation. Its
`max_bookings_per_day` column exists but is **not enforced by any code path
in C1** (reserved for a future phase, per the specification's Quick
Consultation Rules section). A future one-per-customer/email/organisation
policy is likewise not implemented yet.

## Schema

```
consultancy_services
  code (unique, immutable), appointment_type_id (FK, unique — 1:1),
  display_name, description, public_description,
  enabled, publicly_bookable, available_to_existing_customers,
  price_minor_units (nullable, minor units, display-only in C1/C2),
  currency (default GBP), display_order, is_introductory,
  max_bookings_per_day (nullable, reserved/unenforced), soft-deletes

consultation_enquiries
  appointment_id (FK, unique — 1:1), consultancy_service_id (FK),
  title, description, project_stage (free text), contract_form (free text),
  preferred_outcome, submitted_by ('public' | 'authenticated')
```

No changes to `appointments`/`appointment_types`. `appointments.project_id`/
`organization_id`/`linked_user_id` (all pre-existing columns) are populated
for authenticated bookings and left null for public ones — unused for
Consultancy beyond that until Phase C2.

## Services

* `App\Services\Consultancy\ConsultancyCatalogueService` — create/update a
  `consultancy_services` row and its linked `AppointmentType` atomically.
* `App\Services\Consultancy\ConsultationEnquiryService` — the single
  enquiry-creation code path, called by both the authenticated and public
  booking controllers. Delegates conflict-checking and appointment
  persistence entirely to the existing `AppointmentSchedulingService`/
  `AppointmentReferenceService` — adds nothing to the scheduling engine
  itself, only the `consultation_enquiries` row alongside it, in the same
  DB transaction `AppointmentSchedulingService::withConflictCheck()` already
  opens.
* `App\Services\Consultancy\ConsultancyConsultantResolver` — (Consultancy
  Live Booking Upgrade, Stage 1) the single authoritative source of "who is
  the configured Consultancy consultant." Fails safe to `null` (never an
  arbitrary fallback) whenever nothing is configured or the configured user
  no longer passes `AppointmentAvailabilityService::isEligibleStaff()`. See
  the Stage 1 section below.
* `App\Services\Consultancy\ConsultancyBookingReadinessService` — (Stage 1)
  a pure, read-only classifier (consultant configured, availability
  configured, an active bookable service exists) — never itself enforces
  anything, and deliberately excludes Stripe/Google (those arrive in later,
  separately-approved stages).

## Controllers & permissions

| Surface | Controller | Access |
|---|---|---|
| Catalogue management | `ConsultancyServiceController` (`/consultancy-services`, apiResource) | **Super Admin or Admin** — deliberately follows the Pricing Management precedent (both platform-wide roles), not the stricter Appointment-Type-only rule |
| Authenticated customer booking | `ConsultationController` (`/consultations`, `/consultations/bookable-services`, `/consultations/services/{code}[/slots]`) | Any authenticated user (Client/Admin/Super Admin). Consultation read/write (`/consultations*`) is scoped strictly to the caller's own `organization_id`; the catalogue/scheduling-info endpoints (`bookable-services`, `services/{code}`, `services/{code}/slots`) are deliberately NOT organisation-scoped — see Scheduling mode below |
| Public booking | `PublicConsultationController` (`/public/consultancy-services/*`) | No auth — only `enabled` + `publicly_bookable` services are ever exposed |

**`ConsultationController` is a genuinely new authorization boundary.**
Client-role users have zero access to `AppointmentController` or any other
Appointments data — this is deliberate, not relaxed. Every method on
`ConsultationController` scopes to `Appointment::whereHas('consultationEnquiry')
->where('organization_id', $request->user()->organization_id)`; there is no
path by which a Client sees another organisation's consultation.

### A route-collision note (relevant if extending this further)

`GET /api/consultancy-services` is already used by the admin catalogue's
`apiResource` index (`ConsultancyServiceController@index`, Super Admin/Admin
only). Laravel's route collection keys static routes by method+URI, so a
second route registered later at the exact same method+URI silently
**replaces** the first one in the lookup table rather than layering
alongside it — confirmed empirically while building this phase (`route:list`
showed only one of the two). The authenticated customer-facing "list
bookable services" endpoint therefore lives at a **distinct** path,
`GET /api/consultations/bookable-services`, not `/api/consultancy-services`.
Keep this in mind before adding any other customer-facing route that might
collide with an admin-catalogue path.

## Scheduling mode: manual vs. fixed

**Superseded by the Consultancy Live Booking Upgrade, Stage 1 — see that
section below for the current mechanism.** `ConsultationController::serviceDetail()`/`serviceSlots()`
and `PublicConsultationController::show()`/`slots()` still derive
`scheduling_mode` (`'manual'` vs `'fixed'`) from exactly one place, but that
place is no longer the linked `AppointmentType`'s own `assignment_mode`/
`default_assigned_user_id` — both controllers' `fixedStaffFor()` now call
`App\Services\Consultancy\ConsultancyConsultantResolver::resolve()`
exclusively. `assignment_mode`/`default_assigned_user_id` are no longer
accepted at all by `StoreConsultancyServiceRequest`/`UpdateConsultancyServiceRequest`/`ConsultancyCatalogueService`
— the consultant is a platform-wide operational setting, never a
per-service field (this is what makes it possible to change the
consultant later without touching every Consultancy service). Every
seeded default service (Quick/Standard/Extended Consultation) is `manual`
mode until a consultant is configured via **Admin → Consultancy →
Settings**, at which point every Consultancy service becomes `fixed`
simultaneously.

## Phase C2 — Consultant Operations (in progress)

See
[suresign-consultancy-phase-c2-specification-v1.md](../commercial/suresign-consultancy-phase-c2-specification-v1.md)
for the full design. Implemented so far:

**Batch 1 (Engagement Lifecycle Foundation)**: `consultation_enquiries`
gained the `engagement_status` operational lifecycle (`awaiting_consultant`
⇄ `awaiting_customer` → `completed`/`cancelled`) plus `internal_notes` and
the customer-summary publishing fields — all Consultancy-owned, zero new
columns on `appointments`. `App\Services\Consultancy\EngagementLifecycleService`
is the sole authority for valid transitions; `App\Observers\ConsultancyAppointmentObserver`
(registered on the `Appointment` model, zero changes to any Appointments
engine class) is the sole sync point from Appointment cancellation to
`engagement_status = 'cancelled'`, covering all three real cancellation
entry points uniformly. No API, controller, or UI exists for any of this
yet — see the specification's §16 batch plan.

**Batch 2 (Customer Presenter Wiring)**: `App\Support\Consultancy\ConsultationPresenter::customerFacing()`
is now the single shaping point for every authenticated customer-facing
Consultancy response — `ConsultationController::index()`/`show()`/`store()`/
`cancel()` all return its output; none returns a raw Eloquent model any
longer. Mirrors `App\Support\AI\AiAnalysisPresenter`'s discipline exactly
(explicit static methods, hand-whitelisted arrays, no
app/Http/Resources layer). `store()`/`cancel()` now eager-load the same
relations `show()` already did — a deliberate, additive normalisation
(both endpoints previously returned a thinner/raw shape), not a new
feature. The customer summary publishing fields
(`customer_summary_published`/`customer_summary_published_at`) are
**always present, `null` until published** — a deterministic contract
matching `BillingOverviewService::presentSubscription()`'s existing
`pending_checkout`-style convention, chosen over a conditionally-omitted
key. No operator presenter method exists yet (Batch 3); no notes/summary
writer exists yet (Batch 4) — the published-summary fields are therefore
always `null` in production today.

**Batch 3 (Consultant Queue & Read-Only Operator Workspace)**: read-only
only — no write action, no dashboard/summary-count endpoint. Added:

* `ConsultationPresenter::operator()` — the consultant/platform-operator
  shape, deliberately separate from (and, in several relations, wider
  than) `customerFacing()`'s own whitelist: full attendee contact detail,
  the assigned consultant's id (not just name), the service code, every
  Consultancy-internal field (`engagement_status`, `internal_notes`,
  `customer_summary_draft`/`needs_republish`), and a computed
  `permissions` block. Super Admin, the assigned Admin, and every
  unassigned Admin all receive the **identical** field content — only
  `permissions` differs — confirming the visibility model below. Accepts
  an `$includeActivity` flag (`true` for `show()`, `false` for `index()`)
  so the queue never issues a per-row `ActivityLog` query — a genuine N+1
  found and fixed during this batch, verified by a query-count regression
  test.
* `App\Http\Controllers\Api\ConsultancyOperationsController` —
  `index()` (queue: search across reference/attendee name/attendee email/
  organisation name/service name via `whereHas`, not per-row subqueries;
  filters on `engagement_status`/appointment `status`/`assigned_user_id`/
  `consultancy_service_id`; sort against an explicit column whitelist,
  never a raw user-supplied column passed to `orderBy()`) and `show()`
  (operator detail). Both read-only in this batch.
* **Confirmed, Consultancy-specific Admin visibility rule**: Super Admin
  and **every** Admin — not just the one assigned — may view any
  consultation platform-wide via this controller. This is intentionally
  broader than `AppointmentController`'s own generic rule (which restricts
  a generic Admin to appointments assigned to them or unassigned) and
  exists **only** inside `ConsultancyOperationsController`'s own
  `authorizeOperatorAccess()` — zero lines of `AppointmentController` were
  touched to build this. Write access (`authorizeOperatorManage()`) stays
  narrower: Super Admin, or the specific assigned Admin — an unassigned
  Admin's `permissions` block is `false` across the board even though they
  can read the same record. No refactoring of the existing generic
  Appointments authorization flow was required or performed; the two rules
  coexist as two entirely separate authorization methods on two separate
  controllers.
* `GET /admin/consultancy/consultations` (queue) and
  `GET /admin/consultancy/consultations/{appointment}` (detail), both
  `role:Super Admin|Admin`. No dashboard route — deferred until the
  operational workflow itself exists (Batch 4+).
* Frontend: `/admin/consultancy/queue` (list — search, engagement-status
  tabs, service filter, sort, pagination, styled identically to
  `/admin/appointments`) and `/admin/consultancy/queue/{id}` (read-only
  workspace — Overview, Customer, Organisation, Service, Appointment,
  Enquiry, Internal Notes, Customer Summary, Activity). No edit
  affordance anywhere — a visible banner explains why when the viewer
  isn't the assigned consultant. Sidebar "Consultancy" entry now points
  here (was `/admin/consultancy/services`); the services catalogue page
  is linked from within the queue page instead.

**Batch 4 (Operational Write Actions)**: the first batch where consultants
can actually manage engagements. Seven explicit-intent endpoints, one per
business action — deliberately not a generic "set status"/"update" method
(see §10 of the phase specification for the full rationale):

```
PUT   .../notes                          UpdateConsultationNotesRequest
PUT   .../summary                        UpdateConsultationSummaryDraftRequest  (draft save)
POST  .../summary/publish                PublishConsultationSummaryRequest      (also serves republish)
POST  .../status/awaiting-customer        MarkAwaitingCustomerRequest
POST  .../status/awaiting-consultant      MarkAwaitingConsultantRequest
POST  .../status/complete                MarkConsultationCompletedRequest
POST  .../reopen                          ReopenConsultationRequest              (Super Admin only)
```

Each controller method calls `EngagementLifecycleService` (Batch 1) —
no transition logic is duplicated in the controller. Write access
(`authorizeOperatorManage()`, already written in Batch 3) gates every one
of these: Super Admin, or the specific assigned Admin — an unassigned
Admin gets 403 on all seven, even though they can read the same record
(Batch 3's confirmed visibility rule).

**Edit-lock**: once `engagement_status = 'completed'`, `internal_notes`
and the customer summary (draft or published) become immutable for
anyone other than a Super Admin — mirrors `AiTelemetryIntegrityGuard`'s
"protected once terminal" pattern. Reopen first (Super Admin only) to
make further changes.

**Publishing workflow**: `publishSummary()` copies the current
`customer_summary_draft` into `customer_summary_published` verbatim,
records `published_at`/`published_by`, clears `needs_republish`, and —
if the engagement isn't already `completed` — calls
`EngagementLifecycleService::markCompleted(viaSummaryPublish: true)` in
the same request. A draft edit after a publish sets
`customer_summary_needs_republish = true` without touching the published
value; the customer's own endpoint (Batch 2) always shows only
`customer_summary_published`, never the draft, confirmed by test.

**Notifications** — both customer-facing, both email-only (a public
booking has no in-app `User` account to notify), via a new
`App\Services\Consultancy\ConsultationNotificationService` +
`App\Jobs\SendConsultationEmailJob` (mirrors `AppointmentEmailService`/
`SendAppointmentEmailJob`'s exact shape — no new notification
infrastructure):

* **`markAwaitingCustomer()`** → "Action needed on your consultation" —
  not a messaging feature, no reply mechanism. Sent exactly once per
  genuine transition; a repeated request, a failed transition, or an
  unauthorised request never sends a second/any email (`EngagementLifecycleService`'s
  own transition-rejection guarantee, confirmed by a dedicated regression
  test rather than assumed).
* **`publishSummary()`** → "Your consultation summary is ready" — the
  notification that a summary is now there for the customer to read.
  **Correction (found during Batch 6B's audit):** this section originally
  claimed the customer's detail page "already shows the published summary
  live" as of Batch 2 — that was inaccurate. The presenter contract
  (Batch 2) always returned `customer_summary_published`/`_at`, but no
  frontend code ever rendered them; a customer had no way to see a
  published summary at all until Batch 6B added the missing "Consultation
  Summary" section to `/app/consultations/{id}`. See this document's
  Batch 6B section below for the fix.

**Activity logging**: every write action logs a distinct
`ActivityLog` action (`consultation.internal_notes_updated`,
`consultation.summary_draft_updated`, `consultation.summary_published` /
`.summary_republished`, `consultation.engagement_status_changed`,
`consultation.engagement_reopened`). None ever stores the raw note/draft/
summary text — only `previous_length`/`new_length`/`new_content_hash`
(SHA-256), per Batch 1's own established privacy discipline.

* `GET/PUT/POST /api/admin/consultancy/consultations/{appointment}/...` —
  see the API block below for the full route list.
* Frontend: the queue detail view gains real edit affordances — internal
  notes editor, customer summary draft editor + Publish/Republish button +
  "unpublished changes" indicator, status-transition buttons (contextual
  to the current `engagement_status`), a Super-Admin-only Reopen button —
  all conditionally rendered from the `permissions` block already shipped
  in Batch 3. The backend enforces the same boundary independently of
  whatever the UI renders.

**Batch 5 (Project Linkage)**: an operator-managed, one-way link from a
consultation (`appointments.project_id`, the existing column — no new
migration) to a `Project`. Customer-initiated linkage remains deliberately
deferred — no endpoint or UI lets a Client propose or request a link.
Linkage connects context between two independent modules; it never merges
them or transfers ownership either way — `Project` stays the sole
authority for project management, `Consultancy` stays the sole authority
for the consultation/engagement itself, and each side only surfaces a
read-only pointer to the other.

* **Platform invariant (not merely a Batch 5 implementation decision)**: a
  consultation may only be linked to a Project belonging to the *same
  organisation as the consultation itself* — enforced in `linkProject()`
  regardless of caller role, including Super Admin. This is a
  data-integrity rule, not a permission the highest role can waive, and it
  governs any future Consultancy/Project touchpoint, not only today's
  `linkProject()`. Found via a real disclosure-path trace (the new
  `GET /admin/consultancy/projects/{project}/consultations` endpoint would
  otherwise let a cross-organisation consultation's summary surface
  through the Project's own existing customer-facing page), not assumed —
  see the specification's §6 for the full trace and rationale.
* **Confirmed execution order inside `linkProject()`** (deliberate, not
  incidental): (1) `authorizeOperatorManage()` — an unauthorised caller
  (unassigned Admin, Client) learns *nothing* about whether the requested
  project exists, is soft-deleted, or belongs to another organisation; they
  simply get 403 regardless of the `project_id` supplied. (2) resolve the
  project (`withTrashed()`) and reject if missing/soft-deleted. (3) enforce
  the same-organisation rule. (4) idempotency check — re-linking the
  *currently linked* project is a 200 no-op: no write, no `ActivityLog`
  entry, `updated_at` unchanged. (5) only on a genuine change: persist +
  log. `LinkConsultationProjectRequest` deliberately carries **no**
  `exists:projects,id` validation rule — a FormRequest rule runs before the
  controller body, which would leak project-existence information to an
  unauthorised caller ahead of step (1); the controller performs its own
  existence/soft-delete check instead, after authorization.
* `ConsultancyOperationsController::linkProject()` (`PUT`, handles both
  first link and change-to-a-different-project — the same operation with
  the same validation, so a second "change" endpoint would only duplicate
  it) / `unlinkProject()` (`DELETE`, idempotent no-op if already unlinked)
  / `projectConsultations()` (`GET`, the Project-side read view — gated by
  `authorizeOperatorAccess()`, i.e. platform-wide read like Batch 3's
  `index()`/`show()`, **not** `authorizeOperatorManage()`, since this is a
  read surface, not a consultation-specific write action).
* `ConsultationPresenter::projectRef()`/`clientRef()`/
  `organizationWithIdRef()` extend `operator()`'s existing `project` field
  (previously always `null`) with `id`/`name`/`code`/`status`/`client`/
  `organization` — deliberately narrower than a raw `Project` model (no
  `contract_value`, `address`, or any other commercially/physically
  sensitive field). A new plain scalar `organization_id` was also added
  directly to `operator()`'s top level (distinct from the existing
  name-only `organizationRef()`) purely so the frontend project picker can
  scope its search client-side; the actual enforcement stays server-side.
* `ConsultationPresenter::projectSummary()` — the deliberately lightweight
  Project-side view (`id`, `reference`, `consultancy_service`,
  `engagement_status`, `appointment_status`, `assigned_consultant`,
  `created_at`, `starts_at`, `permissions.can_view`). Categorically
  excludes `internal_notes`, summary draft/published content, attendee
  contact details, and the activity log — none of that belongs inside a
  Project's own page.
* `ProjectController::index()` gained a narrowly-scoped `search` query
  parameter (project name, project code, or client name — nothing
  broader), added to the existing endpoint rather than a second,
  Consultancy-specific search architecture. Composes with the endpoint's
  existing `organization_id`/`status` filters and role-based scoping
  unchanged (Super Admin/Admin may pass `organization_id`; a Client is
  always scoped to their own organisation regardless of any search term).
  `Project::appointments()` is a new, deliberately generic inverse relation
  (`hasMany(Appointment::class)`, no Consultancy-flavoured naming) — the
  Project model stays unaware Consultancy exists as a concept; Consultancy
  filters this down to consultation appointments via
  `whereHas('consultationEnquiry')` at the call site.
* Frontend: the operator workspace (`/admin/consultancy/queue/{id}`) gained
  a "Linked Project" card — link/change/unlink with a debounced
  name/code/client-name search scoped to the consultation's own
  organisation (matching `/admin/consultancy/queue`'s existing search
  debounce pattern), read-only for callers without `can_link_project`. The
  Project overview page (`/app/projects/{id}/overview` — there is no
  separate admin project detail page; Super Admin/Admin already reach any
  project through this same customer-facing page) gained a read-only
  "Consultancy" card, visible only to Super Admin/Admin
  (`useProjectPermissions().isPlatformOperator`) since the backing endpoint
  is itself role-gated; a Client sees nothing here.
* `PUT`/`DELETE /api/admin/consultancy/consultations/{appointment}/project`,
  `GET /api/admin/consultancy/projects/{project}/consultations` — see the
  API block below.

**Batch 6A (Operator Dashboard)**: the last new functional capability in
Phase C2 — everything after this is production hardening (Batch 6B), with
functionality frozen entering it. An operational landing page answering
"what needs attention?", deliberately never a BI/reporting surface: no
charting library, no trend analytics, no new domain data.

* `ConsultancyOperationsController::dashboardSummary()` → `GET
  /admin/consultancy/dashboard`, gated by the same `authorizeOperatorAccess()`
  platform-wide-read rule as the queue — a caller can never see a
  dashboard count for a record they couldn't open in the queue. No new
  service class (confirmed appropriate at this scope; revisit only if this
  area grows substantially).
* **Response**: `totals` (all/awaiting_consultant/awaiting_customer/
  completed/cancelled/unassigned), `attention` (four ageing buckets for
  `awaiting_customer`), `recent` (created/completed in the last 7 days).
* **Ageing is audit-derived, not schema-derived**: the `attention` buckets
  come exclusively from the existing `consultation.engagement_status_changed`
  `ActivityLog` trail — the most recent entry per consultation whose
  recorded `to` is `awaiting_customer` — never from `updated_at` (also
  touched by unrelated edits like notes/summary-draft saves, which would
  silently understate age) and never an invented timestamp. A
  consultation currently `awaiting_customer` with no matching event
  (older/migrated data) lands in `awaiting_customer_unknown_age` rather
  than being guessed at. No new column was introduced — this was a
  deliberate constraint for this batch, confirmed explicitly rather than
  assumed. `recent.completed_last_7_days` reuses the same trail
  (`DISTINCT`-ed per consultation, so a completed → reopened →
  completed-again engagement within the window counts once);
  `recent.created_last_7_days` uses `Appointment::created_at` directly (a
  genuinely reliable timestamp needing no derivation).
* Two private helpers on the controller —
  `awaitingCustomerAgeingByAppointmentId()` (the single source of truth
  for the bucket-per-appointment map) and
  `overdueAwaitingCustomerAppointmentIds()` — shared between the dashboard
  and two new queue quick-link filters added to the existing `index()`
  (not a second query surface, matching Batch 5's own precedent):
  `unassigned=1` and `overdue_awaiting_customer=1`. Sharing the same
  helper means the dashboard and the queue can never disagree on what
  "overdue" means.
* Frontend: `/admin/consultancy/dashboard` — stat cards, an "Awaiting
  Customer — Ageing" panel, and "Last 7 Days"/"All Time" cards, styled to
  match `admin/ai-usage`'s existing stat-card language (verified before
  building, not assumed — no chart library exists there either). The
  sidebar's "Consultancy" entry now points here (was
  `/admin/consultancy/queue`); the queue and Services catalogue stay one
  click away. `AdminSidebar`'s active-highlight logic gained an optional
  `activePrefix` field (defaults to `href` when absent) so the Consultancy
  nav item still highlights while on the queue/detail/services pages, not
  only the dashboard itself. The queue page
  (`frontend/src/app/admin/consultancy/queue/page.tsx`) gained
  `useSearchParams()`-based initial filter state — previously it never
  read its own URL on mount, so the dashboard's `?engagement_status=`/
  `?unassigned=1`/`?overdue_awaiting_customer=1` deep links would have
  navigated there without actually filtering anything; a clearable pill
  now shows when either of the two deep-link-only filters is active.
* `GET /api/admin/consultancy/dashboard` — see the API block below.

## API

```
# Admin catalogue (role:Super Admin|Admin)
GET/POST        /api/consultancy-services
GET/PUT/DELETE  /api/consultancy-services/{consultancy_service}

# Admin/operator surface (role:Super Admin|Admin) — Phase C2, Batches 3-4
GET   /api/admin/consultancy/consultations                # queue — search/filter/sort/paginate
                                                            # (Batch 6A: also unassigned=1 / overdue_awaiting_customer=1)
GET   /api/admin/consultancy/consultations/{appointment}   # operator detail (includes activity)
GET   /api/admin/consultancy/dashboard                     # Batch 6A — operational summary, read-only/aggregate-only

# Write actions (Batch 4) — Super Admin or the assigned Admin only
PUT   /api/admin/consultancy/consultations/{appointment}/notes
PUT   /api/admin/consultancy/consultations/{appointment}/summary
POST  /api/admin/consultancy/consultations/{appointment}/summary/publish
POST  /api/admin/consultancy/consultations/{appointment}/status/awaiting-customer
POST  /api/admin/consultancy/consultations/{appointment}/status/awaiting-consultant
POST  /api/admin/consultancy/consultations/{appointment}/status/complete
POST  /api/admin/consultancy/consultations/{appointment}/reopen           # Super Admin only

# Project linkage (Batch 5) — Super Admin or the assigned Admin only for
# link/unlink; platform-wide read (matching index()/show()) for the
# Project-side view
PUT    /api/admin/consultancy/consultations/{appointment}/project
DELETE /api/admin/consultancy/consultations/{appointment}/project
GET    /api/admin/consultancy/projects/{project}/consultations

# Authenticated customer-facing (any authenticated role)
GET   /api/consultations/bookable-services              # catalogue read — not org-scoped, see below
GET   /api/consultations/services/{code}                 # scheduling_mode + duration/price — not org-scoped
GET   /api/consultations/services/{code}/slots           # fixed-mode slot generation — not org-scoped
GET   /api/consultations                                 # org-scoped
POST  /api/consultations                                 # org-scoped
GET   /api/consultations/{appointment}                    # org-scoped
POST  /api/consultations/{appointment}/cancel             # org-scoped

# Public (no auth; enabled+publicly_bookable services only)
GET   /api/public/consultancy-services
GET   /api/public/consultancy-services/{code}
GET   /api/public/consultancy-services/{code}/slots
POST  /api/public/consultancy-services/{code}/book
```

Public booking reuses the exact same security posture as
`PublicAppointmentController` (generic 404 for any non-bookable/nonexistent
code, no assigned-staff identity or internal fields ever returned, honeypot
field, rate-limited via the existing `public-booking`/`public-booking-read`
limiters — no new limiter was needed).

## Deferred to later phases (historical — accurate only as of Phase C2, Batch 6A)

**This list is stale and must not be read as current status** — it was
written at the point in this document's chronological history right
after Batch 6A, before several of the items below were actually built.
Left in place as a historical record (this document's convention is a
dated log, not a living status page); see each item's current, correct
state below instead of trusting the bullet itself:

* ~~Any payment (Phase C3)~~ — **built**. See "Consultancy Live Booking
  Upgrade — Stage 3 (Stripe Checkout)" further down this document.
* Consultant reassignment (Phase C2) — still genuinely not built, this
  part of the original bullet remains accurate. Customer-*initiated*
  project linkage also remains deferred (operator-managed only) — see
  §6 above.
* ~~Google Calendar/Meet (Phase C4)~~ — **built**. See "Stage 4B.1 —
  Google Calendar Event Synchronisation" and "Stage 4B.2 — Google Meet
  Conference Generation" further down, plus `google-integration.md`.
* Document uploads (Phase C5) — still genuinely not built; this part of
  the original bullet remains accurate.
* Marketing page, public funnel polish, reporting, production rollout
  (Phase C6) — the Communications Upgrade Batches 1–3 and the platform-wide
  Communications Platform Batch 4 (see those sections below) cover the
  customer-communication slice of this; broader marketing-site/reporting
  work beyond communications was not tracked further in this document.
* ~~Production polish and final regression (Phase C2, Batch 6B)~~ —
  **built**. See "Batch 6B — Production Polish & Readiness" further down
  this document.
* Future roadmap items (documented, not scheduled): a dedicated Consultants
  catalogue, customer feedback (rating/comments), and structured
  consultation outcomes — still accurate; see the specification's Roadmap
  section.

## Frontend

* `frontend/src/app/admin/consultancy/services/page.tsx` — catalogue
  management (Super Admin or Admin), mirroring
  `admin/appointments/types/page.tsx`'s table+modal pattern. Sidebar entry:
  **Consultancy** under Platform, next to Appointments — since Batch 6A
  this points at `/admin/consultancy/dashboard` (previously
  `/admin/consultancy/queue`); the queue and this catalogue page remain one
  click away from the dashboard.
* (Batch 6A) `frontend/src/app/admin/consultancy/dashboard/page.tsx` — the
  operator landing page: stat cards (totals + quick links into the queue),
  an "Awaiting Customer — Ageing" attention panel, and "Last 7 Days"/"All
  Time" cards. Chart-free, matching `admin/ai-usage`'s existing stat-card
  visual language.
* `frontend/src/app/app/consultations/` — the authenticated customer
  surface: `page.tsx` (list), `new/page.tsx` (booking form: service picker,
  timezone via the shared `TimezoneSelect`, attendee details, and the
  structured enquiry fields), `[id]/page.tsx` (detail + cancel). Sidebar
  entry: **Consultancy** under Tools, next to AI Assistant.
  `new/page.tsx`'s scheduling UI is driven entirely by the selected
  service's `scheduling_mode` (fetched from `GET /consultations/services/{code}`)
  — a manual-mode service (every seeded default today) shows a plain
  date+time proposal; a fixed-mode service shows a real date input plus a
  time-slot button grid backed by `GET /consultations/services/{code}/slots`.
  No Consultancy-specific scheduling logic lives in the frontend — it only
  ever renders whatever `scheduling_mode` the backend reports.
* `marketing/src/app/consultancy/page.tsx` (+ `ConsultancyExperience.tsx`)
  — the public marketing page: what Consultancy is, example topics, an
  explicit "what this is not" section naming the Adjudication boundary
  (see the specification's Marketing Positioning section), and a live
  service list fetched from `GET /api/public/consultancy-services`.
* `marketing/src/app/consultancy/book/[code]/page.tsx` (+
  `components/consultancy/ConsultancyBookingForm.tsx` and its supporting
  `ConsultancyBookingProgress`/`ConsultancySummaryCard`/`EnquiryStep`/
  `ConsultationReviewStep` components) — the public booking flow. Built as
  a deliberate, small fork of `PublicBookingForm.tsx` and its supporting
  components (reusing `BookingCalendar`/`TimeSlotGrid`/
  `PersonalDetailsStep`/`BookingSuccess` directly, unmodified) rather than
  editing the Appointments demo-booking flow to grow a Consultancy-specific
  fifth stage — keeps the two flows independently changeable. New "Consultancy"
  entry added to the top marketing nav (`MarketingNavClient.tsx`).
* (Batch 5) `frontend/src/app/admin/consultancy/queue/[id]/page.tsx` —
  `ProjectLinker` component: link/change/unlink, with a debounced
  (350ms, matching the queue list's own pattern) project search scoped
  client-side to the consultation's own `organization_id`; server-side
  same-organisation enforcement is the real boundary regardless.
* (Batch 5) `frontend/src/app/app/projects/[id]/overview/page.tsx` —
  `ConsultancyWidget`: a read-only list (up to 5) of consultations linked
  to this project, each linking through to
  `/admin/consultancy/queue/{id}`. Rendered only when
  `useProjectPermissions().isPlatformOperator` is true — the backing
  endpoint is itself gated to Super Admin/Admin, so a Client would only
  ever see a 403 here; the widget simply isn't fetched/rendered for them.
* (Batch 6B) The first Consultancy Guided Tour — `page-consultations`
  (`frontend/src/lib/tours/registry.ts`, group "Getting Started" alongside
  the equally non-project-scoped Dashboard tour), wired via
  `data-tour="consultations-header"`/`"consultations-book-button"`/
  `"consultations-list"` on `/app/consultations/page.tsx` and a
  `PageTourButton`. No operator/admin tour was built — see this document's
  Batch 6B section above for why.

**Not built in this pass**: a month-view "which dates are bookable" endpoint,
for either the public or authenticated flow (the Appointments equivalent is
`PublicAppointmentController::availability()`, backed by
`AppointmentSchedulingService::bookableDatesInMonth()`) — both booking UIs
therefore let a visitor/customer pick any in-window date and can show an
empty slot list if that date turns out unavailable for a fixed-mode
service (safe, just less polished than Appointments' pre-filtered
calendar). This is a documented UI optimisation gap, not a reason to build
a second availability engine — closing it means adding one more thin
endpoint per surface delegating to the exact same
`bookableDatesInMonth()`, exactly like every other Consultancy scheduling
endpoint already does.

## Batch 6B — Production Polish & Readiness

The last unit of Phase C2 — functionality frozen entering it (per the
approved Batch 6A completion), production hardening only. Audited in four
groups: backend, then frontend across three sub-passes (Public & Marketing,
Authenticated Customer, Operator Experience), then this documentation pass.
See `project-context.md`'s Batch 6B entries for the full per-group findings;
summarised here:

**Backend (Group 1)**: Consultancy emails' subject lines and sign-off
brought in line with `AppointmentEmailService`'s established convention
(reference in the subject, a `support_email`-aware contact line instead of
a bare "Thanks, SureSign"); a garbled/factually-wrong seeder copy fix
("free-friction" → "low-friction", since Quick Consultation is priced at
£1.00, not free); an additive index on `consultation_enquiries.engagement_status`
(filtered/grouped by the queue, dashboard, and ageing helpers with no index
since Batch 1).

**Public & Marketing (Group 2A)**: a real accessibility defect fixed — the
booking flow's only `<h1>` existed solely inside the "Choose Time" stage,
leaving every later stage with a bare `<h2>` and no heading above it; made
persistent across all stages instead.

**Authenticated Customer (Group 2B)**: the most significant finding of the
whole audit — **the customer consultation detail page never rendered a
published summary at all**, despite the presenter contract existing since
Batch 2 and the publish workflow existing since Batch 4; see the
correction note in Batch 4's own section above. Also fixed: the customer
consultation list ignored pagination entirely (a real data-access gap for
any organisation with more than 25 consultations); the booking form's
service-list fetch had no error/retry state.

**Operator Experience (Group 2C)**: a systemic gap found and fixed in five
places — the dashboard's "Last 7 Days"/"All Time" cards, the dashboard as a
whole, the queue, the operator workspace's own consultation fetch, and the
Project Linker's search, none of which distinguished "fetch failed" from
"genuinely empty," several of which had no loading-skeleton gating either.
Two empirical checks were run rather than assumed: native `confirm()` for
destructive actions matches `/admin/appointments/[id]/page.tsx` exactly
(not a Consultancy inconsistency); the queue's filters not persisting to
the URL matches `/admin/appointments/page.tsx`'s identical limitation
(platform-wide, deferred — see below, not fixed here). Consultancy
Services administration was compared directly against Appointment Types
administration and found already consistent (neither exposes a delete
button in its UI at all, both rely on an Enabled/Disabled toggle).

**Documentation (Group 3)**: repository-wide sweep for stale/false
Consultancy statements. Corrected: the false Batch 4 claim (see above);
`suresign-consultancy-phase-c2-specification-v1.md` §6's claim that the
operator Project picker "defaults to active/on_hold" (the shipped
`ProjectLinker` applies no status filter at all — corrected to match the
real component); the same-organisation Project-linkage rule reframed
explicitly as a platform invariant (not merely a Batch 5 decision) in both
that spec and here; a Help Centre gap (no mention of the signed cancel/
reschedule link for a no-account public booking). Added the first
Consultancy Guided Tour, `page-consultations` (customer-facing,
`/app/consultations`) — no Consultancy tour existed before this batch.
Deliberately did **not** build an operator/admin tour: no tour in this
codebase targets any `/admin/` route for *any* module (confirmed via a
registry search), so doing so for Consultancy alone would mean building a
first-of-its-kind admin-tour capability, not adding a page tour within
existing infrastructure — recorded as a deferred recommendation instead.

**Known platform-wide limitations** (not Consultancy defects — apply
identically to the Appointments module this was built alongside; recorded
here for internal readiness, not surfaced in customer-facing guidance):

* Queue filter state (search/sort/engagement/service) does not persist to
  the URL — matches `/admin/appointments/page.tsx` exactly. Consultancy's
  queue is actually slightly ahead: it reads the two Batch 6A deep-link
  params on mount, which Appointments' queue doesn't do for anything.
* Neither booking flow (Consultancy's fork or the original Appointments
  one) moves focus programmatically between wizard stages.
* Both booking flows' compact stage-name labels (`ConsultancyBookingProgress`/
  `BookingProgress`) are hidden below the `sm` breakpoint in a way that
  removes them from the accessibility tree, not just visually — identical
  in both, a pre-existing platform pattern.
* The generic signed cancel/reschedule page (`/appointments/{token}`) uses
  "appointment" terminology even for a Consultancy consultation, since it
  has no per-type branding — confirmed this still functions correctly for
  Consultancy bookings (same `public_token`/email pipeline), just with
  generic wording.
* Accessibility, responsive behaviour, and animation consistency across
  every Consultancy surface were reviewed exclusively through code
  inspection this batch (no browser automation available in this
  environment) — real keyboard/focus/contrast/screen-reader behaviour
  still requires manual browser validation before this is called
  fully verified.

**Release notes**: intentionally not touched. Per the standing versioning
policy, release notes are written only when a version is deliberately
published — Consultancy completing Batch 6B does not itself authorise a
version bump. Consultancy must be included in the release notes whenever
the next SureSign version is formally prepared for publication.

## Testing

`tests/Feature/ConsultancyPhase1Test.php` covers: catalogue/AppointmentType
sync on create and update, seeded default services, admin catalogue
authorization, the new Client-role authorization boundary (bookable-service
listing, booking, cross-organisation access denial, booking a
not-available-to-existing-customers service), and public booking (visibility,
generic 404, successful booking, honeypot). All pre-existing Appointments
Phase 1–4/2.1/production-fixes tests (159 tests) continue to pass unchanged
— no existing Appointments behaviour was modified.

No JavaScript test framework exists in `frontend/`/`marketing/` (same
standing limitation `appointments.md` documents for its own frontend) — the
new pages/components were verified via `tsc --noEmit` (clean in both apps),
`eslint` (clean in both apps — a handful of pre-existing, repo-wide
`no-explicit-any` warnings on `err: any` catch handlers match the exact
convention already used in `AppointmentTypesPage`/`team/page.tsx` and
elsewhere, not a new issue), and a full `next build` in both `frontend/`
and `marketing/` (all new routes — `/admin/consultancy/services`,
`/app/consultations`, `/app/consultations/new`, `/app/consultations/[id]`,
`/consultancy`, `/consultancy/book/[code]` — compile and prerender
successfully alongside every existing route).

**Batch 5**: `tests/Feature/ConsultancyPhaseC2Batch5Test.php` (25 tests) —
authorization (Super Admin/assigned Admin can link; unassigned Admin/Client
403; an unassigned Admin's 403 is returned even for a nonexistent
`project_id`, confirming authorization runs before any project lookup),
same-organisation validation (success within org; 422 across orgs, even
for Super Admin; 422 for a soft-deleted project), link/change/unlink
relationship behaviour (idempotent re-link creates no `ActivityLog` row and
leaves `updated_at` unchanged; idempotent unlink-when-already-unlinked is a
safe no-op), presenter whitelists (`operator()`'s `project` field;
`projectSummary()`'s full field set — both asserted via
`assertEqualsCanonicalizing()` against the exact expected key list, not
just spot-checked), the `ProjectController::index()` search extension
(name/code/client-name matches, no-match, pagination, composing with
`status`/`organization_id`, an Admin searching a permitted organisation, a
Client unable to discover projects outside their own organisation via
`search`, a soft-deleted Client's project excluded from a client-name
match), and regression coverage for Batch 4's write actions and the
pre-existing `GET /api/projects/{id}` endpoint. One real bug was found and
fixed by these tests before completion:
`LinkConsultationProjectRequest` originally validated `project_id` with an
`exists:projects,id` rule — Laravel runs `FormRequest` validation *before*
the controller body, so an unassigned Admin sending an invalid/nonexistent
`project_id` was getting a 422 instead of the required 403, leaking a
signal ahead of authorization. Fixed by removing the rule entirely (the
controller already performs its own existence/soft-delete check, in the
correct position — after authorization). Full regression: 1983 backend
tests, 1924 passing — the only 2 failures are the same pre-existing,
unrelated environment issues noted elsewhere in this repo (a local storage
directory permission issue for `AdjudicationDocumentTenantIsolationTest`,
and an Excel-generation issue for `PaymentApplicationExcelDisclosureTest`),
neither touched by this batch. Frontend verified the same way as prior
batches (`tsc --noEmit`, `eslint`, `next build` — all clean/passing, no new
warnings beyond the pre-existing `err: any` convention).

**Batch 6A**: `tests/Feature/ConsultancyPhaseC2Batch6ADashboardTest.php`
(12 tests) — access denial for Client/unauthenticated, totals correctness
across engagement statuses, unassigned counting, platform-wide visibility
across organisations (matching the queue's own confirmed rule), ageing
bucket correctness for all four buckets (including a case that explicitly
touches the record to prove `updated_at` is never consulted), the legacy
"no matching ActivityLog event" case, a case proving a stale transition
*to a different status* is never mistaken for the awaiting-customer one,
`recent.created_last_7_days`/`recent.completed_last_7_days` correctness
and de-duplication, the two new queue quick-link filters
(`unassigned`/`overdue_awaiting_customer`) agreeing with the dashboard's
own counts, and an exact response-shape assertion via
`assertEqualsCanonicalizing()`. Focused regression (Consultancy +
Appointments): 287 tests passing. Full backend suite: 1993 tests, 1934
passing — the same 2 pre-existing, unrelated failures as every prior
batch. Frontend: `tsc --noEmit`, `eslint`, `next build` all clean (the new
`AdminSidebar` line has one additional `any` on the same existing
`(item as any)` expression already used for `.exact` — matching, not
introducing, that file's established pattern).

## Stage 1 clarification record

Four points raised during Stage 2 planning, answered here rather than as a
separate addendum — none required reopening Stage 1's design.

### 1.1 Consultancy consultant setting placement

`consultancy_consultant_user_id` correctly belongs on `suresign_settings`.
This is the same singleton settings row every other platform-wide,
non-per-organisation toggle already uses — `SuresignSetting::instance()`
(`firstOrCreate`-backed, one row for the whole platform) already holds
comparable operational settings of the same shape (`ai_credit_operating_mode`,
`appointment_reminders_enabled`, `appointment_cancellation_cutoff_hours`,
etc.) — not organisation-scoped data, not a value that varies per customer.
The configured consultant is exactly this kind of value: a platform
operational decision ("who currently does Consultancy work"), not a
property of any one Consultancy service, customer, or organisation. It
uses the identical persistence (`$fillable` + a plain column),
loading (`SuresignSetting::instance()`), and update (`->update([...])` via
an Admin/Super Admin-gated controller, logged to `ActivityLog`) conventions
as every other setting on that table — no divergent pattern was
introduced.

**Future Consultancy settings can safely continue to live there** as long
as each one is genuinely a single platform-wide value (e.g. a future
`consultancy_booking_mode` readiness/activation flag would fit the same
shape). The threshold for a dedicated `consultancy_settings` table (or a
richer domain object) would be reached only if Consultancy settings
becomes either (a) **multi-row** — e.g. a future multi-consultant rota,
which is explicitly out of scope today and only a documented future
extension point on `ConsultancyConsultantResolver` — or (b) large enough
in field count that `suresign_settings` itself becomes unwieldy as a
single flat row (it already holds ~50 columns across many features; one
more nullable FK does not move that needle). Neither condition is met by
Stage 1 or Stage 2. **No change made** — moving the field now would be
neatness-driven, not convention-driven, and the prompt's own instruction
was correctly to only move it if the current placement violates an
established convention, which it does not.

### 1.2 Resolver caching and invalidation

`ConsultancyConsultantResolver::resolve()` performs a fresh, uncached
database read on every single call: `SuresignSetting::instance()` runs
`firstOrCreate` (a real query) with no `Cache::` wrapper anywhere in
`SuresignSetting` or the resolver itself — confirmed by inspection, not
assumption. There is **no request-level memoization, no cross-request
cache, and no invalidation mechanism to reason about**, because none
exists. A settings update via `PUT /admin/consultancy/settings/consultant`
is visible to the very next `resolve()` call, from any process, immediately
— including a queued job, since a job resolves the consultant the same way
any controller does (a plain `app(ConsultancyConsultantResolver::class)->resolve()`
call inside its own fresh request/job lifecycle, never a value captured at
job-dispatch time). This satisfies every stated expectation (new queries
use the current consultant; existing reservations/appointments keep their
own persisted `assigned_user_id`/`consultant_user_id` snapshot regardless
of later changes; no stale cache can survive a setting change) without any
new caching code — **no cache was added, per the explicit instruction not
to introduce one solely for this feature.** If resolver call volume ever
becomes a real, measured performance concern, the correct fix is
request-scoped memoization (mirroring `PlanEntitlementRepository`'s
existing per-plan-id memoization pattern) — not attempted here since no
such concern has been observed or measured.

### 1.3 Public booking payload

Confirmed by direct inspection of `PublicConsultationController::show()`/
`slots()`/`availability()` and `ConsultationPresenter::customerFacing()`:
the public/customer-facing payload never includes the consultant's user
ID, email, or role; the availability context string; any
`appointment_availabilities`/`appointment_availability_overrides`/
`appointment_blocked_periods` row ID; a blocked-period reason; another
appointment's data; or any internal readiness-failure detail. The public
slot/availability responses expose exactly: `scheduling_mode`
(`'fixed'`/`'manual'`), `timezone`, and a `slots`/`dates` array of plain
`{date, time}` pairs or date strings — nothing else. `show()`/`serviceDetail()`
expose the service's own commercial/scheduling fields (code, display name,
duration, price, currency, notice/advance windows) — never anything about
who fulfils it. This was already true before Stage 2 (unchanged from
Stage 1) and is preserved by every Stage 2 addition below — the new
reservation endpoints (§ below) follow the identical discipline.

### 1.4 Readiness evolution

Stage 1's `ConsultancyBookingReadinessService` checks **configuration
readiness** only (eligible consultant configured, active bookable service
exists, at least one active weekly Consultancy window exists) — it does
not attempt to prove a real bookable slot exists on any specific date.
This is the correct scope for Stage 1 and remains correct for Stage 2:
proving genuine **operational slot readiness** (a real bookable slot
exists within a bounded near-term window, surviving overrides, blocked
periods, existing appointments/reservations, and notice/advance rules)
would require running the exact same bounded scan
`AppointmentSchedulingService::bookableDatesInMonth()` already performs
for the public calendar — which is precisely why Stage 2 does not add a
second, separate "is anything actually bookable" readiness check: it would
either duplicate that bounded scan's logic or become an unbounded
scan if implemented carelessly. Stage 2 does not add an operational
slot-readiness check; if a future stage needs one, it should call the
existing bounded `bookableDatesInMonth()` over a short, explicitly
documented window (e.g. the next 14 days) — never an unbounded scan — and
should be added only when a concrete need for it is identified.

## Consultancy Live Booking Upgrade — Stage 1 (Foundation)

Approved following
`internal-docs/commercial/consultancy-live-booking-phase-0-architecture-review.md`.
Stage 1 builds the availability-context foundation, dynamic consultant
resolution, and a dedicated Consultancy Availability admin surface — it
explicitly excludes Stripe Checkout, payment records/webhooks, temporary
payment holds, refunds, Google OAuth/Calendar/Meet, calendar sync statuses,
and any new payment queue. Those remain scoped to later, separately
approved stages.

### Availability context

`appointment_availabilities`/`appointment_availability_overrides` gained a
`context` column (`App\Support\Appointments\AvailabilityContext::APPOINTMENTS`
default / `::CONSULTANCY`) — every existing row was backfilled to
`APPOINTMENTS` in the same migration
(`2026_08_16_000001_add_context_to_appointment_availability_tables.php`),
so Book a Demo and every other existing Appointment Type's availability is
byte-for-byte unchanged. `appointment_blocked_periods` deliberately has NO
context column — a blocked period represents real consultant unavailability
(leave, sickness, an internal commitment) and must block every context for
that consultant; splitting it would risk an operator forgetting to also
block the "other" context.

`AppointmentAvailabilityService`'s weekly-schedule/override methods
(`getWeeklySchedule()`/`setWeeklySchedule()`/`getOverrides()`/`createOverride()`/
`resolveWindowsForDate()`/`assertBookable()`) all take an explicit,
required `$context` — there is no implicit default, so a missed call site
would fail loudly (`ArgumentCountError`) rather than silently reading the
wrong schedule. `setWeeklySchedule()`'s replace-the-whole-schedule delete is
now scoped `user_id + context`, so replacing Consultancy's schedule can
never wipe Book a Demo's. Sibling-overlap validation
(`assertNoOverlaps()`/`assertValidOverrideRecord()`) is scoped per-context
too — the same consultant can have an identical time window on the same
date in both contexts without a false "conflicting override" rejection.
`AppointmentSchedulingService::generateAvailableSlots()`/`bookableDatesInMonth()`/
`withConflictCheck()` thread `$context` through to those methods only —
same-staff conflict detection (`isSlotFree()`/`hasBufferedConflict()`) is
**unchanged and takes no context at all**, confirming the Phase 0 finding
in code: it was, and remains, scoped purely by `assigned_user_id` and
blocking status, never by appointment type. A confirmed Book a Demo
appointment already blocks an overlapping Consultancy slot for the same
consultant, and vice versa — proven by
`ConsultancyLiveBookingStage1Test::test_confirmed_book_a_demo_appointment_blocks_an_overlapping_consultancy_slot()`/
`test_confirmed_consultancy_appointment_blocks_an_overlapping_book_a_demo_slot()`.

Every existing call site was updated to pass its context explicitly:
`AppointmentAvailabilityController` (Book a Demo/general Appointments admin
UI) always passes `APPOINTMENTS`. `AppointmentController` (the generic
internal create/assign/reschedule/check-availability controller, which can
operate on ANY `AppointmentType` including a Consultancy-linked one) gained
a `resolveContext(AppointmentType $type)` helper that checks
`$type->consultancyService` — never assumes `APPOINTMENTS`.
`PublicAppointmentActionController` (the generic signed cancel/reschedule
link controller, serving both ordinary and Consultancy appointments via the
same `public_token`) gained an equivalent `contextFor(Appointment $appointment)`
helper checking `$appointment->consultationEnquiry`.

### Consultant configuration — dynamic resolution, never per-service

`assignment_mode`/`default_assigned_user_id` are no longer accepted at all
on a Consultancy service (removed from `StoreConsultancyServiceRequest`/
`UpdateConsultancyServiceRequest`/`ConsultancyCatalogueService`) — this
closes the "two competing sources of truth" risk identified during Stage 1
planning. The consultant is a single, platform-wide setting:
`suresign_settings.consultancy_consultant_user_id`, read exclusively via
`ConsultancyConsultantResolver::resolve()`, which returns `null` (never an
arbitrary fallback Admin/Super Admin) whenever nothing is configured or the
configured user is inactive, banned, deleted, or no longer holds the
Admin/Super Admin role. `PublicConsultationController`/`ConsultationController`'s
`fixedStaffFor()` call this resolver exclusively now — Book a Demo's own
`fixedStaffFor()` on `PublicAppointmentController` is completely untouched
and still reads `assignment_mode`/`default_assigned_user_id` as before.

Changing the configured consultant only ever affects future `resolve()`
calls — an already-created `Appointment`'s `assigned_user_id` is its own
durable snapshot, never rewritten
(`ConsultancyLiveBookingStage1Test::test_changing_configured_consultant_does_not_alter_an_existing_appointment()`).
No domain service, migration, or policy hardcodes a name/ID — a seeder
(`database/seeders/DatabaseSeeder.php`) configures Graham's account as the
local/staging operational default, exactly as the approved principle
permits, idempotently (never overwrites a value already set via the Admin
UI).

Configuration surface: `GET`/`PUT /admin/consultancy/settings/consultant`
(`ConsultancySettingsController`) — read: Super Admin or Admin; write:
Super Admin only (mirrors `UpdateAiCreditOperatingModeRequest`'s
convention for a comparably consequential platform-wide toggle).
`GET /admin/consultancy/settings/eligible-consultants` backs the picker;
`GET /admin/consultancy/settings/readiness` exposes the Stage 1 readiness
check (consultant configured, availability configured, an active bookable
service exists — **no Stripe, no Google**). Every change is recorded via
`ActivityLog::record('consultancy.consultant_changed', ...)` with
previous/new user IDs and the acting Super Admin.

### Consultancy Availability admin surface

`Admin → Consultancy → Availability` (`/admin/consultancy/availability`,
`ConsultancyAvailabilityController`) — unlike
`AppointmentAvailabilityController` (self-only for Admin, any eligible
staff member selectable for Super Admin), this always operates on
whichever single consultant `ConsultancyConsultantResolver` currently
resolves, and both Admin and Super Admin may view/manage it (matching this
module's existing platform-wide Consultancy visibility rule, not the
stricter Appointments self-scoping rule). When no consultant is configured,
every read endpoint responds `{ready: false}` (never a fabricated editable
schedule) and every write endpoint responds 422 without ever creating an
orphaned row. Blocked-period endpoints on this controller delegate to the
exact same context-free `AppointmentAvailabilityService` methods
`AppointmentAvailabilityController` uses — one shared list, not a
duplicate. The frontend page (`frontend/src/app/admin/consultancy/availability/page.tsx`)
is adapted directly from the existing Appointments Availability page's
components/structure, with copy clarifying the Consultancy/Book a Demo
separation and the shared nature of blocked periods (including a
confirmation prompt before creating one). `Admin → Consultancy → Settings`
(`/admin/consultancy/settings`) is the consultant picker + readiness
dashboard described above.

### Booking-time behaviour (unchanged in shape, Consultancy-scoped)

Both `PublicConsultationController::slots()`/`ConsultationController::serviceSlots()`
now pass `AvailabilityContext::CONSULTANCY` explicitly to
`generateAvailableSlots()`. Both controllers gained a month-level
`availability()`/`serviceAvailability()` endpoint mirroring
`PublicAppointmentController::availability()` exactly (same bounded
`bookableDatesInMonth()` call — no separate calculation, no unbounded date
generation). `ConsultationEnquiryService::book()` — the single shared
booking-creation path for both public and authenticated flows — hardcodes
`AvailabilityContext::CONSULTANCY` in its own `withConflictCheck()` call
(it is Consultancy's only booking path, so this is the correct place for an
explicit, non-parameterised context). No payment-dependent status, no
placeholder payment record, no temporary hold was introduced — a
Consultancy appointment still reaches `confirmed`/`pending_confirmation`
exactly as it did before Stage 1, per the approved scope exclusion. Server
still revalidates the slot at booking time via the existing
`withConflictCheck()` transaction; browser-supplied duration/consultant/
context values are never trusted — the service's own configured duration
and the resolver's own consultant are always what get used, proven by
`test_price_and_duration_are_never_trusted_from_the_browser_on_public_booking()`.

### Testing & validation

New: `tests/Feature/ConsultancyLiveBookingStage1Test.php` (28 tests) —
context isolation (Appointments/Consultancy schedules invisible to each
other, replacing one never deletes the other, identical overlapping
overrides on the same date in different contexts don't conflict, invalid
context rejected rather than silently resolving to Consultancy, blocked
periods have no context column at all), resolver eligibility (null when
unconfigured; fails safe on inactive/banned/deleted/role-changed;
consultant change never rewrites an existing Appointment), cross-workflow
conflict proof (both directions), readiness (false when nothing
configured, true once all three conditions hold, response shape never
mentions Stripe/Google), the dedicated admin surface (not-ready state,
weekly schedule save, shared blocked-period creation, and a Consultancy
override correctly returning 404 through the *generic* Appointments
availability endpoint), and security (Admin blocked from changing the
consultant — only Super Admin can; an ineligible/banned candidate rejected;
unauthenticated request denied; price/duration/consultant tampering
attempts on the public booking endpoint silently ignored).

Every pre-existing Consultancy test file
(`ConsultancyPhase1Test`/`ConsultancyPhaseC2Batch1-6ADashboardTest`) was
updated to configure the consultant via the new
`SuresignSetting.consultancy_consultant_user_id` mechanism (and to scope
raw `AppointmentAvailability::create()` calls to
`AvailabilityContext::CONSULTANCY`) instead of the removed per-service
`assignment_mode`/`default_consultant_user_id` fields — this is a test-setup
migration to the new architecture, not a weakening of what's asserted;
every original assertion still runs and still passes. One test
(`ConsultancyPhaseC2Batch3Test::test_queue_filters_by_engagement_status_appointment_status_assignee_and_service()`)
needed a small behavioural adjustment: under the single-platform-consultant
model, a live Consultancy booking can no longer arrive "unassigned" the way
the old per-service `manual` mode could, so the test now books normally
and then directly clears `assigned_user_id` to simulate the
historical-unassigned case the queue filter must still handle — the
filter behaviour itself is unchanged and still fully tested.

Full backend regression: 2023 tests, 1964 passing — the same 2
pre-existing, unrelated failures present before Stage 1 began (a local
storage directory permission issue for
`AdjudicationDocumentTenantIsolationTest`, and an Excel-generation issue
for `PaymentApplicationExcelDisclosureTest` — neither touches Appointments,
Consultancy, or any file this stage modified). Every existing Appointments
test (159) and every pre-existing Consultancy test (128, after the setup
updates above) passes unchanged in outcome. Both new migrations verified
up and down against SQLite, and confirmed to preserve existing data (no
row deleted, no destructive schema change). Frontend: `tsc --noEmit`,
`eslint`, and a full `next build` all succeed — the two new pages
(`/admin/consultancy/availability`, `/admin/consultancy/settings`) prerender
successfully; the two pre-existing lint findings they inherit
(`react-hooks/set-state-in-effect` on the same `useEffect` pattern the
Appointments Availability page already has; `@typescript-eslint/no-explicit-any`
on the same `err: any` convention every mutation's `onError` handler in
this codebase already uses) are pre-existing patterns being matched, not
new issues introduced by Stage 1.

### Deferred to later stages

Stripe Checkout/payment records/webhooks, temporary slot reservations,
refunds, Google OAuth/Calendar/Meet, calendar sync status, and any new
payment queue — all explicitly out of scope for Stage 1, per the approved
plan. The existing manual/free-text Consultancy booking flow remains the
only flow in production use until a future stage's readiness gate and
product decision activate live paid booking.

## Consultancy Live Booking Upgrade — Stage 2 (Temporary Slot Reservation)

Approved following Stage 1 and the four Stage 1 clarification points
above. Stage 2 builds a concurrency-safe temporary hold on a Consultancy
slot — the foundation Stage 3 (Stripe Checkout) will build on. It
explicitly stops before Stripe: no Checkout Session, no payment record, no
webhook, no refund, no Google integration, and no path that converts a
reservation into a confirmed Appointment exists yet.

### Domain model

`App\Models\ConsultancySlotReservation` (table `consultancy_slot_reservations`)
— a temporary, server-authoritative hold on one Consultancy slot for one
customer booking attempt. Explicitly **not** an Appointment, payment,
Consultation Engagement, or Google Calendar event. States: `active` →
`{consumed, expired, cancelled}` only — every terminal state is final
(`ConsultancySlotReservation::TRANSITIONS`). `consumed` exists as a domain
boundary for a later stage's verified-payment conversion; nothing in
Stage 2 ever sets it, and no endpoint can reach it — there is deliberately
no "mark as paid" path anywhere in this stage.

Stored fields: opaque `public_token` (customer-facing identity, never a
bare ID), `booking_attempt_token` (retained on every row for audit) +
`active_attempt_token` (the actual idempotency enforcement column — see
below), `consultancy_service_id`, `consultant_user_id`, optional
`organization_id`/`linked_user_id`, minimal attendee `name`/`email`
(no phone/company/message — data minimisation, per the approved decision;
richer Consultancy detail is captured only at the later booking/conversion
stage), `starts_at`/`ends_at` (UTC)/`booking_timezone`, `status`,
`expires_at`, `consumed_at`, `cancelled_at`. No price/currency field —
per the approved decision, Stage 2 is not the commercial snapshot; that
lands with Stage 3's Stripe Checkout work, alongside the service's
then-current price. Consultant display name/email and availability-record
IDs are deliberately not stored — the consultant reference and the
scheduling rules (not a frozen row) are what govern validity going
forward.

### The concurrency fix — closing the empty-result race

The critical, explicitly-flagged risk was that locking only the rows an
overlap query returns provides no protection when two concurrent
transactions both see zero existing conflicts and both proceed to insert.

**Resolution**: `AppointmentSchedulingService::withConflictCheck()` — the
single shared write boundary already used by every Appointment/Consultancy
creation, assignment, and reschedule path — now acquires
`SELECT * FROM users WHERE id = ? FOR UPDATE` on the resolved staff
member's own row **first**, before any conflict query runs, inside the
same transaction. This row always exists independent of whether any
Appointment/reservation row does, which is exactly what makes it usable as
a lock regardless of current conflict state. Because every scheduling
write path already calls this one method — `AppointmentController::store/assign/reschedule`,
`PublicAppointmentController::store`, `PublicAppointmentActionController::reschedule`,
`ConsultationEnquiryService::book()`, and the new
`ConsultancySlotReservationService::reserve()`/`replace()` — **all of them
now serialise on the same per-consultant lock**, with no change required
to any of those call sites individually. This directly satisfies the
"Preferred" cross-workflow requirement: true write-time protection between
a Consultancy reservation attempt and a simultaneous Book a Demo booking
attempt for the same consultant, not merely read-time slot-list agreement.

This necessarily serialises **all** scheduling writes for one consultant
(not only writes to one exact slot) — an accepted tradeoff at current
single-consultant volume, and deliberately safer than any lock that can
disappear when no candidate rows exist yet.

**What indexes do vs. what the lock does** — kept strictly separate, per
the review requirement: the new indexes on `consultancy_slot_reservations`
(`consultant_user_id, status, expires_at` and `starts_at, ends_at`) exist
purely for conflict-query performance; they provide zero concurrency
guarantee on their own. The `active_attempt_token` unique index provides
idempotency (a discrete-value uniqueness guarantee), not concurrency
correctness for overlapping time ranges — no range-uniqueness constraint
was invented, because none can express "no two rows may have overlapping
[start, end) intervals" portably. Concurrency correctness comes
exclusively from the `users`-row `FOR UPDATE` lock described above.

### Reservation conflict integration

Folded directly into `AppointmentSchedulingService::isSlotFree()`/`hasBufferedConflict()`
themselves — not a parallel Consultancy-side filter — so both real booking
creation (`withConflictCheck()`) and read-only slot generation
(`generateAvailableSlots()`) automatically respect active, unexpired
reservations via the exact same query path every other conflict check
already uses. A reservation blocks only while `status = 'active'` **AND**
`expires_at` is in the future — checked together, never `status` alone —
so an elapsed reservation stops blocking immediately, before the scheduled
`consultancy:reservations:expire` command ever runs (proven by
`test_expired_reservation_no_longer_blocks`). Buffers are derived from the
reserved service's own `AppointmentType` (via `consultancyService.appointmentType`),
using the identical effective-interval formula every other candidate in
`hasBufferedConflict()` already uses — no separate buffer interpretation
for reservations.

### Idempotency

`booking_attempt_token` is the client-held idempotency boundary — never
`(consultant, service, start_time)` alone, since two different customers
may legitimately compete for the same slot. Enforced by a genuine
database uniqueness constraint on a derived `active_attempt_token` column
(equal to the booking attempt token while `active`, set back to `null` on
cancel/expire/consume) — a nullable-unique index, so multiple historical
rows for the same attempt (original + replacement) can coexist once the
original is no longer active. `ConsultancySlotReservationService::reserve()`
checks for an existing active row for the token first (a plain,
non-authoritative optimisation) and, on a genuine race, catches
`UniqueConstraintViolationException` and returns the winning row instead
of erroring — mirroring `WebhookIngestionService`'s existing
duplicate-reconciliation pattern. The real correctness guarantee for two
DIFFERENT tokens racing for the same slot is the `users`-row lock above,
not this constraint — this constraint only protects one token against
itself.

### Reservation replacement

Immutable lifecycle semantics, as approved: cancel the previous active
reservation, then create the replacement — both inside the SAME
`withConflictCheck()`-opened transaction/lock. The previous reservation is
cancelled only *after* the replacement slot's own conflict/availability
re-check has already run (inside that same locked callback) — so a
replacement attempt to a now-unavailable slot leaves the original
reservation untouched and still active
(`test_replacement_original_slot_is_freed_only_after_replacement_secured`).
The original row is preserved (never deleted) as an audit record.

### APIs

Public: `POST /public/consultancy-services/{code}/reservations` (create
or replace, decided server-side by whether the submitted `booking_attempt_token`
already owns a different active reservation), `GET`/`POST /public/consultancy-reservations/{token}[/cancel]`.
Authenticated: the same shape under `/consultations/services/{code}/reservations`
and `/consultations/reservations/{token}[/cancel]`, organisation-scoped.
Neither accepts consultant/duration/price/context/expiry/status from the
browser — all are server-derived; a submitted value for any of them is
silently ignored (proven by `test_price_duration_consultant_and_context_are_never_trusted_from_the_browser`).
The public response (`App\Support\Consultancy\ConsultancyReservationPresenter`)
exposes only: opaque token, status, start/end, timezone, expiry, and a
minimal service summary — never the consultant's identity, the database
ID, or the availability context.

### Admin diagnostics

`GET /admin/consultancy/reservations` (status counts + a bounded 50-row
recent list) and `POST /admin/consultancy/reservations/{id}/cancel` — both
Super Admin/Admin. No customer payment controls exist (there is no
payment to control yet). Frontend:
`frontend/src/app/admin/consultancy/reservations/page.tsx`, linked from
the Consultancy dashboard.

### Scheduler

`consultancy:reservations:expire` — `Schedule::command(...)->everyMinute()->withoutOverlapping()->runInBackground()`
in `routes/console.php`, alongside the existing `billing:webhooks:recover`
entry. Tighter cadence than that 5-minute job because the hold duration
itself is short (`config('consultancy.reservation_hold_minutes')`, default
15 minutes via `CONSULTANCY_RESERVATION_HOLD_MINUTES`). This is a
durable-state cleanup only, never a correctness requirement — see above.

### Readiness

Unchanged from Stage 1 — `ConsultancyBookingReadinessService` still
checks configuration readiness only (consultant, availability, an active
service); Stripe/Google remain absent from it, confirmed by
`test_readiness_still_excludes_stripe_and_google_in_stage_2`. No
"reservation infrastructure operational" check was added — the table
existing and the scheduler being registered are deployment facts, not
runtime-observable readiness signals worth a synthetic check.

### Testing — SQLite vs. MySQL

`tests/Feature/ConsultancyLiveBookingStage2Test.php` (29 tests) — state
transitions, creation (public + authenticated, snapshot correctness),
rejection paths (no consultant, outside availability, inactive service),
conflict integration (existing Appointment, existing reservation, cross-
workflow both directions, different consultant no-conflict, buffers),
idempotency (same-slot retry, different-slot replacement, replacement
safety), ownership/security (safe public payload, unguessable token,
cross-organisation denial, browser tampering ignored), expiry (immediate
non-blocking + scheduled command + idempotent re-run + scheduler
registration), and Admin diagnostics.

**Explicit SQLite/MySQL boundary, stated precisely rather than implied**:
every test above runs in a single PHP process against this project's
SQLite test database. `test_sequential_competing_attempts_only_one_reservation_survives`
is deliberately named and documented as a **sequential logic proof, not a
concurrency test** — it proves the query/locking code path produces the
correct outcome when exercised in order, but SQLite does not enforce
InnoDB-style row locking, and the test never opens a second real database
connection, so it cannot demonstrate genuine multi-connection contention.
**No test in this codebase claims to have verified MySQL/InnoDB row-lock
semantics under real concurrent load — that requires the manual procedure
below, run separately against a real MySQL instance.**

#### MySQL validation procedure (to be executed manually before relying on this under real concurrent load)

1. **Two simultaneous attempts for the exact same slot.** Open two
   separate MySQL client connections (or two PHP processes/`Http::pool()`
   calls hitting the API concurrently). Configure a consultant, grant
   Consultancy availability, then fire both `POST .../reservations`
   requests for the identical service/date/time with two different
   `booking_attempt_token` values as close to simultaneously as possible
   (e.g. both dispatched from a shell script with `&` and `wait`, or a
   small concurrent HTTP client script). **Expected**: exactly one `201`,
   one `409`; `SELECT COUNT(*) FROM consultancy_slot_reservations WHERE ...`
   for that consultant/time range returns exactly 1 `active` row.
2. **Overlapping but non-identical intervals.** Repeat with the second
   request's start time offset by less than the service duration (e.g.
   first at 10:00 for 30 minutes, second at 10:15). **Expected**: same
   outcome — one succeeds, one gets `409`.
3. **Duplicate idempotency retry.** Fire two requests with the SAME
   `booking_attempt_token` and the SAME slot simultaneously. **Expected**:
   both return `201` with the identical `token` in the response body;
   exactly one row exists in the database.
4. **Consultancy reservation vs. Book a Demo Appointment.** Configure the
   same user as both the Consultancy consultant and a Book a Demo
   `AppointmentType`'s `default_assigned_user_id`. Simultaneously fire a
   Consultancy reservation request and a Book a Demo booking request for
   the same consultant/overlapping time. **Expected**: exactly one
   succeeds; the losing request receives its normal conflict response
   (`409` for the reservation endpoint, or the existing Appointments
   `409` for the booking endpoint).
5. **Lock wait / retry behaviour.** With `innodb_lock_wait_timeout` at its
   default, confirm the losing transaction's `SELECT ... FOR UPDATE`
   genuinely blocks (visible via `SHOW ENGINE INNODB STATUS` or
   `performance_schema.data_lock_waits` during the test) rather than
   erroring immediately, and that it proceeds correctly once the winning
   transaction commits.
6. **Final assertion for every scenario above**: query
   `consultancy_slot_reservations` (and, for scenario 4, `appointments`
   too) directly and confirm only one blocking record exists for the
   contended interval — never rely solely on HTTP status codes, since a
   correct HTTP response with an inconsistent database state would still
   be a real bug.

### Deferred to later stages

Stripe Checkout Session creation, payment records, payment webhooks,
refunds, Google OAuth/Calendar/Meet, calendar synchronisation, customer
payment confirmation UI, and the verified-payment → confirmed-Appointment
conversion (`consumeForAppointment()`-shaped boundary, deliberately not
implemented) — all remain out of scope, per the approved Stage 2
boundary. The public/authenticated reservation endpoints are built and
tested but deliberately **not wired into the production customer booking
UI** yet — there is no next step (payment) for a customer to complete
after reserving a slot, so exposing this to real customers now would be a
dead-end journey. They will be connected once Stripe Checkout exists.

## Consultancy Live Booking Upgrade — Stage 3 (Stripe Checkout)

Approved following Stage 2. Stage 3 connects the Stage 2 reservation to
Stripe Checkout while preserving complete commercial integrity: the
reservation is the authoritative booking attempt; Stripe Checkout is the
commercial transaction; a verified payment converts the reservation into a
confirmed Appointment. No Google Calendar/Meet work belongs in this stage,
and none was done.

### Architecture confirmation (written and approved before any Stage 3 code)

**Snapshot ownership — why the payment, not the reservation.** `App\Models\ConsultancyPayment`
(table `consultancy_payments`) is the sole owner of the immutable
commercial snapshot and the payment lifecycle — `ConsultancySlotReservation`
continues to own only the temporary scheduling hold, exactly as Stage 2
established. This mirrors the existing `Subscription`/`BillingCheckoutSession`
separation in this codebase's own Billing module, and was chosen over
adding commercial fields to the reservation because Stage 2 explicitly
deferred price/currency "to wherever the Stage 3 snapshot lands" —
retrofitting them onto the reservation would have mixed a scheduling
concept with a commercial one and contradicted that decision.

**Snapshot lifecycle.** `App\Services\Consultancy\ConsultancyCheckoutService::createCheckoutSession()`
implements the approved 9-step sequence exactly: lock the reservation →
revalidate ownership/lifecycle → revalidate the service → read current
commercial values → create the immutable snapshot row → create the Stripe
Checkout Session → persist Stripe identifiers/expiry → extend the
reservation's expiry to match → commit. After the snapshot row is created,
every downstream read (Checkout, webhook processing, conversion) uses
**only** the `*_snapshot` columns — the live `ConsultancyService`/
`AppointmentType` is never read again for this booking attempt.

**Immutable rules, enforced structurally, not by convention**: a later
price/name/description/duration change on the service updates nothing on
an existing payment row (proven by `test_later_service_price_change_never_alters_an_existing_checkout`);
service deactivation does not invalidate an already-created Checkout or
prevent webhook reconciliation (proven by `test_service_deactivation_does_not_invalidate_an_already_created_checkout`)
— it only blocks a *new* Checkout from being created for a *different*
reservation (`test_disabled_service_rejects_new_checkout_creation`).
Appointment conversion reads exclusively from the payment's own snapshot
columns (`ConsultancyPaymentConversionService::convert()`) — never
`ConsultancyService::find(...)` for any commercial value, only for the
immutable `appointment_type_id` FK the `Appointment` row itself requires.

**Two separate local transactions, not one spanning the Stripe API call.**
`ConsultancyCheckoutService::prepareSnapshot()` locks the reservation,
revalidates, and commits the 'creating' snapshot row in its own short
transaction — releasing the lock before the Stripe call, since holding a
database row lock open for the duration of an external HTTP call is unsafe
practice. The provider call then happens with no lock held at all; its
outcome (success → 'checkout_open' + extend reservation; failure →
'failed', reservation untouched) is persisted in a second short
transaction. This also fixes a real bug caught during implementation: an
exception raised inside a `DB::transaction()` closure rolls back
*everything* in that closure — a same-transaction "mark this payment
failed" update would have been silently undone along with the payment row
itself. Proven by `test_checkout_provider_failure_leaves_reservation_expiry_untouched_and_marks_payment_failed`.

### Snapshot contents

`service_code_snapshot`/`service_name_snapshot`/`description_snapshot` —
what Stripe displays and what a receipt would show, frozen. `consultant_user_id_snapshot` —
who the Appointment gets assigned to at conversion, regardless of who is
configured later. `duration_minutes_snapshot`/`starts_at_snapshot`/`ends_at_snapshot`/
`booking_timezone_snapshot` — the exact interval the Appointment will
occupy. `amount_minor_units`/`currency`/`tax_treatment`/`subtotal_minor_units`/
`tax_minor_units`/`total_minor_units` — the commercial values Stripe
actually receives (integer minor units throughout — no floating-point
monetary value anywhere in this schema). `attendee_name_snapshot`/`attendee_email_snapshot` —
who the Appointment's attendee fields will be. `organization_id`/`linked_user_id` —
preserved for an authenticated booking's own scoping. `booking_attempt_token` —
retained for diagnostics only (never logged raw — see Security below).
`status`/`provider`/`livemode`/`stripe_checkout_session_id`/`checkout_url`/
`stripe_payment_intent_id`/`checkout_expires_at`/`confirming_stripe_event_id`/
`appointment_id` — the payment/provider lifecycle. No price/consultant
display name/email/availability-record ID is duplicated beyond what's
listed — every field exists for a specific, named reconciliation or
conversion need.

### Tax and VAT launch policy

`App\Support\Consultancy\ConsultancyTaxTreatment::NOT_SEPARATELY_CALCULATED` —
the only value Stage 3 ever writes. The configured price is treated as the
final customer-visible total; no tax line is calculated; `automatic_tax`
is explicitly disabled on every Stripe Checkout Session
(`StripeBillingProvider::createOneOffCheckoutSession()`). This is recorded
as "tax not separately calculated," never as a claim that the price is
legally VAT-inclusive — real VAT/tax policy, invoicing requirements,
registration status, and place-of-supply rules remain an explicit
commercial/legal decision outside this stage's scope.

### Payment methods

`payment_method_types: ['card']` only — Apple Pay/Google Pay ride on the
card payment method type automatically when the visitor's browser/device
supports it, requiring no separate configuration. No bank transfer,
buy-now-pay-later, instalment, or other delayed-notification method is
enabled — `ConsultancyWebhookEventProcessor` only ever supports
`checkout.session.completed`/`.expired`, since no event type for a
delayed/async payment method can legitimately fire for a Consultancy
Checkout Session under this configuration.

### Stripe architecture — reused, extended only where necessary

`App\Support\Billing\OneOffCheckoutRequest` (a provider-neutral value
object, never a live `ConsultancyService` model or a loose controller
array) is passed to a single new, additive interface method,
`BillingProviderInterface::createOneOffCheckoutSession()`, implemented in
both `StripeBillingProvider` (real, using Stripe's inline `price_data` and
`mode: payment` — no pre-registered Product/Price, since a Consultancy
service's price is a plain admin-editable value, not a versioned Stripe
Price) and `FakeBillingProvider` (deterministic, for the automated test
suite — see Testing below). `BillingProviderInterface::normalizeCheckoutSessionFromWebhookPayload()`
gained three additive fields — `mode`, `payment_status`, `payment_intent_id` —
needed to distinguish a one-off Checkout from a subscription Checkout and
to establish authoritative payment confirmation; existing subscription
callers are unaffected (they simply don't read the new keys).

**Reused completely unchanged**: Stripe signature verification,
`WebhookIngestionService`, the `billing_webhook_events` ledger, event
deduplication (`(provider, provider_event_id)` unique constraint), payload
hashing, the claim/retry/lease infrastructure, and the scheduled
`billing:webhooks:recover` command's overall shape. `App\Services\Billing\WebhookEventProcessor`
(the ~1,500-line subscription processor) was **not modified** — a
dedicated `App\Services\Consultancy\ConsultancyWebhookEventProcessor` was
built instead, on its own queue (`consultancy-payments`), via its own job
(`App\Jobs\ProcessConsultancyWebhookEventJob`).

### Webhook routing — a real bug found and fixed during implementation

`checkout.session.completed`/`.expired` fire for **both** subscription and
one-off Checkout — the Stripe event type alone never distinguishes them.
Routing is decided by local correlation (does a `consultancy_payments` row
already exist for this exact Stripe Checkout Session ID), never by
trusting Stripe's `mode` field or metadata alone — extracted into a single
new class, `App\Services\Billing\WebhookEventRoutingService::jobClassFor()`.

While wiring this in, inspection of `App\Console\Commands\RecoverBillingWebhookEvents`
(the scheduled stranded-event recovery sweep) found it **hardcoded**
`ProcessBillingWebhookEventJob` for every redispatch — meaning a stranded
Consultancy webhook event needing recovery would have been misrouted to
the subscription processor, which would report `checkout_session_not_found_locally`
indefinitely and never actually recover. Both `WebhookIngestionService`
(first dispatch of a new event) and `RecoverBillingWebhookEvents`
(redispatch of a stranded event) now call the SAME `WebhookEventRoutingService`,
so a stranded Consultancy event is routed identically on recovery as it
would have been on first ingestion — this was fixed as part of Stage 3
rather than deferred, since it directly falls under "webhook routing/
recovery reuse," already in this stage's approved scope, and the
correction carve-out explicitly permitted fixing a genuine contradiction
discovered mid-implementation without pausing for separate approval.

Documented routing edge cases: **no Consultancy payment matches** — falls
through to the subscription processor, which reports
`checkout_session_not_found_locally` (retryable) and is picked up by the
existing recovery sweep for investigation, exactly as an unrelated
subscription Checkout Session ID always has. **Both correlation paths
unexpectedly matching** — structurally impossible: a given Stripe Checkout
Session ID is unique per Consultancy payment and unique per subscription
Checkout Session, so it can exist in at most one of the two local tables.
**Metadata absent or malformed** — never consulted for routing at all
(only local correlation is), so this has no effect on routing; metadata is
never authoritative for anything downstream either (see Security). **Event
arrives before Checkout-creation's own transaction commits** — cannot
happen in practice, since Stripe cannot emit a webhook for a Checkout
Session before that Session (and this codebase's own persisted row) exists;
if it somehow did, it would fail to correlate and fall to the subscription
processor's same retryable-not-found path. **Delivered more than once** —
the existing `billing_webhook_events` unique constraint plus
`ConsultancyWebhookEventProcessor`'s own row-locked claim matrix (mirroring
`WebhookEventProcessor`'s identical design) make this safe regardless —
proven by `test_duplicate_webhook_delivery_never_creates_a_second_appointment`.

### The distributed transaction boundary (approved correction)

Stripe's payment is an external, already-completed fact by the time a
webhook is processed — the local transaction can only atomically handle
the *consequences* of that fact, never the payment itself.
`ConsultancyPaymentConversionService::convert()` performs, in one
transaction: lock the consultant's `users` row (the same stable boundary
every other scheduling write already uses) → lock the payment row → lock
the reservation row → verify this exact conversion hasn't already happened →
verify the reservation is in a state conversion can safely resolve →
create the Appointment exactly once → mark the reservation consumed → mark
the payment converted → commit.

**A local conversion failure after Stripe has already been paid is never
reported as a failed payment.** `App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException`
signals this distinct, expected distributed-systems recovery case — the
payment moves to `conversion_pending` (never `failed`), stays retryable via
`ConsultancyWebhookEventProcessor` (webhook redelivery), the manual Admin
retry action (`POST /admin/consultancy/payments/{id}/retry-conversion`),
or the on-demand `consultancy:payments:reconcile` command. `App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException`
signals a genuine inconsistency automatic logic must not guess through
(reservation missing entirely, consumed by a different payment, or —
critically — independently cancelled with the paid time no longer free);
the payment moves to `manual_review`, again never `failed`, and is
surfaced in the same Admin recovery view. Proven by
`test_local_conversion_failure_after_payment_never_reports_payment_as_failed`
and `test_paid_checkout_with_independently_cancelled_reservation_and_time_now_taken_requires_manual_review`.

**A paid Checkout whose reservation was independently cancelled but whose
time remains free still converts** — `handleCancelledReservation()`
re-verifies the exact time against `AppointmentSchedulingService::isSlotFree()`/
`hasBufferedConflict()` (the same authoritative check every other booking
path uses) before proceeding; if the time is no longer free, conversion is
refused and routed to manual review rather than creating an Appointment
blind. Proven by both
`test_paid_checkout_with_independently_cancelled_reservation_converts_if_time_still_free`
and the "time now taken" counterpart above.

### The expiry race (approved correction)

Because `reservation.expires_at`, `consultancy_payments.checkout_expires_at`,
and the real Stripe Checkout Session's own `expires_at` are deliberately
kept identical (extended together at Checkout-creation time — see
Snapshot lifecycle above), a webhook is never rejected merely because it
arrived after that stored timestamp, nor because the local wall clock now
reads later than it. Stripe does not allow a Checkout Session to complete
after its own `expires_at` — so a verified webhook reporting the Checkout
Session's own `status: complete` **and** `payment_status: paid` is, by
construction, proof payment completed within the aligned window,
regardless of how late the webhook itself is delivered or processed. These
two fields — never webhook arrival time, never event-created timestamps —
are what `ConsultancyWebhookEventProcessor::processCompleted()` treats as
authoritative. Proven directly by
`test_late_arriving_webhook_after_expiry_still_converts_successfully`,
which moves both the local reservation and payment expiry into the past
before processing and confirms conversion still succeeds.

### Scheduling lock coverage (verified from actual call sites, not repeated from Stage 2)

Every current write path that changes consultant or occupied time calls
`AppointmentSchedulingService::withConflictCheck()` — confirmed by direct
inspection, not assumption:

- `AppointmentController::store()`/`assign()`/`reschedule()` — generic
  Appointment creation, Book a Demo, and any admin-created Consultancy
  Appointment, plus rescheduling.
- `PublicAppointmentController::store()` — public Book a Demo/generic
  booking.
- `PublicAppointmentActionController::reschedule()` — signed-link
  rescheduling (any workflow).
- `ConsultationEnquiryService::book()` — every Consultancy manual/
  free-text booking (public and authenticated).
- `ConsultancySlotReservationService::reserve()`/`replace()` (Stage 2) —
  temporary reservation creation and replacement.

Stage 3's own conversion step (`ConsultancyPaymentConversionService::convert()`)
does **not** call `withConflictCheck()` directly — it does not need a
fresh availability/conflict *check* (the reservation already secured the
slot), only the same *lock* to serialise against any other concurrent
scheduling write for that consultant. It acquires the identical
`SELECT ... FOR UPDATE` on the consultant's `users` row directly, achieving
the same serialisation via the same stable row, without re-running
`isSlotFree()`/`hasBufferedConflict()`/`assertBookable()` a second time
for a slot that's already reserved. Checkout *creation* (`ConsultancyCheckoutService`)
does not need this lock at all — it changes neither consultant nor
occupied time, only locks the reservation row itself (see Snapshot
lifecycle above).

### Idempotency boundaries (kept separate, per the approved requirement)

| Boundary | Mechanism |
|---|---|
| Reservation creation | `booking_attempt_token` + `active_attempt_token` unique index (Stage 2, unchanged) |
| Consultancy payment/Checkout creation | One `checkout_open` payment per reservation, reused while unexpired (`ConsultancyCheckoutService::prepareSnapshot()`) |
| Stripe API request | `idempotency_key: "consultancy_checkout:{payment->id}"` passed to Stripe on every Checkout-creation call |
| Webhook ingestion | `billing_webhook_events`' existing `(provider, provider_event_id)` unique constraint (unchanged) |
| Webhook lifecycle application | `ConsultancyWebhookEventProcessor`'s row-locked claim matrix (`received`/`processing`/`processed`/`ignored`/`failed`/`conflict`) |
| Appointment conversion | `ConsultancyPaymentConversionService::convert()`'s own idempotent check (`status === 'converted'` → no-op) inside the locked transaction |

Activity Log entries record only a one-way SHA-256 hash of the booking
attempt token (`booking_attempt_token_hash`) — never the raw token, never
a public reservation token, never a Stripe secret, and never a full
webhook payload.

### Admin recovery

`GET /admin/consultancy/payments` — status counts plus a bounded 50-row
"needs attention" list (`conversion_pending`/`manual_review` only).
`POST /admin/consultancy/payments/{id}/retry-conversion` — a safe,
explicit retry, idempotent by construction. `consultancy:payments:reconcile`
(manual/on-demand, `--dry-run` capable) retries every `conversion_pending`
payment in one pass. No refund or payment-amendment action exists anywhere
in Stage 3.

> **Superseded by the Consultancy Live Booking Activation Hardening pass
> below**: this command was originally deliberately left unscheduled. It is
> now also scheduled every 5 minutes — see that section for why the
> original "mirrors `billing:stripe:reconcile`" reasoning was itself
> revisited and corrected.

### Customer journey activation

The reservation → Checkout → payment-status API surface is fully built
and tested but **deliberately not wired into the production marketing/
frontend booking UI** — consistent with the same principle Stage 2 already
established (no dead-end customer journey). The success page contract
(`GET /public/consultancy-reservations/{token}/payment` /
`.../consultations/reservations/{token}/payment`) already implements "show
a processing state until the authoritative webhook-driven status is
available" — `conversion_pending`/`manual_review` are both collapsed to a
single safe `processing` value for the customer (`ConsultancyPaymentPresenter`),
never exposing an internal recovery-state name, and the browser's arrival
at the success URL is never itself treated as proof of payment.

### Security

Every commercial/scheduling value the browser could attempt to influence —
amount, currency, consultant, duration, tax, reservation/payment status,
Checkout expiry — is server-derived from the snapshot at every step; none
of these fields exist on any request shape the checkout/reservation
endpoints accept, so a submitted value is silently ignored (proven by
`test_checkout_endpoint_ignores_any_browser_supplied_amount_or_currency`).
Stripe metadata (`purpose`/`consultancy_payment_id`/`consultancy_reservation_id`)
is safe correlation-and-reporting data only — never read as authoritative
by the webhook processor, which correlates exclusively via the locally
stored `stripe_checkout_session_id`. Public payment/reservation responses
never expose the consultant's identity, a database ID, or a Stripe
identifier (proven by `test_public_payment_status_response_never_exposes_internal_or_consultant_fields`).

### Testing — what was verified, and under what

**Automated (SQLite + `FakeBillingProvider`, this environment)**: 29 new
tests in `tests/Feature/ConsultancyLiveBookingStage3Test.php` covering the
full required list — commercial snapshot correctness and immutability,
one-Checkout-per-reservation idempotency, the corrected two-transaction
provider-failure path, reservation/Checkout expiry alignment, verified
webhook success and atomic conversion, duplicate webhook delivery,
Checkout expiration, the late-arriving-webhook expiry-race correction, the
independently-cancelled-reservation cases (both outcomes), the
distributed-transaction-failure cases (never reported as a failed
payment), event routing (Consultancy vs. subscription, including the
`RecoverBillingWebhookEvents` fix), Admin recovery visibility and retry,
the reconciliation command (including `--dry-run`), and browser-tampering
rejection. Full regression: 2081 tests, 2022 passing — the same 2
pre-existing, unrelated failures present since before Stage 1 (confirmed
unrelated: neither touches Adjudication document upload or Excel
generation, the only two failing areas). All 526 pre-existing Billing
tests pass unchanged, confirming the webhook-routing refactor introduced
no subscription regression.

**MySQL/InnoDB — NOT verified in this environment** (no MySQL instance
available here). The Stage 2 manual validation procedure is extended for
Stage 3 with:

1. **Checkout creation for the same reservation from concurrent requests** —
   fire two simultaneous `POST .../checkout` requests for one active
   reservation; expect exactly one `checkout_open` payment row (the
   reservation-row lock in `prepareSnapshot()` should serialise these).
2. **Duplicate conversion workers** — with a payment already `paid`, invoke
   `ConsultancyPaymentConversionService::convert()` from two concurrent
   processes/connections; expect exactly one Appointment created, the
   second call observing `status === 'converted'` and returning as a
   no-op once the first's transaction commits and releases the consultant-
   row lock.
3. **Consultant-row lock behaviour under real contention** — with a
   Consultancy reservation attempt and a Book a Demo booking attempt for
   the same consultant fired simultaneously, confirm via
   `performance_schema.data_lock_waits` (or `SHOW ENGINE INNODB STATUS`)
   that the second genuinely blocks on the first's `FOR UPDATE` lock
   rather than proceeding independently.
4. **Unique Appointment outcome** — for every scenario above, the final
   assertion must be a direct database query (`SELECT COUNT(*) FROM appointments WHERE ...`),
   never just HTTP status codes, exactly as Stage 2's own procedure
   requires.

**Stripe test mode — NOT performed** (no Stripe test-mode credentials or
account access available in this environment). A manual procedure for
later execution, once credentials exist:

1. Hosted Checkout creation — confirm a real `checkout.session.create` API
   call succeeds with `mode: payment`, inline `price_data`, `payment_method_types: ['card']`,
   and `automatic_tax.enabled: false`, and that the returned `url` loads a
   real Stripe-hosted Checkout page showing the correct amount/currency/
   product name.
2. Card payment — complete a real test-card payment; confirm the resulting
   `checkout.session.completed` webhook has `status: complete`/
   `payment_status: paid` and a real `payment_intent` ID.
3. Apple Pay/Google Pay availability — confirm each wallet button appears
   automatically on a supported device/browser combination without any
   additional Checkout configuration, and that a completed wallet payment
   produces an identically-shaped webhook to a card payment.
4. Successful webhook — confirm SureSign's real `/api/billing/webhooks/stripe`
   endpoint receives, verifies, and routes the event to the Consultancy
   processor (not the subscription one), and that the Appointment is
   created.
5. Duplicate webhook replay — use Stripe CLI's `stripe events resend` (or
   the Dashboard's "resend webhook" action) to redeliver the same event;
   confirm no second Appointment is created.
6. Session expiration — let a real test Checkout Session expire
   (or use `stripe trigger checkout.session.expired`-equivalent test
   tooling); confirm the payment moves to `expired` and no Appointment is
   created.
7. Customer cancellation — cancel from the hosted Checkout page; confirm
   the reservation remains available for a fresh attempt and no
   Appointment is created.
8. Delayed webhook delivery — introduce an artificial delay (e.g. a
   temporarily paused queue worker) between payment completion and webhook
   processing; confirm conversion still succeeds once processing resumes
   (this exercises the expiry-race correction against a REAL Stripe-issued
   timestamp, not a locally-fabricated one).
9. Success-page processing state — confirm the customer-facing success
   page shows "processing" immediately after redirect (before the webhook
   has necessarily been processed) and updates to a confirmed state only
   once `paymentStatus()` reports `converted`.
10. Local recovery after forced conversion failure — temporarily break
    local conversion (e.g. a feature-flag-gated fault injection), complete
    a real payment, confirm the payment lands in `conversion_pending`, then
    verify `consultancy:payments:reconcile` or the Admin retry action
    successfully converts it once the fault is removed.

Do not treat any of the above as performed until it is actually run against
a real Stripe test-mode account.

### Deferred to later stages

Refunds, Google OAuth/Calendar/Meet, the Stripe customer portal, automatic
tax/VAT calculation, delayed payment methods, invoicing expansion, and
reminder communications remain entirely unbuilt — none was started in
Stage 3.

## Consultancy Live Booking Activation Hardening

A focused production-readiness pass between Stage 4A (Google Integration
Foundation — see `internal-docs/super-admin/google-integration.md`) and
Stage 4B (Google Calendar/Meet automation). Not a new feature stage — it
resolves exactly the two genuine production-activation gaps the Stage 4A
verification found in the existing Stage 3 payment journey, plus a small,
deliberately minimal preparation step for Stage 4B. No Google/Calendar code
was touched.

### 1. Paid booking readiness enforcement (the primary gap closed)

Before this pass, `ConsultancyBookingReadinessService` existed (Stage 1)
but neither `PublicConsultancyReservationController::checkout()` nor
`ConsultationReservationController::checkout()` called it — a customer
could start (and pay for) a Stripe Checkout even if the platform had no
consultant configured, no availability, or no active service, since
`ConsultancySlotReservationService::reserve()` only requires a *resolvable
consultant* to create the temporary hold, not the full readiness picture,
and readiness itself can regress in the window between a reservation being
held and the customer actually paying (e.g. an operator removes the
consultant's availability mid-checkout).

Both checkout endpoints now call
`ConsultancyBookingReadinessService::checkoutAvailability()` — a new
method, deliberately separate from the existing `check()` (which stays the
Admin diagnostics/per-field breakdown, and is never called directly by a
customer-facing endpoint) — before creating any Stripe Checkout Session.
If the platform is not ready, no `ConsultancyPayment` row and no Stripe
Checkout Session are created; the endpoint returns `503` with a
customer-safe `{message, reason}` body.

`reason` is one of exactly two values, never which specific configuration
check failed:

- `configuration_unavailable` — `check()` ran and reported `ready: false`
  (no consultant, no availability, or no active service). Requires
  operator action.
- `temporarily_unavailable` — `check()` itself threw (e.g. a database
  error). Logged via `Log::error()` (message only, never surfaced to the
  customer) — a transient condition that may resolve on its own.

`ConsultancyBookingReadinessService` remains the single source of truth —
neither controller duplicates any readiness rule; both simply consume the
structured result.

### 2. Admin diagnostics

`GET /admin/consultancy/settings/readiness` (unchanged route, same
`role:Super Admin|Admin` gate) now also returns `checkout_blocked` (the
exact inverse of `ready`) alongside the existing per-field breakdown, so an
operator sees the same live blocking state a customer would experience
without cross-referencing two endpoints or inferring it from three
booleans.

### 3. Automatic payment recovery — now scheduled

`consultancy:payments:reconcile` (Stage 3) is now scheduled every 5 minutes
in `routes/console.php`, alongside `billing:webhooks:recover`. The
original "manual/on-demand only, mirrors `billing:stripe:reconcile`"
reasoning was corrected during this pass: `billing:stripe:reconcile` is
read-only drift *detection* across a whole dataset with no retry step, so
"never scheduled" is right for it — but `consultancy:payments:reconcile`
retries a specific, already-known recoverable state (Stripe already
confirmed payment; local Appointment conversion previously failed), which
is exactly the shape `billing:webhooks:recover` already schedules. Manual
execution, including `--dry-run`, remains fully supported and safe —
scheduling is additive.

The full recovery pipeline is now:

```
Payment succeeds (Stripe)
  -> conversion_pending (local conversion failed once)
  -> automatic queue retry (webhook redelivery / ProcessConsultancyWebhookEventJob)
  -> scheduled reconciliation (consultancy:payments:reconcile, every 5 minutes)
  -> Admin manual retry (POST .../retry-conversion) — final fallback, never primary
```

`withoutOverlapping()` alone (no `onOneServer()`) matches every other
scheduled command in this codebase — `ConsultancyPaymentConversionService::convert()`'s
own row locking (User/ConsultancyPayment/ConsultancySlotReservation, in
that order) is what actually prevents a double-conversion, not the
scheduler.

### 4. Cancelled Appointment protection (Stage 4B preparation only)

`Appointment::isEligibleForExternalSync(): bool` is a new, minimal,
status-based guard (`status !== 'cancelled' && !trashed()`) — the single
contract point any future external-calendar synchronisation (Google
Calendar/Meet, Stage 4B, or any later Appointments/Meetings-module
integration) MUST consult before creating or requesting any provider-side
resource for an Appointment. No synchronisation code exists yet — this
only establishes the guard so Stage 4B's job cannot be built without it.
Deliberately not Consultancy-specific: it lives on `Appointment` itself,
reusable for any future module.

### 5. Queue and deployment verification (no changes required)

`docker/entrypoint.sh`'s worker already consumes
`billing-webhooks,consultancy-payments,default` in that order — no new
queue was needed for this pass (the reconciliation command runs via the
scheduler, not a queue). A future Stage 4B `google-integrations` queue can
be appended to that same `--queue=` list without any other architectural
change; the worker/scheduler split (`entrypoint.sh`'s `queue`/`scheduler`
branches) already generalises to it.

### 6. Testing

`tests/Feature/ConsultancyActivationHardeningTest.php` — checkout blocked/
allowed (public and authenticated), structured `checkoutAvailability()`
responses (including the `temporarily_unavailable` exception path, via a
mocked `ConsultancyConsultantResolver`), Admin `checkout_blocked`
diagnostics, scheduler registration (cadence/overlap/background/
single-instance, mirroring `BillingWebhookRecoverySchedulerTest`'s exact
pattern), manual reconciliation continuing to work, and
`isEligibleForExternalSync()` for confirmed/cancelled/soft-deleted
Appointments. Full Stage 1–3 regression suite re-run and green.

### Deferred / unchanged

No Google/Calendar/Meet code was touched. No refund, invoicing, or
communications work was added. The customer-facing booking UI remains
deliberately unwired (see Stage 3's "Customer journey activation" above) —
this pass only hardens the API layer Stage 4B will build on top of.

## Stage 4B.1 — Google Calendar Event Synchronisation

Built on top of this Activation Hardening pass and the verified Stage 4A
Google Integration Foundation. `ConsultancyPaymentConversionService::convert()`
now queues Google Calendar synchronisation (via `AppointmentCalendarSyncService`)
after its transaction commits — Consultancy is the trigger, but the
synchronisation model itself (`AppointmentExternalSync`) and orchestration
service are deliberately Appointment-domain-owned, not Consultancy-owned,
for the same reusability reason `Appointment::isEligibleForExternalSync()`
was added here for. See
[internal-docs/super-admin/google-integration.md](google-integration.md)'s
Stage 4B.1 section for the full architecture, state machine, and test
writeup — this file is not duplicated here.

## Stage 4B.2 — Google Meet Conference Generation

Extends Stage 4B.1's SAME Calendar event with a Google Meet conference —
no second event, no second sync table, no new Consultancy-owned code at
all (everything lives in the Appointment-domain Google services, exactly
as Stage 4B.1 established). One explicit, important product decision was
confirmed before any code was written and is recorded here as the
authoritative statement of it:

**Google Calendar and Meet readiness are operational readiness signals
only. They do not gate Checkout because external-provider availability
must not interrupt payment acceptance or valid Consultancy booking
creation.** `ConsultancyBookingReadinessService::checkoutAvailability()`
is completely unchanged by this stage — it still checks only consultant/
availability/service configuration, per its own Stage 1 design, and never
Stripe or Google. A customer can complete a paid Consultancy booking even
when Google Meet is entirely unavailable; the meeting link then simply
shows a "preparing" state on the Consultation detail page until Google
sync/recovery catches up, via the existing external-sync machinery — see
[internal-docs/super-admin/google-integration.md](google-integration.md)'s
Stage 4B.2 section for the full architecture, readiness split, state
model, and test writeup.

## Consultancy Communications & Global Email Experience Upgrade — Batch 1

Approved following live validation (see google-integration.md's own Stage
4B.2 Live Validation section) that confirmed Calendar/Meet themselves work
end to end, and that surfaced the real gap this batch closes: SureSign's
pre-existing branded confirmation email (`AppointmentEmailService`) had no
knowledge whatsoever of `AppointmentExternalSync.meeting_join_url` — it
only ever read the generic, never-populated-for-Consultancy
`Appointment.meeting_url`. Combined with `sendUpdates='none'` (Google
never emails guests directly, by design — SureSign owns customer
communication), a customer previously had no reliable way to receive
their Meet link by email at all.

**Scope of this batch**: exactly two new communication types
(`booking_confirmed`, `meeting_link_ready`) for Consultancy, plus the
shared foundation both are built on. Reminders, reschedule, cancellation,
follow-up, published-summary email, and the full public no-account
signed page are explicitly deferred to Batches 2/3 — see this phase's own
approved scope document for the full four-batch breakdown.

**Architecture audit findings** (full detail lives in the phase's own
architecture-confirmation record, not duplicated here — summary only):

- **One shared HTML wrapper already existed and needed no replacement**:
  `EmailNotificationService::buildHtml()` — the branded black/gold card,
  header/footer, used by literally every email family in the codebase.
  The real gap was one level in: every caller built its body as plain
  text lines (`nl2br(e($bodyText))`), so long URLs rendered as raw escaped
  text rather than buttons, and Brevo's request never carried a genuine
  `textContent` (multipart/alternative) part.
- **Reminder infrastructure is already mature and already covers
  Consultancy** (`SendAppointmentReminders`, `AppointmentReminderSend`'s
  real DB-unique-constraint idempotency, automatic reschedule/cancellation
  awareness) — confirmed reusable as-is for Batch 2, no parallel
  Consultancy scheduler needed.
- **Public confirmed-booking access already exists and needed no new
  token system**: `Appointment.public_token` (permanent, rotates only on
  reschedule as a deliberate security measure) already serves both
  ordinary Appointments and Consultancy bookings through
  `PublicAppointmentActionController`/`AppointmentPublicLinkService` since
  Stage 1. No dedicated no-action "view" page exists yet, though (only the
  existing reschedule/cancel confirmation pages, which happen to already
  render the full booking view) — building that dedicated page remains
  Batch 3's job.

**New, additive-only components** (nothing existing was removed or
behaviourally changed for any other Appointment/email family):

| Component | Purpose |
|---|---|
| `App\Support\Email\EmailComponents` | Pure static HTML-fragment builders (button/details-table/status-callout/support-block/text-actions) — table-based, inline-CSS, consumed as the `$bodyHtml` passed into the untouched `EmailNotificationService::buildHtml()` wrapper. |
| `EmailNotificationService::sendDirect()`/`sendDirectWithMessageId()` | Gained two new optional parameters (`$htmlBody`, `$sendPlainTextAlternative`) — both default to today's exact behaviour; `sendDirectWithMessageId()` additionally captures Brevo's own `messageId` (previously discarded), and `sendDirect()` is now a thin wrapper over it (no duplicated Brevo-call logic). |
| `App\Support\Consultancy\ConsultationCommunicationLinks` | The single action-link resolver — `manageUrl()`/`rescheduleUrl()`/`cancelUrl()`/`joinMeetUrl()`. Routes on `Appointment.linked_user_id` (authenticated → in-app; public → existing signed marketing links). `joinMeetUrl()` only ever returns the trusted, already-provider-normalised `AppointmentExternalSync.meeting_join_url` — never a fallback or placeholder. |
| `consultation_communication_deliveries` (migration + `ConsultationCommunicationDelivery` model) | The delivery/idempotency record — modelled directly on `AppointmentReminderSend`'s proven real-DB-unique-constraint pattern (`idempotency_key` = `{type}:{appointment_id}:{schedule_version}`), not a boolean flag. Batch 1 writes only `booking_confirmed`/`meeting_link_ready`; the `communication_type` column is a plain string so Batch 2/3 types need no schema change. **Written defensively in two guarded steps** (table creation, then indexes) after the first migration attempt hit the same interrupted-multi-statement class of bug already documented for `appointment_external_syncs` — a naive `hasTable()`-only guard on re-run would have silently left the unique constraint missing forever; caught by the test suite itself before this shipped. |
| `App\Services\Consultancy\ConsultationCommunicationService` | Owns both new communication types — builds HTML/plaintext content, checks/claims idempotency (insert-first, catch `UniqueConstraintViolationException`), calls `EmailNotificationService::sendDirectWithMessageId()`, records the outcome. A deliberately separate service from `ConsultationNotificationService` (which keeps owning `awaiting_customer`/`summary_published`) — booking/Meet-lifecycle communication is a distinct responsibility. |
| `App\Jobs\SendConsultationCommunicationJob` | Mirrors `SendConsultationEmailJob`'s exact contract (`afterCommit()`-dispatched, re-fetches by id, a delivery failure never surfaces as a failure of the triggering write). Runs on the existing `default` queue — deliberately not `billing-webhooks`/`consultancy-payments`/`google-integrations`. |

**Triggers**:
- `booking_confirmed` — replaces the generic `SendAppointmentEmailJob::dispatch($id, 'created')` call, but **only** at the two Consultancy booking endpoints (`PublicConsultationController::store()`, `ConsultationController::store()`); every other Appointment/Book-a-Demo booking path is completely untouched and still uses `AppointmentEmailService`.
- `meeting_link_ready` — one new call inside `AppointmentCalendarSyncService::applyConferenceResult()`, firing only on a genuine transition into `MeetConferenceState::AVAILABLE` (the method already tracks `$previousMeetingState`) — covers immediate availability, later reconciliation, and uncertain-outcome recovery alike, since all three paths call this same method. The state-change check is an optimisation, not the actual safety guarantee — that's the DB unique constraint.

**ICS**: `AppointmentIcsService::generate()` gained one new optional
parameter, the trusted Meet URL, passed in by the caller — takes priority
over `Appointment.meeting_url` for `LOCATION` when provided, added as a
`DESCRIPTION` line, and included only while genuinely available (never a
placeholder while pending). Every existing caller passes nothing and is
unaffected. Reschedule/cancellation ICS behaviour is unchanged (Batch 2).

**Security**: HTML escaping confirmed via `EmailComponents` (every value
passed through `e()`); the Meet URL never renders unless
`AppointmentExternalSync::isMeetingJoinable()` is true; `manageUrl()`
for a public recipient falls back to the existing signed reschedule/cancel
links or is omitted — never a raw numeric ID or unsigned lookup.

**Testing**: `tests/Feature/ConsultancyCommunicationsBatch1Test.php` — 14
tests / 31 assertions, all against `Http::fake()` (no real email sent by
the automated suite). Covers: shared-component escaping, public/
authenticated confirmation content and destinations, pending-vs-available
Meet wording, ICS attachment content, no-duplicate-on-repeat-trigger
(booking-confirmed and meeting-link-ready both), genuine-transition-only
dispatch (vs. unchanged-reconciliation non-resend), the action-link
resolver, and a regression check that non-Consultancy Appointments still
use the untouched generic `SendAppointmentEmailJob` path. See
project-context.md's Batch 1 entry for the full regression totals.

**Live validation** (manual, real Brevo send, this session): both new
communication types sent successfully via the real, already-configured
Brevo account to a real inbox, with idempotency independently re-confirmed
live (a second trigger against the same appointment/schedule_version
correctly sent nothing).

**Deferred to later batches**: reminders, reschedule/cancellation/
follow-up/published-summary communications, the dedicated public
no-account view page, and the global (non-Consultancy) email-family visual
migration.

## Consultancy Communications & Global Email Experience Upgrade — Batch 2

Closes the two remaining gaps Batch 1 explicitly deferred for Consultancy
itself: reminders and reschedule/cancellation communications. Follow-up/
published-summary email, the public no-account view page, and the global
(non-Consultancy) email-family visual migration remain deferred to
Batch 3/4.

**New communication types** on `ConsultationCommunicationService` (all
reuse Batch 1's shared foundation — `EmailComponents`,
`ConsultationCommunicationLinks`, `consultation_communication_deliveries`,
`SendConsultationCommunicationJob` — no new components):

- `booking_rescheduled` — the "updated confirmation" for a Consultancy
  booking, mirroring `AppointmentEmailService::sendForReschedule()`'s
  convention for the generic Appointment flow: new date/time/duration,
  Join Meet button (or the same pending-Meet wording as
  `booking_confirmed` while it isn't ready yet), manage/reschedule/cancel
  links, and an ICS attachment carrying the trusted Meet URL. Idempotent
  per `schedule_version`, which a reschedule always bumps, so this is
  always a genuinely new key, never a resend of the original
  `booking_confirmed`.
- `booking_cancelled` — cancellation notice with the cancellation reason
  (when recorded) and a `METHOD:CANCEL` ICS attachment
  (`AppointmentIcsService::generateCancellation()`). Deliberately omits
  the Join Meet button and the manage/reschedule/cancel action links —
  the booking is terminal, so none of those actions apply.
- `meeting_reminder_{offsetMinutes}` — reuses the existing, already-mature
  `SendAppointmentReminders`/`AppointmentReminderSend` scheduling and
  idempotency infrastructure unchanged (confirmed reusable as-is by
  Batch 1's own audit); only the email content and the job it dispatches
  into differ for a Consultancy appointment. The offset is folded into
  the communication's own type string (e.g. `meeting_reminder_1440`,
  `meeting_reminder_60`) purely so
  `consultation_communication_deliveries`' unique constraint stays
  meaningful per-offset — a 24h and a 1h reminder for the same
  `schedule_version` are two distinct communications, not a duplicate of
  each other. No ICS attachment on a reminder, matching
  `AppointmentEmailService::sendReminder()`'s existing convention (the
  event was already sent to the attendee's calendar at booking time).

**Routing**: every existing call site that dispatches
`SendAppointmentEmailJob` for a reschedule/cancellation/reminder now
branches on `Appointment::consultationEnquiry()` — present only for a
Consultancy booking — and dispatches `SendConsultationCommunicationJob`
instead when it is:

| Call site | Branch added |
|---|---|
| `AppointmentController::reschedule()` | `booking_rescheduled` vs. generic `reschedule` |
| `AppointmentController::applyTransition()` (`cancel()`) | `booking_cancelled` vs. generic `transition` — `confirm()`/`decline()` are untouched, since Consultancy has no confirm/decline step |
| `PublicAppointmentActionController::reschedule()` / `cancel()` | Same branch, via the controller's existing `contextFor()` helper (already used to pick the right `AvailabilityContext`) |
| `ConsultationController::cancel()` | Always Consultancy — the generic dispatch is replaced outright with `booking_cancelled`, no branch needed |
| `SendAppointmentReminders::handle()` | `meeting_reminder` vs. generic `reminder`, per due appointment |

Every other Appointment/Book-a-Demo path through these same controllers
is completely unaffected — confirmed by the regression suite (below),
which asserts the generic job still fires for a non-Consultancy
appointment at every one of these call sites.

`SendConsultationCommunicationJob` gained an optional `$context` array
(mirroring `SendAppointmentEmailJob`'s own shape exactly) carrying
`offset_minutes`/`reminder_send_id` for `meeting_reminder` — the job
updates the already-claimed `AppointmentReminderSend` row's
`status`/`sent_at`/`failure_message` itself once the send is attempted,
the same responsibility split `SendAppointmentEmailJob` already has for
the generic reminder path.

**Testing**: `tests/Feature/ConsultancyCommunicationsBatch2Test.php` — 15
tests / 48 assertions, all against `Http::fake()`. Covers: rescheduled/
cancelled content and pending-vs-available Meet wording, ICS attachment
content (`generate()` with the Meet URL for reschedule,
`generateCancellation()` for cancellation), no-duplicate-on-repeat-trigger
for all three new types, per-offset reminder distinctness (two offsets on
the same `schedule_version` both send), the reminder job's
`AppointmentReminderSend` status update, the `SendAppointmentReminders`
command routing a Consultancy vs. non-Consultancy appointment to the
correct job, and every dispatch-wiring call site above (internal
Admin/Super Admin reschedule/cancel, public signed-link reschedule/cancel,
customer-facing cancel) routing correctly for a Consultancy booking while
a non-Consultancy appointment still uses the generic job. Targeted
regression (`ConsultancyCommunicationsBatch1Test`, `AppointmentsProductionFixesTest`,
`AppointmentsPhase4CommunicationsTest`, `ConsultancyPhaseC2Batch1Test`,
`ConsultancyPhaseC2Batch2Test`, `GoogleCalendarSyncStage4B1Test`,
`GoogleMeetConferenceStage4B2Test`, plus every other Appointment/
Consultancy/Google feature test file): 543 passed / 1564 assertions,
zero regressions from this batch — the only 2 failures are pre-existing
and unrelated (an environment `FRONTEND_URL` mismatch in
`ConsultancyCommunicationsBatch1Test`'s two signed-marketing-link
assertions, confirmed present before this batch's changes and in files
this batch never touched).

**Deferred to later batches**: follow-up/published-summary
communications, the dedicated public no-account view page, and the
global (non-Consultancy) email-family visual migration (Batch 3/4).

**Pre-Batch-3 transactional-safety audit**: confirmed every communication
dispatch site across both batches uses `->afterCommit()` and none sits
inside a still-open transaction at the point of dispatch, so a rolled-back
transaction can never result in a sent email. See
[internal-docs/commercial/consultancy-communications-upgrade-batch-3-architecture-report.md](../commercial/consultancy-communications-upgrade-batch-3-architecture-report.md)
for the full per-communication-type trace.

## Consultancy Communications & Global Email Experience Upgrade — Batch 3

Closes the customer-experience gaps left after Batch 2: a public,
no-account "view your consultation" page (Scope A/B), a post-meeting
follow-up email (Scope C), a published-summary email (Scope D), and a
public no-account summary page (Scope E). Global email-family visual
migration remains Batch 4's job — this batch's two new templates are
built to that visual bar without touching the five existing templates.

**Architecture audit preceded implementation**, and resolved two
questions rather than assuming an answer:

- **Follow-up trigger**: `Appointment::status` reaching `completed` (via
  `AppointmentWorkflowService::transition()`, terminal — no path back out
  — reached only through `AppointmentController::complete()`) is the one
  canonical "the consultation actually happened" event, and was already
  the exact deferred hook (`AppointmentController::applyTransition()`'s
  own "completed/no_show intentionally send no attendee email this
  phase" comment). `ConsultationEnquiry::engagement_status` reaching
  `completed` is a different, later event (the consultant's own
  post-meeting admin work, via `EngagementLifecycleService::markCompleted()`)
  that would have collapsed the follow-up and summary-published emails
  into the same instant in the common case — not used.
- **Public summary/view link**: reuses `Appointment::public_token` and
  Laravel's existing signed-URL mechanism, no second token system. The
  one real problem found: `AppointmentPublicLinkService::expiryFor()`
  computes expiry as `starts_at - cutoffHours`, which is a past timestamp
  for a link only relevant after the meeting — fixed with a new, dedicated
  expiry formula (flat TTL from `now()`, via one new setting,
  `consultation_public_link_ttl_hours`, default 4320h/180 days), not a new
  signing mechanism. Token rotation isn't a real risk: rotation only
  happens on reschedule, and a `completed` appointment can never be
  rescheduled again.
- **A finding not previously flagged**: `ConsultancyOperationsController::publishSummary()`
  already dispatched an email (`SendConsultationEmailJob` →
  `ConsultationNotificationService::sendSummaryPublishedNotice()`) —
  plain-text, no link at all, and told a public no-account customer their
  summary was "available in your SureSign account," which is false for
  them. Scope D migrates that ONE dispatch call to the new premium email
  rather than adding a second one alongside it — see below.

**New public routes** (all behind Laravel's `signed` middleware, same
`public_token` every other public Appointment route uses, GET-only — no
mutation exists on either page this batch):

| Route | Purpose |
|---|---|
| `GET /public/consultations/{token}/view` | `PublicConsultationViewController::show()` — status, schedule, Meet join status, ICS download link, summary link once published |
| `GET /public/consultations/{token}/view/ics` | Calendar-file download — unavailable once cancelled/declined, or when ICS is disabled platform-wide |
| `GET /public/consultations/{token}/summary` | `PublicConsultationViewController::summary()` — 404 (same generic message as an invalid token) until a summary is actually published |

A `public_token` that resolves to a real, non-Consultancy Appointment
404s identically — this controller has nothing valid to show for it, and
a distinct response would itself leak "this token is real, just the
wrong kind."

**New presenter methods**: `ConsultationPresenter::publicView()` and
`::publicSummary()` — deliberately a THIRD whitelist, not a reuse of
`customerFacing()`: the authenticated shape includes the Appointment's own
numeric `id` (needed for the in-app route), which must never appear for a
public visitor identified only by their opaque token. `ConsultationMeetingPresenter::customerFacing()`
is reused completely unchanged for the public page's Meet status — it was
already authentication-agnostic and customer-safe by construction.

**Link resolver extended**: `ConsultationCommunicationLinks` gained
`viewUrl()` (always non-null) and `summaryUrl()` (null until published).
`manageUrl()`'s public-customer fallback, previously `null` when neither
reschedule nor cancellation was available, now falls back to `viewUrl()`
— closing the gap that class's own Batch 1 docblock had flagged as
"Batch 3's job." `AppointmentPublicLinkService` gained the matching
signed-link builders (`consultationViewApiUrl()`/`consultationSummaryApiUrl()`/`consultationViewIcsApiUrl()`
plus their `...MarketingUrl()` rewrites to `/consultations/{token}` on the
marketing site) — see the audit finding above for why these don't reuse
`expiryFor()`.

**New premium email components** (`App\Support\Email\EmailComponents`,
additive only — the five existing templates are untouched): `hairline()`
(a single thin rule replacing a boxed section break) and `meta()` (a
label-over-value list with hairline dividers, no visible table borders —
the "Stripe receipt" register the brief asked for, as opposed to
`detailsTable()`'s boxed cells). Both new templates reuse the existing
`button()`/`paragraph()`/`supportBlock()` primitives rather than forking
them, specifically so Batch 4 can migrate the older five templates onto
this same visual language without reconciling two button styles.

**Two new communication types** on `ConsultationCommunicationService`:

- `consultation_followup` — thank-you, a short recap (service/consultant/date
  via `meta()`), an honest "your consultant is preparing a written summary,
  we'll email you" note, and a secondary "View Consultation" button. No
  summary content, no ICS (nothing left to add to a calendar for a
  consultation that's already happened).
- `summary_published` — **replaces**, in place, the old
  `ConsultationNotificationService::sendSummaryPublishedNotice()` dispatch
  at `publishSummary()`'s one call site (updated from `SendConsultationEmailJob`
  to `SendConsultationCommunicationJob`). Never includes the summary text
  itself (that's the public summary page's job) — just enough context
  (title, consultant, date) plus one secure "View Consultation Summary"
  button. `ConsultationNotificationService` now owns only
  `awaiting_customer`; `SendConsultationEmailJob`'s `summary_published` kind
  was removed.
  - **Idempotency note**: unlike every other type, this one can't use
    `schedule_version` as its distinguishing key — a republish doesn't
    change it, which would have silently suppressed the republish
    notification (a real regression from the old behaviour, which resent
    unconditionally on every publish). `customer_summary_published_at`
    (freshly stamped before dispatch) is folded into the type string
    instead (`summary_published_{timestamp}`), the same way
    `sendMeetingReminder()` folds in its offset — a genuine republish gets
    its own key and sends again; a retried job for the SAME publish
    (identical timestamp) still collides and sends nothing twice. This is
    a strict improvement over the old path, which had no idempotency
    protection at all.

**Trigger wiring**: `AppointmentController::applyTransition()` dispatches
`consultation_followup` when `toStatus === 'completed'` AND the
appointment has a `consultationEnquiry` — a generic (non-Consultancy)
Appointment marked completed still sends nothing, unchanged from before
this batch.

**Public frontend** (marketing site, mirrors the existing
`/appointments/{token}` page's conventions exactly — same `StateScreens`
components, same gsap reveal, same signed-query handling):

- `marketing/src/lib/publicConsultations.ts` — reuses `publicAppointments.ts`'s
  generic `request()`/`parseError()`/`signedQueryFrom()`/`isPastExpiry()`
  rather than re-implementing signed-link handling a second time.
- `/consultations/{token}` (`PublicConsultationExperience`/`ConsultationDetailCard`/`MeetJoinBlock`) —
  read-only: no cancel/reschedule flow lives here, only status/schedule/Meet/ICS/summary-link.
- `/consultations/{token}/summary` (`PublicConsultationSummaryExperience`) —
  renders the published summary text via React's normal escaping
  (`whitespace-pre-wrap` on plain interpolated text, matching the existing
  admin summary view's own convention) — never `dangerouslySetInnerHTML`.

**Security**: every public response is built exclusively from
`ConsultationPresenter`'s dedicated public whitelists — no internal id,
Google/provider identifier, or token ever appears in a JSON body (verified
by test, not just by construction). The Meet join action is always a
button/anchor pointing at the trusted `meeting_join_url`, never printed as
visible text on the pages; the two new emails' plain-text alternatives do
print the full signed URL as text, consistent with every existing
Consultancy email's own plain-text convention (booking_confirmed,
meeting_link_ready, etc. all already do this for their action links) —
not a new exposure pattern.

**Testing**: `tests/Feature/ConsultancyCommunicationsBatch3Test.php` — 17
tests / 66 assertions. Covers: no internal identifiers in either public
response, all four Meet states, ICS availability/unavailability
(cancelled, ICS disabled), summary link presence/absence, invalid/expired
signature rejection (403) on both routes, 404 for an unknown token and for
a real non-Consultancy token, the `manageUrl()` fallback, follow-up
content/no-duplicate/no-summary-content, the `complete` transition
dispatching follow-up only for Consultancy (and nothing for a generic
Appointment), summary-published content/no-raw-summary-text-in-email,
republish-sends-again-but-retry-of-same-publish-does-not, and the real
`publishSummary()` endpoint dispatching the new job end-to-end.
`ConsultancyPhaseC2Batch4Test`'s existing `test_publish_sends_summary_published_notification`
was updated in place (asserts `SendConsultationCommunicationJob` instead
of the removed `SendConsultationEmailJob`/`summary_published` path) — the
underlying business event (one notification per publish) is unchanged,
only which job/service delivers it. Targeted regression across every
Appointment/Consultancy/Google/Billing feature test file (1088 tests) plus
the full backend suite in memory-limited chunks: zero regressions
attributable to this batch — remaining failures are the same pre-existing,
unrelated ones already documented in the Batch 1/2 entries (the
`FRONTEND_URL` environment mismatch, `PaymentApplicationExcelDisclosureTest`,
`SupportTicketControllerTest`, and this environment's storage-permission
issues on `support-tickets`/`adjudication` disks).

**Live validation**: not performed this batch (automated tests only) —
unlike Batch 1's real Brevo send, no live email was sent for
`consultation_followup`/`summary_published`. The underlying send mechanism
(`EmailNotificationService::sendDirectWithMessageId()`) is unchanged and
was already live-validated in Batch 1.

**Known limitations / explicitly out of scope this batch**: no
customer-initiated action exists on the public view page (no
cancel/reschedule from `/consultations/{token}` — those remain on the
existing `/appointments/{token}` pages); email-client rendering
(Outlook/Gmail/Apple Mail) was not manually verified across real clients,
only structurally (table-based layout, inline CSS, matching every existing
email's own untested-across-clients precedent); no automated
accessibility audit tool was run against the new pages/emails.

**Batch 3 is complete.**

## SureSign Communications Platform — Batch 4 (global migration, complete)

The global, non-Consultancy email-family visual migration referenced
above shipped as its own initiative, "SureSign Communications Platform,
Batch 4" — broader than a Consultancy batch, since it also covered
authentication (password reset, email verification) and the commercial-
workflow notification families. Consultancy's own components
(`EmailComponents`) and idempotency pattern were the reference
implementation the rest of the platform was migrated toward — nothing in
Consultancy's own five (Batch 1/2) or two (Batch 3) email types changed.
See
[internal-docs/commercial/communications-platform-batch-4-audit.md](../commercial/communications-platform-batch-4-audit.md)
and
[internal-docs/commercial/communications-platform-batch-4-report.md](../commercial/communications-platform-batch-4-report.md)
for the full platform-wide audit and implementation record.

## Operator queue detail page — Meet visibility gap fixed, layout consolidated (2026-08-03)

Two fixes found and closed together, outside any numbered batch:

**Meet join button was missing entirely from the operator/Super Admin
consultation detail page** (`/admin/consultancy/queue/{id}`), even once a
Google Meet link was genuinely available — confirmed via a real Activity
Log entry ("Google Meet for appointment ...: available.") with no
corresponding UI anywhere on the page. Root cause:
`ConsultationPresenter::operator()` never included Meet status at all —
its own docblock had gone stale, still saying "meeting_url... unpopulated
until C4" long after Stage 4B actually shipped Google Meet, and nobody
revisited the line. Fixed by having `ConsultancyOperationsController::show()`
append `ConsultationMeetingPresenter::customerFacing($appointment)` under
a `meeting` key — reusing the exact same four-state, provider-detail-free
presenter the authenticated customer page already uses, rather than
inventing a second Meet shape for operators. `externalSync` is loaded only
in `show()`, not added to the shared `OPERATOR_RELATIONS` constant `index()`
also uses, since the queue list has no use for Meet status.

**The page also required far more scrolling than its actual amount of
information warranted.** Root cause: four separate full-width cards
(Overview, Organisation, Service, Appointment) each holding only one to
three small fields, each paying the full card padding/border/heading cost
for almost no content. Consolidated into one "Details" card (a single
grid of all nine fields); the new "Meeting" card sits right after it. Net
effect: 11 stacked sections became 9, with the two smallest-value ones
removed entirely rather than just shrunk.

**Testing**: two new tests in `ConsultancyPhaseC2Batch3Test.php` — Meet
available (status + join URL present) and no sync row yet (status is one
of pending/temporarily_unavailable/unavailable, `join_url` always null).
Full Consultancy/Appointments/Google regression (564 tests): zero
regressions, the same 2 pre-existing `FRONTEND_URL`-environment failures
already documented in every prior batch.
