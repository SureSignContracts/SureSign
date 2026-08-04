# Consultancy Live Booking Upgrade — Phase 0 Architecture Review

Status: **Stage 1 (Foundation) approved and implemented** — see
`internal-docs/super-admin/consultancy.md`'s "Consultancy Live Booking
Upgrade — Stage 1" section for the full implementation writeup. This
document remains the historical Phase 0 record; the recommendations below
(§9 availability architecture, §10 dynamic consultant resolution, §14–§15
Stripe routing) were reviewed and approved before Stage 1 began, per the
agreed recommended implementation order (Foundation → Availability/Slots →
Reservations → Stripe → Google → Notifications → Hardening). Stages 2
onward (temporary reservations, Stripe, Google Calendar/Meet) remain
un-started.

Every claim below is tagged:

- **[Confirmed]** — read directly from the current codebase, with file paths.
- **[Inferred]** — a reasonable conclusion from confirmed code, not itself a
  literal quote.
- **[Proposed]** — new architecture this document recommends; not yet built.
- **[Product decision needed]** — a fork in the road only the business can
  resolve.
- **[Cannot verify locally]** — depends on external services (Stripe live
  mode, Google OAuth) this environment cannot exercise.

---

## 1. Executive summary

The existing Appointments engine is materially more reusable than the
original brief assumed. Two of the hardest-sounding requirements are already
solved by existing code with **zero changes needed**:

- **Cross-workflow conflict detection** already works today, for free —
  `AppointmentSchedulingService`'s conflict queries are scoped by
  `assigned_user_id` and appointment status only, never by appointment type
  or "workflow." A confirmed Book a Demo appointment for a consultant already
  blocks an overlapping Consultancy slot for that same consultant, and vice
  versa, with no new code. **[Confirmed]** — see §11.
- **Availability, by contrast, has zero separation today.** `appointment_availabilities`,
  `appointment_availability_overrides`, and `appointment_blocked_periods` are
  all keyed purely by `user_id` — one calendar per staff member, shared
  across every appointment type they're assigned to. **[Confirmed]** — see
  §9. This is the real gap the upgrade must close, and it's narrower than
  "build a second scheduling engine" — it's "add one scoping dimension to
  three existing tables and thread it through one existing service."

The Stripe side is the opposite story: the existing billing architecture is
excellent but is **subscription-shaped top to bottom**. `WebhookEventProcessor`
is ~1,500 lines of subscription/Checkout-Session/invoice logic with no
extension seam for a one-off payment, and `BillingProviderInterface::createCheckoutSession()`
only supports a pre-registered recurring Stripe Price, not Consultancy's
admin-editable one-off amount. The recommendation is to reuse the trusted
**ingestion boundary** (`WebhookIngestionService`, the `billing_webhook_events`
ledger, signature verification, the recovery-command pattern) unchanged, but
give Consultancy its own processor class and its own one-off Checkout method
— never touching `WebhookEventProcessor` itself. See §14–§15.

Google Calendar/Meet integration does not exist in any form — not a
dependency, not a config stub, not a partial migration. `google_meet` is
merely one unused enum value on `meeting_method`. This is greenfield work.
See §5.

The rest of this document works through the 14 required architecture
decisions in detail, then a stage-by-stage plan.

---

## 2. Current Consultancy architecture

**[Confirmed]**

- `ConsultancyService` (`app/Models/ConsultancyService.php`) is a
  commercial/presentation wrapper around exactly one `AppointmentType`
  (1:1, `appointment_type_id` unique FK). Scheduling fields (duration,
  buffers, notice/advance windows, assignment mode, default assignee) live
  exclusively on the linked `AppointmentType` — `ConsultancyService` never
  duplicates them.
- `ConsultancyCatalogueService::create()/update()` is the only place both
  rows are written together, in one transaction
  (`app/Services/Consultancy/ConsultancyCatalogueService.php`). It already
  accepts `assignment_mode` and `default_consultant_user_id` in its field
  list, but **no frontend form currently exposes these fields** — the
  Consultancy Services admin edit modal
  (`frontend/src/app/admin/consultancy/services/page.tsx`) only edits
  duration/price/currency/enabled/visibility/display-order/introductory
  flag. Every Consultancy service today is therefore stuck on
  `assignment_mode = 'manual'` (the column default) in practice, unless
  someone calls the API directly.
- `ConsultationEnquiry` (`app/Models/ConsultationEnquiry.php`) is 1:1 with
  `Appointment` (`appointment_id` unique FK) and holds the structured
  enquiry fields plus (Phase C2) `engagement_status` — an operational
  lifecycle deliberately separate from `appointments.status`.
  `EngagementLifecycleService::STATUSES = ['awaiting_consultant',
  'awaiting_customer', 'completed', 'cancelled']`.
- **One single enquiry-creation code path**:
  `ConsultationEnquiryService::book()`
  (`app/Services/Consultancy/ConsultationEnquiryService.php`) is called by
  both `PublicConsultationController::store()` and
  `ConsultationController::store()` — no divergent public/authenticated
  logic. It delegates conflict-checking entirely to
  `AppointmentSchedulingService::withConflictCheck()` and does nothing
  scheduling-specific itself.
- `fixedStaffFor()` is duplicated verbatim (by design, matching the existing
  Appointments Public/Admin controller pattern of duplication-over-shared-base)
  across `PublicConsultationController`, `ConsultationController`, and
  `PublicAppointmentController`/`AppointmentController`'s equivalents: a
  service/type is staff-backed only when `assignment_mode === 'fixed'` AND
  `default_assigned_user_id` is set AND that user passes
  `AppointmentAvailabilityService::isEligibleStaff()` (active, not banned,
  Admin or Super Admin role). Otherwise scheduling_mode is `'manual'` and
  slot endpoints return an empty list — the customer proposes a date/time
  in free text instead.
- Cancellation exists today (`ConsultationController::cancel()`, plus the
  generic signed-link `PublicAppointmentActionController::cancel()`, which
  works for any `Appointment` including Consultancy ones via
  `public_token`). **Rescheduling** exists generically via
  `PublicAppointmentActionController::reschedule()`/`rescheduleSlots()`
  (signed-link only) but there is **no authenticated in-app reschedule
  endpoint** for `ConsultationController` today — only `cancel()`.
- Operator surface: `ConsultancyOperationsController`
  (`app/Http/Controllers/Api/ConsultancyOperationsController.php`) — queue,
  detail, dashboard summary, internal notes, customer-summary draft/publish,
  engagement transitions, reopen, Project linkage. Super Admin and every
  Admin (not just the assigned consultant) may read any consultation
  platform-wide — a deliberate Consultancy-specific visibility rule.
- Tenant/authorization boundary: `ConsultationController::authorizeOwnOrganization()`
  requires `$appointment->consultationEnquiry` to exist AND
  `organization_id` to match the caller's own organisation. No policy
  classes are used anywhere in this codebase (see root `CLAUDE.md`) —
  authorization is inline per-controller.

---

## 3. Current Appointments and availability architecture

**[Confirmed]**

### Schema

