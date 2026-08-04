# SureSign Consultancy — Phase C2 Specification v1 (Consultant Operations)

**Status: specification only. No code, migration, route, or config in this
document has been implemented.** This document is the authoritative,
phase-specific specification for Phase C2 — it supersedes the brief C2
placeholder in
[suresign-consultancy-specification-v1.md](suresign-consultancy-specification-v1.md),
which now points here (see that document's own C2 section).

Phase C1 (Foundation) is frozen and approved. This phase adds nothing to
scheduling, payments, Google Meet, document uploads, reporting, analytics,
AI, or billing — those remain out of scope until their own phases.

---

## 0. Core principle, restated as an implementation constraint

**Appointments continues to own scheduling, availability, reminders,
cancellation, rescheduling, timezones, and calendar logic — unchanged.**
Every new C2 field, service, and table introduced here belongs to
Consultancy. Where a genuine choice exists between reusing an existing
Appointments-owned column and adding a new Consultancy-owned one, this
document states the test applied and the result — see §1.4.

No code in `AppointmentSchedulingService`, `AppointmentAvailabilityService`,
or `AppointmentWorkflowService` is modified by this phase. The one place
Consultancy needs to react to an Appointment-owned event (cancellation) is
handled via a model observer on `Appointment`, not a change to any
Appointments engine class — see §2.5.

---

## 1. Schema

### 1.1 New columns on `consultation_enquiries` (Consultancy-owned table)

```
consultation_enquiries  (existing table — additive columns only)
  engagement_status                  string, default 'awaiting_consultant', not null
  internal_notes                     text, nullable
  customer_summary_draft             text, nullable
  customer_summary_published         text, nullable
  customer_summary_published_at      timestamp, nullable
  customer_summary_published_by      FK -> users.id, nullable
  customer_summary_needs_republish   boolean, default false
```

`engagement_status` is a plain `string` column with application-level
validation (`Rule::in([...])`), not a MySQL `ENUM` column — deliberately,
so a future additional value never requires a migration, matching how
`Appointment::STATUSES` itself is a PHP-level constant over a plain column
rather than a DB-level enum.

### 1.2 No new columns on `appointments`

This phase does **not** add `customer_summary`, `internal_notes`, or any
engagement-status column to `appointments` — a deliberate refinement over
the brief placeholder in the v1 specification, which had informally
suggested `customer_summary` as an `appointments` column. Now that the
actual design work has been done: every field whose *meaning* is
Consultancy-specific (visibility rules, publishing workflow, operational
status) lives on `consultation_enquiries`. `appointments` gains **zero**
Consultancy-specific columns in this phase, strengthening the separation
principle beyond what C1 had assumed.

### 1.3 Reused, unmodified `appointments` columns

```
appointments (existing, untouched)
  assigned_user_id   -- consultant assignment (see §5)
  project_id         -- project linkage (see §6)
  organization_id / linked_user_id  -- unchanged from C1
```

### 1.4 The test applied to each field: reuse vs. new column

| Field | Decision | Why |
|---|---|---|
| Assignment (`assigned_user_id`) | **Reuse `appointments.assigned_user_id`** | Assignment has real *scheduling* meaning — `AppointmentSchedulingService` already uses it for buffer/availability conflict checks. It is not Consultancy-specific data; every Appointment Type already has this concept. |
| Project link (`project_id`) | **Reuse `appointments.project_id`** | "Which project does this appointment concern" is a generic concept every Appointment Type could use, not something whose meaning changes because it's a Consultancy booking. Duplicating a second FK on `consultation_enquiries` would itself be the kind of redundant duplication this phase is instructed to avoid. |
| Notes (`internal_notes`) | **New column on `consultation_enquiries`**, not a reuse of the generic `appointments.internal_notes` | Its *visibility semantics* (consultant-only, structurally excluded from every customer-facing path) are Consultancy-specific, unlike the generic `appointments.internal_notes` field, which carries no such guarantee for other Appointment Types. Mirrors why `customer_summary` was never a good fit for `appointments` either. |
| Customer summary + publishing fields | **New columns on `consultation_enquiries`** | Entirely Consultancy-specific concept; no equivalent exists anywhere in Appointments. |
| Engagement status | **New column on `consultation_enquiries`** | See §2 — a genuinely distinct operational lifecycle from `appointments.status`. |

### 1.5 Backfill migration for existing C1 rows

C1 shipped with no notes/summary/engagement-status UI at all, so there is
**no existing data to migrate** for the new text columns — they simply
start `null`. `engagement_status` is the only column needing a backfill
rule for pre-existing rows, derived unambiguously from each row's linked
`Appointment.status` at migration time:

```
appointment.status = 'cancelled'                       → engagement_status = 'cancelled'
appointment.status = 'completed'                        → engagement_status = 'completed'
appointment.status in ('requested','pending_confirmation','confirmed','no_show','declined')
                                                          → engagement_status = 'awaiting_consultant'
```

This is a one-time, purely additive `UPDATE` inside the same migration
that adds the column (matching this codebase's existing convention for
additive-column migrations with a derivable backfill, e.g. how
`telemetry_schema_version` was introduced) — no manual data entry, no
ambiguous cases.

---

## 2. Engagement status: the state machine

### 2.1 Naming: `engagement_status`, not `consultancy_status`

Chosen over `consultancy_status` because "consultancy" already names the
module/vertical (`ConsultancyService`, `consultancy_services` table) — a
column called `consultancy_status` reads ambiguously ("status of the
consultancy business"?). `engagement_status` names the actual concept:
the state of *this customer's engagement* with a consultant, independent
of the underlying appointment's scheduling state.

### 2.2 Minimal state set — the test applied

Per instruction, each candidate state was tested against: *"does this
represent a genuine Consultancy operational state that cannot already be
derived reliably from the linked Appointment?"* Results:

| Candidate (from the illustrative brief) | Kept? | Reasoning |
|---|---|---|
| New | ❌ | Indistinguishable in practice from "nothing has happened yet, ball is with the consultancy team" — folded into the default value of `awaiting_consultant` rather than kept as a separate value with no distinct transition or permission behind it. |
| Assigned | ❌ | Fully derivable from `appointments.assigned_user_id IS NOT NULL` — a second status value duplicating that fact is exactly the redundancy this phase must avoid. |
| Scheduled | ❌ | Fully derivable from `appointments.status = 'confirmed'` — Appointments already authoritatively represents this. |
| In Progress | ❌ | For a 15–60 minute consultation, no realistic operational workflow depends on a consultant toggling a "the call is happening right now" flag; nothing reads or acts on it. Not built. |
| Awaiting Customer | ✅ | Genuine meaning Appointments cannot express: "the ball is with the customer for some reason" (e.g. the consultant needs more information before proceeding). No automatic trigger produces this in C2 (no messaging/document-upload feature exists yet to generate it) — it is manually set by the consultant, and reserved as a real, usable value for that reason. |
| Awaiting Consultant | ✅ | The default state and the genuine opposite of the above — "the consultancy team needs to do something" (triage, prepare, run the session, write up the summary). |
| Completed | ✅ | Genuinely distinct from `appointments.status = 'completed'` (which only means "the meeting happened") — this means "the consultant's own follow-up work is done," which can lag the meeting by days. See §2.4 for its two triggers. |
| Cancelled | ✅ | Required to exist so a cancelled appointment is never left showing an active operational state — but its only trigger is automatic, see §2.5. |
| Closed | ❌ | See §2.6 — collapsed into `completed`; no genuine distinguishing behaviour exists for it yet in C2. |

**Final set**: `awaiting_consultant` (default) → `awaiting_customer` →
`completed` (terminal) / `cancelled` (terminal).

### 2.3 State diagram

```
                    ┌────────────────────┐
        (default)   │ awaiting_consultant │◄─────────────┐
             ┌─────►│                     │               │
             │       └──────────┬─────────┘               │
             │                  │ manual (consultant/       │ manual (consultant/
             │                  │ Super Admin)              │ Super Admin)
             │                  ▼                           │
             │       ┌─────────────────────┐                │
             └───────┤  awaiting_customer   ├────────────────┘
                     └──────────┬───────────┘
                                │
        publish summary (auto) │  OR manual "mark completed"
                                │  (consultant/Super Admin)
                                ▼
                     ┌─────────────────────┐        Super Admin only,
                     │      completed       │───────► reopen (§2.7) ──┐
                     └─────────────────────┘                          │
                                                                       │
                     (back to awaiting_consultant) ◄───────────────────┘

  From ANY non-terminal state, automatically, whenever the linked
  Appointment's own status becomes 'cancelled' (see §2.5):

  awaiting_consultant ──┐
  awaiting_customer ────┼──► cancelled  (terminal — no manual path in or out)
```

### 2.4 `completed` — two triggers, one state

1. **Automatic**: `customer_summary_published_at` is set for the first
   time (the consultant published a summary — see §4). This is the
   expected path for any non-introductory consultation.
2. **Manual**: the assigned consultant or Super Admin explicitly marks the
   engagement completed without publishing a summary — the expected path
   for `is_introductory` services, which C1's specification already says
   have "no written consultancy report" as a matter of product policy.

Both call the same `EngagementLifecycleService::markCompleted()` method
(see §2.8) — there is exactly one completion code path, not two.

### 2.5 `cancelled` — automatic only, never manual, mirrors Appointment cancellation exactly

There is deliberately **no manual way to set `engagement_status =
'cancelled'`** — doing so independently of a real Appointment cancellation
is exactly the kind of contradictory state combination this phase must
prevent (an engagement claiming "cancelled" while its Appointment is still
`confirmed` would be a lie the UI could show a customer).

**Mechanism**: a Laravel model observer on `Appointment`
(`App\Observers\ConsultancyAppointmentObserver`, registered in a service
provider — zero lines changed in `AppointmentWorkflowService` or any other
Appointments engine class) watches the model's native `updated` event. When
it sees `status` change to `cancelled` **and** the appointment has a linked
`consultationEnquiry`, it calls
`EngagementLifecycleService::syncFromAppointmentCancellation()`, which sets
`engagement_status = 'cancelled'` in the same request.

This single observer covers **every** cancellation entry point uniformly —
the existing internal `AppointmentController::cancel()`
(Super Admin/assigned Admin), the Consultancy-specific
`ConsultationController::cancel()` (Client, from C1), and the public
signed-link cancellation (`PublicAppointmentActionController::cancel()`) —
without any of those three call sites needing to know Consultancy exists.
This mirrors the exact mechanism the C1 specification already prescribed
for the future Google Meet integration in Phase C4 ("via an event/listener
rather than inline coupling, so the core Appointments engine still has
zero Consultancy-specific code in it").

**Contradiction prevention**: because `AppointmentWorkflowService::TRANSITIONS`
already makes `cancelled` a genuinely terminal state for the Appointment
itself (no outgoing transitions), and `engagement_status = 'cancelled'` has
no outgoing transitions either (see §2.7), the two states can never
diverge once both are terminal — there is no path that re-opens one
without the other.

### 2.6 Are `completed` and `closed` genuinely distinct?

**No — `closed` is not built in C2.** Applying the same test as §2.2: a
`closed` state would only earn its place if something *reads or acts on it
differently from `completed`* — e.g. a future invoicing prerequisite, a
formal audit sign-off step, or a distinct edit-lock boundary. None of those
exist yet. Building `closed` now, with no real behaviour behind it, would
be exactly the kind of premature, undefended state the instruction warns
against. `completed` is the single terminal "done" state for C2, and it is
what triggers the notes/summary edit-lock in §2.9. If a genuine future need
for a further-finalized state emerges (e.g. in C3, once payment/refund
windows exist), `closed` can be added then without breaking anything built
here — a strict superset, not a rework.

### 2.7 Reopening

Allowed, narrowly: **`completed → awaiting_consultant`, Super Admin only**,
logged as its own distinct `consultation.engagement_reopened` activity
event (never silently folded into a generic "status changed" entry).
Reopening does **not** clear `customer_summary_published`/`published_at` —
a previously published summary stays visible to the customer even while
the engagement is reopened for further consultant work; if the consultant
edits the draft afterwards, `customer_summary_needs_republish` naturally
flips true again (§4), so the customer is never silently shown stale
content as if it were current without the consultant's own explicit
re-publish action.

`cancelled` is never reopenable — mirrors `AppointmentWorkflowService`'s
own design, where `cancelled` has no outgoing transitions at all. A
customer who wants to try again books a new consultation (a new record),
exactly as they would for any other cancelled Appointment today.

### 2.8 `EngagementLifecycleService` — the single authority for this state machine

```
App\Services\Consultancy\EngagementLifecycleService

  transitionManual(ConsultationEnquiry $enquiry, string $to, User $actor): void
      // awaiting_consultant <-> awaiting_customer only; throws
      // InvalidArgumentException for any other requested target — manual
      // transitions to completed/cancelled are rejected here by design
      // (see markCompleted()/syncFromAppointmentCancellation() below).

  markCompleted(ConsultationEnquiry $enquiry, User $actor, bool $viaSummaryPublish): void
      // The one path to 'completed' — called either by the summary-publish
      // action or by an explicit manual "mark completed" admin action.

  reopen(ConsultationEnquiry $enquiry, User $actor): void
      // completed -> awaiting_consultant, Super Admin only (enforced by the
      // calling controller's authorize() check, not re-checked here —
      // this service assumes its caller has already authorized the action,
      // matching AppointmentWorkflowService::transition()'s own contract).

  syncFromAppointmentCancellation(ConsultationEnquiry $enquiry): void
      // The only path to 'cancelled' — called exclusively by
      // ConsultancyAppointmentObserver.
```

A single `const TRANSITIONS` map (mirroring
`AppointmentWorkflowService::TRANSITIONS`'s own shape exactly) is the one
source of truth for which `engagement_status` transitions are valid; every
method above consults it before writing, and an invalid combination throws
rather than silently succeeding.

---

## 3. Internal notes

Single current value (`consultation_enquiries.internal_notes`), never a
version table (see §0 of the earlier phase's approval and the reasoning
below).

* **Access**: assigned consultant or Super Admin only, write and read.
  Never returned by any customer-facing response, structurally — enforced
  by `ConsultationPresenter::customerFacing()` categorically excluding the
  field (see §8), the same discipline `AiAnalysisPresenter` already uses
  for execution telemetry.
* **Create/edit/delete**: a single `PUT` (upsert) endpoint — "delete" means
  clearing the field to `null`, not a separate destructive action; there is
  no meaningful distinction between "delete the note" and "edit it to
  empty" worth a second code path.
* **Audit trail, without leaking sensitive content into `ActivityLog`**:
  every edit records a `consultation.internal_notes_updated` `ActivityLog`
  entry, but the `meta` payload never stores the note's full text —
  `ActivityLog` is visible to every Super Admin/Admin, a broader audience
  than should necessarily read a consultant's private working notes about
  a customer. Instead `meta` stores:
  * `previous_length` / `new_length` (character counts — reveals "a
    substantive edit happened" without revealing content)
  * `new_content_hash` (SHA-256 of the new value) — lets a future
    investigation confirm "does the current value match what was recorded
    at this point in time" without ever storing the value itself in a
    second place.
  * `actor_id` / timestamp (already standard `ActivityLog::record()`
    fields).

  This gives genuine integrity/audit value (detecting an unexpected
  reversion or confirming when a specific piece of text was written) without
  duplicating sensitive content into a table with broader read access than
  the notes field itself has.
* **Edit-lock**: once `engagement_status = 'completed'`, `internal_notes`
  becomes immutable for non-Super-Admin actors — mirrors
  `AiTelemetryIntegrityGuard`'s "protected once terminal" pattern exactly
  (enforced via the `ConsultationEnquiry` model's `updating` event, not a
  full event-sourcing rebuild). Super Admin retains write access even when
  completed, for genuine correction — consistent with every other
  "Super-Admin-only override of an otherwise-locked state" precedent in
  this codebase (e.g. `AiCreditsGrantController`).

---

## 4. Customer summary — publishing workflow

Five fields (§1.1), not a version table, because the workflow genuinely
needs more than one value at a time (a draft, and what's actually visible),
but not a full history of every intermediate edit:

| Field | Meaning |
|---|---|
| `customer_summary_draft` | The consultant's current working text — never customer-visible, editable any time before `completed` (and by Super Admin after). |
| `customer_summary_published` | The exact text a customer currently sees — a snapshot copied from the draft at the moment of publishing, not a live reference to the draft. |
| `customer_summary_published_at` | Null until first published; set (and only ever updated forward) on each publish/republish. |
| `customer_summary_published_by` | Who published it — attributable, mirrors `customer_summary_published_by` naming convention already used for `billing_plan_changes`-style actor-attribution fields elsewhere in this codebase. |
| `customer_summary_needs_republish` | `true` whenever `customer_summary_draft` is edited **after** a publish has already happened; cleared back to `false` only by the next actual publish action. |

**Publishing workflow**:

1. Consultant edits `customer_summary_draft` freely — never visible to the
   customer, no `ActivityLog` noise for every keystroke-level save (draft
   saves are only logged with the same length/hash discipline as §3, and
   only on a debounced/explicit save, not per-keystroke).
2. Consultant calls "Publish" — this copies `customer_summary_draft` →
   `customer_summary_published`, sets `published_at`/`published_by`,
   clears `needs_republish`, calls
   `EngagementLifecycleService::markCompleted(..., viaSummaryPublish: true)`,
   and triggers the customer-facing "your consultation summary is ready"
   notification (§9).
3. If the consultant edits the draft again afterwards,
   `needs_republish` flips `true` — surfaced in the consultant UI as a
   visible "you have unpublished changes" indicator — but
   `customer_summary_published` (what the customer actually sees) is
   **not** silently changed; only another explicit "Publish"/"Republish"
   action updates it.
4. A republish after `reopen()` (§2.7) follows the identical mechanism —
   there is no separate "republish" code path, `publish()` and
   `republish()` are the same method call.

`ActivityLog` records `consultation.summary_published` (actor, timestamp,
`new_content_hash` — same content-privacy discipline as §3, since a
customer summary can also contain commercially sensitive project detail
even though the customer themselves will see it; the log itself still
shouldn't duplicate the full text unnecessarily).

---

## 5. Consultant assignment

Represented entirely via the existing `appointments.assigned_user_id` (see
§1.4) — **no new "assigned" status, no new assignment table, in C2.**

* **Today (single consultant in practice)**: reuses the existing,
  unmodified `/appointments/{appointment}/assign` endpoint and its existing
  Super-Admin-only reassignment rule (`AppointmentController::assign()` —
  zero changes). The consultant queue simply reads `assigned_user_id` like
  every other Appointments view already does.
* **Assignment history**: already fully covered by the existing
  `appointment.assigned` `ActivityLog` action (confirmed in
  `AppointmentWorkflowService::assign()` — it already records
  `previous_assigned_user_id`/`assigned_user_id` in `meta` today). C2 adds
  **no new history mechanism** — the consultant detail view's "Activity"
  section simply queries `ActivityLog` for this appointment, which already
  includes every past reassignment.
* **Future multiple consultants**: this design already generalizes without
  change — `assigned_user_id` is a single FK today, and nothing in this
  phase assumes exactly one eligible consultant exists platform-wide. A
  future "Consultants Catalogue" (roadmap item from the v1 specification)
  would add richer consultant *profile* data (bio, specialties) referenced
  optionally by a `consultancy_services` row, but assignment itself would
  remain this same `assigned_user_id` field — no rework implied.
* **Ownership**: "who owns this consultation operationally" is simply
  "whoever `assigned_user_id` currently points to" — there is no separate
  ownership concept to keep in sync.

---

## 6. Project linkage (Phase C2, Batch 5)

Reuses `appointments.project_id` (existing column, unconstrained, see
§1.4) — no migration, no pivot table, no polymorphic relation. One
consultation links to zero or one Project; one Project may have many
linked consultations (`Project::appointments()`, a plain, generically-named
inverse relation — deliberately not called `consultations()`, since the
Project model must stay unaware Consultancy exists as a concept).

**Operator-managed only, in this batch.** Linking, changing, and unlinking
are all Admin/Super-Admin actions via `ConsultancyOperationsController`,
gated by the exact same `authorizeOperatorManage()` used by every other
write endpoint since Batch 4 — Client has no linkage write capability at
all.

**Deferred, recorded explicitly rather than silently dropped**: the
original v1 specification's product-decision #3 anticipated a customer
proposing a project link *at booking time* (via `ConsultationController`).
That is **not implemented in Batch 5** — Batch 5 is scoped entirely to
operator-managed linkage after a consultation already exists. This remains
a real, deferred product idea, not a rejected one — it needs its own
future decision (does a Client select from their own org's projects at
booking time, and does that then require the *customer*-facing presenter
to expose project info at all, which it currently categorically does not)
before any implementation. Nothing in Batch 5's design forecloses it —
the same `project_id` column and the same-organisation rule below would
apply identically to a future customer-initiated path.

**The confirmed, standing tenant-isolation rule for this relationship**:

> A consultation may only be linked to a Project belonging to the same
> organisation as the consultation itself.

**This is now a platform invariant, not merely a Batch 5 implementation
decision** — it governs any future Consultancy/Project touchpoint, not
only `linkProject()` as it exists today. Linkage connects *context*
between two independent modules; it does not merge them or transfer
ownership either way: `Project` remains the sole authority for project
management (contract value, retention, documents, RFIs, variations, and
every other project-scoped module), and `Consultancy` remains the sole
authority for the consultation/engagement itself (`engagement_status`,
notes, summary, assignment). Linking a consultation to a project changes
nothing about who owns which data — it only lets each side surface a
read-only pointer to the other (`ConsultationPresenter::projectRef()` on
the consultation; `ConsultationPresenter::projectSummary()` on the
project side).

This was not assumed — it was found by tracing a concrete leak: Batch 5
introduces `GET /admin/consultancy/projects/{project}/consultations`,
reachable by a Client via the *Project's own* existing
`authorizeProject()` rule (a Client may view their own organisation's
project). Without the same-organisation rule, a consultation from
Organisation A linked to a Project owned by Organisation B would let a
legitimate Client at Organisation B see a real summary of Organisation A's
consultation (reference, service, engagement status, assigned consultant,
dates) through their own project's overview page — a genuine
cross-organisation disclosure introduced by this batch's own new read
surface, not a pre-existing gap. Enforced at the application layer only
(no DB foreign-key constraint, per instruction) — an unconditional check
in `linkProject()`, applying identically to Super Admin and the assigned
Admin alike; Super Admin status never excuses creating an invalid
relationship. A cross-organisation attempt returns `422` with an explicit
message ("This project belongs to a different organisation."), never a
silent relink and never a 403 (403 is reserved for the authorization
question — see §6's execution order below — a 422 here confirms the
caller *was* authorized to attempt the action, just requested an invalid
target).

**Execution order inside `linkProject()`** (deliberately authorization
before data validation, so an unassigned Admin or Client — who has no
right to perform this action at all — never learns *anything* about
whether a given project exists, is soft-deleted, or belongs to another
organisation):

1. `authorizeOperatorManage()` — 403 if the caller may not manage this
   consultation at all.
2. Resolve and validate the requested Project exists and is not
   soft-deleted — ordinary validation failure (422) if not.
3. Enforce the same-organisation rule unconditionally — 422 if it fails.
4. Compare the requested `project_id` against the currently stored value —
   if identical, this is an idempotent no-op: return 200 with the
   unchanged state, write nothing, log nothing.
5. Only when the relationship actually changes: persist and log
   (`consultation.project_linked` for a first link,
   `consultation.project_changed` for a replacement).

**Project status and linkability**: the backend does **not** hard-exclude
`completed`/`cancelled` projects from `linkProject()` or from the search
endpoint itself in Batch 5 — `ProjectController::index()`'s existing
`status` filter would let a caller narrow results by status if it chose
to. **Correction (found during Batch 6B's documentation audit): the
operator frontend's `ProjectLinker` picker does not actually apply any
status filter** — an earlier draft of this section described it as
defaulting to `active`/`on_hold` as a UX convenience, but the shipped
component (verified directly, `frontend/src/app/admin/consultancy/queue/[id]/page.tsx`)
searches across projects of every status. This is a narrower guarantee
than the original sketch implied (which described terminal statuses as
*structurally* excluded) — recorded here as a deliberate simplification
for Batch 5, not an oversight: adding a hard backend exclusion would mean
special-casing Consultancy's own linkage call inside the shared, generic
`ProjectController::index()`, which the batch's own instruction to "keep
this limited to search support, don't refactor the rest of
`ProjectController`" argues against. An operator can knowingly link to a
terminal-status project — unusual, not invalid. Revisit (either a
frontend status default or a real backend exclusion) only if a real
operator mistake is ever observed; not fixed speculatively here.

**Read access to the linked project's own detail page**: unchanged from
how `ProjectController::authorizeProject()` already works — Super
Admin/Admin can already open any project via the existing `/app/projects/{id}`
workspace (the same one Clients use); Batch 5 doesn't add or relax any
project-detail authorization, only the new, narrow, Consultancy-owned
linked-consultations list endpoint on the Project side.

---

## 7. Permissions and authorisation

**No Laravel Policy classes are introduced** — this codebase has no
`app/Policies/` directory and does not use them; C2 follows the existing
per-controller `authorize()` method convention exactly, as every other
module in this codebase does.

### Confirmed visibility model (approved — see Decision Register)

Four tiers, precisely:

| Tier | Visibility | Write access |
|---|---|---|
| **Super Admin** | Full platform-wide visibility of every consultation. | Full operational control — every action in the table below. |
| **Assigned Admin** (`appointments.assigned_user_id === self`) | Full access to the consultation. | May update internal notes, customer summary (draft + publish), engagement status, project linkage, and every other operational field permitted below. |
| **Unassigned Admin** (any Admin where `assigned_user_id !== self`, including one assigned to a *different* Admin) | **Read-only**, platform-wide — may view the full consultant-facing workspace (same data an assigned Admin sees, including internal notes and the draft summary) for operational continuity if the assigned consultant is unavailable. | **None.** Must not edit internal notes; must not edit or publish the customer summary; must not change engagement status; must not change project linkage; must not reassign; must not reopen a completed engagement; must not perform any other consultant-owned operational action. |
| **Client** | Only their own organisation's consultations, via the existing `ConsultationController`/organisation-scoping rule. | None — read own consultation + cancel, unchanged from C1. Never receives internal notes, assignment metadata, or operator-only activity detail. |

**This is a deliberate, Consultancy-specific divergence from the generic
Appointments visibility rule, not an extension of it.**
`AppointmentController::authorizeView()` continues to restrict a generic
Admin to appointments assigned to them or unassigned — **unchanged, not
touched by this phase**. The broader "any Admin may view any consultation
read-only" rule exists **only** inside the new
`ConsultancyOperationsController`'s own authorization checks, justified by
a genuine Consultancy-specific operational need (continuity when the
assigned consultant is unavailable) that does not apply to Appointments
generally (e.g. an internal Training Session or Account Review appointment
has no equivalent "another operator needs to understand this engagement"
requirement). Implementing this exclusively in the Consultancy-owned
controller/presenter/authorization layer — never by loosening
`AppointmentController` itself — is what keeps this consistent with §0's
core principle.

**Backend-enforced, not merely a hidden button**: every write endpoint
re-checks `authorizeOperatorManage()` independently of whatever the
frontend renders. An unassigned Admin who directly calls
`PUT /admin/consultancy/consultations/{id}/notes` gets a 403 regardless of
what the UI shows them — the read-only restriction is a real
authorization boundary, not a client-side affordance.

| Action | Super Admin | Assigned Admin | Unassigned Admin | Client | Future Consultant role |
|---|---|---|---|---|---|
| View dashboard/queue | ✅ (all) | ✅ | ✅ (platform-wide, read-only) | ❌ | Deferred — see below |
| View a specific consultation's operator detail (full data, incl. internal notes/draft summary) | ✅ | ✅ | ✅ (read-only) | ❌ (uses the existing customer-facing `ConsultationController::show()` instead) | Deferred |
| Write internal notes | ✅ | ✅ | ❌ | ❌ | Deferred |
| Write/publish customer summary | ✅ | ✅ | ❌ | ❌ (read-only, published content only, via the existing customer surface) | Deferred |
| Manual engagement-status transition | ✅ | ✅ | ❌ | ❌ | Deferred |
| Mark completed (without publishing) | ✅ | ✅ | ❌ | ❌ | Deferred |
| Reopen a completed engagement | ✅ only | ❌ | ❌ | ❌ | Deferred |
| Reassign consultant | ✅ only (existing `/assign` rule, unchanged) | ❌ | ❌ | ❌ | Deferred |
| Link/change linked project | ✅ | ✅ (own assigned) | ❌ | ✅ (may propose a link on their own organisation's project — never another org's) | Deferred |
| View linked project (read-only) | ✅ | ✅ | ✅ (read-only, same continuity rationale) | N/A (already has full access to their own project) | Deferred |

**Centralised, reusable checks** (still not Policy classes — private
methods on the new controller, following the exact shape of
`AppointmentController::authorizeView()`/`authorizeManage()`):

```php
// ConsultancyOperationsController
private function authorizeOperatorAccess(Request $request, Appointment $appointment): void
    // Super Admin: any. Admin: ANY consultation, platform-wide — this is
    // the confirmed, intentionally-broader-than-Appointments read rule.
    // (No Client/public caller ever reaches this controller at all.)
private function authorizeOperatorManage(Request $request, Appointment $appointment): void
    // Super Admin: any. Admin: only if assigned_user_id === self — an
    // unassigned Admin fails this check even though authorizeOperatorAccess()
    // already let them read the same record.
private function requireSuperAdmin(Request $request): void
    // reopen(), reassign (delegates to the existing AppointmentController rule).
```

**Future Consultant role — explicitly deferred, not implemented in C2.**
C2 continues using the existing Super Admin/Admin roles as consultants,
exactly as C1 already does. This is a firm product decision for this
phase, not an open question: the architecture (assignment via
`assigned_user_id`, the presenter's capability-flag design in §8, the
authorization checks above) is deliberately written so that introducing a
narrower future "Consultant" role later requires **adding a new branch to
existing checks, not restructuring them** — e.g.
`authorizeOperatorManage()` would gain "OR (`hasRole('Consultant')` AND
assigned)" alongside its existing Admin branch, and the presenter's
capability flags would simply be computed the same way for that role.
No RBAC rework is anticipated, but the exact shape (a role scoped to
Consultancy surfaces only, functionally narrower than Admin) is not built
or finalised now — see the Decision Register.

---

## 8. `ConsultationPresenter` — the customer/operator field boundary

Mirrors `AiAnalysisPresenter`/`BillingPresenter` exactly (no
`app/Http/Resources` layer exists in this codebase — this is the
established substitute). Three response shapes, matching the three real
audiences (public is out of scope for new fields in C2 — see below):

```php
App\Support\Consultancy\ConsultationPresenter

  customerFacing(Appointment $appointment): array
      // reference, status (Appointments' own scheduling status),
      // starts_at/ends_at, booking_timezone, service display name,
      // the enquiry fields the customer themselves submitted,
      // assigned consultant's name (already shown in C1's frontend;
      // not new here), customer_summary_published (only if
      // published_at is set, else omitted entirely — never an empty
      // string standing in for "not yet published").
      //
      // Categorically excludes: internal_notes, customer_summary_draft,
      // customer_summary_needs_republish, engagement_status (an
      // operational/internal vocabulary — never surfaced raw to a
      // customer, mirroring AiCreditWorkflowLifecycle's "customer-safe
      // message" discipline), and any activity-log detail.

  operator(Appointment $appointment, bool $canEdit): array
      // The single operator data shape — internal_notes, engagement_status,
      // customer_summary_draft, customer_summary_needs_republish, publish
      // attribution fields, organisation/attendee contact detail, linked
      // project reference, and a recent-activity excerpt.
      //
      // Assigned Admin and Super Admin, and an unassigned Admin, all
      // receive the IDENTICAL field set — the confirmed visibility rule
      // (§7) is that an unassigned Admin sees the same data for
      // operational continuity, just cannot write it. The presentation
      // layer's only job here is to also compute and attach a
      // `permissions` block:
      //
      //   'permissions' => [
      //       'can_edit_notes'          => $canEdit,
      //       'can_publish_summary'     => $canEdit,
      //       'can_change_status'       => $canEdit,
      //       'can_link_project'        => $canEdit,
      //       'can_reassign'            => $isSuperAdmin,   // always Super-Admin-only
      //       'can_reopen'              => $isSuperAdmin,   // always Super-Admin-only
      //   ]
      //
      // $canEdit is computed by the CALLING CONTROLLER from the same
      // authorizeOperatorManage() check that gates every write endpoint —
      // never re-derived independently inside the presenter, so the
      // displayed capability flags and the actual enforcement can never
      // drift apart. This `permissions` block is a presentation
      // convenience for the frontend (so it doesn't need to reimplement
      // the assignment/role logic to decide what to render) — it is NOT
      // itself a security boundary. The security boundary is
      // `authorizeOperatorManage()` on each write endpoint, which every
      // write request re-checks independently of what this block said.
```

There is **no separate "read-only operator" data-shape method** — an
unassigned Admin calling the same `GET` detail endpoint gets the exact same
`operator()` output as an assigned Admin, just with every `permissions.*`
flag `false`. This is deliberate: building a second, trimmed data shape for
"read-only operator" would risk exactly the kind of drift the
customer/internal split elsewhere in this document is designed to prevent
— two shapes to keep in sync instead of one shape plus a computed
capability block. `customerFacing()` remains fully separate and
categorically excludes every operator-only field regardless of any
`permissions` flag, matching `AiAnalysisPresenter`'s existing discipline
exactly.

Public (unauthenticated) responses are unchanged from C1's
`PublicConsultationController` — this presenter is not used there at all;
nothing in C2 adds a new public-facing field.

---

## 9. Notifications

Reuses existing infrastructure only — no new notification framework:

* **In-app** (`App\Models\SuresignNotification` via the existing
  `NotificationService`) for staff-facing events:
  * A new consultation is booked → notify the fixed-mode service's
    `default_assigned_user_id` if one resolved at booking time (§ C1),
    otherwise notify Super Admins generally (mirrors "needs triage/manual
    assignment" reality for manual-mode services, which is every seeded
    default today).
  * Consultation reassigned → notify the newly assigned consultant.
* **Email** (new `App\Services\Consultancy\ConsultationNotificationService`,
  reusing the existing generic `EmailNotificationService::sendDirect()`
  transport — no new email infrastructure, only new Consultancy-specific
  templates/trigger points, kept out of `AppointmentEmailService` per §0).
  **Two committed customer-facing notifications in C2** (confirmed —
  no longer a recommendation):
  * **Customer summary published** → the customer receives an email
    linking to their consultation detail page, where
    `customer_summary_published` is now visible.
  * **Engagement transitioned to `awaiting_customer`** → the customer
    receives a short, plain operational email — e.g. "Your consultant has
    requested additional information" / "Your consultation requires your
    response before it can continue." Deliberately not a messaging
    feature — no reply-in-app mechanism, no thread, just a notification
    that action is needed (the customer responds via whatever channel
    SureSign already directs them to, e.g. the existing support/contact
    path — no new communication channel is built for this in C2).
  * Consultation cancelled/rescheduled/confirmed continue to be handled
    entirely by the existing, unmodified `AppointmentEmailService` — C2
    adds no new email for these, since nothing about their *content* is
    Consultancy-specific.

Explicitly **not** built: Google Meet-related notification content
(no meeting link exists until C4), payment-related notification content
(no payment exists until C3).

---

## 10. API surface

```
# New: Consultant/operator surface (role:Super Admin|Admin — the existing
# platform-wide-role convention, matching the catalogue's own access level)
# No dashboard/summary-count endpoint — deferred until Batch 4+ (see Batch 3's
# revised scope note in §16); introduced once the operational workflow exists.
GET   /api/admin/consultancy/consultations                      # queue, filterable/paginated
GET   /api/admin/consultancy/consultations/{appointment}         # operator detail (operator() presenter, incl. `permissions` block)

# Batch 4 — explicit-intent endpoints, one per business action. Revised
# from this section's original sketch (a single generic PUT .../status
# endpoint): each EngagementLifecycleService method already represents one
# specific action, not a generic state mutation, so the API mirrors that
# 1:1 rather than reintroducing genericness at the HTTP layer. PUT for the
# two idempotent "save current value" actions (notes, draft); POST for
# every real state-changing action — matching this codebase's existing
# convention (Appointments uses PUT for a reschedule-style update and POST
# for /confirm, /cancel, /assign).
PUT   /api/admin/consultancy/consultations/{appointment}/notes
PUT   /api/admin/consultancy/consultations/{appointment}/summary                     # draft save
POST  /api/admin/consultancy/consultations/{appointment}/summary/publish             # also serves republish — same call
POST  /api/admin/consultancy/consultations/{appointment}/status/awaiting-customer
POST  /api/admin/consultancy/consultations/{appointment}/status/awaiting-consultant
POST  /api/admin/consultancy/consultations/{appointment}/status/complete
POST  /api/admin/consultancy/consultations/{appointment}/reopen                      # Super Admin only

PUT    /api/admin/consultancy/consultations/{appointment}/project          # link a project, or replace the existing link
DELETE /api/admin/consultancy/consultations/{appointment}/project          # unlink

# Batch 5 — the Project-side view, read-only, Consultancy-owned. Every
# Super Admin/Admin who can read via authorizeOperatorAccess() may call
# this; it is not restricted to the assigned Admin (matches Batch 3's
# platform-wide read rule, not Batch 4's narrower write rule).
GET   /api/admin/consultancy/projects/{project}/consultations

# Extended in Batch 5 — a narrowly-scoped `search` param (project name,
# code, client name only) added to the EXISTING endpoint, reusing its
# existing role/organisation scoping, status filter, and pagination
# rather than a second, Consultancy-specific search architecture.
GET   /api/projects                                                        # existing, ProjectController::index()

# Existing, unchanged
POST  /api/appointments/{appointment}/assign            # reassignment, Super Admin only (C1/Appointments)
GET   /api/consultations, GET /api/consultations/{appointment}, POST /api/consultations/{appointment}/cancel
      # customer-facing (C1) — now also returns customer_summary_published via ConsultationPresenter::customerFacing()
```

New prefix `/admin/consultancy/*` (distinct from the existing flat
`/consultancy-services` catalogue resource and `/consultations` customer
surface) — chosen to match this codebase's established `/admin/*`
namespacing for platform-operator surfaces (`/admin/pricing`,
`/admin/ai-telemetry`, etc.), and to avoid any repeat of the exact
method+URI collision class of bug C1 already found and documented in
`consultancy.md`.

### Services and controllers to build

```
App\Services\Consultancy\EngagementLifecycleService     (§2.8)
App\Support\Consultancy\ConsultationPresenter            (§8)
App\Services\Consultancy\ConsultationNotificationService (§9)
App\Observers\ConsultancyAppointmentObserver              (§2.5)
App\Http\Controllers\Api\ConsultancyOperationsController  (§10)
App\Jobs\SendConsultationEmailJob                          (§9 — mirrors SendAppointmentEmailJob's exact shape)

# One Form Request per business action (Batch 4) — deliberately not one
# shared request across unrelated operations, even where a given request's
# own validation is minimal today. This keeps every future expansion (e.g.
# a required note on MarkCompletedRequest) scoped to exactly the one
# action it affects.
App\Http\Requests\UpdateConsultationNotesRequest
App\Http\Requests\UpdateConsultationSummaryDraftRequest
App\Http\Requests\PublishConsultationSummaryRequest
App\Http\Requests\MarkAwaitingCustomerRequest
App\Http\Requests\MarkAwaitingConsultantRequest
App\Http\Requests\MarkConsultationCompletedRequest
App\Http\Requests\ReopenConsultationRequest

# Batch 5
App\Http\Requests\LinkConsultationProjectRequest
App\Http\Requests\UnlinkConsultationProjectRequest
```

No Policy classes, no API Resource classes, no new Event/Listener framework
beyond the single Eloquent model observer already justified in §2.5.

---

## 11. Frontend (design only — no code in this document)

* **`/admin/consultancy` dashboard — deferred.** Not built until the
  operational workflow itself exists (Batch 4+); no value in surfacing
  counts before consultants can act on anything. When it arrives: operational
  lists (Upcoming, Awaiting My Action, Awaiting Customer, Completed Today,
  Cancelled, Recently Updated) — no charts, matching this codebase's
  existing "prefer operational lists" convention for similar internal tools.
* **`/admin/consultancy/queue`** — the filterable/sortable/paginated queue,
  styled identically to the existing Appointments admin list
  (`/admin/appointments`) and the C1 catalogue table — same table
  component conventions, same filter-bar pattern, no new design language.
* **`/admin/consultancy/queue/{id}`** — the full consultant workspace:
  Overview, Customer, Organisation, Linked Project (read-only reference if
  terminal, per §6), Service, Timeline/Activity (from `ActivityLog`),
  Internal Notes (editor), Customer Summary (draft editor + publish action
  + "unpublished changes" indicator), Appointment Information (read-only,
  sourced from the existing Appointments detail fields). **Documents**,
  **Payments**, and **Meeting** sections appear only as clearly-labelled
  "coming in a future phase" placeholders — no functional UI behind them.
* **`/app/consultations/{id}`** (existing, C1) — gains the
  `customer_summary_published` display (already partially anticipated by
  C1's UI, which currently has no summary section at all yet) once
  present; never gains any internal-only field.

---

## 12. Testing strategy

* **State machine**: every valid transition in `EngagementLifecycleService`
  succeeds; every invalid one throws — including the specific
  contradiction cases named in this document (manual transition to
  `cancelled` rejected; manual transition to `completed` via the wrong
  method rejected; reopen attempted by non-Super-Admin rejected; reopen
  attempted on a `cancelled` engagement rejected).
* **Cancellation sync**: a cancellation through *each* of the three real
  entry points (`AppointmentController::cancel()`,
  `ConsultationController::cancel()`, the public signed-link cancel) all
  correctly flip `engagement_status` to `cancelled` via the observer — one
  test per entry point, not just one, since the whole point of the
  observer design is entry-point-agnostic coverage.
* **Presenter**: `ConsultationPresenter::customerFacing()` never includes
  `internal_notes`/`engagement_status`/draft-summary fields under any
  input, mirroring the existing `AiAnalysisPresenter` regression-test
  shape exactly. Separately: `operator()` returns identical field content
  for an assigned Admin, a Super Admin, and an unassigned Admin — only the
  `permissions` block differs (all `false` for the unassigned Admin,
  except where Super-Admin-only actions are also correctly `false` for a
  merely-assigned Admin).
* **Backend-enforced read-only, independent of the frontend**: an
  unassigned Admin's direct API call to every write endpoint (`notes`,
  `summary`, `summary/publish`, `status`, `project`, `reopen`) is rejected
  with 403 — one test per endpoint, added in the same batch that
  introduces the endpoint (see the implementation plan), never deferred to
  a later "add security tests" pass.
* **Notes/summary audit logging**: an edit never stores the raw note/draft
  text in `ActivityLog.meta` — only length/hash fields.
* **Publishing workflow**: publish copies draft → published correctly;
  a subsequent draft edit sets `needs_republish = true` without altering
  `customer_summary_published`; republish clears the flag and updates the
  published copy; `markCompleted` fires exactly once per publish, not
  once per draft save.
* **Project linkage**: only `active`/`on_hold` projects from the caller's
  own organisation appear in the link picker; a `completed`/`cancelled`
  project cannot be newly linked; an existing link to a project that later
  became terminal remains readable but is rendered non-navigable.
* **Permissions**: the full matrix in §7, including that an Admin cannot
  act on a consultation assigned to someone else, and that only Super
  Admin can reopen.
* **Regression**: the full existing Appointments suite (159 tests as of
  C1) and the full C1 `ConsultancyPhase1Test` suite (19 tests) continue to
  pass unchanged — this phase adds a new observer and new tables/columns,
  never modifies existing Appointments or C1 Consultancy behaviour.

---

## 13. Documentation updates (this phase only)

* This document (new).
* `suresign-consultancy-specification-v1.md` — its brief C2 section is
  replaced with a short pointer stating this document is authoritative for
  C2 (see the accompanying edit).
* `internal-docs/super-admin/consultancy.md` — new C2 section following
  the same phase-by-phase style as its C1 section and `appointments.md`.
* `project-context.md` — a C2 entry, once built, following this
  codebase's standing per-phase entry convention.
* Help Centre (`docs/consultancy/overview.md`) — a short addition covering
  only what's newly customer-visible (the published summary appearing on
  their consultation), not any operator-facing content.

---

## 14. Risks

* **Observer coverage depends on Eloquent-level updates.** If any future
  code path updates `appointments.status` via a raw query builder call
  (bypassing the Eloquent model), the observer would silently miss it.
  Confirmed today that every real cancellation path
  (`AppointmentWorkflowService::transition()`, and both controllers calling
  it) already goes through Eloquent `Model::update()`, so this is a
  standing convention to preserve, not a currently-real gap — worth a code
  comment on the observer itself warning future maintainers.
* **A future "Consultant" role remains a design sketch, not a built
  mechanism** (confirmed deferred, not unresolved — see Decision Register
  item 8) — the permissions table in §7 documents how existing checks
  would extend to it, but introducing a module-scoped role may still
  require a small RBAC pattern decision (e.g. whether spatie roles can
  stay platform-wide-only or need a scoped variant) not resolved by this
  document.
* **String-typed `engagement_status` has no DB-level constraint** — a
  direct SQL `UPDATE` could write an invalid value. Mitigated the same way
  `Appointment::STATUSES` already is: application-level validation only,
  an accepted existing pattern in this codebase, not a new risk C2
  introduces.

---

## 15. Decision Register

### Decisions approved in this instruction (already settled, not open)

1. `engagement_status` is a separate field from `appointments.status`,
   never merges or replaces it.
2. No Laravel Policy classes; existing controller `authorize()` convention
   only.
3. A presenter (`ConsultationPresenter`), not API Resources.
4. No full version-history table for notes/summary; current-value fields
   plus `ActivityLog` with length/hash metadata only.
5. Customer summary publishing needs more than one field (draft +
   published + attribution + needs-republish), but not a version table.
6. Project linkage documented against the real Project states
   (`active`/`on_hold`/`completed`/`cancelled`) — no invented `archived`
   concept.
7. A dedicated C2 specification document, with the v1 document's brief C2
   section updated to point to it as authoritative.
8. **Future Consultant role: deferred, not implemented in C2.** C2
   continues using Super Admin/Admin as consultants, exactly as C1 does.
   The architecture (assignment via `assigned_user_id`, the presenter's
   `permissions` capability-flag design, the shape of
   `authorizeOperatorManage()`) is deliberately written so introducing this
   role later is an additive branch on existing checks, not a rework — but
   the role itself, its exact scope, and its RBAC mechanics are not
   designed or built now (§7).
9. **`awaiting_customer` triggers a committed customer-facing email
   notification in C2** — simple, operational, non-messaging copy (§9).
10. **Confirmed Admin visibility model** — four tiers: Super Admin (full),
    assigned Admin (full), unassigned Admin (read-only, platform-wide, on
    every operational field — enforced at the backend, not just hidden in
    the UI), Client (own consultation only). This is an intentional,
    Consultancy-specific divergence from `AppointmentController`'s own
    (unchanged, untouched) generic visibility rule, justified by the
    operational-continuity need documented in §7.
11. **Project linkage is same-organisation-only, enforced unconditionally,
    even for Super Admin** (§6) — found, not assumed, by tracing a
    concrete cross-organisation disclosure the new Project-side read
    endpoint would otherwise create.
12. **Customer-proposed project linkage (v1 spec's product-decision #3)
    is explicitly deferred, not rejected.** Batch 5 is operator-managed
    linkage only. A future decision would need to resolve: does a Client
    select from their own organisation's projects at booking time, and
    does `ConsultationPresenter::customerFacing()` then need to expose
    project info at all (it categorically does not today)? Not answered
    here — recorded so this old sketch is never mistaken for settled
    scope.

### Recommendations made by this specification (reasonable defaults, not yet separately approved)

* The four-value minimal state set in §2.2 (dropping New/Assigned/
  Scheduled/In Progress/Closed from the illustrative eight).
* `engagement_status` living on `consultation_enquiries`, and
  `internal_notes` moving to a new `consultation_enquiries` column rather
  than continuing to reuse the generic `appointments.internal_notes` (a
  refinement over the C1 v1 document's original placeholder).
* The length/hash-only `ActivityLog` metadata discipline for notes/summary
  edits (§3, §4).
* The single `operator()` presenter shape plus a computed `permissions`
  block, rather than a second trimmed "read-only operator" data shape (§8).
* The `/admin/consultancy/*` route prefix and the four new frontend pages
  named in §11.

### Genuinely unresolved product decisions requiring approval before implementation

None remain from this specification pass — all three items previously
listed here (future Consultant role, `awaiting_customer` notification,
Admin visibility model) were resolved by explicit user confirmation and
are recorded as decisions 8–10 above. Any new open question raised during
implementation should be added here, not silently resolved in code.

### Deferred C3+ concerns that must not leak into C2

* Any payment/refund/Stripe object, or a `consultation_payments` table.
* Google Meet/Calendar integration, `MeetingProviderInterface`, or any
  `meeting_url` population beyond what C1 already leaves untouched.
* Document uploads, `consultation_documents`, or any file attached to a
  consultation.
* Any reporting/analytics service, dashboard chart, or AI-generated
  summary — the customer summary in this phase is entirely
  consultant-authored free text, never machine-generated.
* A `closed` engagement status (§2.6) — revisit only if a genuine future
  need (e.g. a C3 invoicing prerequisite) produces real distinguishing
  behaviour for it.

---

## 16. Implementation plan — batches

Mirrors how Appointments (Phases 1 → 2 → 2.1 → 3 → 4) and AI Credits
(G4C.1 → … → G4C.3I) were sequenced: each batch is independently
deployable, independently testable, and adds nothing from a later batch.
No batch depends on any C3+ work.

**The broader Admin read-only visibility rule (§7) is introduced in Batch
3, and its security tests ship in that same batch** — not deferred to a
later UI/polish batch. Batch 4 (the first batch with real write actions)
correspondingly ships the "unassigned Admin gets 403 on every write
endpoint" tests alongside each endpoint as it's built, for the same
reason: a security-relevant boundary's tests are never separated in time
from the code that creates the boundary.

### Batch 1 — Engagement lifecycle foundation (no API, no UI)

**Migrations**: the seven `consultation_enquiries` columns (§1.1) +
the `engagement_status` backfill (§1.5).

**Services**: `EngagementLifecycleService` (§2.8) with its `TRANSITIONS`
map and all four methods; `App\Observers\ConsultancyAppointmentObserver`,
registered in `AppServiceProvider`/`EventServiceProvider`.

**Controllers/UI**: none. Nothing yet calls any of this.

**Tests**:
- Every valid/invalid `EngagementLifecycleService` transition (§2.2–§2.7),
  including the specific contradiction cases (manual → `cancelled`
  rejected; manual → `completed` via the wrong method rejected; reopen by
  non-Super-Admin rejected; reopen of a `cancelled` engagement rejected).
- The observer correctly flips `engagement_status` to `cancelled` when a
  **pre-existing** C1 consultation is cancelled through each of the three
  real entry points (`AppointmentController::cancel()`,
  `ConsultationController::cancel()`, the public signed-link cancel) — all
  three are already fully built from C1, so this batch can test the
  observer against real, unmodified endpoints immediately.
- Backfill migration produces the correct `engagement_status` for each of
  the three real C1 status categories (cancelled/completed/everything
  else).

**Documentation**: none yet (batch is invisible to any user).

**Completion criteria**: migration runs cleanly against a copy of
production data with zero errors; full existing regression suite (159
Appointments + 19 C1 Consultancy tests) still passes unchanged; the new
tests above pass. **Safe to deploy on its own** — additive schema, and an
observer that only ever acts on a column no reader or writer outside this
batch touches yet.

### Batch 2 — Customer-facing presenter wiring (no admin surface yet)

**Services**: `ConsultationPresenter::customerFacing()` only (§8) — the
`operator()` method is not built yet, since nothing calls it until Batch 3.

**Controllers**: `ConsultationController::show()`/`index()`/`store()`
(existing, C1) now return `ConsultationPresenter::customerFacing()`'s
shape instead of a raw Eloquent `->load()` dump — the first real caller of
the presenter.

**UI**: `/app/consultations/{id}` gains the (currently always-empty, since
nothing publishes yet) `customer_summary_published` display slot — no
functional change visible to a real user yet, since no consultation has a
published summary.

**Tests**: `customerFacing()` never includes `internal_notes`/
`engagement_status`/draft-summary fields under any input (the presenter
regression test, buildable now even before any writer for those fields
exists — the test asserts absence, not a specific non-empty value).
Existing C1 `ConsultationController` tests continue to pass against the
new response shape (update any test asserting the old raw shape).

**Documentation**: none yet.

**Completion criteria**: no behavioural change visible to a real customer
(the new field is always absent/null today); all C1 tests pass against the
new presenter-shaped response. **Safe to deploy independently.**

### Batch 3 — Consultant queue + read-only operator detail (introduces the broader Admin visibility rule)

**Revised scope (intentional refinement, recorded here so the plan stays
aligned with what was actually built)**: the dashboard/summary-counts
surface originally sketched for this batch is **not built here**. There is
little value in surfacing operational counts before consultants can
actually perform operational actions — dashboard-style summaries are
introduced once the operational workflow itself exists (Batch 4+). Batch 3
is strictly: operator queue, read-only operator detail, the operator
presentation boundary, and the confirmed Consultancy-specific Admin
visibility rule. Nothing more.

**Migrations**: none new.

**Services**: `ConsultationPresenter::operator()` (§8), including the
`permissions` block (all flags computed correctly even though no write
endpoint exists yet to act on them — informative only in this batch, never
implying a write capability that doesn't exist).

**Controllers**: new `ConsultancyOperationsController` —
`index()` (queue: searchable, filterable by `engagement_status`/appointment
`status`/`assigned_user_id`/`consultancy_service_id`, sortable against an
explicit column whitelist, paginated) and `show()` (operator detail).
**Read-only in this batch — no write action exists yet, and no
dashboard/summary-count endpoint exists yet either.**
`authorizeOperatorAccess()` implemented here: Super Admin or **any** Admin,
platform-wide (§7's confirmed rule). `authorizeOperatorManage()` is also
written in this batch (not deferred to Batch 4) purely so `operator()`'s
`permissions` block can compute real capability flags now — no route calls
it yet.

**Routes**: `GET /admin/consultancy/consultations`,
`GET /admin/consultancy/consultations/{appointment}` — both
`role:Super Admin|Admin`. No dashboard route in this batch.

**UI**: `/admin/consultancy/queue` (list), `/admin/consultancy/queue/{id}`
(detail — Overview, Customer, Organisation, Service, Appointment, Enquiry,
Internal Notes **display-only**, Customer Summary **display-only**,
Activity). No dashboard page in this batch. No edit affordance anywhere
yet — there's nothing to write to.

**Tests (the batch this document's instruction specifically calls out)**:
- Super Admin can view any consultation via this controller.
- An **assigned** Admin can view their own.
- An **unassigned** Admin can view **any** consultation, including one
  assigned to a different specific Admin — the confirmed broader rule,
  tested explicitly and distinctly from "can view unassigned ones," which
  is a strictly narrower claim.
- A Client role gets 403 from every route on this controller (defense in
  depth — Client is never meant to reach it at all; it uses the existing
  `ConsultationController` instead).
- The `permissions` block in `operator()`'s response is `false` across the
  board for an unassigned Admin, and correctly `true`/`false` per-action
  for an assigned Admin vs. Super Admin (e.g. `can_reassign`/`can_reopen`
  only `true` for Super Admin).

**Documentation**: `consultancy.md` gains a C2 section covering the
dashboard/queue/read-only detail view and the confirmed visibility model.

**Completion criteria**: every test above passes; a Super Admin/Admin can
browse the full consultant queue and read (but not yet edit) any
consultation's full operator detail in the real running application.
**Safe to deploy independently** — purely additive read surface, zero
write capability exists to misuse even if a permission check had a bug
(defense in depth by construction, not just by test coverage).

### Batch 4 — Operational write actions

**Migrations**: none new.

**Services**: `authorizeOperatorManage()` added to
`ConsultancyOperationsController`;
`App\Services\Consultancy\ConsultationNotificationService` (§9).

**Controllers**: new endpoints on `ConsultancyOperationsController` — one
method per explicit business action (not one generic
"update-and-branch-on-a-field" method): `updateNotes()`,
`updateSummaryDraft()`, `publishSummary()`, `markAwaitingCustomer()`,
`markAwaitingConsultant()`, `markCompleted()`, `reopen()` (Super Admin
only). Each calls `EngagementLifecycleService`/
`ConsultationNotificationService` — no business logic duplicated in the
controller. One Form Request per action (§10's services-to-build list) —
even where a given request's own validation is minimal today, this keeps
every future expansion scoped to exactly the one action it affects.

**Routes** (see §10 for the full explicit-intent rationale):
```
PUT   /admin/consultancy/consultations/{appointment}/notes
PUT   /admin/consultancy/consultations/{appointment}/summary
POST  /admin/consultancy/consultations/{appointment}/summary/publish
POST  /admin/consultancy/consultations/{appointment}/status/awaiting-customer
POST  /admin/consultancy/consultations/{appointment}/status/awaiting-consultant
POST  /admin/consultancy/consultations/{appointment}/status/complete
POST  /admin/consultancy/consultations/{appointment}/reopen
```

**UI**: the queue detail view (Batch 3) gains real edit affordances —
internal notes editor, customer summary draft editor + Publish button +
"unpublished changes" indicator, engagement-status control, a Super-Admin-
only Reopen button — **all conditionally rendered from the `permissions`
block already shipped in Batch 3**, so an unassigned Admin simply doesn't
see edit controls (a UX nicety) while the backend enforces the same
boundary independently (the actual security guarantee).

**Tests**:
- Every endpoint succeeds for Super Admin and for the assigned Admin.
- **Every endpoint rejects an unassigned Admin with 403** — one test per
  endpoint, added in this same batch (this is the "write-side" half of the
  read/write security-test pairing this plan calls out explicitly; the
  read-side half already shipped in Batch 3).
- `publishSummary()` correctly triggers `markCompleted(viaSummaryPublish:
  true)` and the customer-facing "summary published" email exactly once
  per publish, not once per draft save.
- `markAwaitingCustomer()` triggers the customer-facing "action needed"
  email exactly once per genuine transition — repeating the same request,
  a failed transition, or an unauthorised request must never send a
  second email (see §2.8's own transition-rejection guarantee).
- `reopen()` succeeds only for Super Admin, only from `completed`, and
  correctly preserves `customer_summary_published` (§2.7).
- `ActivityLog` entries for every action never contain raw note/summary
  text — length/hash fields only (§3, §4).

**Documentation**: `consultancy.md` C2 section extended with the full
operational write surface; Help Centre gets the short customer-visible
addition (published summary appearing on their consultation).

**Completion criteria**: a consultant can run a real engagement end to
end — receive a booking, add notes, publish a summary, mark completed —
entirely through this batch's endpoints and UI, with the customer
receiving both new email notifications correctly. **Safe to deploy
independently** — Batch 3's read surface already proved the visibility
model; this batch only adds gated writes on top of it.

### Batch 5 — Project linkage (operator-managed only — see §6 for the deferred customer-initiated variant)

**Migrations**: none new (reuses `appointments.project_id`, §1.4).

**Model relationships**: `Appointment::project()` already exists (C1) —
reused unmodified. New: `Project::appointments()`, a plain, generically
named inverse `hasMany` — deliberately not `consultations()`; the Project
model stays unaware Consultancy exists as a concept, per §6.

**Services/controllers**:
* `ProjectController::index()` extended with a narrowly-scoped `search`
  param (project name, code, client name only) — reuses the endpoint's
  existing role/organisation scoping, `status` filter, and pagination
  entirely unchanged; the new `OR` conditions are grouped inside their own
  closure so they can never widen what the existing scope already
  permits. No other change to `ProjectController`.
* `ConsultancyOperationsController` gains `linkProject()`/`unlinkProject()`
  (§6's exact execution order: authorize → validate the project →
  same-organisation check → idempotency check → persist+log) and
  `projectConsultations()` (the new Project-side read endpoint, gated by
  `authorizeOperatorAccess()` — platform-wide read, matching Batch 3, not
  the narrower write rule).

**Routes**: see §10 for the full list —
`PUT`/`DELETE .../consultations/{appointment}/project` and
`GET /admin/consultancy/projects/{project}/consultations`.

**UI**: a Linked Project card in the operator workspace (picker via the
extended `GET /projects?search=...`, Change/Remove actions with
confirmation, "Open Project" once linked); a lightweight Consultancy
summary card on the existing `/app/projects/[id]` workspace (no separate
admin project detail page exists or is built) — empty state when nothing
is linked, "Open Consultation" per row, no editing surface there at all.

**Tests**: the full matrix in this batch's own instruction — authorization
(Super Admin/assigned Admin can link; unassigned Admin/Client 403),
same-organisation validation (success within org, 422 across orgs, 422 for
a soft-deleted project), idempotent re-link (200, no write, no activity
event, `updated_at` unchanged where the model allows verifying that),
search (name/code/client-name matches, no-match, pagination, `status`+`search`
composing, `organization_id`+`search` composing, an Admin searching across
permitted organisations, a Client unable to discover projects outside
their existing scope), and both presenters' whitelists.

**Documentation**: `consultancy.md` gains the Batch 5 section (linkage
workflow, the confirmed organisation rule, search extension, both
frontend surfaces) and records explicitly that customer-proposed linkage
remains a deferred, undecided idea, not something this batch rejected.

**Completion criteria**: an operator can search for, link, change, and
unlink a project on a consultation, entirely within the same
organisation; a Client viewing their own project sees a correct,
read-only Consultancy summary for any consultation genuinely linked to
it; all listed tests pass. **Safe to deploy independently.**

### Batch 6 — Dashboard completeness, production polish, and final regression

Split into two independently reviewable/deployable units per the user's
explicit direction: 6A is the one remaining functional addition; 6B is
production hardening only, with functionality frozen once 6A lands.

#### Batch 6A — Operator Dashboard

**Migrations**: none — an explicit constraint, not an oversight. The
`attention` ageing metric is derived entirely from the existing
`consultation.engagement_status_changed` `ActivityLog` trail (the most
recent entry per consultation whose recorded `to` is `awaiting_customer`),
never from `updated_at` (also touched by unrelated edits — notes, summary
draft — and would silently understate age) and never from an invented
timestamp. A consultation currently `awaiting_customer` with no matching
ActivityLog row (older/migrated data) is placed in a dedicated
`awaiting_customer_unknown_age` bucket.

**Services/controllers**: no new service class (confirmed appropriate at
this scope — revisit only if this area grows substantially, e.g.
automatic project suggestions or multi-project relationships). One new
read-only, aggregate-only method: `ConsultancyOperationsController::dashboardSummary()`
→ `GET /admin/consultancy/dashboard`, gated by the existing
`authorizeOperatorAccess()` (identical platform-wide-read rule as the
queue — a caller can never see a dashboard count for a record they
couldn't open in the queue). Two private helpers extracted for reuse
between the dashboard and the queue's own new quick-link filters (see
below): `awaitingCustomerAgeingByAppointmentId()` (the single source of
truth for the bucket-per-appointment map) and
`overdueAwaitingCustomerAppointmentIds()`.

**Response contract** (as approved):
```
{
  "totals":    { "all", "awaiting_consultant", "awaiting_customer", "completed", "cancelled", "unassigned" },
  "attention": { "awaiting_customer_under_3_days", "awaiting_customer_3_to_7_days",
                 "awaiting_customer_over_7_days", "awaiting_customer_unknown_age" },
  "recent":    { "created_last_7_days", "completed_last_7_days" }
}
```
`recent.completed_last_7_days` is likewise ActivityLog-derived (never
`updated_at`), `DISTINCT`-ed on `subject_id` so a completed → reopened →
completed-again engagement within the window counts once.

**Queue quick-link filters (added to the existing `index()`, not a second
query surface)**: `unassigned=1` (`whereNull('assigned_user_id')`) and
`overdue_awaiting_customer=1` (delegates to the same
`overdueAwaitingCustomerAppointmentIds()` helper the dashboard uses, so the
two surfaces can never disagree on what "overdue" means) — mirrors Batch
5's own precedent of extending an existing endpoint rather than building a
new one.

**UI**: `/admin/consultancy/dashboard` — stat cards (totals + quick
links), an "Awaiting Customer — Ageing" attention panel, and "Last 7
Days"/"All Time" cards. Chart-free, matching the AI Usage & Cost
dashboard's own established stat-card language (verified before building,
not assumed) — no charting library, no trend analytics. The sidebar's
"Consultancy" entry now points here (was `/admin/consultancy/queue`); the
queue and Services catalogue remain one click away. The queue page
(`/admin/consultancy/queue`) gained `useSearchParams()`-based initial
filter state so the dashboard's `?engagement_status=`/`?unassigned=1`/
`?overdue_awaiting_customer=1` links actually pre-filter on load (they
previously would not have — the queue never read its own URL on mount
until this batch), plus a clearable pill for the two deep-link-only
filters.

**Tests**: full authorization/correctness coverage — totals across
statuses/assignment/organisations (platform-wide, matching queue
visibility), ageing bucket correctness (including the legacy/no-matching-event
case, and a case proving ageing ignores `updated_at`), `recent` metric
correctness and de-duplication, the two new queue filters agreeing with
the dashboard's own counts, and an exact response-shape assertion.

**Completion criteria**: an operator can load one page and know what needs
attention and where to click next; all listed tests pass; no schema
change introduced. **Safe to deploy independently of 6B.**

#### Batch 6B — Production Polish & Readiness

Functionality frozen entering this unit — see the user's own detailed
scope breakdown (UX consistency, loading/empty/error states, accessibility,
responsive review, performance review, code cleanup, documentation review,
onboarding/tours, production readiness review, final regression). Not yet
started as of this document's Batch 6A entry.

**Migrations**: none expected.

**Services/controllers**: none new — this unit is refinement, not new
capability.

**Tests**: none new expected beyond regression — this unit is about
running the *existing* full suite and fixing anything the polish pass's UI
changes might have broken, exactly like C1's own closing polish pass.

**Documentation**: final `consultancy.md` C2 section pass for accuracy;
`project-context.md` entry recording the whole phase; confirm the Help
Centre addition reads correctly end-to-end.

**Completion criteria**: identical bar to C1's own freeze — `tsc`/`eslint`/
`next build` clean in both frontend apps, full backend suite green,
production-polish checklist complete. This is the unit after which Phase
C2 can be formally frozen, mirroring exactly how C1 was frozen.
