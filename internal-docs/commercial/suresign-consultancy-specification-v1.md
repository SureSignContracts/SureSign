# SureSign Consultancy — Specification v1 (Phased, Pre-Implementation)

**Status: specification only. No code, migration, route, or config in this
document has been implemented.** This is the pre-implementation counterpart
to the AI Credit policy document and the Subscription & Billing checkpoints —
same discipline: agree the model on paper, in small reviewable phases,
before writing code.

## 0. Relationship to existing systems

Consultancy is a **new, independent commercial vertical**. It must not
touch, extend, or share tables/services with:

* AI Credits / `AiCreditLedgerService` / `AiCreditWorkflowLifecycle` / any
  `contract_ai_analyses` or `trade_package_ai_analyses` table or service
* Contract AI Analysis / Trade Package AI Analysis
* Adjudication (`adjudication_cases`, `adjudication_documents`) — a
  separate, dedicated future product, not this one
* Subscription & Billing's *recurring* subscription model
  (`subscriptions`, `SubscriptionLifecycleService`, entitlements) — a
  consultation is a one-off paid service, not a plan entitlement, and must
  never be modelled as one

Consultancy **does** reuse:

* The existing Appointments scheduling engine (`AppointmentType`,
  `Appointment`, `AppointmentSchedulingService`,
  `AppointmentAvailabilityService`, `AppointmentWorkflowService`,
  `AppointmentEmailService`, `AppointmentIcsService`,
  `AppointmentPublicLinkService`, `SendAppointmentReminders`) exactly as
  built in Phases 1–4 (see `internal-docs/super-admin/appointments.md`) —
  no fork, no parallel scheduling logic.
* `CurrencyService` for any currency display (never hardcode `£`/GBP).
* The existing per-controller `authorize()` pattern (no Policy classes in
  this codebase).
* The existing `suresign_settings` per-section convention for global
  toggles, and `ActivityLog::record()` for audit trail (no new logging
  framework).

### Architectural decision this spec makes now

**A Consultancy Service is a commercial/presentation wrapper around exactly
one `AppointmentType` — not a replacement for it, and not a second
scheduling concept.**

```
consultancy_services (new)              appointment_types (existing, untouched)
------------------------------          ------------------------------------------
code, display_name, description   1:1   duration_minutes, buffers,
price, currency, enabled,       ─────►   min_notice_hours, max_advance_days,
publicly_bookable,                       assignment_mode, default_assigned_user_id,
available_to_existing_customers,         requires_confirmation, meeting_method,
display_order, default_consultant,       cancellation/reschedule_notice_hours
is_introductory, max_bookings_per_day
(reserved)
```

`consultancy_services` owns every field that doesn't already exist on
`AppointmentType` (commercial + catalogue presentation). Scheduling fields
that already exist on `AppointmentType` (duration, buffers, notice windows,
assignment mode, default assignee) continue to live there — the Consultancy
admin editor writes to both tables in one form submission via a single new
`ConsultancyCatalogueService`, never duplicating a scheduling field onto the
new table. **This is what lets a new service be added later purely as a
data row, with zero change to `AppointmentSchedulingService`,
`AppointmentAvailabilityService`, or any other engine class.**

This also means Consultancy inherits the existing Super-Admin-only
`AppointmentType` mutation rule at the engine layer; a decision on who may
manage the `consultancy_services` *catalogue* layer is needed per phase (see
Permissions in each phase — recommendation below matches the Pricing
Management precedent: Super Admin **or** Admin, since both are
platform-wide roles here, not org-scoped).

---

## Product decisions this spec resolves (or explicitly defers)