- `appointment_types` — one row per bookable "kind" (Book a Demo, each
  Consultancy service, etc.). Carries `duration_minutes`,
  `buffer_before_minutes`, `buffer_after_minutes`, `min_notice_hours`,
  `max_advance_days`, `is_public`, `is_active`,
  `default_assigned_user_id`, `assignment_mode` (**enum, only `'fixed'` or
  `'manual'` today** — `database/migrations/2026_07_21_070815_create_appointment_types_table.php:32`
  explicitly says "Phase 1 supports only fixed/manual assignment —
  'auto' and 'round_robin' are deliberately not in this enum yet"),
  `requires_confirmation`, `meeting_method` (enum including the unused
  `'google_meet'` value), `cancellation_notice_hours`,
  `reschedule_notice_hours`.
- `appointments` — one row per booking, of ANY kind. `assigned_user_id`
  (nullable FK to `users`), `status` (enum:
  `requested/pending_confirmation/confirmed/declined/cancelled/completed/no_show`),
  `starts_at`/`ends_at` (UTC), `booking_timezone`, `public_token`
  (signed-link identity), `schedule_version` (bumped on reschedule, also
  reused as the ICS `SEQUENCE`). Indexed on `(assigned_user_id, starts_at)`,
  `(organization_id, status)`, `(status, starts_at)`.
- `appointment_availabilities` — weekly recurring windows, **keyed only by
  `user_id` + `weekday`**. No appointment-type or workflow column exists.
  Multiple rows per user/weekday support multiple daily windows already.
- `appointment_availability_overrides` — date-specific overrides, **keyed
  only by `user_id` + `local_date`**. Full-day-unavailable XOR one-or-more
  custom windows for that date; overrides always take total precedence
  over the weekly schedule for that date (enforced in
  `AppointmentAvailabilityService`, not the DB).
- `appointment_blocked_periods` — arbitrary UTC instant ranges (leave,
  internal commitments), **keyed only by `user_id`**. Stores the IANA
  timezone active when created (redisplay purposes only, like
  `MeetingMinutes.scheduled_timezone`).

**None of the three availability tables has any concept of "which
workflow" today.** A staff member has exactly one calendar, full stop.

### Services

- `AppointmentAvailabilityService`
  (`app/Services/AppointmentAvailabilityService.php`) — owns weekly
  schedule CRUD, override CRUD, blocked-period CRUD, `isEligibleStaff()`,
  `resolveWindowsForDate()` (override-takes-precedence-over-weekly logic),
  `assertTypeBookable()` (notice/advance window, type-level, doesn't require
  a staff member), and `assertBookable()` (staff-level: within a resolved
  window, not blocked). Every write path logs to `ActivityLog`.
- `AppointmentSchedulingService`
  (`app/Services/AppointmentSchedulingService.php`) — **the single
  authoritative source of same-staff conflict rules**, and the slot
  generator. Key methods:
  - `isSlotFree($userId, $start, $end, $excludeId)` — raw overlap query
    against `Appointment::where('assigned_user_id', $userId)->whereIn('status',
    ['requested','pending_confirmation','confirmed'])`. **Type-agnostic.
    Workflow-agnostic.** Any active appointment for that user, from any
    source, blocks.
  - `hasBufferedConflict()` — same query widened by each candidate's own
    buffer minutes (every appointment uses its own type's buffers, even
    when comparing across two different types).
  - `withConflictCheck()` — wraps creation/reschedule/assignment in a DB
    transaction with `lockForUpdate()` on the candidate range, re-checks
    `isSlotFree`/`hasBufferedConflict` (never overridable, even by Super
    Admin), then optionally `AppointmentAvailabilityService::assertBookable()`
    (overridable).
  - `generateAvailableSlots($staff, $type, $localDate, $displayTimezone)` —
    the one slot-list generator every read path (public, authenticated,
    reschedule) calls. 15-minute step granularity, DST-gap-safe, returns
    `{date, time}` pairs keyed/sorted by UTC instant.
  - `bookableDatesInMonth()` — thin wrapper calling
    `generateAvailableSlots()` once per candidate day; no separate
    availability calculation.
- `AppointmentWorkflowService` — the sole status-transition authority
  (`TRANSITIONS` map), plus `reschedule()` (an event, not a status change —
  bumps `schedule_version`, rotates `public_token`) and `assign()`.
- `AppointmentPublicLinkService` / `AppointmentEmailService` /
  `AppointmentIcsService` / `AppointmentReferenceService` — signed-link
  URL building, cancellable/reschedulable eligibility checks
  (`cancellation_notice_hours`/`reschedule_notice_hours`), ICS generation,
  atomic reference number generation. All type/workflow-agnostic, all
  directly reusable for Consultancy without modification (Consultancy
  already uses `AppointmentReferenceService` today).

### Book a Demo

**[Confirmed]** There is no separate "Book a Demo" table or service at all
— `DemoRequestController`/`/demo-requests` is explicitly a legacy,
superseded lead-capture endpoint
(`routes/api.php:135-138`: "Superseded by the public Appointments booking
flow... left in place/unused rather than removed"). The real "Book a Demo"
experience today **is** the generic public Appointments booking flow
(`PublicAppointmentController`, `/book/{slug}`) against whichever
`AppointmentType` is configured as the demo type. There is no dedicated
"Book a Demo availability" table distinct from `appointment_availabilities`
— it's the exact same three tables, for whichever staff member that demo
type's `default_assigned_user_id` points at. **This confirms the user's
framing is correct in spirit** ("Book a Demo availability" = today's
undifferentiated availability for that consultant) even though there's no
literally-named "Book a Demo" table to keep separate from.

### Reminders

`SendAppointmentEmailJob`, `AppointmentReminderSend` (idempotent per
`schedule_version`) — generic, type-agnostic, already covers Consultancy
appointments today since they're just `Appointment` rows.

---

## 4. Current Stripe architecture

**[Confirmed]** — `app/Services/Billing/*`, `stripe/stripe-php: ^21.0`
(official SDK), `config/billing.php`.

- **Provider abstraction**: `BillingProviderInterface` /
  `StripeBillingProvider` / `FakeBillingProvider` (for tests) —
  `BillingProviderManager` resolves which is active. Every method takes/
  returns plain arrays; no `\Stripe\*` object ever leaves
  `StripeBillingProvider`.
- **`createCheckoutSession()`'s current shape is subscription-only**: its
  params are `{customer_id, price_id, quantity, success_url, cancel_url,
  metadata, subscription_metadata, idempotency_key}` — it assumes a
  pre-registered, immutable Stripe `Price` object
  (`PlanPriceMappingService`'s whole reason for existing: idempotent
  Product/Price sync, "supersede never mutate" when pricing changes). **A
  Consultancy service's price is a plain admin-editable integer on the
  `consultancy_services` row, changed at will with no historical Price
  object** — building Consultancy's Checkout through this exact method
  would force duplicating `PlanPriceMappingService`'s entire
  immutable-Price-supersession machinery for no reason. See §14.
- **Webhook ingestion is fully generic and reusable as-is.**
  `WebhookIngestionService::ingest()` — verifies signature against the
  correct test/live secret (`config('billing.stripe.webhook_secret_test/_live')`),
  builds a `VerifiedWebhookEvent`, persists into `billing_webhook_events`
  exactly once (unique `(provider, provider_event_id)` constraint is the
  concurrency boundary, not a check-then-create race), dispatches
  `ProcessBillingWebhookEventJob` `->afterCommit()`. Nothing about this
  service is subscription-specific — `provider` and `event_type` are plain
  strings; the ledger table has no FK to any subscription/checkout table.
  **Confirmed reusable unchanged for Consultancy events.**
- **`WebhookEventProcessor` is NOT reusable for Consultancy** — ~1,500
  lines, ~30 private methods, entirely built around
  `Subscription`/`BillingCheckoutSession`/`BillingPlanChange`/
  `BillingInvoice` correlation and Stripe subscription-lifecycle status
  mapping. `SUPPORTED_EVENT_TYPES` is a fixed list
  (`checkout.session.completed/.expired`,
  `customer.subscription.created/.updated/.deleted`, `invoice.paid`,
  `invoice.payment_failed`); anything else is `ignored`, never `failed` —
  **there is no dispatch branch or extension point for a one-off payment
  event today**, and it would be actively wrong to add one, since
  `processCheckoutCompleted()` already assumes the Checkout Session
  correlates to a `BillingCheckoutSession`/`Subscription` row — a
  Consultancy Checkout Session correlating to neither would currently fall
  through to `checkout_session_not_found_locally` → `failed(retryable:
  true)` and retry forever via `billing:webhooks:recover` until manually
  investigated. This is a **latent bug-in-waiting**, not yet triggered
  only because nothing creates a Consultancy Checkout Session yet.
- `ProcessBillingWebhookEventJob` (`queue: billing-webhooks`) is hardcoded
  to construct `WebhookEventProcessor` via DI — cannot be reused verbatim
  for a different processor without either a new job class or making the
  job processor-selectable.
- Queue: production worker
  (`docker/entrypoint.sh:28`) runs
  `queue:work --queue=billing-webhooks,default` — `billing-webhooks` listed
  first so billing events are never delayed behind slower jobs.
- Recovery: `billing:webhooks:recover` scheduled every 5 minutes
  (`routes/console.php:64-66`, `withoutOverlapping()`, no `onOneServer()`)
  — discovers stale-`processing`/retryable-`failed`/stranded-`received`
  rows and redispatches. Manual-only (`billing:subscriptions:check-integrity`,
  `billing:stripe:reconcile`) commands exist for other reconciliation
  needs — all `--dry-run` capable, none scheduled destructively.
- Money convention: integer minor units (`App\Support\Billing\Money`),
  matching Stripe's own convention — **already the same convention
  `consultancy_services.price_minor_units` uses** (see migration comment:
  "matches App\Support\Billing\Money's convention").
- Config: Stripe key/secret/webhook secrets are **env-only, deliberately
  never in `suresign_settings`** (`config/billing.php`'s own docblock:
  "Stripe secret/webhook keys are a direct financial-exposure risk... an
  infrastructure-level concern"). `.env.example` already has
  `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET_TEST`/`_LIVE`/`STRIPE_API_VERSION`.
  `BILLING_ENABLED`/`BILLING_PROVIDER` gate whether Stripe features are
  reachable at all.
- Rate limiting: `RateLimiter::for('billing-webhooks', ...)` already
  exists (`app/Providers/AppServiceProvider.php:239`), and the webhook
  route is explicitly `withoutMiddleware('throttle:api')->middleware('throttle:billing-webhooks')`.

---

## 5. Current Google integration status

**[Confirmed] Nothing exists.**

- No Google package in `composer.json` (`grep -i google composer.json` —
  no output).
- No file anywhere under `app/` or `config/` references Google OAuth,
  Calendar, or Meet, other than the single unused enum literal
  `'google_meet'` on `appointment_types.meeting_method` /
  `appointments.meeting_method` (an admin-facing label choice with zero
  behaviour behind it — no code branches on this value to actually create
  a Meet link).
- No `.env.example` entries for any Google client ID/secret/redirect URI.
- No token-storage table, no encrypted-credential pattern for a third-party
  OAuth connection of any kind exists in this codebase today (Brevo/
  Anthropic API keys are static server-to-server keys stored in
  `suresign_settings`, not OAuth tokens with a refresh cycle).

This is genuinely greenfield work — there is no partial implementation to
extend, and no existing "external account connection" UI pattern to copy
exactly (the closest precedent in spirit is the Stripe Customer Portal
connection status shown on the Billing page, but that has no OAuth
handshake of its own — Stripe Portal is provider-hosted, not a token
SureSign stores).

---

## 6. Confirmed reusable components and services

| Component | Reusable for Consultancy live booking? |
|---|---|
| `AppointmentSchedulingService` (conflict detection, slot generation) | **Yes, unchanged.** Already type/workflow-agnostic by construction. |
| `AppointmentWorkflowService` (status transitions, reschedule event) | **Yes, unchanged.** |
| `AppointmentReferenceService`, `AppointmentPublicLinkService`, `AppointmentEmailService`, `AppointmentIcsService` | **Yes, unchanged.** Already used by Consultancy today. |
| `AppointmentAvailabilityService` | **Yes, extended** — needs a scoping dimension added (see §9), not replaced. |
| `ConsultationEnquiryService`, `EngagementLifecycleService`, `ConsultancyCatalogueService` | **Yes, unchanged**, plus small additive changes (consultant/assignment fields already accepted by the catalogue service — only the admin UI needs to expose them). |
| `TimezoneResolver`, `Money`, `DocumentNumberService`-style reference generation | **Yes, unchanged.** |
| `WebhookIngestionService`, `billing_webhook_events` ledger, signature verification, recovery-command pattern | **Yes, unchanged.** Genuinely provider/purpose-agnostic already. |
| `BillingProviderInterface` (as a pattern: array-in/array-out, one interface, swappable implementation) | **Yes, as a pattern.** The interface itself needs one new method for one-off Checkout (see §14) — not a replacement, an addition. |
| `WebhookEventProcessor` | **No — do not extend.** Subscription-specific; a new sibling processor is the correct shape (see §14–§15). |
| `ProcessBillingWebhookEventJob` | **No — needs a sibling job**, since it's hardcoded to one processor class. |
| `AiCreditOperatingMode` (pattern only, not the class) | **Yes, as a pattern** for the Consultancy readiness/activation model (see §18). |
| Google anything | **No — none exists.** |

---

## 7. Confirmed architectural gaps

1. **No availability scoping mechanism** — the literal blocker for
   "Consultancy must own its own availability configuration" (§9).
2. **No reservation/hold concept anywhere** — `Appointment::STATUSES` has
   no "temporarily held pending payment" state, and no separate table
   models a time-limited claim on a slot (§10).
3. **No one-off Stripe Checkout capability** — `BillingProviderInterface`
   cannot create a Checkout Session without a pre-registered recurring
   Price (§14).
4. **No Consultancy-specific webhook processor or payment record** (§14–§15).
5. **No Google integration of any kind** (§5, §16–§17).
6. **No authenticated in-app reschedule endpoint for Consultancy** — only
   cancel exists on `ConsultationController`; reschedule only exists via
   the generic signed public link. Whether the paid live-booking upgrade
   needs an authenticated reschedule endpoint too is a scope question for
   Stage 2/5, not answered by existing code.
7. **No admin UI for `assignment_mode`/`default_consultant_user_id` on a
   Consultancy service** — the backend already accepts these fields; only
   the frontend form is missing them (small, low-risk gap, but worth
   fixing regardless as part of Stage 1, since the whole live-booking flow
   depends on a fixed consultant being configurable at all).
8. **`assignment_mode` enum is `['fixed', 'manual']` only** — no `'pool'`/
   `'round_robin'` exists, which is fine and actually matches the product
   direction (single consultant, no picker) — flagged only so nobody
   assumes a multi-consultant pool mode already exists.

---

## 8. Consultancy availability options considered

Three realistic options, evaluated against the schema in §3 and the
explicit constraint "do not simply copy the entire Appointments
availability implementation into another disconnected subsystem":

**Option A — Add a scope/context column to the three existing tables.**
Add `context` (string, e.g. `'general'` default / `'consultancy'`) to
`appointment_availabilities`, `appointment_availability_overrides`, and (see
below) optionally `appointment_blocked_periods`. Thread an explicit
`$context` parameter through every read/write method on
`AppointmentAvailabilityService`. Existing rows default to `'general'`
(zero backfill logic needed — a new column with a default value is
additive and non-breaking). Book a Demo's behaviour is provably unchanged
because every existing call site can be updated to pass `$context =
'general'` explicitly, preserving today's exact query shape.

- *Pros*: reuses the exact same service, model, validation
  (`assertNoOverlaps`, `assertValidOverrideRecord`), UI patterns, and test
  suite shape. Minimal new code. Directly satisfies "reuse shared services
  and validation."
- *Cons*: touches a proven, live class and three live tables used by
  today's Book a Demo flow — requires care and full regression coverage
  (§26) to guarantee zero behaviour change for existing calls.

**Option B — Dedicated, fully separate Consultancy tables** (`consultancy_availabilities`,
`consultancy_availability_overrides`, `consultancy_blocked_periods`) plus a
parallel `ConsultancyAvailabilityService` mirroring
`AppointmentAvailabilityService` method-for-method.

- *Pros*: zero risk to existing Book a Demo code/tables.
- *Cons*: this is precisely "simply copy the entire Appointments
  availability implementation into another disconnected subsystem," which
  the brief explicitly rules out. Two near-identical services drift over
  time (bug fixes applied to one, forgotten in the other) — the exact
  duplication risk the existing codebase's own conventions (see root
  `CLAUDE.md`: "do not duplicate the Appointments engine") are designed to
  prevent.

**Option C — Introduce an "availability profile" indirection table**
(`availability_profiles: id, key, owner_user_id`), repoint all three
existing tables to reference `availability_profile_id` instead of
`user_id` directly.

- *Pros*: the most "architecturally pure" long-term shape — a profile
  could later represent something other than "one user, one context"
  (e.g. a shared team calendar) without a further migration.
- *Cons*: by far the largest migration (repoints three live foreign keys
  and every read/write path), and solves a problem that isn't actually
  posed today — the product direction is explicitly "one consultant, no
  selector," not "flexible multi-owner profiles." This is exactly the kind
  of "architecture for features that do not yet exist" the project's own
  engineering principles (root `CLAUDE.md`, "Preferred Implementation
  Style") ask to avoid.

---

## 9. Recommended availability architecture

**[Proposed]** **Option A** — add a `context` column (string, indexed
alongside the existing `user_id`/`weekday` and `user_id`/`local_date`
indexes) to `appointment_availabilities` and
`appointment_availability_overrides`, defaulting to a named constant (e.g.
`AvailabilityContext::GENERAL = 'general'`) for every existing row and
every existing call site (Book a Demo / internal Appointments admin
availability page). Consultancy's dedicated Availability page
(`Admin → Consultancy → Availability`) reads/writes with `context =
'consultancy'` exclusively.

**Blocked periods — recommend NOT scoping by context.** A blocked period
represents a real-world absence (leave, an internal commitment) — Graham
being on leave should block Consultancy *and* Book a Demo *and* anything
else simultaneously; requiring the same absence to be entered twice under
two contexts would be an admin usability regression and a real
double-data-entry risk (forgetting to also block the "other" context is
exactly the kind of bug this whole exercise is trying to prevent). Blocked
periods stay exactly as they are today — one shared list per user, visible
from and editable by both the existing Appointments Availability page and
the new Consultancy Availability page. **[Product decision needed]**:
confirm this UX makes sense (i.e. Consultancy's Availability page shows the
same blocked-periods list as the Appointments page, not a separate one) —
flagged rather than assumed.

**What's shared**: the `user_id` (one consultant → one calendar, still
true), `AppointmentAvailabilityService`'s validation logic
(`assertNoOverlaps`, override-precedence-over-weekly rule), the admin UI
component patterns, blocked periods (see above).

**What stays separate**: weekly schedule rows and date-override rows are
now distinguishable per context — Consultancy's working hours can differ
from Book a Demo's without either affecting the other.

**How duplicated scheduling logic is avoided**: `AppointmentSchedulingService`
(conflict detection, slot generation) needs **zero changes** — it already
operates purely on `appointments` rows, which have no availability-table
dependency at all; `generateAvailableSlots()` calls
`resolveWindowsForDate($staff, $localDate, $context)` (the only method
whose signature changes) and the rest of the slot-generation algorithm is
untouched.

**How Book a Demo remains unaffected**: every existing call site (internal
Appointments availability controller/service calls) passes
`context: AvailabilityContext::GENERAL` explicitly (never a bare default
relied on implicitly at every call site — explicit is safer against a
future third context being added carelessly). A full regression pass
(§26) proves the existing Appointments Availability page's behaviour is
byte-for-byte identical before/after.

---

## 10. Consultant configuration recommendation

**[Proposed]** A new, single settings surface: `SuresignSetting` gains a
`consultancy_consultant_user_id` (nullable FK to `users`) — mirroring the
existing precedent of `suresign_settings` already holding singleton
platform-level configuration (appointment settings were added to this same
table: `2026_07_21_095624_add_appointment_settings_to_suresign_settings_table.php`).
A new `App\Support\Consultancy\ConsultancyConsultant` class (mirroring
`AiCreditOperatingMode`'s exact shape — a single `current(): ?User` static
accessor, the ONLY place this setting is ever read from) resolves it,
returning `null` (never a hardcoded fallback) if unset or if the configured
user no longer passes `AppointmentAvailabilityService::isEligibleStaff()`
(inactive, banned, or role changed away from Admin/Super Admin since being
configured).

- **Validation**: a new `UpdateConsultancyConsultantRequest` requires the
  target user to pass `isEligibleStaff()` — reuses the exact existing
  method, no new eligibility rule invented.
- **Authorization**: Super Admin only to change it (mirrors
  `UpdateAiCreditOperatingModeRequest`'s convention of the more
  consequential toggle being Super-Admin-only while read access is
  Super Admin **or** Admin).
- **Activity Log**: every change recorded via the existing
  `ActivityLog::record()`, same pattern as
  `ai_credits.operating_mode_changed`.
- **How this replaces "hardcode Graham"**: `ConsultancyCatalogueService`
  already accepts `default_consultant_user_id`/`assignment_mode` per
  service (§2) — but per the product direction ("no per-service
  consultant, one platform-wide consultant"), the *simpler* and more
  correct design is: every Consultancy service's `AppointmentType`
  automatically gets `assignment_mode = 'fixed'` and
  `default_assigned_user_id = ConsultancyConsultant::current()?->id`,
  either defaulted at creation time in `ConsultancyCatalogueService::create()`
  itself, or (cleaner) resolved dynamically at read time by
  `fixedStaffFor()` rather than stored redundantly per service at all.
  **[Product decision needed]**: which of these two — store it
  denormalized on each `AppointmentType` row (simple, matches existing
  `fixedStaffFor()` code unchanged, but must stay in sync if the
  configured consultant later changes), or resolve it dynamically
  (`fixedStaffFor()` ignores the type's own `default_assigned_user_id`
  entirely and always asks `ConsultancyConsultant::current()` for any
  Consultancy-linked type) so a future consultant change takes effect
  everywhere instantly with no backfill. **Recommend the dynamic
  resolution** — it's what "the architecture should permit replacement
  later" actually requires; a denormalized copy would need an explicit
  backfill/migration every time the consultant changes, which is exactly
  the kind of hardcoding-by-another-name the brief is trying to avoid.
- A seeder (`ConsultancyServiceSeeder`, already exists per the current git
  status — untracked) may set this value for local/staging setup, but no
  domain service ever hardcodes a name or ID — confirmed no such reference
  exists anywhere in `app/` (§5's grep also covered this).

---

## 11. Cross-workflow conflict-detection findings

**[Confirmed]** No new subsystem is needed. Verified directly from
`AppointmentSchedulingService`:

- **Which statuses block time**: `ACTIVE_STATUSES = ['requested',
  'pending_confirmation', 'confirmed']` (a private constant). Declined,
  cancelled, completed, and no-show appointments never block.
- **Are temporary reservations currently considered?** No — there is no
  reservation concept in the codebase today (§7.2). This is the one place
  the new Stage 3 reservation work *must* integrate with this existing
  query, one way or another — see §12's recommendation.
- **Are buffers applied consistently?** Yes — `hasBufferedConflict()`
  expands both the proposed interval and every candidate's own interval by
  that candidate's *own* type's buffer minutes (a Consultancy appointment's
  buffers and a Book a Demo appointment's buffers can legitimately differ
  and both apply correctly when compared against each other).
- **Scoping**: purely `assigned_user_id` + status. No `appointment_type_id`
  filter, no organisation filter, no "workflow" concept at all in either
  `rawOverlapQuery()` or `candidateQuery()`.
- **What must change**: **nothing**, provided Consultancy bookings
  continue to be created as ordinary `Appointment` rows with
  `assigned_user_id` set to the configured consultant (exactly as they are
  today). The one open question is how a **reservation** (Stage 3, not yet
  built) participates in this same query — see §12.

---

## 12. Proposed state model

Per the requirement to keep reservation, payment, appointment, engagement,
and calendar-sync states fully separate.

### Reservation state **[Proposed]**

| Value | Meaning |
|---|---|
| `active` | Hold created, not yet expired, Checkout in progress |
| `converted` | Payment confirmed; became a real confirmed `Appointment` |
| `expired` | Hold's expiry passed with no successful payment |
| `released` | Explicitly released (Checkout cancelled/failed before expiry) |
| `cancelled` | Superseded before conversion (e.g. a duplicate hold attempt lost the race) |

Authoritative service: new `ConsultancyReservationService` **[Proposed]**.
Source of truth: a new `consultancy_reservations` table. Idempotency
boundary: conversion is keyed on the reservation's own ID plus the Stripe
event ID that triggered it (§15). Failure behaviour: an expired/released
hold is deleted from the *blocking* query (see §13) but the row itself is
retained (soft state, not deleted) for audit/analytics.

### Payment state **[Proposed]**

`unpaid → checkout_created → pending → paid → failed | expired`, plus
`partially_refunded`/`refunded` reachable only from `paid`. Authoritative
service: new `ConsultancyPaymentService`. Source of truth: the Stripe
webhook, never the browser return URL (per explicit requirement).
Idempotency boundary: the Stripe event ID recorded on the payment row; a
webhook redelivery for an already-`paid` row is a no-op, not a re-charge.

### Appointment state — **unchanged**, reused exactly as-is
(`Appointment::STATUSES`, §3). A Consultancy appointment is not created at
all until payment is confirmed (see §13's "no pre-payment Appointment row"
recommendation) — so `requested`/`pending_confirmation` are not used by
the new live-booking path at all; a converted reservation goes straight to
`confirmed` (mirroring how a non-`requires_confirmation` type already
skips straight to `confirmed` today).

### Consultancy engagement state — **unchanged**
(`EngagementLifecycleService::STATUSES`, §2). `awaiting_consultant` is set
the same way it is today, the moment the `Appointment` row is created.

### Calendar integration state **[Proposed]**

`not_required → pending → creating → synced | failed`, plus `cancelled`
(appointment itself was cancelled/rescheduled and the calendar event was
retired/updated accordingly). Authoritative service: new
`GoogleCalendarSyncService`. Idempotency boundary: the `Appointment`'s own
ID (or reference) as the deterministic key for Google event lookups (§17).

**Allowed transitions** (documented, rejected if invalid, matching
`AppointmentWorkflowService::TRANSITIONS`'s existing pattern of an explicit
map rather than an implicit "anything goes"):

- Reservation: `active → {converted, expired, released, cancelled}` only;
  no transition out of any terminal state.
- Payment: `unpaid → checkout_created → {pending, paid, failed, expired}`;
  `paid → {partially_refunded, refunded}`; no other transitions.
- Calendar: `not_required → pending → creating → {synced, failed}`;
  `{synced, failed} → cancelled`; `failed → creating` (retry).

---

## 13. Temporary reservation architecture

**[Proposed]** A new `consultancy_reservations` table:

```
id, consultancy_service_id, appointment_type_id (denormalized for query convenience),
consultant_user_id, starts_at, ends_at, timezone,
status (active/converted/expired/released/cancelled),
attendee_name, attendee_email, attendee_phone, organization_id (nullable), linked_user_id (nullable),
stripe_checkout_session_id (nullable, unique when set),
idempotency_key (unique),
expires_at,
converted_appointment_id (nullable FK, set only on conversion),
created_at, converted_at, released_at
```

**Whether an unconfirmed Appointment should exist before payment: No.**
Recommend a reservation is its **own row, not a pre-payment Appointment**.
Reasoning: `Appointment::STATUSES` has no natural "held, unpaid" status
that wouldn't confuse every existing reporting/dashboard/notification path
that already filters on `Appointment::STATUSES` (Consultancy operator
queue, `ConsultancyOperationsController`, `ApplicationMonitoringService`,
etc.) — adding a transient status to that shared enum risks every one of
those call sites needing an audit to make sure they correctly exclude it.
A separate table keeps the blast radius contained to the new reservation
service only.

**Concurrency protection — how a reservation participates in existing
conflict detection**: `AppointmentSchedulingService::isSlotFree()`/
`hasBufferedConflict()` query `Appointment` rows only, so a reservation
that isn't an `Appointment` row is invisible to them by default. Recommend
`ConsultancyReservationService::reserve()` runs its own check-and-insert
**inside the same style of transaction** `withConflictCheck()` already
uses (`lockForUpdate()` on the relevant time range) but scoped to
`consultancy_reservations` (active, unexpired rows) **in addition to**
calling the existing `AppointmentSchedulingService::isSlotFree()`/
`hasBufferedConflict()` against real `Appointment` rows. This is
additional, not duplicated, logic — the existing engine still owns "is
this consultant already booked"; the new table owns "is this consultant
already *tentatively* booked by someone mid-checkout." A unique constraint
on `(consultant_user_id, starts_at)` for `status = 'active'` rows provides
the DB-level backstop beneath the transactional check (Postgres/MySQL
partial/filtered unique indexes may need a computed/generated column
depending on the DB engine already in use — confirm current DB engine
before finalizing this constraint's exact SQL in Stage 3).

**Conversion**: on authoritative payment confirmation (§15), a single DB
transaction: re-validate the reservation is still `active` and unexpired
→ create the real `Appointment` row (`assigned_user_id` = configured
consultant, `status = 'confirmed'`) → create/link `ConsultationEnquiry` →
mark the reservation `converted` with `converted_appointment_id` set →
commit. Re-processing the same Stripe event against an already-`converted`
reservation is a no-op (idempotent by reservation ID + event ID pair,
mirroring `WebhookEventProcessor`'s own "already processed" idempotent
result pattern).

**Expiry cleanup**: a new scheduled command,
`consultancy:reservations:expire` (naming mirrors
`billing:webhooks:recover`), scheduled every minute or every few minutes
(hold duration is short — 10–15 minutes — so cleanup cadence should be
tighter than the 5-minute webhook-recovery cadence; recommend every
minute), `withoutOverlapping()`, marks any `active` row past `expires_at`
as `expired`. Idempotent (only touches rows still `active`).

**Hold duration**: `config('consultancy.reservation_hold_minutes', 15)` —
a config value, not a scattered magic number, matching the "configuration
value rather than embedding a magic number" requirement.

---

## 14. One-off Stripe payment architecture

**[Proposed]** Extend `BillingProviderInterface` with **one new method**
(additive, not a breaking change to the existing interface):

```
createOneOffCheckoutSession(array $params): array
```

using Stripe Checkout's inline `price_data` (currency + unit_amount +
product_data.name supplied directly in `line_items`, `mode: 'payment'`) —
this is the standard Stripe-documented way to sell a dynamically-priced,
non-catalogued item without pre-registering a Product/Price, and is
exactly what avoids duplicating `PlanPriceMappingService`'s
immutable-Price-supersession machinery for Consultancy (§4). Implemented
in `StripeBillingProvider` (new method) and `FakeBillingProvider` (test
double, matching the existing pattern). `normalizeCheckoutSessionFromWebhookPayload()`
needs two additive fields on its return shape: `payment_intent_id` and
`mode` (currently only returns `subscription_id`, which is null for
`mode: payment` sessions) — additive, not breaking, since it's a typed
array shape, not a class.

**A new, dedicated payment table**, `consultancy_payments`
(not reusing `billing_invoices`/`billing_payments`, which are
subscription-invoice-shaped and FK to `Subscription`):

```
id, consultation_enquiry_id (nullable until appointment exists), reservation_id, appointment_id (nullable until confirmed),
consultancy_service_id, amount, currency,
status (unpaid/checkout_created/pending/paid/failed/expired/partially_refunded/refunded),
stripe_checkout_session_id (unique), stripe_payment_intent_id (nullable, unique when set),
stripe_customer_id (nullable), confirming_stripe_event_id (nullable),
paid_at, failed_at, refunded_amount, refund_status, refunded_at, failure_reason,
commercial_snapshot (json), idempotency_key (unique),
timestamps
```

**Commercial snapshot** (json column, per the explicit "changing the
service price later must not alter historical payments" requirement):
captured once, at Checkout-creation time, from the *current* `ConsultancyService`
+ `AppointmentType` state — service id/code/name, duration, amount,
currency, customer name/email, organisation id (nullable), reservation id,
intended start/end, and a snapshot timestamp. Never re-read from the live
`consultancy_services` row after this point.

**Webhook routing** (the concrete answer to "how should Consultancy events
be routed by WebhookEventProcessor" — **they should not be**, per §4/§6):
add a **new, separate processor**, `ConsultancyPaymentWebhookProcessor`
**[Proposed]**, and a **new, separate job**,
`ProcessConsultancyWebhookEventJob` **[Proposed]** (queue:
`consultancy-payments`, a new dedicated queue — see §21). Routing decision
happens once, cheaply, right after `WebhookIngestionService::ingest()`
persists the row: inspect the normalized Checkout Session's `mode` field
(`'payment'` vs `'subscription'`) or, more robustly, check whether
`consultancy_payments` has a row matching the session ID **before**
`billing_checkout_sessions` — since both are keyed by
`(provider, provider_checkout_session_id)`, a simple `orWhere` correlation
check at the ingestion or job-dispatch layer decides which job to enqueue.
Recommend doing this check inside `WebhookIngestionService::persist()`'s
successful-creation branch (the one place a job is ever dispatched today)
rather than inside a shared job that then decides — keeps both processors
completely ignorant of each other's existence, matching the existing
principle "WebhookEventProcessor... is the ONLY place a webhook event's
business meaning is decided" for its own domain, mirrored by the new
processor for its domain. **[Product/architecture decision needed]**:
confirm this dual-dispatch approach (one ingestion service, two possible
downstream jobs) rather than a single generic dispatcher job that queries
both tables — the former keeps `WebhookIngestionService` provider-generic
but adds one small `if` there; the latter adds a new indirection layer.
Recommend the former as the smaller, more surgical change.

**Relevant events for Consultancy** (a strict subset, decided from what
one-off Checkout in this payment-method configuration actually produces —
do not implement more than needed): `checkout.session.completed`,
`checkout.session.expired`, and — only if async payment methods (e.g.
bank redirects) are enabled in the Stripe configuration —
`checkout.session.async_payment_succeeded`/`checkout.session.async_payment_failed`.
`payment_intent.payment_failed` and `charge.refunded` for refund
visibility (§refunds below). **[Product decision needed]**: confirm which
payment methods Consultancy Checkout will accept (card-only vs. also
redirect-based methods) — this directly determines whether the async
events are needed at all; do not add handlers for events that can't fire
given the actual Stripe configuration.

**Refunds**: `charge.refunded`/`refund.updated` events (if selected)
update `consultancy_payments.refunded_amount`/`refund_status`/`refunded_at`
via the same new processor — never a second one. An authorised
Admin/Super Admin refund action calls Stripe's refund API directly (via a
new `BillingProviderInterface::createRefund()` method, additive) and the
confirming webhook is still what marks the local row — the initiating API
call itself does not mark it paid-refunded optimistically, matching the
existing "browser/caller is never authoritative" principle applied
symmetrically to operator-initiated actions too.

---

## 15. Stripe webhook-routing approach

Covered in full in §14 above (this section number preserved to match the
requested report structure 1:1). Summary: **do not touch
`WebhookEventProcessor`**; reuse `WebhookIngestionService` and
`billing_webhook_events` unchanged; add one small routing `if` at the
successful-ingestion dispatch point; add a new sibling job + processor +
queue exclusively for Consultancy events.

---

## 16. Google OAuth and account-ownership recommendation

**[Proposed, pending product decision]** Two realistic options:

**Option 1 — OAuth connection owned by the configured consultant's own
Google account.** The Admin "Connect Google Calendar" action performs a
standard OAuth Authorization Code flow as that specific user, storing the
resulting refresh token against a new `google_calendar_connections` table
(FK to `users`, not to `consultancy_services` or any other domain
concept — the connection belongs to a *person*, matching who actually
needs the meeting on their real calendar).

**Option 2 — A central, dedicated SureSign business Google account**,
disconnected from any individual consultant's own inbox/calendar, used
solely for creating Consultancy Meet events (with the consultant added
only as an event attendee, not the calendar owner).

**Recommend Option 1**, because: (a) the product direction is "one
configured consultant" whose real calendar should show real meetings —
Option 2 requires the consultant to separately watch a second calendar for
their own consultations, which is worse day-to-day operator UX for no
compensating benefit; (b) it directly satisfies "if the consultant
changes" (§10) — reconnection is then just "the new consultant runs the
same OAuth flow," no infrastructure change; (c) a domain-wide-delegation
service account (explicitly de-prioritised by the brief: "only if
operationally appropriate... do not assume a Workspace service account is
available") requires Google Workspace admin console access this
environment cannot confirm is available — Option 1 requires only an
ordinary Google account and a Google Cloud OAuth client, the lowest
operational bar. **[Cannot verify locally]**: whether the actual consultant
account is a Google Workspace account or a personal Gmail account changes
nothing architecturally for Option 1 — both support the same OAuth
Calendar API scope.

- **Required scopes** (least privilege):
  `https://www.googleapis.com/auth/calendar.events` (create/update/delete
  events + Meet conferencing on a calendar the connected account owns or
  has write access to) — **not** the broader `calendar` (full calendar
  management) or any Gmail/Drive scope. Confirm at implementation time
  whether Meet conference generation requires
  `calendar.events` alone or additionally
  `calendar.events.readonly`/a Meet-specific scope — Google's API surface
  for this should be re-checked against current documentation in Stage 5,
  not assumed here.
- **Token storage**: a new encrypted-columns table, using Laravel's native
  `encrypted` Eloquent cast (the same mechanism already implicitly
  available to this codebase's own encryption key, `APP_KEY` — no new
  encryption key needs inventing) for `access_token`/`refresh_token`.
  Unlike Stripe's static server keys, these are **not** env-only (they're
  per-connection, dynamic, and refresh over time) — they must be DB-stored,
  encrypted at rest, mirroring how a dynamic per-organisation credential
  would be stored if one existed elsewhere in this codebase (none
  currently does — this is a new pattern, clearly flagged as such).
- **Refresh-token handling**: Google refresh tokens are long-lived but can
  be revoked externally (e.g. the consultant changes their Google account
  password, or manually revokes SureSign's access in their Google Account
  settings) — every calendar operation must catch an
  invalid_grant/revoked-token error specifically and transition the
  connection status to `failed` with a clear "reconnect required" signal,
  never retry-loop indefinitely against a permanently revoked token.
- **Revocation**: the Admin "Disconnect" action should call Google's token
  revocation endpoint (best-effort, matching `expireCheckoutSession()`'s
  own "best-effort, local state remains authoritative" pattern) then delete
  the stored token locally regardless of whether the remote call succeeds.
- **Calendar selection**: default to the connected account's primary
  calendar; a calendar-picker (list available calendars via the Calendar
  API) is a reasonable Stage 5 nice-to-have, not a hard requirement for
  MVP — **[Product decision needed]**: confirm primary-calendar-only is
  acceptable for launch.
- **Multi-environment redirect URIs**: Google OAuth clients require exact
  redirect URI registration per environment (local, staging, production)
  — `GOOGLE_OAUTH_REDIRECT_URI` as an env var, one value per deployment,
  matching how `billing.portal_return_url` is already environment-specific
  today.
- **Local/test limitations** **[Cannot verify locally]**: a real OAuth
  consent screen requires a publicly reachable HTTPS redirect URI in most
  configurations (or Google's documented local-development allowances) —
  local development will need either a tunnelling tool or a documented
  manual token-seeding path for developers; this cannot be exercised in
  this sandboxed environment at all.
- **What happens if the connection expires while a booking is mid-flow**:
  payment must never be blocked or reversed by this — see §17's Google
  failure handling, unchanged in spirit from the brief's own requirement.

---

## 17. Google Calendar and Meet lifecycle

**[Proposed]** Sequence, matching the brief's own preferred order exactly:
payment confirmed → appointment confirmed → `CreateGoogleCalendarEventJob`
queued (new job, queue: `google-calendar`, see §21) → event + Meet link
created → appointment marked "meeting-ready" (a boolean/timestamp on
`consultancy_payments` or a dedicated small
`consultancy_calendar_syncs` table — recommend the latter, one row per
`Appointment`, holding `google_event_id`, `google_calendar_id`,
`google_meet_url`, `google_conference_id`, `sync_status`,
`last_synced_at`, `last_error`, `retry_count`) → confirmation email
containing the Meet link sent only once this row reaches `synced`.

**Idempotency**: the `Appointment`'s own `id` (or `reference`) is the
deterministic key — before creating a new Google event, the job checks
whether `consultancy_calendar_syncs` already has a `google_event_id` for
this appointment; if so, it calls Google's `events.update` (or `patch`),
never `events.insert` again. This is the same "look up by our own
authoritative identifier before creating" pattern
`PlanPriceMappingService` already uses for Stripe Price supersession, and
`AiCreditLedgerService` uses for its idempotency keys — a consistent
codebase-wide convention, not a new one invented for Google specifically.

**Rescheduling**: `AppointmentWorkflowService::reschedule()` already
exists and is generic — after it runs, dispatch the *same* calendar job
(update path, same `google_event_id`), never a second insert. Google
preserves the same Meet link across an `events.update` call by default
(confirmed via Google's own API documentation conventions — **[Cannot
verify locally]**: this should be spot-checked against a real test Google
account in Stage 5, not assumed blind).

**Cancellation**: `AppointmentWorkflowService::transition(..., 'cancelled')`
already exists and is generic — after it runs, dispatch a calendar job
that calls Google's `events.delete` (or `events.patch` with
`status: cancelled`, which also notifies attendees automatically — likely
the better choice so Google itself handles attendee cancellation
notification).

**Google failure handling**: never touch `consultancy_payments.status` or
`Appointment.status` on a Google failure — only
`consultancy_calendar_syncs.sync_status` moves to `failed`, with bounded
retry/backoff (mirrors `ProcessBillingWebhookEventJob`'s `$tries`/`$backoff`
pattern exactly) and a new recovery command,
`consultancy:calendar:reconcile-missing` (dry-run capable, matching every
other recovery command's convention), for confirmed-paid bookings whose
sync never succeeded.

**Consultant replacement mid-flight**: if the configured consultant
changes (§10) while an existing confirmed appointment's calendar event
still points at the *previous* consultant's Google connection, that
historical event is left alone (it already happened or is already
correctly on that person's calendar) — only *new* bookings after the
change use the newly connected account. **[Product decision needed]**:
whether a consultant change should trigger any bulk recalendaring of
future confirmed appointments, or leave them as-is (recommend leave as-is
— a consultant change is a rare, deliberate operational event that should
be handled with explicit care, not an automated bulk mutation).

---

## 18. Readiness and activation model

**[Proposed]** Recommend the **simplest model that safely supports staged
rollout** per the brief's own preference, modelled directly on the
existing `AiCreditOperatingMode` pattern (§6): a new
`App\Support\Consultancy\ConsultancyBookingMode` with values
`disabled`/`availability_only`/`fully_enabled` (three states, not four —
"payment_required" as a fourth state doesn't add real value once payment
is the *only* way to reach a confirmed booking under the new flow; the
distinction that matters is "is the paid flow live at all," not a
graduated payment toggle). Stored the same way
(`suresign_settings.consultancy_booking_mode`), read from exactly one
place, fails safe to `disabled` on any unrecognised stored value (never to
`fully_enabled`).

A companion **read-only** `ConsultancyBookingReadinessService`
**[Proposed]** (mirrors `AiCreditReadinessGate`'s exact shape — a pure
classifier, never itself enforcing anything) computes live readiness
across: active configured consultant (§10), Consultancy availability
exists for that consultant (§9), Stripe configured
(`config('billing.enabled')` + a real secret present), at least one active
Consultancy service with a valid price/currency, Google connection healthy
(§16) if Meet is being treated as mandatory for launch **[Product decision
needed]**: is a missing/failed Google connection allowed to still permit
paid bookings (with Meet link delivered "shortly" per the failure-recovery
path) or must it block bookable-ness entirely? — recommend the former
(matches the brief's own "a Google failure must not reverse a valid
payment" principle: if Google being briefly down blocks *starting* a paid
booking too, that's arguably an unnecessary tightening of the same
principle) — and queue/scheduler availability.

The **public booking flow itself** checks `ConsultancyBookingMode::current()`
directly (not the readiness service) at the point of exposing live slots
— `fully_enabled` shows real slots and the paid flow; `availability_only`
and `disabled` both fall back to the existing manual/free-text proposal
flow (§7.6's existing behaviour, completely unchanged, never removed) with
a clear "the way you book a consultation is changing soon" absence of any
broken half-state. The readiness service is purely an **Admin-facing
dashboard signal** ("you have set mode to fully_enabled but Google isn't
connected — bookings will confirm but Meet links may be delayed") — it
never itself flips the mode automatically. A human operator moves through
`disabled → availability_only → fully_enabled` deliberately, informed by
what the readiness service shows them, exactly as the brief requests
("clear Admin readiness indicators," never an automatic silent
activation).

---

## 19. Backward-compatibility plan

**[Proposed]** No existing data is touched:

- Existing manual/free-text Consultancy enquiries remain exactly as they
  are — `Appointment.status`/`ConsultationEnquiry.engagement_status` for
  historical rows are untouched; nothing backfills them into the new
  reservation/payment tables (those tables simply have no row for
  historical bookings, which is correct — they predate payment entirely).
- Existing services keep working exactly as today (`scheduling_mode:
  'manual'`) for as long as `ConsultancyBookingMode` stays `disabled`/
  `availability_only` — the manual free-text proposal flow is **never
  deleted**, only superseded in priority once `fully_enabled` is set,
  per the brief's own "should no longer be the primary booking experience
  once this upgrade is enabled" (a UI/routing priority change, not a code
  removal).
- Existing public/signed cancel-reschedule links continue to work
  unchanged for any appointment, historical or new, paid or not — that
  logic lives entirely in the generic, type-agnostic
  `PublicAppointmentActionController`.
- Existing tests for Consultancy (`ConsultancyPhase1Test`,
  `ConsultancyPhaseC2Batch1-6Test`, per current untracked test files)
  remain green with no modification required, since none of the proposed
  changes alter any existing method's behaviour when called with the
  pre-existing default context/mode.

---

## 20. Security and authorisation model

**[Proposed / confirmed-reusable]**

| Concern | Existing protection reused | New work required |
|---|---|---|
| Price/duration/consultant tampering | N/A — no existing pattern to reuse for a browser-supplied amount, since subscription Checkout never accepted one either | Commercial snapshot (§14) captured server-side only, at Checkout-creation time, from the authoritative `ConsultancyService`/`AppointmentType` rows — browser never supplies price/duration/consultant |
| Slot tampering | `AppointmentSchedulingService::withConflictCheck()`'s existing re-validate-inside-transaction pattern | Reservation creation must re-run the exact same server-side `generateAvailableSlots()` check before creating the hold — never trust a client-supplied slot without revalidation (already how `store()` works today for ordinary bookings) |
| Duplicate checkout creation | N/A | Idempotency key per reservation (§13) |
| Duplicate webhook fulfilment | `WebhookIngestionService`'s unique `(provider, provider_event_id)` constraint — reused unchanged | None — this is already solved generically |
| Refund authorisation | `ManageAiCreditsRequest`'s pattern (amount/reason/confirmed) as a direct template | New `ProcessConsultancyRefundRequest` mirroring that exact shape |
| Google OAuth callback security | N/A — first OAuth integration in this codebase | Standard `state` parameter CSRF protection (Google's own recommended pattern), validated server-side before token exchange |
| Token encryption | Laravel's `encrypted` cast (used elsewhere for sensitive fields already, e.g. wherever this codebase already encrypts a column) | Apply to the new Google token columns |
| Cross-organisation access | `ConsultationController::authorizeOwnOrganization()` — reused unchanged | None — reservations/payments inherit the same organisation scoping via their linked `ConsultationEnquiry`/`Appointment` |
| Public endpoint data leakage | `PublicConsultationController`'s existing "generic 404, no staff identity, no internal fields" discipline | Slot/reservation endpoints must follow the identical discipline — never expose the configured consultant's name/identity on any public endpoint (already true today, since no controller returns it) |
| IDOR on reservation/payment records | N/A | New endpoints must scope by the reservation's own signed reference or the authenticated user's organisation — never a bare sequential ID lookup on a public route |

---

## 21. Queue and scheduler impact

**[Proposed]**

- **Reused unchanged**: `billing-webhooks` queue stays subscription-only,
  per §14/§15's explicit non-mixing recommendation.
- **New queues**:
  - `consultancy-payments` — `ProcessConsultancyWebhookEventJob`. Should
    be added to the production worker's `--queue` flag
    (`docker/entrypoint.sh`) alongside `billing-webhooks`, e.g.
    `--queue=billing-webhooks,consultancy-payments,default` — listed
    before `default` for the same "don't delay behind slower jobs" reason
    `billing-webhooks` already is.
  - `google-calendar` — event creation/update/cancellation jobs. These
    call an external API with real latency and potential rate limits;
    keeping them on their own queue prevents a slow/rate-limited Google
    call from delaying payment-webhook processing or ordinary appointment
    emails.
- **New scheduled commands**:
  - `consultancy:reservations:expire` — every minute,
    `withoutOverlapping()`.
  - `consultancy:calendar:reconcile-missing` — recommend every 15–30
    minutes (Google failures should be noticed reasonably quickly, but
    this is not as time-critical as reservation expiry), `withoutOverlapping()`,
    `--dry-run` capable.
  - A Consultancy-specific Stripe reconciliation command mirroring
    `billing:stripe:reconcile`'s exact non-destructive, scan-and-report
    shape — manual/on-demand only, not scheduled (matching that command's
    own documented reasoning for staying manual).
- **Deployment configuration**: `docker/entrypoint.sh` must be updated
  (Stage 4/5, not Stage 0) and — per this codebase's own established
  discipline around this exact file
  (`DeploymentQueueConfigurationTest` already exists to statically parse
  `entrypoint.sh` and assert queue coverage, per the CLAUDE.md service
  table's "Billing Architecture Audit" section) — a parallel test should
  assert the new queues are present too, not just documented.

---

## 22. Database changes likely required

**[Proposed — not yet migrated]**

- `appointment_availabilities` — add `context` column.
- `appointment_availability_overrides` — add `context` column.
- `suresign_settings` — add `consultancy_consultant_user_id`,
  `consultancy_booking_mode`.
- New table: `consultancy_reservations`.
- New table: `consultancy_payments`.
- New table: `consultancy_calendar_syncs`.
- New table: `google_calendar_connections`.
- Possibly widen `appointment_types.assignment_mode`'s enum — **not
  required** given the dynamic-resolution recommendation in §10 (no new
  enum value needed if `fixedStaffFor()` for Consultancy types resolves
  the consultant dynamically rather than via the stored
  `default_assigned_user_id`/`assignment_mode` pair at all).

All additive; no existing column dropped or renamed; no existing table
restructured.

---

## 23. API and route changes likely required

**[Proposed]**

- `Admin → Consultancy → Availability`: new CRUD endpoints mirroring
  `AppointmentAvailabilityController`'s existing shape, scoped to
  `context = 'consultancy'`.
- New Consultancy consultant/settings endpoints (`GET`/`PUT`).
- New reservation endpoints (`POST /public/consultancy-services/{code}/reserve`,
  equivalent authenticated route) — additive, alongside the existing
  `/book` endpoint, not replacing it (the manual flow must keep working
  per §19).
- New Checkout creation endpoint.
- New Checkout success/cancel status-polling endpoints (never exposing
  raw Stripe IDs, per the brief).
- New Google OAuth connect/callback/disconnect/test-connection endpoints
  (Admin-only).
- New refund endpoint (Admin/Super Admin only).
- Possibly a new authenticated Consultancy reschedule endpoint (§7.6's
  gap) — **[Product decision needed]**: is authenticated in-app reschedule
  in scope for this upgrade, or does the existing signed-link reschedule
  flow (already generic, already works for Consultancy appointments today)
  suffice? Recommend confirming scope before Stage 2, since it changes
  estimate size.

---

## 24. Admin UI changes likely required

**[Proposed]**

- Consultancy Services edit modal: add `assignment_mode`/
  `default_consultant_user_id` fields — **actually, given §10's dynamic-resolution
  recommendation, these fields may not need to be admin-editable
  per-service at all** if the platform-wide consultant setting is the sole
  source of truth. Recommend removing/hiding these fields from the
  per-service form entirely (matching the brief's own "if consultant
  configuration belongs at the overall Consultancy level rather than per
  service, keep it out of each service modal") rather than adding them.
- New `Admin → Consultancy → Availability` page (§9), reusing the existing
  Appointments Availability page's components.
- New `Admin → Consultancy → Settings` (or extend an existing Consultancy
  settings area) — consultant picker, booking mode, hold duration, Stripe/
  Google readiness indicators (§18).
- New `Admin → Consultancy → Google Connection` panel (§16's connection
  status/connect/disconnect/test UI).
- `ConsultancyOperationsController`'s existing detail/workspace view gains
  read-only payment/calendar-sync status fields (§20 of the original
  brief) — additive to an existing page, not a new one.

---

## 25. Public and authenticated UX changes likely required

**[Proposed]** New booking steps (service → live slots → attendee/enquiry
details → review/price → Stripe redirect → success/processing page →
confirmation) on both the public marketing flow and the authenticated
in-app flow, sharing one slot-generation/reservation/payment backend per
the brief's explicit "do not implement separate public and authenticated
scheduling rules" — mirrors how `ConsultationEnquiryService::book()`
already is the one shared code path today (§2).

---

## 26. Stage-by-stage testing strategy

Delivered per-stage, not deferred:

- **Stage 1** (this document's own follow-on): unit tests for
  `ConsultancyConsultant`/`ConsultancyBookingMode`/
  `ConsultancyBookingReadinessService`; feature tests proving the
  `context`-column change produces byte-identical behaviour for existing
  Book a Demo availability calls (regression proof, not new-feature
  testing).
- **Stage 2**: availability CRUD tests for the new `context` dimension;
  slot-generation tests reusing the exact existing DST/notice/advance/
  buffer test patterns already in place for Appointments, parameterised
  for Consultancy; a specific cross-workflow conflict test (a confirmed
  Book a Demo appointment blocks a Consultancy slot and vice versa) —
  this test mainly *proves* §11's finding rather than testing new code.
- **Stage 3**: reservation concurrency tests (two simultaneous reserve
  attempts, one must lose), expiry tests, conversion idempotency (same
  Stripe event processed twice → one appointment).
- **Stage 4**: Checkout creation with a mocked provider (never live
  Stripe), webhook tests using recorded/fabricated payloads through
  `WebhookIngestionService`+the new processor, duplicate/out-of-order
  event tests, refund tests, price/amount tampering tests (client-supplied
  values ignored server-side).
- **Stage 5**: Google API fully mocked (a fake `GoogleCalendarProviderInterface`
  implementation, mirroring `FakeBillingProvider`'s existing pattern
  exactly) — event creation, idempotent duplicate-job handling, reschedule-
  updates-same-event, cancellation, failure-leaves-appointment-confirmed,
  missing-Meet recovery command.
- **Stage 6/7**: notification idempotency, full regression run of existing
  Consultancy/Appointments/Billing test suites (proving zero unintended
  behaviour change), authorisation/IDOR sweep.

**Cannot be certified in this environment** (stated up front, not
discovered late): live Stripe test-mode payment completion, real webhook
delivery from Stripe's servers, real Google OAuth consent flow, real
Google Calendar event/Meet creation, production queue worker behaviour,
manual browser/screen-reader testing. All of these require credentials
and/or network access this sandboxed environment does not have.

---

## 27. Documentation plan

Mapped per stage, not deferred to the end, matching this project's
existing "Documentation Review Requirement":

- **Stage 1**: this document; a new
  `internal-docs/commercial/suresign-consultancy-live-booking-specification-v1.md`
  capturing the approved decisions from this review as the ongoing
  build-log (mirroring `suresign-consultancy-phase-c2-specification-v1.md`'s
  existing role); update this repository's `CLAUDE.md` service table with
  the new consultant-configuration/readiness classes once built.
- **Stage 2**: `internal-docs/super-admin/consultancy.md` — Availability
  page section.
- **Stage 3**: reservation/hold behaviour added to the same doc.
- **Stage 4**: a new "Consultancy Payments" section — Stripe setup
  (env vars, webhook endpoint registration), refund policy/process.
- **Stage 5**: Google Calendar/Meet setup guide (Cloud Console project,
  OAuth client, required scopes, redirect URI per environment).
- **Stage 6**: customer-facing Help Centre/User Guide updates
  (`docs/`), FAQ updates, Release Notes (only once actually shipped,
  per the existing Versioning & Release Notes Policy).
- **Stage 7**: deployment doc updates (new queues in
  `docker/entrypoint.sh`), environment variable reference, troubleshooting/
  recovery-command reference, production-readiness report.

---

## 28. Environment and deployment requirements

**[Proposed]** New `.env.example` entries (placeholders only):

```
CONSULTANCY_RESERVATION_HOLD_MINUTES=15
CONSULTANCY_BOOKING_MODE=disabled

GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT_URI=
```

Stripe reuses existing `STRIPE_KEY`/`STRIPE_SECRET`/`STRIPE_WEBHOOK_SECRET_TEST`/`_LIVE`
— **no new Stripe env vars needed**, since Consultancy shares the same
Stripe account/mode as subscription billing (only the webhook event
routing is new, not the credentials). Google token encryption reuses the
existing `APP_KEY`-backed `encrypted` cast — no separate encryption key to
introduce. `docker/entrypoint.sh` gains the two new queue names (§21).

---

## 29. Known limitations and unverified assumptions

- **[Cannot verify locally]** Whether Meet conference generation via the
  Calendar API requires any scope beyond `calendar.events` — must be
  confirmed against live Google API docs/a real test account in Stage 5,
  not assumed here.
- **[Cannot verify locally]** Whether Google preserves the same Meet link
  across an `events.update` call in every circumstance (e.g. if the
  conference data itself needs to be explicitly re-requested on update) —
  flagged for a Stage 5 spike before committing to the reschedule-preserves-link
  design as certain.
- **[Cannot verify locally]** Local/CI environment's ability to complete a
  real Google OAuth consent flow at all (needs a real reachable redirect
  URI) — a documented manual/seeded-token developer path will likely be
  needed for local development.
- **[Assumption, needs confirmation]** Current database engine
  (MySQL/MariaDB per `CLAUDE.md`) support for the exact partial/filtered
  unique constraint recommended in §13 — needs confirming against the
  actual engine/version in use before finalizing that migration's SQL.
- **[Product decision needed, listed centrally]**:
  1. Should blocked periods stay shared across contexts, or also be
     scoped? (§9)
  2. Dynamic vs. denormalized resolution of the configured consultant on
     each Consultancy `AppointmentType`? (§10, recommend dynamic)
  3. Which Stripe payment methods will Consultancy Checkout accept —
     determines which webhook events are actually needed? (§14)
  4. Must Google be healthy for `fully_enabled` mode to permit *starting*
     a paid booking, or only to guarantee a same-session Meet link?
     (§18, recommend: not required to start)
  5. Is authenticated in-app reschedule in scope for this upgrade, or does
     the existing generic signed-link reschedule suffice? (§23)
  6. Is primary-calendar-only acceptable for Google Calendar selection at
     launch, or is a calendar picker required? (§16)
  7. Should a mid-flight consultant change trigger any bulk recalendaring
     of existing future confirmed appointments? (§17, recommend: no)

---

## 30. Recommended staged implementation plan

Unchanged from the brief's own proposed 7 stages, now grounded in the
findings above:

1. **Foundation** — `context` column migration + `AppointmentAvailabilityService`
   threading (with full regression proof of zero Book a Demo behaviour
   change), `ConsultancyConsultant`/`ConsultancyBookingMode`/readiness
   service, database constraints, this document plus its companion
   ongoing-spec doc.
2. **Availability UI and slot engine** — Consultancy Availability admin
   page, public+authenticated slot integration (both already share
   `ConsultationEnquiryService`/`fixedStaffFor()` — minimal new plumbing),
   the cross-workflow conflict regression test.
3. **Temporary reservations** — `consultancy_reservations`, concurrency
   protection, expiry command, booking-flow integration.
4. **Stripe payment** — `createOneOffCheckoutSession()`, `consultancy_payments`,
   commercial snapshot, new processor/job/queue, success/cancel pages,
   refund capability.
5. **Google Calendar and Meet** — OAuth connection, event lifecycle,
   reschedule/cancellation handling, retry/recovery.
6. **Notifications and operational surfaces** — customer/operator emails,
   `ConsultancyOperationsController` workspace additions, Activity Log
   coverage.
7. **Hardening and certification** — full regression, security review,
   queue/scheduler verification, documentation, production-readiness
   report.

Each stage ships its own tests and its own documentation updates, per §26/
§27 — no stage defers testing or docs to a later stage.

---

## 31. Explicit approval gate before Stage 1

**No code has been written.** This document, and the seven product
decisions listed centrally in §29, are the required output of Phase 0.

Please confirm:

1. This architecture review accurately reflects your intent (particularly
   §9's Option A recommendation, §10's dynamic-resolution recommendation,
   and §14's "new sibling processor, not an extension of
   `WebhookEventProcessor`" recommendation — these are the three
   highest-leverage decisions in this document).
2. Resolutions (or "decide later, don't block Stage 1 on it") for the
   seven items in §29.
3. Explicit go-ahead to begin Stage 1.

Stage 1 will not begin until this approval is given.
