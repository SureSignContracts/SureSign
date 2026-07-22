# Appointments & Scheduling — Phases 1–4 (Foundation, Staff Availability, Public Booking, Communications)

`/admin/appointments` is SureSign's own scheduling module for sales demos,
onboarding, product walkthroughs, training, support consultations, and
account reviews. `/book/{slug}` on the marketing site (suresigncontracts.app)
is its public, unauthenticated booking counterpart. Together they are a
platform administration + lead-capture module — not a generic calendar
product and not a rebrand of any third-party scheduling tool.

This covers **Phase 1 (Foundation), Phase 2 (Staff Availability & Internal
Scheduling), Phase 2.1 (Scheduling Integrity Hardening), Phase 3 (Public
Booking), and Phase 4 backend (Communications & Appointment Experience —
confirmation/reminder emails, signed cancel/reschedule links, ICS)**. The
Phase 4 marketing-site confirmation pages are not built yet — the backend
they'll call is complete. Calendar-provider integrations (Google/Outlook/
Teams/Zoom), round-robin assignment, and dashboards/reporting are all
deferred to later phases and do not exist yet — see
[Deferred to later phases](#deferred-to-later-phases).

## Relationship to project Meetings

Appointments is a completely separate domain from the existing project
Meetings feature (`MeetingMinutes`, `/app/projects/{id}/meetings`):

| | Meetings | Appointments |
|---|---|---|
| Scope | Project-scoped internal minutes record | Platform-wide staff/customer scheduling |
| Attendees | Free-text name labels only | Real identity: name, email, phone |
| Access | Client + Admin/Super Admin on a project | Super Admin + Admin only (no Client access) |

There is no shared table and no inheritance between the two. Both reuse the
same underlying timezone pattern (`TimezoneResolver`, UTC instant +
IANA-zone column pair) but are otherwise independent — see
`CLAUDE.md`'s Timezone & Scheduling Architecture section for the shared
primitives.

## Appointment Types

Appointment Types are database-backed (`appointment_types` table), seeded
with: Book a Demo, Product Walkthrough, Customer Onboarding, Training
Session, Support Consultation, Account Review, General Enquiry
(`AppointmentTypeSeeder`). Each type carries its own duration, buffers
(`buffer_before_minutes`/`buffer_after_minutes` — enforced as of Phase 2),
notice/advance windows (`min_notice_hours`/`max_advance_days` — enforced as
of Phase 2), assignment mode (`fixed` or `manual` — round-robin/auto is not
supported), confirmation requirement, meeting method, and
cancellation/reschedule notice periods.

**Only Super Admin can create, edit, or delete Appointment Types** — this is
platform-wide configuration, per the approved architecture decision. Admin
users can read the list (needed to create appointments against a type) but
get a 403 from the API on any mutation, and the `/admin/appointments/types`
page redirects them away client-side as defense-in-depth. A Type's
`default_assigned_user_id` is a **Super-Admin-only convenience** — it is
ignored entirely when an Admin creates an appointment (see Permissions
below).

## Appointments

An appointment (`appointments` table) has a human-friendly reference
(`APT-000001`, generated atomically by `AppointmentReferenceService` against
a single global counter — the same lock-and-increment approach as
`DocumentNumberService`, just not project-scoped), a type, an optional
assigned staff user, attendee details (name/email/phone/company/timezone),
a UTC `starts_at`/`ends_at` pair plus the `booking_timezone` it was scheduled
in, and a status.

### Statuses

```
requested → pending_confirmation → confirmed → completed
                                 ↘ declined      ↘ no_show
requested/pending_confirmation/confirmed → cancelled
```

`AppointmentWorkflowService::TRANSITIONS` is the single source of truth for
which transitions are allowed; any other pair is rejected with a 422.
**Rescheduling is not a status** — moving an appointment's time is an event
(`AppointmentWorkflowService::reschedule()`) that updates `starts_at`/
`ends_at` in place while the current status is left untouched, logged to
`ActivityLog` as `appointment.rescheduled`.

`requested` and `pending_confirmation` are functionally identical everywhere
in the codebase (both are "active"/blocking statuses, both transition to
`confirmed`/`declined`/`cancelled`) — they differ only in label/intent.
Internal admin-created appointments never actually use `requested` (they're
created directly as `pending_confirmation` or `confirmed`, since a staff
member already reviewed the input by typing it in); **public bookings are
the one path that uses `requested`** — an unreviewed inbound request from
outside the platform — when the Appointment Type requires confirmation.

## Staff availability (Phase 2)

Three dedicated tables, deliberately kept separate rather than one JSON blob:

- **`appointment_availabilities`** — weekly recurring schedule. One row per
  window (`user_id`, `weekday` 0=Sunday..6=Saturday matching Carbon's
  `dayOfWeek`, `start_time`, `end_time`, `is_active`). Multiple rows per
  weekday support more than one window (e.g. 09:00–12:00 and 13:00–17:00).
- **`appointment_availability_overrides`** — date-specific. A given
  `local_date` is represented either by a single `is_unavailable=true` row
  (whole day off) or by one-or-more window rows — never both at once for
  the same date. **A date override fully replaces the weekly schedule for
  that date** — validated only against sibling override rows for the same
  date, never against the weekly schedule.
- **`appointment_blocked_periods`** — fixed UTC instants (`starts_at`/
  `ends_at` + the `timezone` active when created, `reason`,
  `created_by_user_id`). Represents leave, internal commitments, or any
  other manually blocked time; can span multiple days.

**Timezone rule (important):** weekly windows and date overrides are local
wall-clock time in the staff member's **current** effective IANA timezone
(`TimezoneResolver::effectiveTimezone()`), re-resolved fresh on every read —
they are never stored with their own timezone column. If a staff member's
effective timezone setting changes later, their stored "09:00–17:00" is
reinterpreted against the new timezone going forward. Blocked periods and
appointments, by contrast, are fixed UTC instants that do **not** move if
the timezone setting changes later (the `timezone` column on blocked
periods is kept only so the original local time can be redisplayed
correctly afterwards, the same rationale as `MeetingMinutes.scheduled_timezone`).

DST is handled entirely through the existing `TimezoneResolver::buildLocalInstant()`
— a spring-forward gap (a local time that doesn't exist) is rejected with a
422; a fall-back ambiguous local time (occurs twice) resolves to its first
occurrence, per `TimezoneResolver`'s existing documented behaviour. Nothing
new was introduced for DST — Phase 2 relies entirely on the same helper
Meetings already uses.

### Availability resolution (`AppointmentAvailabilityService`)

Validation here splits into two tiers, on two different methods:

- **`assertTypeBookable()`** — Appointment Type business rules: minimum
  notice (`type.min_notice_hours`) and maximum advance window
  (`type.max_advance_days`). These are properties of the *type*, not of a
  staff member's calendar, so they apply **regardless of whether a staff
  member is assigned yet** — `AppointmentSchedulingService::withConflictCheck()`
  calls this unconditionally (staff-assigned or not), unless a Super Admin
  override is in effect. When there's no assigned staff member (and often
  no organisation either — e.g. a public booking with no prospect
  organisation on file), the timezone these are evaluated in falls back
  through the existing `TimezoneResolver` user→organisation→platform chain
  with both arguments `null` — no new timezone rule was introduced.
- **`assertBookable()`** — everything else: calls `assertTypeBookable()`
  first, then checks weekly/override window containment for that local
  date (override takes full precedence when any override rows exist) and
  blocked periods (fixed UTC overlap check). This only ever runs when a
  staff member is assigned, since there's no calendar to check otherwise.

**Fixed in the production-readiness pass**: prior to this, the
notice/advance checks lived only inside `assertBookable()`, so they were
silently skipped for every *unassigned* appointment — which is the default
outcome for every seeded Appointment Type (`assignment_mode` defaults to
`manual`), including the public `Book a Demo` flow. A visitor could submit
a booking for a date in the past or years in the future with no server-side
rejection. `assertTypeBookable()` is now the single place these two rules
live, called by both the staff-assigned and unassigned paths, so there is
no duplicated validation logic between them.

Neither `assertTypeBookable()` nor `assertBookable()` includes same-staff
appointment overlap or buffered (buffer-expanded) interval conflict — both
of those are enforced separately and unconditionally by
`AppointmentSchedulingService` and are **never** overridable (Phase 2.1 —
see below; Phase 2 originally bundled buffer conflicts into this
overridable path, which was a bug, fixed in 2.1).

### Conflict prevention: buffered intervals (Phase 2.1)

`AppointmentSchedulingService` is the single authoritative source for
same-staff conflict rules, used identically by create, reschedule, assign,
and the `check-availability` preview — there is exactly one conflict
calculation, not one per endpoint.

Every appointment's **effective interval** is:

```
effective_start = starts_at - appointment_type.buffer_before_minutes
effective_end   = ends_at   + appointment_type.buffer_after_minutes
```

computed using **each appointment's own Appointment Type** — the proposed
appointment and every existing appointment it's compared against may use
different types with different buffer values, and each side's own buffers
apply to that side only. A conflict exists when

```
proposed_effective_start < existing_effective_end
AND
proposed_effective_end   > existing_effective_start
```

Half-open interval semantics: exact boundary contact (one appointment's
effective end equals another's effective start) is explicitly **allowed**,
never a conflict — only genuine overlap of the effective intervals is
rejected.

**Bug fixed in Phase 2.1:** the original Phase 2 implementation only
expanded the *proposed* appointment's interval by its own type's buffers,
then compared it against existing appointments' raw stored
`starts_at`/`ends_at` — ignoring each existing appointment's own buffer
requirement entirely. That allowed a new booking to land inside another
appointment's required pre/post buffer whenever the *existing* appointment
(not the proposed one) was the side carrying the buffer. `hasBufferedConflict()`
now computes both sides symmetrically.

### Conflict prevention & the Super Admin override

`AppointmentSchedulingService::withConflictCheck()` runs inside one DB
transaction with the assigned staff member's relevant existing appointments
locked (`lockForUpdate()`, over a range wide enough to cover the
buffer-widened candidate set), so two concurrent requests can't both pass
validation and double-book:

1. Same-staff raw time overlap — **never overridable**, checked unconditionally.
2. Buffered (effective-interval) conflict — **never overridable**, checked
   unconditionally, regardless of `override`.
3. `AppointmentAvailabilityService::assertBookable()` — **skipped entirely**
   when a Super Admin supplies `override: true` + a required
   `override_reason`. Admin can never set `override`.

Every override use is logged via `ActivityLog` (`appointment.availability_override_used`,
recording the context — create/reschedule/assign — and the reason) — there
is no new column on `appointments` for this, by design (keep this minimal).

An unassigned appointment (Super-Admin-only — see Permissions) skips the
staff-availability checks (weekly/override/blocked periods) above; there is
no staff member to validate against. It does **not** skip
`assertTypeBookable()`'s notice/advance rules, which apply unconditionally
unless overridden — see the fix note above. **When a previously-unassigned
appointment is later assigned via `/assign`, it must pass the full
validation chain at that moment** (raw overlap + buffered conflict +
availability, using the appointment's already-stored time and both
appointments' own buffers) — assignment is not a free pass.

### Permissions

| Action | Super Admin | Admin |
|---|---|---|
| View all appointments | ✅ | Only appointments assigned to them + unassigned ones |
| Create an appointment | ✅ (any assignee, or unassigned) | ✅, but **always** assigned to themselves — cannot leave unassigned, cannot assign to anyone else |
| Edit / confirm / decline / cancel / complete / no-show | ✅ (any) | Only their own assigned appointments |
| Reassign an appointment (`/assign`), or remove an assignee | ✅ | ❌ (403) |
| Delete an appointment | ✅ | ❌ (403) |
| Manage Appointment Types | ✅ | ❌ (403, read-only) |
| Use the scheduling override | ✅ (reason required) | ❌ (403) |
| View/manage own availability (weekly, overrides, blocked periods) | ✅ | ✅ (self only) |
| Preview availability (`check-availability`) | ✅ (any target) | ✅ (self only — see below) |
| View/manage another eligible staff member's availability | ✅ | ❌ (403) |

Client users have no access at all — the entire route group requires
`role:Super Admin|Admin` middleware. "Eligible staff" = an active,
non-banned user with the `Admin` or `Super Admin` role — the same
definition used for `/assign` in Phase 1, now shared by
`AppointmentAvailabilityService::isEligibleStaff()`.

**Fixed in the production-readiness pass**: `POST /appointments/check-availability`
previously accepted any `assigned_user_id` with no ownership check at all —
an Admin could pass a colleague's user id and receive back availability
plus a rejection reason that named them (e.g. "has blocked time during this
period"), circumventing the "Admin cannot view another staff member's
availability" rule enforced everywhere else in the module
(`AppointmentAvailabilityController::resolveTarget()`). The endpoint now
applies the identical rule: Admin may only pass their own id (or omit it
entirely — the unassigned-preview branch needs no target and is unaffected);
Super Admin may pass any id; a mismatched target is rejected with the same
`403 Access denied.` response the rest of the controller already uses, before
any availability data is computed.

## Public booking (Phase 3)

`/book/{slug}` on the marketing site (`marketing/src/app/book/[slug]`) is
the public, unauthenticated counterpart to `/admin/appointments`. It only
ever exposes an Appointment Type with `is_public=true` **and**
`is_active=true` — a non-public or inactive slug (or one that doesn't
exist) returns the exact same generic 404, so a public caller can't
distinguish "doesn't exist" from "exists but is private."

### Scheduling mode: fixed vs manual

A public Appointment Type is handled one of two ways, driven entirely by
its existing `assignment_mode`/`default_assigned_user_id` fields — no new
schema was added for this:

- **`fixed`** (with an eligible `default_assigned_user_id`): the visitor is
  shown **real available time slots**, generated by
  `AppointmentSchedulingService::generateAvailableSlots()` against that
  specific staff member's actual weekly schedule, date overrides, blocked
  periods, and every other active appointment (buffers included) — the
  exact same `isSlotFree()`/`hasBufferedConflict()`/`assertBookable()` rules
  a real booking is checked against, at a fixed 15-minute step granularity.
  There is no separate, looser rule set for what's offered vs. what's
  actually bookable.
- **`manual`** (or a `fixed` type whose configured assignee has since
  become ineligible — e.g. deactivated): no staff-specific slot list is
  generated at all; the visitor instead proposes a date/time (validated
  only against the type's own notice/advance window), and the appointment
  is created **unassigned**, exactly like a Super-Admin-created unassigned
  appointment in Phase 1/2 — an Admin/Super Admin assigns a real staff
  member afterwards via the existing `/assign` action, which then runs the
  full validation chain against that appointment's already-stored time.

### Security

- Generic 404 for any non-bookable/nonexistent slug (no enumeration).
- Two named rate limiters, IP-keyed only (no authenticated user to key on):
  `public-booking-read` (30/15 min — type info + slot lookups as a visitor
  browses dates) and `public-booking` (5/15 min — the actual booking
  submission), mirroring the existing `demo-request` limiter's rationale.
- A hidden honeypot field (`website`) — if a bot fills it, the endpoint
  returns a normal-looking success response without creating anything,
  rather than revealing that detection occurred.
- The public response never includes assigned-staff identity, `id`,
  `internal_notes`, or any other appointment's data — only `reference`,
  `status`, `starts_at`/`ends_at`/`booking_timezone`, and the type's public
  name/meeting method.
- `booking_source` is constrained server-side to a known allow-list
  (`marketing_homepage`, `marketing_navigation`, `pricing_page`,
  `contact_page`, `public_booking_page`); anything else is normalised to
  `public_booking_page` rather than rejected — it's a reporting label, not
  a security boundary.
- `Appointment::create()` is called with an explicit, fully-controlled
  field array (not a spread of raw request input) — untrusted input can
  never set `status`, `assigned_user_id`, or any other field it isn't
  explicitly mapped to.

### Marketing-site integration

The existing `/book-a-demo` page and its `BookDemoForm`/`DemoRequestController`
lead-capture flow are **superseded**, not deleted — every "Book a Demo" CTA
across the marketing site now points to `/book/demo?src=...` (the seeded
`demo` Appointment Type, `is_public=true`, auto-confirms since
`requires_confirmation=false`), and `/book-a-demo` itself is now a redirect
to `/book/demo` so existing bookmarks/indexed links still resolve.
`DemoRequestController`/`POST /api/demo-requests` and the now-unused
`BookDemoForm` component are left in place rather than removed (per "don't
remove existing workflows without confirmation") but are no longer linked
from anywhere in the UI.

## Communications & appointment experience (Phase 4)

### Attendee emails

`AppointmentEmailService` composes every attendee-facing email and is the
only thing that calls `EmailNotificationService::sendDirect()` for
appointments (single explicit recipient — the attendee is external, not an
organisation's Client-role users, so `send()`'s org/event-toggle model
doesn't fit; `sendDirect()` was extended with an optional `$attachments`
array for ICS, with every existing caller/behaviour unchanged).

| Status reached | Email |
|---|---|
| `requested` / `pending_confirmation` | "request received" / "awaiting confirmation" |
| `confirmed` (on creation or via `/confirm`) | confirmation, ICS attached if `appointment_ics_enabled` |
| `declined` | declined, with reason if given |
| `cancelled` | cancelled, with reason if given |
| rescheduled | updated confirmation email + replacement ICS |
| `completed` / `no_show` | **no automatic attendee email** (deliberate, this phase) |

Every email is sent via **`SendAppointmentEmailJob`** (queued,
`ShouldQueue`) and every dispatch site uses **`->afterCommit()`** — the job
never enters the queue before the triggering DB transaction (appointment
create/transition/reschedule) actually commits, and if that transaction
rolls back the job is never dispatched at all. A delivery failure inside
the job can never fail the appointment mutation itself, since the mutation
already committed by the time the job runs; `EmailNotificationService`
itself also never throws (catches and logs), so this is defence in depth,
not the only safeguard. Duplicate-dispatch protection for a retried
same-status transition falls out of `AppointmentWorkflowService::transition()`'s
existing same-status rejection (`$from === $toStatus` throws before the
controller ever reaches the dispatch call) — no separate idempotency
mechanism was needed for that specific case.

**Reminders also go through `SendAppointmentEmailJob`** (revised after
review — the original design sent them synchronously inside
`SendAppointmentReminders`, matching `SendDeadlineReminders`'s precedent).
That precedent didn't transfer cleanly: `claimReminderSend()` inserts the
`appointment_reminder_sends` row *before* the send is attempted, so once
claimed, the `(appointment_id, offset_minutes, schedule_version)` unique
constraint means that exact reminder slot can never be claimed again — a
crash or thrown exception between claim and a synchronous send would have
stranded that reminder unsent forever, with zero retry. Dispatching through
the queued job (`tries=3`, `backoff=[30, 120]`) gives a claimed-but-not-yet-
sent reminder genuine retry coverage. The command still performs the claim
itself (synchronously, so the atomic-insert guarantee is unchanged) and then
dispatches the job with `reminder_send_id` in its context; the job updates
that row to `sent`/`failed` itself once it has actually attempted delivery,
and its `failed()` handler also marks the row `failed` (with the exception
message) if all retries are exhausted — so a permanently-failing reminder
never sits at `pending` forever.

### Signed cancel/reschedule links

Laravel's built-in `signed` middleware only — no custom signature code.
Every link is keyed on the appointment's **`public_token`** (a 48-character
random string, generated in `Appointment::booted()`'s `creating` hook) —
never the numeric id or the sequential `reference`, so a link leaks no
information about how many appointments exist.

**Expiry formula** — the earlier of two independently configured bounds:

```
expires_at = min(
    sent_at + {cancel|reschedule}_link_ttl_hours,
    appointment.starts_at - {cancellation|reschedule}_cutoff_hours
)
```

so a generous TTL can never leave a link "valid" past the point it's
actually useful (e.g. cancelling an appointment that already started).
Cancel and reschedule each have their own TTL *and* their own cutoff — four
independent settings, not a shared pair — since operationally they may
warrant different tolerances. Computed by `AppointmentPublicLinkService`.

**GET and POST share one signed link.** Laravel's signature verification is
URL-based (path + query string), not HTTP-verb-based, so the exact same
generated link serves both the marketing page's initial GET (show
confirmation details) and its later POST (perform the action) — no second
link or extra round trip needed.

**`reschedule/slots?date=...` is signed too** (revised after review — the
original design left this one endpoint deliberately unsigned, relying on the
token's unguessability plus rate limiting, since a fixed signature can't
accommodate an arbitrary, visitor-chosen `date`). Laravel's `signed`
middleware supports excluding specific query parameters from signature
validation via `signed:{param}` (the same built-in mechanism intended for
stripping tracking params like `fbclid` from signed URLs) — the route uses
`signed:date`, so `AppointmentPublicLinkService::rescheduleSlotsApiUrl()`
generates a signature over everything *except* `date`, and the frontend
freely appends `&date=...` as the visitor browses without needing a fresh
signature per date. This closes the one gap in "every public endpoint is
signed" without sacrificing the variable-date usability — no bespoke
signing scheme was needed.

**Security properties verified by test**: tampered signature → 403;
expired link → 403; a cancel link's signature does not validate against
the reschedule route (different path); cancelling an already-cancelled
appointment is idempotent (200, not an error); a cancelled appointment
cannot be rescheduled (422); **a successful reschedule rotates
`public_token`**, so every link in a *previous* confirmation email 404s
immediately afterwards — only the newest email's links work.

### Reschedule side effects (`AppointmentWorkflowService::reschedule()`)

In the same update, inside the same DB transaction as the reschedule
itself:
- **`schedule_version` increments.** This is what makes reminders "due
  again" against the new time — see below — and is reused directly as the
  ICS `SEQUENCE` value.
- **`public_token` rotates** (see above).

### ICS calendar invitations (`AppointmentIcsService`)

Hand-rolled RFC5545 generator — no library dependency, since the
requirements (one non-recurring VEVENT, no two-way sync) don't justify one.
Correctness points implemented and tested: CRLF (`\r\n`) line endings
throughout; line folding at 75 octets with a single-space continuation
prefix; TEXT-value escaping of backslash/semicolon/comma/newline per
§3.3.11; all `DTSTART`/`DTEND`/`DTSTAMP` values in UTC (`...Z` form, reusing
the appointment's already-UTC `starts_at`/`ends_at` directly — no `VTIMEZONE`
component); `SEQUENCE` = `schedule_version` (tells calendar clients a
re-sent invite replaces the previous version, not a duplicate); `STATUS`
mapped from the appointment's status (`CONFIRMED`/`CANCELLED`/`TENTATIVE`).
Attached to confirmation/reschedule emails only when
`appointment_ics_enabled` is on.

**`UID` is derived from `reference`, not `public_token`** (fixed after
review — the original implementation derived it from `public_token`, which
rotates on every reschedule; since RFC5545 requires the same calendar event
to keep the same `UID` across updates, a UID that changed on reschedule
would make Google/Apple/Outlook treat the "rescheduled" invite as a
brand-new, unrelated event rather than updating the one already on the
attendee's calendar). `reference` is immutable for the appointment's
lifetime, so the UID is stable across regenerations of the same appointment
including after a reschedule — covered by a regression test that rotates
`public_token` and asserts the UID doesn't change.

**Cancellations send a dedicated `METHOD:CANCEL` ICS** (added after review
— the original implementation sent no ICS at all on cancellation).
`AppointmentIcsService::generateCancellation()` emits `METHOD:CANCEL`
(RFC5546 iTIP) instead of `METHOD:PUBLISH`, with the same stable `UID` and
`STATUS:CANCELLED`, so calendar clients that understand iTIP CANCEL
semantics actually remove/grey-out the previously-added event, rather than
receiving an inert status update on an event that still otherwise looks
live. `AppointmentEmailService::sendCancelled()` attaches it when
`appointment_ics_enabled` is on.

**Known interoperability limitation, disclosed rather than worked around:**
Brevo's generic attachment API sends the ICS as a plain file attachment, not
with the special `text/calendar; method=REQUEST` MIME framing that some
clients (notably Gmail) use to render an interactive RSVP widget inline in
the email body. Recipients still receive a correct, importable `.ics` file
for every state (confirmation, reschedule, cancellation) — this is a
one-way calendar file delivery, not a full two-way iTIP invite/response
flow, which was out of scope for this phase.

### Reminders

**`appointment_reminder_sends`** is a dedicated table (not a repurposing of
`deadline_reminder_runs`/`deadline_reminder_sends`, which are shaped for a
different problem — an organisation-day checkpoint — not a
per-appointment-per-offset one). Columns: `appointment_id`,
`offset_minutes`, `schedule_version`, `scheduled_for`, `sent_at`, `status`
(`pending`/`sent`/`failed`), `failure_message`. The unique constraint is
`(appointment_id, offset_minutes, schedule_version)` — this is the actual
duplicate-send guarantee, not application logic.

Offsets are stored and configured **in minutes**, not hours (default `[1440,
60]` — 24h and 1h before), so finer-grained future offsets (e.g. 30 minutes)
don't require a schema change.

**`SendAppointmentReminders`** runs every 15 minutes
(`routes/console.php`), but "due" is deliberately not a fixed 15-minute
wall-clock slice: a reminder for offset `O` is due once
`appointment.starts_at > now() AND appointment.starts_at <= now() + O`. A
late-running tick (a slow deploy, a missed cron minute) just catches up on
its next run instead of silently skipping a gap — there is nothing to
"miss". The `starts_at > now()` half of the condition is what stops a
reminder being sent for an appointment whose start time has already
passed, bounding staleness after a long outage without needing a separate
grace-period concept. `claimReminderSend()` mirrors
`SendDeadlineReminders::claimReminderSend()` exactly: attempt the `INSERT`
first, catch `UniqueConstraintViolationException` to detect "already
claimed" — safe under concurrent/overlapping runs by construction, not by
this check.

**Reschedule "resets" reminders without deleting anything.** Because
`schedule_version` is part of the unique key, a rescheduled appointment's
old reminder-send rows simply stop matching the new version — the command's
normal due-check then finds it due again on its own, with full send history
preserved (mirroring `DeadlineReminderSend`'s own `effective_deadline_date`-in-the-key
rationale, not row deletion).

Reminders are only sent for **assigned** appointments — an unassigned
appointment has no confirmed staff context to remind on behalf of yet.
Not sent for `cancelled`/`declined`/`completed`/`no_show` (not in the
active-status query at all). Suppressed entirely when
`appointment_reminders_enabled` is off.

### Settings

New, under the existing `suresign_settings` table/`admin/suresign-settings/appointments`
endpoint (same per-section-update convention as `/ai`, `/notifications`,
etc. — no new settings framework):

- `appointment_reminders_enabled` (bool)
- `appointment_reminder_offsets_minutes` (JSON array of minutes — bounded:
  max 5 entries, each 5–10080 minutes, must be distinct)
- `appointment_cancel_link_ttl_hours` / `appointment_reschedule_link_ttl_hours`
- `appointment_cancellation_cutoff_hours` / `appointment_reschedule_cutoff_hours`
- `appointment_ics_enabled` (bool)
- `appointment_default_meeting_instructions` (free text, appended to
  confirmation emails and the ICS description)

Sender identity, support contact email, and branding are **reused as-is**
from existing `suresign_settings` columns (`email_sender_name`,
`support_email`, etc.) — not duplicated. This endpoint deliberately follows
the existing platform-settings precedent (`role:Super Admin|Admin`, same as
`/ai`, `/notifications`) rather than the stricter Super-Admin-only rule
Appointment Types use — a conscious choice to match `suresign_settings`'
established access pattern rather than invent a new one for just this
section.

## API

```
GET/POST            /api/appointment-types
GET/PUT/DELETE      /api/appointment-types/{id}              (mutations: Super Admin only)
GET/POST            /api/appointments
GET/PUT/DELETE      /api/appointments/{id}                    (delete: Super Admin only)
POST /api/appointments/check-availability                     (read-only dry-run for internal forms; assigned_user_id ownership-checked — see below)
POST /api/appointments/{id}/assign
POST /api/appointments/{id}/reschedule
POST /api/appointments/{id}/confirm
POST /api/appointments/{id}/decline
POST /api/appointments/{id}/cancel
POST /api/appointments/{id}/complete
POST /api/appointments/{id}/no-show

GET/PUT             /api/appointment-availability/me                          (weekly schedule, bulk replace)
GET/POST            /api/appointment-availability/me/overrides
PUT/DELETE          /api/appointment-availability/me/overrides/{override}
GET/POST            /api/appointment-availability/me/blocked-periods
PUT/DELETE          /api/appointment-availability/me/blocked-periods/{blockedPeriod}

GET/PUT             /api/appointment-availability/{user}                      (Super Admin only)
GET/POST            /api/appointment-availability/{user}/overrides            (Super Admin only)
PUT/DELETE          /api/appointment-availability/{user}/overrides/{override} (Super Admin only)
GET/POST            /api/appointment-availability/{user}/blocked-periods                    (Super Admin only)
PUT/DELETE          /api/appointment-availability/{user}/blocked-periods/{blockedPeriod}    (Super Admin only)

GET  /api/public/appointment-types/{slug}                (no auth; is_public+is_active types only)
GET  /api/public/appointment-types/{slug}/slots?date=... (no auth)
POST /api/public/appointment-types/{slug}/book            (no auth; the actual booking submission)

GET/POST  /api/public/appointments/{token}/cancel         (Laravel `signed` middleware; GET shows details, POST cancels)
GET/POST  /api/public/appointments/{token}/reschedule     (signed; GET shows details, POST reschedules)
GET       /api/public/appointments/{token}/reschedule/slots?date=... (Laravel `signed:date` middleware — signed except `date`, see Communications section)

PUT  /api/admin/suresign-settings/appointments            (role:Super Admin|Admin, matching other suresign-settings sections)
```

`/me` routes are registered **before** their `/{user}` counterparts in
`routes/api.php`, and `check-availability` is registered before the
`appointments` resource — both to stop the literal path segment being
swallowed by a wildcard route-model-binding, the same class of ordering
risk as the `check-availability`-before-`{appointment}` guard from Phase 1.
`{user}`/`{override}`/`{blockedPeriod}` ownership is cross-checked (a
mismatched staff member on a nested override/blocked-period route 404s,
mirroring `MeetingMinutesController::authorizeProjectMeeting()`'s pattern).

## Frontend

- `/admin/appointments` — list with status/type filters, search, pagination,
  and a create modal with a live availability check (debounced call to
  `check-availability`) and, for Super Admin, an override checkbox +
  required reason when the selected time isn't available. Admin's form
  shows a static "will be assigned to you" note instead of an assignee field.
- `/admin/appointments/{id}` — detail view; the reschedule modal runs the
  same live availability preview, the assign modal surfaces a conflict
  inline (with the Super Admin override option) if the direct submit hits a
  409.
- `/admin/appointments/types` — Appointment Type management (Super Admin
  only).
- `/admin/appointments/availability` — weekly schedule editor (multiple
  windows per day, add/remove), date override list/editor, blocked-period
  list/editor. Admin sees only their own; Super Admin gets a staff picker
  (backed by the existing Super-Admin-only `/users` endpoint, filtered to
  Admin/Super Admin roles) to manage another eligible staff member's
  complete availability profile.

**`<TimezoneSelect>`** (`components/shared/TimezoneSelect.tsx`) is a shared
component extracted in Phase 2 — it replaces what had become four
independent copies of the same `getIanaTimezones()`-backed dropdown
(Company Settings, project Meetings, and two Phase 1 Appointments pages).
Any new IANA timezone dropdown should use this rather than adding a fifth.

Nav entry: **Appointments** in the Super Admin/Admin sidebar (Platform
group), using the `CalendarClock` icon (unchanged from Phase 1).

**Marketing site** (`marketing/`, a separate Next.js app/deployment):
`/book/[slug]` (`marketing/src/app/book/[slug]/page.tsx` +
`components/booking/PublicBookingForm.tsx`) — timezone auto-detect (editable
selector, `marketing/src/lib/timezones.ts`, a deliberate separate copy of
the main app's timezone list since this is a genuinely different app), a
date picker bounded by the type's notice/advance window, either a slot-button
grid (`fixed` mode) or a plain time proposal input (`manual` mode), contact
fields, a required consent checkbox, and a confirmation screen whose copy
depends on the returned status (`confirmed` → "Your SureSign demo is
confirmed"; `requested` → "Your request has been received"). This is the
public slot API/picker Phase 2 explicitly deferred — kept intentionally
simple (a slot-button grid or a plain time input), not a generic calendar
UI, drag-and-drop, or a multi-step wizard.

`/appointments/[token]` (`marketing/src/app/appointments/[token]/page.tsx` +
`components/appointments/`) — the Phase 4 cancel/reschedule confirmation
pages reached from attendee emails. `PublicAppointmentExperience`
(client component) reads `?action=cancel|reschedule` plus the `expires`/
`signature` query parameters the email link carried, and calls
`GET /api/public/appointments/{token}/{action}` forwarding **only**
`expires`/`signature` (never `action`, which is a marketing-routing flag,
not part of what the backend signed) — see `signedQueryFrom()` in
`lib/publicAppointments.ts`. The page never reconstructs or recomputes a
signature; it treats every backend-returned URL (in particular
`reschedule_slots_url`) as opaque and only ever appends the one
explicitly-permitted `date` parameter to it.

Because a cancel link and a reschedule link are separate signed URLs (each
only valid for its own route), the page is single-purpose per load — a
visitor on a cancel link sees only the cancel flow, a visitor on a
reschedule link only the reschedule flow. There is no cross-navigation
between the two on this page (offering "reschedule instead" from a cancel
link would require a signature the page was never given).

**Public view fields are narrower than a full appointment record** — only
what `PublicAppointmentActionController::publicView()` returns is ever
shown: `reference`, `status`, `starts_at`/`ends_at`, `booking_timezone`,
appointment type name/duration, and the two `can_cancel`/`can_reschedule`
flags. Attendee name, location/meeting instructions, and any internal
notes are deliberately not part of that payload, so the page doesn't show
them either — extending `publicView()` to add fields is a backend change,
not a frontend one, and wasn't made as part of this phase.

**No public ICS download link exists.** The ICS file is only ever attached
to the transactional emails (see ICS section above) — there is no signed
"download the calendar file from the web page" endpoint, so the page does
not attempt to offer one. Adding that would be a new backend surface, not
a frontend wiring task.

**Link-state screens** distinguish exactly what the backend can honestly
distinguish, no more:
- **Invalid** (`InvalidLinkScreen`) — the URL is missing `expires`/
  `signature` entirely, or the signature itself doesn't validate
  (Laravel's `signed` middleware 403, checked client-side against the
  visible `expires` timestamp to rule out simple expiry first).
- **Expired** (`ExpiredLinkScreen`) — a 403 where the `expires` timestamp
  already in the URL has passed.
- **Stale / no longer active** (`StaleLinkScreen`) — a 404, i.e. the token
  doesn't match any appointment. This covers two cases the backend
  deliberately can't and shouldn't distinguish from each other (not
  revealing whether a token ever existed is the point): a token that never
  existed, and an old email's token made stale by `public_token` rotating
  after a successful reschedule.
- **Network** (`NetworkErrorScreen`) — request failed before getting an
  HTTP response; offers a retry, never a false success.

Terminal statuses (`cancelled`/`declined`/`completed`/`no_show`) show the
appointment summary plus a plain status note instead of any action UI,
regardless of which link (cancel or reschedule) was used to get there.
After a successful cancel or reschedule, the page shows the updated result
and stops offering further actions from that same page load — the
signature the visitor arrived with is single-use in spirit even where the
backend allows idempotent re-cancellation, and a successful reschedule
rotates `public_token` server-side anyway, so this page's own link is
already dead once that happens (see Security properties above). Staff
helping an attendee who wants to make another change should tell them to
use the newest email SureSign sent, not this page again.

**No automated frontend tests were added for this feature** — the
`marketing/` and `frontend/` apps currently have no JavaScript test
framework installed at all (no Jest/Vitest/RTL config anywhere in the
repo), and introducing one is a project-wide tooling decision outside this
feature's scope. Verified instead via `tsc --noEmit`, `next lint` (pre-
existing `react-hooks/set-state-in-effect` warnings only, matching the
same pattern already present in `PublicBookingForm.tsx`/`ThemeToggle.tsx`/
`useReducedMotion.ts`), and `next build`.

## Activity log

Phase 1 events (`created`, `updated`, `assigned`, status transitions,
`rescheduled`) plus, as of Phase 2: `appointment_availability.updated`,
`appointment_availability_override.created/updated/deleted`,
`appointment_blocked_period.created/updated/deleted`, and
`appointment.availability_override_used`. No separate history table —
the existing feature-agnostic `ActivityLog::record()` covers all of it.
Rejected bookings (conflicts, unavailable slots) are **not** logged —
consistent with existing audit-log convention elsewhere in the codebase,
which only logs successful mutations. A public-link cancellation is
recorded the same way as any other cancellation (actor `null`, same
`appointment.cancelled` action) — Phase 4 didn't add a separate action name
for it.

## Deferred to later phases

Not implemented, and must not be assumed to exist:

- Google Calendar / Microsoft Outlook / Teams / Zoom integrations, OAuth,
  or webhooks of any kind
- Round-robin / automatic assignment
- Custom appointment-type form fields
- Dashboard widgets, reporting, and analytics
- Organisation holiday calendars or recurring leave rules
- Recurring appointments
- CRM integrations
- Google/Microsoft/Apple calendar OAuth-based "Add to Calendar" (the
  marketing pages only ever expose an emailed `.ics` file, never a live
  provider integration)
- A public, unsigned "view my appointment" page — every public GET is
  behind a signed link tied to a specific action (cancel or reschedule);
  there is no plain read-only public view