| # | Decision | Resolution for C1–C2 | Notes |
|---|---|---|---|
| 1 | Must the public user pay before confirmation? | **No — not until Phase C3 exists.** In C1/C2 every consultation (public or authenticated) is created `pending_confirmation`/`confirmed` with **no payment step at all**, exactly like today's `Book a Demo` flow. Phase C3 is the first phase where price is ever collected. | Avoids building a "confirmed but unpaid" state twice — C1 has no payment concept to get wrong. |
| 2 | One service or many from the start? | **Catalogue from day one** (`consultancy_services` table), seeded with the three defaults (Quick/Standard/Extended). Never a single hardcoded type. | Per the brief's explicit requirement. |
| 3 | Can authenticated customers link a consultation to a project? | **Yes, optionally, in C2** — `appointments.project_id` already exists on the table today; C1 leaves it unused for Consultancy, C2 makes it selectable from the customer's own organisation's projects only. | Confirmed by reading `Appointment.php` — no schema change needed for the column itself, only for who's allowed to set it and how it's authorised. |
| 4 | What project information may the consultant access? | **C2 default: read-only access to the linked project's summary, contract, and document list — never edit access, never access to a project not explicitly linked to the consultation.** Exact field list is finalised in C2's own design step, not this document. | Needs a real authorization check mirroring `authorizeProject()`-style methods already used elsewhere, scoped to "this consultant, this consultation, this linked project only" — never blanket cross-organisation project access. |
| 5 | Are uploaded documents retained, and for how long? | **Deferred to C5.** Recommendation to confirm at that phase: retain for a fixed period post-consultation (e.g. 90 days) then purge, configurable, never indefinite by default. | Not decided now — flagged so C5 doesn't silently default to "forever." |
| 6 | Can consultation notes be shared with the customer? | **Yes, but only via an explicit, separate customer-visible summary field — never the consultant's internal notes by default.** Mirrors the brief's own "strict separation" requirement. | See C2 schema: `internal_notes` vs `customer_summary`, never one field with a visibility flag. |
| 7 | What happens when the consultant cancels? | **C2**: reuses the existing `cancelled` status/cancellation-email path; the customer is notified with a generic reason, and (if a payment exists once C3 ships) triggers the refund path defined in C3. In C1/C2 there is no payment, so cancellation is a plain status transition — no money to reason about yet. | |
| 8 | Automatic or manual refunds? | **Deferred to C3.** Recommendation to confirm at that phase: manual-approval-required by default (mirrors this codebase's existing conservative bias — e.g. `AiCreditLedgerService`'s manual-adjustment-only design, `StripeReconciliationService` having no auto-repair). Automatic refund only for a narrow, explicitly-approved case (e.g. consultant-initiated cancellation) once evidenced. | |
| 9 | Is tax included in the displayed price? | **Deferred to C3**, where a real Stripe Price/tax-code decision is made — mirrors the real Stripe Sandbox tax-code finding already hit in Subscription & Billing (`CheckoutController`'s Slice C2 section). Not decided now. | |
| 10 | Which currencies initially? | **GBP only at launch**, resolved via the existing `CurrencyService` fallback chain — never hardcoded. Additional currencies are a config/catalogue change per service, not an architecture change. | |

---

## Scheduling mode: manual vs. fixed booking UI

This is a standing architectural rule, not a Phase C1-only detail — it
governs every future Consultancy Service, including any built in later
phases:

* **Manual-assignment Consultancy Services intentionally use
  customer-proposed date and time.** This is not a placeholder — it is the
  correct, permanent behaviour for a service whose linked `AppointmentType`
  has `assignment_mode = manual`, exactly matching how a manual-mode
  Appointment Type already behaves everywhere else in this codebase.
* **Fixed-assignment Consultancy Services use the existing Appointment
  slot-selection workflow** — the same staff-calendar-backed
  `AppointmentSchedulingService::generateAvailableSlots()` data every other
  fixed-mode Appointment Type already uses, surfaced through a thin,
  Consultancy-scoped endpoint (see below) rather than a second scheduling
  implementation.
* **The linked `AppointmentType`'s `assignment_mode` is the single source of
  truth for which booking UI renders — Consultancy does not maintain its
  own scheduling mode or availability logic, and never infers the mode from
  the Consultancy Service's code, price, duration, `is_introductory` flag,
  or default consultant.** Both `ConsultationController::serviceDetail()`
  (authenticated) and `PublicConsultationController::show()` (public)
  report a `scheduling_mode` derived the same way — `fixed` only when the
  linked type's `assignment_mode` is `fixed` AND its
  `default_assigned_user_id` resolves to a currently-eligible member of
  staff, `manual` otherwise. Neither controller shares a base class for
  this (mirrors the existing Public/Admin Appointments controller split),
  but both must always resolve the identical answer for the same
  `AppointmentType` — covered by tests on the authenticated side
  (`ConsultancyPhase1Test`) verifying the fixed/manual split.
* **Two thin, Consultancy-scoped endpoints exist for the authenticated
  flow** — `GET /consultations/services/{code}` (scheduling info) and
  `GET /consultations/services/{code}/slots` (fixed-mode slot generation) —
  added once it was confirmed no existing authenticated endpoint could
  safely be reused: `POST /appointments/check-availability` is
  `role:Super Admin|Admin` only (Client has no access to it, and its
  dry-run-preview shape doesn't match "generate a list of slots for a
  date" anyway). Both new endpoints delegate every calculation to
  `AppointmentSchedulingService`/`AppointmentAvailabilityService` — no new
  scheduling rule was written for Consultancy. Consultancy Services remain
  a global catalogue (not organisation-scoped), so these two endpoints are
  deliberately not organisation-scoped either — organisation isolation is
  enforced where it actually matters, at the `Appointment`/consultation
  level (`ConsultationController::authorizeOwnOrganization()`), not by
  hiding scheduling info for a service any authenticated user could
  discover from the public site anyway.
* **The missing month-level "which dates are bookable" filter remains a
  documented UI optimisation gap, not a reason to build a second
  availability engine.** Both the public and authenticated Consultancy
  booking UIs let a visitor pick any in-window date and can return an empty
  slot list for a fixed-mode service if that date turns out to have no
  availability — safe, just less polished than the pre-filtered calendar
  Appointments' own public booking flow has. Closing this means adding a
  month-view availability endpoint mirroring
  `PublicAppointmentController::availability()`, reusing the exact same
  `AppointmentSchedulingService::bookableDatesInMonth()` the Appointments
  flow already uses — never a Consultancy-specific date-availability
  calculation.

---

## Marketing positioning: Consultancy vs. Adjudication

Consultancy and the future Adjudication product must never be conflated in
any customer-facing copy, from C1's first draft onward. Every Consultancy
marketing/booking surface must make the boundary explicit, for example:

> SureSign Consultancy provides professional guidance and discussion
> regarding construction contract administration. It is not legal
> representation, dispute resolution, or adjudication services.

This applies to the marketing page (C1 draft, C6 final review), the
booking confirmation flow, and any consultant-facing template that could
be forwarded to a customer. It is a standing constraint on copy across
every phase, not a single deliverable in one phase — each phase's
"Documentation updates"/"Frontend and marketing surfaces" section should
be checked against it before that phase is considered done.

---

## Consultancy Service Catalogue — cross-phase architecture (introduced in C1, used by every later phase)

### Default seeded services (C1)

| Code | Display Name | Duration | Default Price | `is_introductory` |
|---|---|---|---|---|
| `quick-consultation` | Quick Consultation | 15 min | £1 | `true` |
| `standard-consultation` | Standard Consultation | 30 min | £40 | `false` |
| `extended-consultation` | Extended Consultation | 60 min | £75 | `false` |

These are **seeded configuration**, editable like any `pricing_plans` row —
never a hardcoded `match($code)` in application code. A future service
(Contract Review, Commercial Review, Project Health Check, Training
Session, NEC Workshop, Bespoke Consultancy) is added the same way: one new
`consultancy_services` row + one new `appointment_types` row, no engine
change.

### Quick Consultation rules (introductory consultation, not a discount)

Recorded as data, not enforced logic, in C1:

* `consultancy_services.is_introductory = true` for this service.
* `consultancy_services.max_bookings_per_day` — reserved column, present
  from C1, **not enforced by any code path yet** (per the brief: "do not
  implement yet, simply design for it").
* A **one-per-customer/email/organisation** policy is explicitly a
  **future phase** — this spec deliberately does not add an enforcement
  column (e.g. a "has this email already had an intro consultation"
  check) in C1. When that policy is approved, it is added as a new,
  narrowly-scoped check inside `ConsultancyServiceService`/the booking
  controller — never bolted onto the generic Appointments engine, since it
  is a Consultancy-specific commercial rule, not a scheduling rule.
* The service's `description`/`public_description` fields (already
  supported per the schema below) are how "no document review, no written
  report, no project-specific legal or commercial advice" is communicated
  to the customer at booking time in C1 — this is copy, not a technical
  restriction, since C1 has no document upload or report-generation
  feature to restrict anyway (both arrive, if ever, in C5+).

---

## Phase C1 — Consultancy Foundation

### Objective

Prove the service-catalogue-on-top-of-Appointments model and the booking
flow (authenticated + public) end to end, with **zero money, zero external
integration, zero file handling**.

### In scope

* `consultancy_services` catalogue table + admin CRUD (create/edit/enable-
  disable/reorder), seeded with the three default services.
* `consultation_enquiries` table — the structured enquiry fields captured
  alongside a booking (see schema below).
* A `ConsultancyCatalogueService` that creates/updates a `consultancy_services`
  row and its linked `AppointmentType` together, in one transaction, so the
  two never drift out of sync.
* Authenticated customer entry point: a new, narrowly-scoped
  `ConsultationController` (Client-role accessible) that lists
  enabled+`available_to_existing_customers` services and creates a booking
  for the caller's own organisation only. **This is a new authorization
  surface** — Client users gain no access to `AppointmentController` or any
  other Appointments data; they only ever see their own organisation's
  consultation appointments through this dedicated controller.
* Public entry point: extends the *existing* `/book/{slug}` public booking
  flow — a `consultancy_services` row with `publicly_bookable = true` uses
  its linked `AppointmentType`'s existing `is_public`/slug exactly as any
  other public Appointment Type does today. No new public route family is
  needed for browsing/booking; only the enquiry-form fields are new.
* Reuse, unmodified: availability resolution, timezone handling, booking
  conflict rules, signed cancel/reschedule links, confirmation/cancellation/
  reschedule emails, reminders, ICS.

### Explicitly out of scope

* Payments of any kind (the £1/£40/£75 prices are **display-only** in C1 —
  no Stripe object, no payment requirement, no "unpaid" state).
* Google Meet / Google Calendar.
* Document uploads.
* Consultant notes, consultant dashboard/queue, reassignment workflow
  (Phase C2).
* Enforcement of `max_bookings_per_day` or one-intro-per-customer policy.
* Any change to `AppointmentSchedulingService`, `AppointmentAvailabilityService`,
  `AppointmentWorkflowService`, or any other existing Appointments engine
  class.

### Schema changes

```
consultancy_services
  id
  code                              string, unique, immutable after creation
  appointment_type_id               FK -> appointment_types.id, unique (1:1), not null
  display_name                      string
  description                       text, nullable (internal/admin-facing)
  public_description                text, nullable (customer/marketing-facing)
  enabled                           boolean, default false
  publicly_bookable                 boolean, default false
  available_to_existing_customers   boolean, default false
  price_minor_units                 integer, nullable   -- display-only in C1, see note
  currency                          string(3), default 'GBP'
  display_order                     integer, default 0
  is_introductory                   boolean, default false
  max_bookings_per_day              integer, nullable   -- reserved, unenforced in C1
  created_at / updated_at / deleted_at (soft deletes, mirrors AppointmentType)

consultation_enquiries
  id
  appointment_id                    FK -> appointments.id, unique (1:1)
  consultancy_service_id            FK -> consultancy_services.id
  title                             string
  description                       text
  project_stage                     string, nullable      -- free-standing field, not an enum table (mirrors contract_form below)
  contract_form                     string, nullable       -- e.g. NEC / JCT / FIDIC / Other, free text, not a hardcoded enum
  preferred_outcome                 text, nullable
  submitted_by                      enum('public','authenticated')
  created_at / updated_at
```

Note on `price_minor_units`: stored as integer minor units from C1 (matches
`App\Support\Billing\Money`'s existing convention), even though nothing
charges it yet — this avoids a schema change in C3 purely to fix the unit
representation. It is **display-only** in C1/C2; no code path in these two
phases treats it as an amount owed.

No changes to `appointments`, `appointment_types`, or any other existing
table. `appointments.project_id`, `.organization_id`, `.linked_user_id`
(already existing columns, confirmed unused by any current Consultancy
logic) are populated by the new `ConsultationController` for authenticated
bookings; left null for public bookings, exactly as they are today for a
public Appointment.

### Services and controllers

* `App\Services\Consultancy\ConsultancyCatalogueService` — create/update a
  `consultancy_services` row and its linked `AppointmentType` atomically;
  the only place either is written together.
* `App\Services\Consultancy\ConsultationEnquiryService` — validates and
  persists `consultation_enquiries` alongside appointment creation; called
  by both the authenticated and public booking paths so there is exactly
  one enquiry-creation code path, not two.
* `App\Http\Controllers\Api\ConsultancyServiceController` — admin catalogue
  CRUD (`/admin/consultancy/services`).
* `App\Http\Controllers\Api\ConsultationController` — authenticated
  customer-facing: list bookable services, create/view/cancel their own
  organisation's consultations (`/consultations`). Delegates the actual
  appointment creation to the existing `AppointmentSchedulingService` —
  does not reimplement booking logic.
* Public booking: **no new controller** — extends
  `PublicAppointmentController`'s existing `/book/{slug}` request handling
  to accept and persist the additional enquiry fields when the resolved
  Appointment Type is linked to a `consultancy_services` row. If this
  turns out not to fit cleanly without complicating
  `PublicAppointmentController`, the fallback is a thin
  `PublicConsultationController` that wraps the same underlying services —
  a call to be made during implementation, not this spec.

### Permissions and authorisation

| Action | Super Admin | Admin | Client |
|---|---|---|---|
| Manage `consultancy_services` catalogue | ✅ | ✅ (recommendation: matches Pricing Management's Super Admin **or** Admin precedent, not the stricter Appointment-Type-only rule) | ❌ |
| Manage linked `AppointmentType` scheduling fields (duration/buffers/notice/assignee) via the combined editor | ✅ | ✅ (via the same combined form; direct `AppointmentType` mutation endpoints remain Super-Admin-only, unchanged) | ❌ |
| View own organisation's consultations | ✅ | ✅ | ✅ — **new**, via `ConsultationController` only |
| Create a consultation for their own organisation | ✅ | ✅ | ✅ — **new** |
| Cancel/reschedule their own organisation's consultation (pre-confirmation window) | ✅ | ✅ | ✅ — **new**, via signed public-style link or an authenticated equivalent action (design decision at implementation time: reuse the existing signed-link mechanism even for authenticated users, rather than inventing a second cancel/reschedule code path) |
| View/manage another organisation's consultations | ✅ | Only if assigned as consultant (existing Appointments rule — Admin sees only appointments assigned to them + unassigned) | ❌ |

Client access is genuinely new and must be implemented as its own
authorization boundary — never by relaxing `AppointmentController`'s
existing `role:Super Admin|Admin` middleware. `ConsultationController`
scopes every query to `organization_id = $request->user()->organization_id`
(unless Super Admin/Admin), matching the `authorize()` pattern used
elsewhere in this codebase.

### Frontend and marketing surfaces

* `frontend/src/app/admin/consultancy/services/page.tsx` — catalogue
  management (Super Admin/Admin), mirroring the Pricing Management plan
  editor's UX conventions.
* `frontend/src/app/app/consultations/` — authenticated customer surface:
  service list, enquiry form, booking confirmation, own-consultation list.
  New sidebar entry: **Consultancy**.
* `marketing/src/app/consultancy/page.tsx` — the explanatory marketing page
  from the brief (what it is, example topics, what it explicitly is not:
  "win your adjudication" language never appears). Links to `/book/{slug}`
  for each `publicly_bookable` service, reusing the existing
  `PublicBookingForm` with the additional enquiry fields appended.
  **Must include an explicit positioning statement distinguishing
  Consultancy from the future Adjudication product** — e.g. "SureSign
  Consultancy provides professional guidance and discussion regarding
  construction contract administration. It is not legal representation,
  dispute resolution, or adjudication services." This boundary is a
  permanent requirement of the page from its first draft in C1 onward, not
  a C6 launch-polish afterthought — see the Marketing Positioning section
  below.
* New top-level marketing nav entry: **Consultancy**.

### Testing requirements

* `ConsultancyCatalogueService` keeps `consultancy_services` and its linked
  `AppointmentType` in sync on create/update; never creates an orphaned row
  on either side.
* Seeded default services exist, are correctly priced/durationed, and
  `quick-consultation` is flagged `is_introductory`.
* Authenticated booking: a Client user can only see/book services flagged
  `available_to_existing_customers`, only for their own organisation;
  cannot view another organisation's consultation.
* Public booking: only `publicly_bookable` + `enabled` services are
  reachable; a disabled/non-public service's slug 404s exactly like a
  private Appointment Type does today.
* `consultation_enquiries` is correctly linked 1:1 to its appointment and
  captures all structured fields.
* Regression: existing non-Consultancy Appointment Types/bookings are
  completely unaffected (no shared code path was modified in a way that
  changes existing behaviour).

### Documentation updates

* New `internal-docs/super-admin/consultancy.md` (mirrors
  `appointments.md`'s structure), cross-linking to
  `appointments.md` for the shared engine.
* `CLAUDE.md` — add `consultancy_services`/`consultation_enquiries` to the
  key-tables list, and a short "Consultancy" entry in the services table
  once C1 ships.
* Marketing site docs/nav updated for the new page.
* No public Help Centre article yet — deferred to C6 (production rollout),
  since C1 is not the public launch.

### Exit criteria

* An authenticated Client can book, view, and cancel a Consultation for
  their own organisation against any enabled/available service.
* A public visitor can browse the Consultancy marketing page and book a
  publicly-bookable service with no account, receiving the same
  confirmation/ICS/reminder experience as any other public Appointment
  Type today.
* No payment, Google Meet, file upload, or consultant-specific tooling
  exists or is implied by any UI shown.
* Zero changes to `AppointmentType`/`Appointment` schema or to any existing
  Appointments engine service.

---

## Phase C2 — Consultant Operations

**Superseded by a dedicated, phase-specific specification — that document,
not this section, is authoritative for C2.** See
[suresign-consultancy-phase-c2-specification-v1.md](suresign-consultancy-phase-c2-specification-v1.md)
for the full design: the consultant dashboard/queue/detail workspace, the
`engagement_status` state machine (a field distinct from
`appointments.status`, replacing this section's original assumption of "no
new statuses"), internal notes and the customer-summary publishing
workflow, project linkage against the real `Project` states, permissions,
the `ConsultationPresenter` field boundary, notifications, and a full
decision register (approved decisions, recommendations, genuinely
unresolved product decisions, and deferred C3+ concerns).

This section is kept only as a historical record of the original,
brief C2 sketch written before C1 was implemented — several of its
specific assumptions (e.g. `customer_summary` living on `appointments`,
reusing `Appointment::STATUSES` for engagement tracking) were revised
during the dedicated C2 specification pass. Do not implement against this
section.

---

## Phase C3 — One-off Consultancy Payments

### Objective

Require payment for a paid consultation before it is confirmed, using a
genuinely new one-off payment pattern — **not** an extension of the
existing subscription billing services.

### In scope

* `consultancy_services.price_minor_units`/`currency` become load-bearing
  (previously display-only).
* A new one-off Stripe Payment/Checkout flow, deliberately separate from
  `PlanPriceMappingService`/`SubscriptionLifecycleService`/
  `CheckoutSessionService` (all of which assume a recurring subscription
  relationship that does not apply here).
* Booking now creates the appointment in a new pending-payment-equivalent
  state (implementation detail: either a new narrow status value or a
  dedicated `consultation_payments` table tracking payment state
  independently of `appointments.status` — **decision needed at C3 design
  time**, leaning toward the latter to avoid overloading
  `Appointment::STATUSES`, which is shared with every non-Consultancy
  Appointment Type).
* Refund handling (manual-approval default — see product decision #8).
* Failed-payment handling: booking is not confirmed; slot is released
  after a short hold window (mirrors `CheckoutSessionService::startCheckout()`'s
  existing stale-Checkout self-healing pattern conceptually, not by reusing
  its code, since that code is subscription-specific).
* A dedicated webhook path for consultancy payment events — separate from
  `billing_webhook_events`/`WebhookEventProcessor` (which are scoped to
  subscription lifecycle events specifically) or, if reused, handled by a
  clearly Consultancy-specific event-type branch reviewed on its own merits
  at implementation time.

### Explicitly out of scope

* Any change to `subscriptions`/`billing_*` tables or services.
* Any entitlement/`FeatureGate` coupling — a consultation is never gated by
  an organisation's subscription plan.
* Google Meet/documents (C4/C5).

### Schema changes (indicative — finalised at C3 design time)

```
consultation_payments
  id
  appointment_id            FK -> appointments.id
  consultancy_service_id    FK -> consultancy_services.id
  amount_minor_units        integer
  currency                  string(3)
  provider                  string   -- 'stripe'
  provider_payment_intent_id / provider_checkout_session_id
  status                    enum('pending','paid','failed','refunded','cancelled')
  paid_at / refunded_at     nullable timestamps
  livemode                  boolean   -- mirrors existing billing_* convention
```

### Services and controllers

* `App\Services\Consultancy\ConsultationPaymentService` — the sole writer
  of `consultation_payments`, mirroring `WebhookIngestionService`'s
  "trusted boundary, never interprets, just records" discipline for
  ingestion, and a separate processor for interpretation — same separation
  of concerns already proven in Billing.
* `App\Http\Controllers\Api\ConsultationCheckoutController` — creates the
  one-off payment/Checkout session.
* A dedicated webhook controller/route for consultancy payment provider
  events.

### Permissions and authorisation

No change to who can book; adds: only Super Admin (recommendation, mirrors
`AiCreditsGrantController`'s Super-Admin-only mutation precedent for
money-moving actions) can manually trigger a refund.

### Frontend and marketing surfaces

* Checkout step inserted between enquiry submission and confirmation, for
  both authenticated and public flows.
* Payment status shown on the consultation detail view (both customer and
  consultant sides).

### Testing requirements

* No consultation is ever confirmed without a successful payment record,
  for a service with a non-null price.
* Webhook idempotency (mirrors `billing_webhook_events`'s unique-constraint
  discipline) — a duplicate payment-provider event never double-processes.
* Refund path requires explicit authorisation and is fully audit-logged.

### Documentation updates

* `consultancy.md` C3 section; a dedicated note in `CLAUDE.md`'s services
  table once built (matching how every Billing service is documented
  there).

### Exit criteria

* A real Stripe Test Mode payment can be completed end-to-end for a paid
  consultation, confirming it only on payment success.
* A failed/abandoned payment never leaves a confirmed-but-unpaid
  consultation.

---

## Phase C4 — Google Calendar and Meet

### Objective

Add a real Google Meet link to a confirmed (and, once C3 exists, paid)
consultation, with safe degradation if the integration is unavailable —
behind a provider abstraction, not a Google-specific integration point.

### In scope

* **`App\Services\Consultancy\MeetingProviderInterface`** — a small
  provider-agnostic contract (e.g. `createMeeting()`, `updateMeeting()`,
  `cancelMeeting()`) that every meeting-video integration implements,
  mirroring `BillingProviderInterface`'s existing abstraction discipline
  for Stripe in this codebase. Only one implementation ships in C4 —
  `App\Services\Consultancy\GoogleMeetProvider` — but no other Consultancy
  service ever calls Google's API directly; every call goes through the
  interface. This is what would let a future Teams/Zoom provider be added
  as a second implementation with a config switch, not a rewrite — that
  future provider is **not** being built now, only the seam for it.
* Google OAuth credential architecture (service-account or a connected
  operator account — decision at C4 design time; a per-consultant OAuth
  connection is more realistic given multiple consultants, but adds
  material complexity documented as a trade-off, not assumed here).
* Create a Calendar event + Meet link only after the consultation reaches
  its confirmed (and paid, once applicable) state.
* Store `provider_event_id`/`meeting_url` — `appointments.meeting_url`
  **already exists** and is the natural home for the Meet link; no new
  column needed for that specific field.
* Reschedule/cancellation sync to the Google-side event.
* Fallback: if Google integration fails or is unavailable, the
  consultation still confirms normally with the existing
  `meeting_method`/`location`/manual-instructions fields — Google Meet is
  additive, never a hard dependency of confirmation.

### Explicitly out of scope

* Two-way sync (e.g. reading changes made directly in Google Calendar
  back into SureSign) — one-way push only, matching the existing ICS
  "one-way calendar file" precedent explicitly documented in
  `appointments.md`.
* Outlook/Teams/Zoom — not requested, not built.

### Schema changes

```
appointments  (existing table — additive columns only)
  google_calendar_event_id   string, nullable
```

(`meeting_url` reused as-is for the Meet link.)

### Services and controllers

* `App\Services\Consultancy\MeetingProviderInterface` (contract) +
  `App\Services\Consultancy\GoogleMeetProvider` (sole implementation in
  C4) — the only caller of the Google Calendar API; every other
  Consultancy service depends on the interface, never on
  `GoogleMeetProvider` directly.
* A thin `App\Services\Consultancy\MeetingSchedulingService` resolves the
  configured provider (config-driven, one active provider at a time — same
  shape as `AiCreditOperatingMode::current()`'s single-authoritative-setting
  pattern) and is what `AppointmentWorkflowService` integration actually
  calls.
* Hooked into `AppointmentWorkflowService::transition()`'s existing
  confirmation path and `::reschedule()`, via an event/listener rather
  than inline coupling, so the core Appointments engine still has zero
  Consultancy-specific code in it.

### Permissions and authorisation

No end-user-facing permission change; the OAuth credential itself is
Super-Admin-only configuration (mirrors AI/Anthropic settings' existing
"admin settings, not env-only" convention already used elsewhere in this
codebase).

### Frontend and marketing surfaces

* "Join Meeting" link/button on both the customer confirmation email/page
  and the consultant detail view, once populated.

### Testing requirements

* A Google API failure never blocks or reverses a confirmation — verified
  by a test that simulates the integration throwing/timing out.
* Reschedule updates the same Google event (not a duplicate); cancellation
  removes/cancels it.

### Documentation updates

* `consultancy.md` C4 section including the explicit one-way-sync
  limitation and the credential architecture chosen.

### Exit criteria

* A real confirmed consultation gets a working Meet link end-to-end in a
  test Google Workspace/account.
* Disabling the integration entirely still allows normal confirmed
  bookings with no Meet link, no errors.

---

## Phase C5 — Documents and Pre-consultation Pack

### Objective

Allow a customer to attach supporting documents to a consultation enquiry,
safely, without conflating them with the main project Documents feature.

### In scope

* Consultation-specific file uploads (contract, drawings, notices, PDF,
  ZIP) attached to a `consultation_enquiries` row.
* File type/size limits, virus/malware scanning (reuse whatever existing
  upload pipeline this codebase already has for `file_uploads`/`documents`
  — confirm the current scanning approach at implementation time rather
  than assuming one here).
* Public-upload token security — a public (unauthenticated) enquiry's
  upload must be bound to that specific booking's token, never a bare
  open-upload endpoint.
* Access boundaries: consultant (assigned only) and the booking customer
  can see the files; nobody else.
* **Explicit rule, per the brief**: these files are never auto-copied into
  the organisation's normal project Documents — only a later, explicitly
  approved rule could change this, and it is not approved here.

### Explicitly out of scope

* Retention policy enforcement beyond what's decided at this phase (see
  product decision #5 — must be resolved before this phase starts, not
  during it).
* Any document analysis/AI processing of the uploaded files — that would
  be a new AI integration, explicitly against this codebase's standing
  rule without a separate, deliberate request.

### Schema changes

```
consultation_documents
  id
  consultation_enquiry_id   FK -> consultation_enquiries.id
  file_upload_id            FK -> file_uploads.id   -- reuse existing file_uploads, don't reinvent storage
  uploaded_by                 enum('customer','consultant')
  visible_to_customer        boolean, default true
  created_at
```

### Services and controllers

* `App\Services\Consultancy\ConsultationDocumentService` — upload/list/
  delete, enforcing the access boundaries above; built on the existing
  file-upload primitives, not a new storage layer.

### Permissions and authorisation

Only the assigned consultant + the booking's own customer (authenticated
case) or a valid public token holder (public case) may upload/view.

### Frontend and marketing surfaces

* File upload control on the enquiry form (both authenticated and public).
* Attachment list on both the customer and consultant consultation detail
  views.

### Testing requirements

* A public upload token cannot be used to access another consultation's
  files.
* File type/size limits are enforced server-side, not just client-side.
* Files never appear in the organisation's general Documents list.

### Documentation updates

* `consultancy.md` C5 section, including the final retention-period
  decision reached before this phase started.

### Exit criteria

* A customer can attach documents at enquiry time; the consultant can view
  them ahead of the consultation; access is provably scoped to that one
  consultation only.

---

## Phase C6 — Marketing and Production Rollout

### Objective

Take Consultancy from working feature to a supported, documented, publicly
launched product surface.

### In scope

* Full Consultancy marketing page (final copy, pricing table sourced live
  from `consultancy_services`, never hardcoded).
* Public booking funnel polish (loading states, error states, mobile).
* Terms, privacy, cancellation and refund messaging — real, reviewed copy,
  not placeholder text; must accurately reflect the C3 refund policy
  actually implemented.
* Final review of the Adjudication positioning boundary (see Marketing
  Positioning section below) across every surface it appears on (marketing
  page, booking confirmation copy, any consultant-facing template) —
  confirming it's not just present on the marketing page but consistent
  everywhere a customer could form the impression Consultancy is a dispute
  resolution or legal service.
* Analytics/operational reporting: booking volume, conversion, revenue per
  service, consultant utilisation — read-only reporting only, reusing the
  existing Application Monitoring / module-usage patterns where they fit,
  not a new analytics stack.
* Public Help Centre article, FAQ entries, onboarding mention (per this
  repo's standing Documentation Review Requirement).
* Production rollout checklist and support runbook (what support does when
  a payment fails, a Meet link doesn't generate, a consultant is
  unavailable, etc.).

### Explicitly out of scope

* Any new service beyond the three defaults (adding more is a catalogue
  data change, not a C6 task).
* Any change to the core scheduling engine.

### Schema changes

None expected — this phase is presentation, content, and operational
readiness, not new data model.

### Services and controllers

* Reporting queries only, likely a `ConsultancyReportingService` (read-only,
  mirrors `AiTelemetryReportingService`'s "internal reporting service,
  builds from existing presenters/data, never a new source of truth" shape).

### Permissions and authorisation

Reporting: Super Admin/Admin only, matching every other internal reporting
surface in this codebase.

### Frontend and marketing surfaces

* Finished `marketing/src/app/consultancy/page.tsx`.
* `/admin/consultancy/reporting` (or a section of the existing dashboard).

### Testing requirements

* End-to-end smoke test of the full funnel: marketing page → book →
  (pay, if applicable) → confirm → Meet link → complete → notes → summary
  visible to customer.
* Release-readiness review against this repo's own Versioning & Release
  Notes Policy — Consultancy going live is exactly the kind of deliberate,
  customer-facing milestone that policy describes; a version bump (Minor,
  e.g. a new module) is only made when this phase is actually deployed,
  never earlier.

### Documentation updates

* Help Centre, FAQ, onboarding, Release Notes (only upon actual shipping,
  per the Versioning & Release Notes Policy), `CLAUDE.md`, `project-context.md`.

### Exit criteria

* Consultancy is a fully supported, documented, publicly bookable product
  surface with a real support runbook — the point at which this stops
  being "phased implementation" and becomes "an owned module," same bar
  every other production module in this codebase is held to.

---

## Roadmap (beyond C6 — documentation only, nothing here is implemented or scheduled)

These are approved as future direction, explicitly **not** part of the
C1–C6 implementation scope. Each is a candidate for its own future phase
(C7+), to be scoped and reviewed on its own merits when actually taken up
— nothing below should be pre-built, stubbed, or half-implemented during
C1–C6 "just in case."

### Future Consultants Catalogue

Today, and throughout C1–C6, a "consultant" is simply an existing
Admin/Super Admin user assigned to a consultation — there is no separate
consultant concept, and Graham is the only consultant in practice. The
architecture should not be designed around a permanent single-consultant
assumption, though: a future phase could introduce a dedicated
`consultants` catalogue (profile, biography, specialties/topics, active
status, linked `user_id`) that a `consultancy_services` row could
optionally reference (e.g. "this service is offered by these specific
consultants," or a public profile shown at booking time). Nothing in
C1–C6 forecloses this — `consultancy_services.default_consultant_user_id`
and `appointments.assigned_user_id` both already point at `users` directly,
which a future `consultants` table could sit alongside or wrap without a
breaking change to either.

### Future customer feedback

After a consultation reaches `completed`, a future phase could collect
optional customer feedback: a 1–5 star rating plus optional free-text
comments, linked to the appointment/consultation. Not part of C1–C6 —
no schema, endpoint, or UI for this exists yet.

### Future consultation outcomes

A future phase could add a structured outcome classification to a
completed consultation, distinct from its lifecycle `status` — for
example: Completed, Follow-up Required, Training Recommended, Escalated,
No Further Action. This would likely live alongside (not replace)
`Appointment::STATUSES`, since it's an outcome classification, not a
scheduling state — mirroring the same reasoning C2 already applies to
`customer_summary` sitting alongside `internal_notes` rather than
overloading a single field. Not part of C1–C6.

---

## Summary: what must never happen in any phase

* No phase touches `AiCreditLedgerService`, `contract_ai_analyses`,
  `trade_package_ai_analyses`, `adjudication_*` tables, or
  `subscriptions`/`billing_*` subscription-lifecycle tables/services.
* No phase modifies `AppointmentSchedulingService`,
  `AppointmentAvailabilityService`, or `AppointmentWorkflowService`'s
  existing behaviour for non-Consultancy Appointment Types — Consultancy
  only ever adds new `AppointmentType`/`Appointment` *rows*, never new
  *branches* inside those engine classes.
* No new currency/branding/document-number logic is invented — `CurrencyService`,
  `BrandingService`, `DocumentNumberService` (if a Consultancy reference
  number is ever needed) are reused as-is.
* No hardcoded consultation type, price, or duration in application code —
  everything customer-facing is a `consultancy_services` row.
