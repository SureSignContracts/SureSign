# Subscription & Billing (Stripe) — Foundation

**Status: database/model/config/provider foundation (checkpoint 1),
`PlanPriceMappingService` and `BillingCustomerService` (checkpoint 3),
`SubscriptionLifecycleService` (checkpoint 4), `CheckoutSessionService`
(checkpoint 5), verified webhook ingestion (checkpoint 6), persisted
webhook event processing/lifecycle dispatch (checkpoint 7), Subscription
Event Hardening and Checkout Metadata Correlation (checkpoint 8),
Automatic Webhook Processing and Recovery Orchestration (checkpoint 9),
Stripe Paused Subscription Policy and Billing Operations Readiness
(checkpoint 10 — `paused` policy confirmed; found the deployment gap
below), and Billing Worker Alignment and Offline Production-Readiness
Validation (checkpoint 11 — the deployment gap checkpoint 10 found is now
fixed and proven with real database-queue integration tests, entirely
without a Stripe account), Plan Entitlements & Feature Access
Architecture (checkpoint 12 — the layer above Billing every application
module will eventually call; architecture only, nothing calls it yet),
Subscription Lifecycle and Entitlement Access Policy Review
(checkpoint 13 — the authoritative lifecycle-status-to-access-mode
matrix, a new `SubscriptionAccessPolicy` service, and a real safety fix
to `FeatureGate`'s override precedence found during this review), and
Subscription Commercial State Automation & Immutable Entitlement
Snapshot Foundation (checkpoint 14 — closes checkpoint 13's two
documented automation gaps for grace-period expiry and trial expiry,
automates scheduled cancellation, and introduces the immutable
`billing_entitlement_snapshots` foundation `FeatureGate` now prefers over
live `PlanEntitlements`), and Subscription Suspension Completion,
Snapshot Integrity & Commercial Automation Hardening (checkpoint 15 —
completes the suspension lifecycle with a real effective-date field and
full scheduling/rescheduling/cancellation/automation, and hardens
`FeatureGate`'s snapshot fallback so only a genuinely legacy subscription
uses the live-`PlanEntitlements` compatibility path), and Stripe Test Mode
Integration, Provider Synchronisation & End-to-End Billing Validation
(checkpoint 16 — closes the "no provider-side Stripe Price update is
executed" gap: `SubscriptionPlanChangeService` sends/confirms real
upgrade/downgrade Price changes, `InvoiceSyncService` persists
invoice/payment history and drives payment-failure/recovery, and
`BillingPortalService`/`StripeReconciliationService` add the Customer
Portal and provider-drift-detection foundations — no Stripe Test Mode
credentials were configured this checkpoint, so nothing here was executed
against a real Stripe account; see that section's "External validation"
subsection) — see
[SureSign Commercial Strategy v1](../commercial/suresign-commercial-strategy-v1.md)
and [SureSign Entitlement Specification v1](../commercial/suresign-entitlement-specification-v1.md)
for the approved commercial/entitlement model these services follow.
Webhook events are now durably verified, recorded, AND automatically
processed end-to-end: ingestion dispatches a queued job after commit,
`WebhookEventProcessor` claims and interprets the event, and a scheduled
recovery command sweeps up anything stranded (stale processing claims,
retryable failures, undispatched received rows) — see "Automatic Webhook
Processing and Recovery Orchestration (checkpoint 9)" below. Invoice/
payment sync, entitlement persistence, Super Admin UI, organisation-facing
UI, and Customer Portal all remain unbuilt — see "Deferred" below.** This
module is not reachable or usable by Super Admin or customers at this
stage; it exists only as backend foundation for the next checkpoint to
build on.

## Pricing vs. Billing

These are two separate systems and must not be conflated:

- **Pricing Management** (`internal-docs/super-admin/pricing-management.md`)
  controls the *public presentation* of commercial plans on the marketing
  Pricing page — plan names, prices shown, the comparison table, FAQs.
  Complete and unaffected by this work.
- **Subscription & Billing** (this document) controls *real organisation
  subscriptions* — which organisation is actually subscribed, how they pay,
  and whether their access should remain active. Stripe is the payment
  processor; SureSign remains the source of truth for access, plan
  assignment, lifecycle status, grace periods, and entitlements.

Editing a `pricing_plans` row's public price must never silently reprice an
existing subscriber — see "Historical pricing protection" below.

## Architecture decision

`stripe/stripe-php` (v21.0.0) is used directly — **Laravel Cashier is not
installed**. Cashier assumes a single `Billable` Eloquent model with its own
schema/status vocabulary; this platform's billable entity is the
**Organisation**, and the required internal status vocabulary/lifecycle
diverges deliberately from Stripe's own. A thin adapter
(`App\Services\Billing\StripeBillingProvider`) around the official SDK keeps
business logic in SureSign's own service layer, matching how
`ClaudeAiProvider`/`EmailNotificationService` already wrap their vendor SDKs.

## Database

Nine new tables plus one additive relationship onto the completed Pricing
Management schema:

- `billing_customers` — one Stripe Customer mapping per organisation/provider.
- `subscriptions` — the authoritative SureSign subscription. Status is one
  of `App\Support\Billing\SubscriptionStatus`, never a raw Stripe status
  string.
- `subscription_items` — line items on a subscription (one plan item today;
  seats/add-ons/usage products later without a schema change).
- `billing_invoices`, `billing_payments` — local mirrors of Stripe invoices/
  payments, keyed uniquely by provider ID.
- `billing_webhook_events` — durable webhook idempotency ledger, unique on
  `(provider, provider_event_id)`.
- `billing_checkout_sessions` — tracks checkout sessions initiated from
  SureSign.
- `billing_adjustments` — manual credits/waivers/discounts ledger, no
  automatic financial effect.
- `pricing_plan_provider_prices` — maps a `pricing_plans` row to one or more
  Stripe Price objects (see "Historical pricing protection").
- `billing_reference_sequences` — backs human-readable references (see
  below); one row per reference type.

**Money is stored as integer minor units** (e.g. `2999` = £29.99) in every
billing table — deliberately different from `pricing_plans.monthly_price`/
`annual_price`, which stay decimal major units for display.
`App\Support\Billing\Money` is the single conversion boundary; nothing else
should do a manual `* 100` or `/ 100`.

**Concurrency**: MySQL has no partial/conditional unique index, so "only one
live subscription per organisation" cannot be enforced by a unique
constraint alone. The `(organization_id, status)` index on `subscriptions`
supports the query pattern; actual enforcement is a row-lock-inside-a-
transaction responsibility for the not-yet-built `SubscriptionService`.

## Historical pricing protection

`subscriptions.plan_code_snapshot`, `plan_name_snapshot`, and
`commercial_terms_json` (plus the subscription's own `unit_amount`/
`subtotal_amount`/`tax_amount`/`total_amount`) freeze the commercial terms
agreed at subscription-creation time. Editing a `pricing_plans` row later
never touches these. `pricing_plan_provider_prices` similarly never mutates
an existing row's `unit_amount`/`currency` — a price change creates a new
mapping row and deactivates the old one for *new* checkouts, while existing
subscriptions keep referencing their original `provider_price_id`.

## Status vocabulary and lifecycle

`App\Support\Billing\SubscriptionStatus` defines eleven statuses: `draft`,
`pending_payment`, `incomplete`, `trialing`, `active`, `past_due`, `unpaid`,
`paused`, `cancelled`, `expired`, `suspended`. Four of these
(`draft`/`pending_payment`/`suspended`/`expired`) have no Stripe equivalent
by design. `App\Support\Billing\SubscriptionStatusMapper` is the *only*
place a raw Stripe status string is ever translated into this vocabulary —
nothing else should compare against a Stripe status string directly.

`App\Support\Billing\SubscriptionTransitions` defines which status changes
are valid (e.g. `active → past_due → suspended`, `pending_payment → active`).
This is a foundation only — no service yet performs a transition; the
not-yet-built `SubscriptionService` (next checkpoint) is required to consult
this map rather than re-deriving its own rules, and to record every
transition via the existing `ActivityLog` (previous/new status, reason,
actor, provider event ID) rather than a new dedicated table.

## Human-readable references

`App\Services\Billing\BillingReferenceService` generates operator-facing
references (`SUB-000001`, `INV-000001`, `PAY-000001`, `CHK-000001`) using
the same atomic `lockForUpdate()`+`increment()` pattern
`App\Services\DocumentNumberService` already uses for document numbers —
one sequence row per reference type in `billing_reference_sequences`.

## Provider abstraction

`App\Services\Billing\BillingProviderInterface` is the only boundary between
SureSign's billing services and the configured payment provider. An
implementation may create/retrieve provider objects, create checkout/portal
sessions, and verify webhook signatures — it must never decide platform
access, entitlements, suspension, transition validity, or notifications;
those stay in SureSign's own services.

- `StripeBillingProvider` — thin adapter around `\Stripe\StripeClient`.
- `FakeBillingProvider` — deterministic in-memory fake, bound automatically
  in the `testing` environment via `App\Providers\BillingServiceProvider`.
  No automated test may construct a real Stripe client.
- `BillingProviderManager` — resolves/validates the configured provider name
  (`billing.provider`), independent of which concrete implementation the
  container resolves.

Webhook signature verification uses the real Stripe SDK
(`\Stripe\Webhook::constructEvent`) even in the fake-bound testing
environment — `StripeBillingProviderWebhookSignatureTest` proves this
against locally-signed fixture payloads with no network call.

## Configuration

`config/billing.php`, environment variables in `.env`/`.env.example`
(`BILLING_*`, `STRIPE_*`). Stripe keys are **environment-only** — unlike the
Anthropic/Brevo provider keys in `suresign_settings`, Stripe secret/webhook
keys are never stored in the database or Super Admin-editable, since they're
a direct financial-exposure risk rather than an AI-cost risk.

`BILLING_ENABLED` and `BILLING_ENFORCEMENT_ENABLED` both default `false` and
must stay `false` until a deliberate go-live decision.

`App\Support\Billing\BillingConfigGuard`, called from
`AppServiceProvider::boot()`, refuses to boot in `local`/`testing` if a
live-looking Stripe key (`sk_live_`/`pk_live_`) is configured, unless
`BILLING_ALLOW_LIVE_KEYS_IN_TESTING=true` is explicitly set. Automated tests
never make a real Stripe API request regardless of this flag — the
container always binds `FakeBillingProvider` in `testing`.

## Sensitive data handling

`App\Support\Billing\BillingPresenter` is the explicit, hand-whitelisted
array-shaping layer for every billing model that may reach an API response
— it exists because this codebase has no `app/Http/Resources` layer, and
billing models carry fields (`provider_payload_json`, full provider
customer IDs) that must never round-trip to the frontend verbatim. Not yet
used by any controller (none exist yet this checkpoint) — defined now so the
first controller that needs it doesn't invent its own ad hoc whitelist.

## Test/Live (livemode) separation

`billing_customers` and `pricing_plan_provider_prices` both carry a
`livemode` boolean, populated from the provider's own returned `livemode`
flag — never guessed locally — matching the field `billing_webhook_events`
already had. This is what lets `PlanPriceMappingService`/
`BillingCustomerService` refuse to use a mapping created under a different
Stripe mode than the one currently configured
(`BillingProviderInterface::isLivemode()`, derived from whether
`STRIPE_SECRET` starts with `sk_live_`), without an extra API round-trip on
every read. `billing_customers`' uniqueness is `(organization_id, provider,
livemode)` rather than `(organization_id, provider)` — an organisation may
legitimately accumulate both a Test Mode row (development/staging use) and
a Live Mode row (real billing) over time; these are two distinct slots, not
one contested slot. This was an additive migration
(`2026_07_27_000001_...`), not a workaround — the gap was real (no schema
change would have left services unable to locally distinguish test/live
objects without a network call per check) and is documented per the
checkpoint's explicit instruction to report rather than silently work
around a genuine schema gap.

## `PlanPriceMappingService`

Maps a `pricing_plans` row to a provider Product and one or more provider
Prices (one per billing interval/currency). SureSign's `pricing_plans` row
remains the authoritative plan name/description/decimal display price —
provider Product/Price metadata (`suresign_pricing_plan_id`,
`suresign_plan_code`, `suresign_source`) exists purely for reconciliation,
never the reverse.

**Immutability policy**: Stripe Prices cannot have their amount/currency
mutated (a real Stripe API constraint). `syncPlanPrice()` is idempotent —
calling it repeatedly with unchanged pricing reuses the existing active
mapping without any provider call at all — but the moment the amount (or
currency, or interval) actually differs from what's currently active, it
creates a brand-new provider Price under the same Product, marks the
previous local mapping `is_active = false` with an `effective_until`
timestamp, and calls `deactivatePrice()` on the provider side (hidden from
future Checkout, never deleted). Any existing subscription still
referencing the old `provider_price_id` is completely unaffected —
`resolveActivePrice()` is the only thing that changes which mapping a *new*
checkout would use.

Key public methods: `resolveActivePrice()` (read-only, no provider call),
`syncPlanPrice()` (the idempotent create-or-supersede entrypoint),
`deactivateMapping()` (explicit retirement with no replacement),
`reconcileMapping()` (on-demand diagnostic — confirms local state still
matches the provider, never auto-repairs), `assertMappingBelongsToPlan()`
(guards against a mapping being applied against the wrong plan).

## `BillingCustomerService`

Owns the relationship between an `Organization` and a provider Customer.
SureSign owns organisation identity/name/billing email; the provider owns
only its own Customer object. A provider Customer must never create or
define a SureSign Organisation.

**Concurrency**: `getOrCreate()` uses a `Cache::lock()` around the
check-then-create sequence (the same pattern `SendDeadlineReminders` uses
when there's no existing row to `lockForUpdate()` on), with the database's
own `(organization_id, provider, livemode)` unique constraint as the final
backstop if two processes/hosts ever race past the lock anyway.

**Field ownership on sync**: `syncOrganizationDetails()` only ever pushes a
field to the provider when SureSign's own value is non-null AND has
actually changed — it never overwrites a provider-side field with `null`
just because SureSign's own value happens to be empty, and it never
touches anything provider-managed (payment methods, tax settings) this
service doesn't own.

**Missing/deleted provider customer**: `reconcile()` distinguishes two
cases. With no related financial history (no `subscriptions` or
`billing_invoices` referencing the mapping), a missing provider customer is
demonstrably safe to replace — the stale local row is deleted and a fresh
one created via the normal `getOrCreate()` path, logged as
`billing_customer.reconciled_missing`. With related financial history, this
throws `BillingCustomerReconciliationException` instead — a human
(Super Admin, in a future UI) must decide what to do, since auto-replacing
would orphan real financial records.

## Provider interface additions (checkpoint 3)

`BillingProviderInterface` grew six methods, each backing a genuine
requirement of the two services above — not speculative: `isLivemode()`,
`updateCustomer()`, `createProduct()`, `retrieveProduct()`, `createPrice()`,
`retrievePrice()`, `deactivatePrice()`. Both `StripeBillingProvider` and
`FakeBillingProvider` implement every one of them — the fake stamps its own
`livemode` public property (default `false`) onto everything it creates, so
a test can flip it to simulate a livemode mismatch without any real Stripe
involvement.

## ActivityLog events added (checkpoint 3)

`plan_price_mapping.created`, `plan_price_mapping.superseded`,
`plan_price_mapping.deactivated`, `billing_customer.created`,
`billing_customer.synced` — all via the existing `ActivityLog::record()`
helper, metadata limited to stable identifiers (plan ID, provider price/
customer ID, amounts, `livemode`) — never a raw provider payload, matching
the pattern Pricing Management's own service already established.

**`BillingCustomerService::reconcile()` behaviour reversal (checkpoint
4, approved as deliberate)**: this method previously auto-replaced a
missing/deleted provider customer when no subscription or invoice
referenced the local mapping. It now **always** throws
`BillingCustomerReconciliationException` once a mapping has ever been
established, regardless of financial history — a payment method or an
incomplete Checkout may still reference a provider customer with no
subscription/invoice record locally, the provider's identity for an
organisation should never change silently, and a missing provider object
may itself indicate an operational problem worth a human noticing. The
`billing_customer.reconciled_missing` ActivityLog action from checkpoint 3
no longer exists — there is nothing left to auto-reconcile. The *only*
place this service still creates a provider customer without hesitation is
`getOrCreate()`, and only because no local mapping exists yet in that case.
A Super Admin-facing repair workflow for this exception is not built yet —
noted as a future need below.

## `SubscriptionLifecycleService` (checkpoint 4)

The single authoritative path for every commercially significant
subscription state transition — Checkout (not yet built), verified
webhooks (not yet built), Super Admin actions, scheduled commands, and
future provider-reconciliation processes must all call this service rather
than mutating `subscriptions.status` or any other commercial field
directly. **There is no public arbitrary status setter anywhere in this
class.**

### Status model — retained, not redesigned

This checkpoint deliberately kept the existing eleven-status
`SubscriptionStatus` vocabulary rather than adding `grace_period`,
`suspension_pending`, or `cancel_at_period_end` as statuses, because the
existing fields already represent each concept with a genuinely different
lifecycle from "current status":

- **Grace period** = `status: past_due` + `grace_period_ends_at`. A
  subscription in grace is still, structurally, past_due — the grace
  window is a policy detail about *how* past_due is handled, not a
  separate commercial state.
- **Suspension scheduling** is recorded via `ActivityLog` +
  `metadata_json['planned_suspension_reason']`
  (`scheduleSuspension()`), never a status — the subscription stays in its
  current status (active/past_due/unpaid) until `suspend()` actually
  applies the SUSPENDED transition.
- **Cancellation scheduling** = `cancel_at_period_end` (boolean) on an
  otherwise-ACTIVE subscription — the subscription keeps full access right
  up to the scheduled date; a `cancel_at_period_end` *status* would
  incorrectly imply reduced access starts immediately.

Five distinct axes, deliberately not collapsed into one field: current
lifecycle status (`SubscriptionStatus`), requested future action
(`cancel_at_period_end`, `pending_pricing_plan_id`/
`pending_billing_interval`/`plan_change_effective_at`), billing health (a
presentation-layer concept derived from status, per Commercial Strategy
§17 — never stored separately), provider status (never stored verbatim;
translated once via `SubscriptionStatusMapper`), and access policy/
entitlement state (explicitly deferred — this service records commercial
state only).

### Transition map additions (checkpoint 4)

`App\Support\Billing\SubscriptionTransitions::MAP` gained: `draft →
trialing`, `draft → cancelled`, `trialing → pending_payment`, `trialing →
cancelled`, `pending_payment → expired`, `suspended → cancelled` — each
tied to a lifecycle path this checkpoint's design review found genuinely
missing from the original Phase 0 map. Deliberately **not** added:
`active → expired` — expiry only ever follows cancellation, a lapsed
trial, or an abandoned pending-payment window; there is no "fixed term"
concept in this schema to justify expiring directly off an active
subscription, and adding it would risk `expired` becoming a catch-all
(explicitly warned against in this checkpoint's brief).

### Public API

`createDraftSubscription()`, `startTrial()`, `markPendingPayment()`,
`markIncomplete()` (added checkpoint 8 — see its own section below),
`activate()`, `markPastDue()`, `startGracePeriod()`, `restoreToActive()`,
`markUnpaid()`, `scheduleSuspension()`, `suspend()`,
`scheduleCancellation()`, `cancelImmediately()`, `confirmCancellation()`,
`expire()`, `scheduleUpgrade()`, `scheduleDowngrade()`,
`recordProviderState()`.

### Transition context

Every transition takes an immutable `App\Services\Billing\TransitionContext`
(built via `TransitionContext::make()`), never a bare string/array —
`source` (validated against `App\Support\Billing\TransitionSource`'s
controlled vocabulary: `super_admin`, `checkout`, `verified_webhook`,
`scheduled_command`, `provider_reconciliation`, `system_migration`,
`manual_correction`), `reason`, `actorUserId`, `provider`,
`providerEventId`, `providerSubscriptionId`, `occurredAt` (defaults to
now), `effectiveAt`, `metadata`, `correlationReference`. Never carries a
raw provider payload.

### Concurrency and idempotency

Every transition method locks the subscription row (`lockForUpdate()`
inside a `DB::transaction()`) before validating or applying anything — no
check-then-update race. A transition to the subscription's **current**
status is a safe no-op almost everywhere (repeated provider events must
never duplicate `ActivityLog` history) — `activate()` is the deliberate
exception: a repeat call while already ACTIVE still validates the incoming
provider subscription ID matches what's recorded before treating it as a
no-op, so a *conflicting* repeat throws instead of being silently ignored.

**Stale-event handling**: `subscriptions.last_transition_occurred_at`
(new this checkpoint) is compared against `TransitionContext::$occurredAt`
on every transition — an older event arriving after a newer one was
already applied is rejected via `SubscriptionLifecycleConflictException`
rather than silently rolling the subscription backward. Full webhook-event
idempotency (persisting `billing_webhook_events` rows, retry bookkeeping)
remains for the webhook checkpoint — this service only guarantees that
whatever normalized command it's eventually called with is safe to apply
more than once.

### Provider normalization boundary

This service never calls Stripe and never receives a raw
`\Stripe\Subscription` — every provider-originated input is the plain
normalized array `BillingProviderInterface::retrieveSubscription()`
already returns. A future webhook processor only ever needs to produce
that same shape.

**Real bug found and fixed while verifying this boundary**: `current_period_start`/
`current_period_end` do **not** exist on the top-level `\Stripe\Subscription`
object in the installed SDK (`stripe-php` v21.0.0) — confirmed by direct
inspection of `vendor/stripe/stripe-php/lib/Subscription.php`'s
`@property` docblock, not assumed. Stripe moved billing-period tracking to
the subscription-item level (`\Stripe\SubscriptionItem::$current_period_start`/
`$current_period_end`). `StripeBillingProvider::normalizeSubscription()`
now reads these from `$subscription->items->data[0]` — SureSign never does
per-seat/multi-item billing, so a subscription has exactly one primary
item, and its period is the subscription's period for SureSign's purposes.
This is isolated entirely inside the provider adapter (one method), so a
future Stripe API/SDK change touches only that method, never
`SubscriptionLifecycleService`. Verified with fixture-based tests
(`StripeBillingProviderSubscriptionNormalizationTest`) built via
`\Stripe\Subscription::constructFrom()` — no network call.

### Plan changes — preparation only

`scheduleUpgrade()`/`scheduleDowngrade()` record
`pending_pricing_plan_id`/`pending_billing_interval`/
`plan_change_effective_at` — they do **not** touch the subscription's
current plan/amount fields and do **not** call the provider. Actually
*applying* a scheduled change (resolving a new provider Price relationship
on the live Stripe subscription, updating `pricing_plan_id`/`unit_amount`,
creating a new entitlement snapshot) requires a provider call this
checkpoint explicitly excludes — deferred to a future Checkout/webhook-
integrated checkpoint, which reads these three fields. Downgrades default
their effective date to the current billing period's end (renewal-aligned,
per the approved commercial policy); upgrades default to immediate.

### Schema additions (checkpoint 4)

Additive migration `2026_07_28_000001_...` added to `subscriptions`:
`livemode` (boolean — the same gap-fix pattern already applied to
`billing_customers`/`pricing_plan_provider_prices`), `last_transition_occurred_at`
(stale-event comparison — see above), `pending_pricing_plan_id` (FK,
nullable), `pending_billing_interval` (nullable string),
`plan_change_effective_at` (nullable timestamp) — the minimum needed to
represent a scheduled plan change without overwriting the current plan's
historical snapshot fields. No entitlement table, no webhook event store,
no invoice/payment table was added.

### Access enforcement boundary

This service records commercial state only. A future
`SubscriptionAccessPolicy`/`BillingAccessService` will interpret that state
together with grace-period policy, entitlement state, customer health, and
manual Super Admin exceptions. Documented for now, not enforced: `active`
usually allows normal access; `trialing` allows trial access; `past_due`
may retain access during a grace period; `suspended` may restrict new
activity; `cancelled`/`expired` may become read-only per a future
access-policy decision — but existing records and downloads must never be
destructively removed, at any status.

## ActivityLog events added (checkpoint 4)

`subscription.created`, `subscription.trial_started`,
`subscription.payment_pending`, `subscription.activated`,
`subscription.past_due`, `subscription.grace_started`,
`subscription.restored`, `subscription.unpaid`,
`subscription.suspension_scheduled`, `subscription.suspended`,
`subscription.cancellation_scheduled`, `subscription.cancelled`,
`subscription.expired`, `subscription.plan_change_scheduled`,
`subscription.provider_state_recorded` — metadata limited to the
subscription reference, organisation ID, status, plan ID, billing
interval, and the transition context's own safe fields; never a raw
provider payload.

## `CheckoutSessionService` (checkpoint 5)

A thin orchestration layer preparing and creating a provider Checkout
Session — nothing more. It owns none of the rules it orchestrates:
`BillingCustomerService` resolves the Organisation-to-Customer
relationship, `PlanPriceMappingService` resolves the plan-to-Price
mapping, and `SubscriptionLifecycleService` owns the draft subscription
and remains the sole authority on whether the organisation is eligible for
a new one. This service never activates a subscription, never interprets
a webhook event, and never enforces access — its job ends once the
Checkout Session exists and local references are persisted. A completed
provider Checkout Session still waits for a verified webhook (a future,
not-yet-built checkpoint) before anything commercial changes.

### Zero schema changes — confirmed sufficient

The existing `billing_checkout_sessions` table (created in the Phase 1–4
checkpoint) already had everything this checkpoint needed:
`provider_checkout_session_id`, `internal_reference`, `status`,
`billing_interval`, `currency`, `amount`, `success_url`/`cancel_url`,
`expires_at`, `completed_at`, `metadata_json`. `App\Support\Billing\CheckoutSessionStatus`
(`created`/`open`/`completed`/`expired`/`cancelled`) and
`BillingProviderInterface::createCheckoutSession()`/`retrieveCheckoutSession()`
also already existed. **No migration and no new provider-interface method
were required this checkpoint** — the only interface change was enriching
the existing `createCheckoutSession()`/`retrieveCheckoutSession()` return
shape with `livemode` (confirmed on `\Stripe\Checkout\Session` by direct
SDK inspection), needed to keep this object's mode-consistency checkable
the same way every other provider object already is. `billing_checkout_sessions`
itself deliberately did **not** need its own `livemode`/`billing_customer_id`
columns — both are available transitively via the linked `subscription_id`
(a checkout session always has one; its subscription already carries
`livemode`, and `billing_customer_id` is included in `metadata_json` for
reconciliation rather than duplicated as a column).

### Commercial conflict invariant — where it's enforced

Per explicit instruction, the "organisation must not create a new
incompatible subscription while a conflicting one exists" rule lives
**only** in `SubscriptionLifecycleService::hasConflictingSubscription()`,
called internally by `createDraftSubscription()` — never in
`CheckoutSessionService`. `CheckoutSessionService` performs no early
duplicate check of its own; it calls `createDraftSubscription()` and lets
`SubscriptionLifecycleConflictException` propagate untouched. This was a
deliberate simplification versus adding a separate "early, advisory"
check in Checkout: an early check would need to account for
correlation-reference reuse itself (a request reusing its own prior draft
is not a conflict) to avoid false positives, which would have meant
partially duplicating logic `createDraftSubscription()` already handles
correctly — so Checkout defers entirely rather than risk that duplication
diverging.

**Conflict matrix** (scoped to the current provider `livemode` only — a
test-mode subscription never blocks a live-mode one or vice versa):

| Status | Conflicts? | Why |
|---|---|---|
| `trialing`, `pending_payment`, `incomplete`, `active`, `past_due`, `unpaid`, `paused`, `suspended` | **Always** | Each represents an existing, unresolved commercial relationship |
| `active` with `cancel_at_period_end = true` | **Always** | A scheduled cancellation does not reduce commercial activity until its effective date |
| `cancelled`, `expired` | **Never** | Terminal, historical — must not block a fresh subscription |
| `draft` | **Only if** it has an associated `billing_checkout_sessions` row in `created`/`open` status with no expiry or a future one | A bare draft, or one whose only checkout attempts are expired/cancelled/completed, represents an abandoned attempt, not a reusable intent |

### Draft and pending-payment subscription treatment

`createDraftSubscription()` is called first, exactly as designed —
nothing about the subscription's commercial snapshot is recreated after
Checkout. The moment a Checkout Session is actually created (or a reusable
one is found), `CheckoutSessionService` calls
`SubscriptionLifecycleService::markPendingPayment()` — the **only** write
to the subscription itself, routed entirely through the lifecycle
service, never a direct field assignment. This is the correct commercial
reading of "a checkout session now exists and the customer is being sent
to pay." `markPendingPayment()` is a safe no-op when the subscription is
already `pending_payment` (the common case on session reuse).

### Checkout Session reuse and expiration-aware recreation

An existing session is reused only when it is `created`/`open`, not
expired, and matches the draft subscription's own organisation/plan/
provider/billing-interval/currency/amount (which in practice always match,
since they're the same linked subscription — the check exists as a
defensive consistency guard, not because these are expected to diverge).
**A previously `completed` session is never reusable** — completion means
the provider-side flow already finished; a new attempt needs a new
session, not a reopened old one. When no session is reusable, a new
provider Checkout Session is created and the old local record is kept
exactly as it was (never deleted, never mutated) — purely historical.

**Provider retrieval is NOT required to decide reusability.** An `OPEN`
local record's own recorded `expires_at` is sufficient — Stripe Checkout
Sessions don't silently change status before expiry outside of completion
(which a future webhook checkpoint reconciles), so re-fetching from Stripe
on every reuse check would be an unnecessary provider call in the common
case, inconsistent with the idempotent-reuse pattern
`PlanPriceMappingService::syncPlanPrice()` already established.

### Idempotency and concurrency

Two layers: `createDraftSubscription()`'s own `correlationReference`
dedup (unchanged from checkpoint 4) resolves the same draft across
repeat requests; `CheckoutSessionService` additionally locks per-subscription
(`Cache::lock("checkout-session:{$subscription->id}")`) around the
reuse-check-then-create sequence, so two concurrent requests for the same
draft (a double-click, or a duplicate sales-assisted request) can never
both create a provider Checkout Session — the second always finds what
the first just created. The provider idempotency key
(`checkout:{internal_reference}:{attempt_number}`) changes only when a
**new** session is genuinely being created (expiry/recreation), so a
transient-network-error retry before the local record is persisted still
reuses the same key safely.

### ActivityLog events added (checkpoint 5)

`checkout.created`, `checkout.recreated` — metadata limited to the
subscription/checkout references, provider checkout session ID, plan ID,
billing interval, and attempt number; never a raw provider payload, card
data, or secrets.

## Verified Webhook Ingestion (checkpoint 6)

The trusted boundary between Stripe and SureSign — `POST /api/billing/webhooks/stripe`
verifies a delivery's signature, builds a normalized `VerifiedWebhookEvent`,
and durably persists it into `billing_webhook_events` **exactly once**.
This layer never interprets what an event means: it does not call
`SubscriptionLifecycleService`, does not mutate a `BillingCheckoutSession`,
and does not touch an invoice or payment record. A future, separate
event-processing checkpoint reads the ledger this checkpoint fills and
decides what each event means — that split (ingestion decides "can this be
trusted and stored"; processing decides "what does it mean") is
deliberate, the same discipline that kept Checkout a thin caller of the
lifecycle service.

### Schema — two columns added, nothing else

`billing_webhook_events` already had `provider`, `provider_event_id`
(unique together), `event_type`, `api_version`, `livemode`,
`processing_status`, `attempt_count`, `received_at`, `processed_at`,
`failed_at`, `failure_message`, `payload_json` — all reused unchanged.
Two genuinely missing fields were added
(`2026_07_29_000001_add_integrity_fields_to_billing_webhook_events_table`):
`payload_hash` (nullable — SHA-256 of the exact verified raw body) and
`provider_created_at` (nullable — Stripe's own `event.created`, distinct
from `received_at`'s local ingestion time). Both nullable for backward
compatibility; no historical rows existed to backfill (webhook ingestion
never existed before this checkpoint), and none were fabricated — a
provider timestamp can't be reconstructed after the fact, and a payload
hash must never be computed from a re-serialized copy of `payload_json`
(that would hash something other than what was actually verified). A plain
index on `provider_created_at` supports the one anticipated future query
pattern (ordered processing/investigation); no index was added for
`payload_hash`, which is only ever looked up via the existing
`(provider, provider_event_id)` row, never queried standalone.

`processing_status` remains the same plain `string(20)` column it always
was — adding `conflict` to `App\Support\Billing\WebhookProcessingStatus`
was a PHP-level allow-list change only, no migration required beyond the
two columns above.

### Signing-secret strategy — one mode, one secret, no fallback

`config('billing.stripe.webhook_secret_test')`/`webhook_secret_live`
replace the single `webhook_secret` key checkpoint 1 originally added
(never used elsewhere, safe to replace outright). `BillingProviderManager::resolveWebhookSecret(bool $livemode)`
selects exactly one, given the application's own resolved mode
(`BillingProviderInterface::isLivemode()` — the same source every other
billing service already trusts) — **never the incoming payload**, and
**never both secrets tried in sequence**. If the matching secret is empty,
`WebhookSecretNotConfiguredException` is thrown (500 — a deployment
misconfiguration Stripe should retry once fixed, never exposing which
secret or env var in the response). The Stripe API secret key
(`billing.stripe.secret`) is never used as a webhook signing secret — they
are different credentials serving different purposes. Local Stripe CLI
forwarding (`stripe listen`) must use `STRIPE_WEBHOOK_SECRET_TEST` against
a test-mode-configured deployment — never mix a CLI-forwarded live secret
into a test environment or vice versa.

### Mode isolation — verified event livemode must match the app's own mode

After signature verification succeeds, the verified event's own
`livemode` is compared against the application's resolved mode. A
mismatch (a live event delivered to a test-configured deployment, or vice
versa) is **never persisted into the ledger** — this is a Stripe Dashboard/
endpoint-configuration issue (the wrong webhook endpoint URL registered
against the wrong mode), not something a retry can fix, so the response is
a 200 (acknowledged, not retried) with a concise `billing.webhook.mode_mismatch`
operational log — never a silent drop.

### Raw-body handling and payload hashing

`$request->getContent()` — the exact raw body — is what's both verified
against the signing secret and SHA-256-hashed; never `$request->all()`,
never a re-encoded copy. Confirmed no global middleware in this app's
`bootstrap/app.php` mutates the raw body before the controller sees it
(this app registers no `TrimStrings`/`ConvertEmptyStringsToNull`-style
middleware on the `api` group at all — those would only affect parsed
input arrays regardless, never the raw stream). The hash exists for
duplicate/conflict detection and audit integrity — it is never a
substitute for Stripe signature verification itself.

### Deduplication and conflict policy

The existing `(provider, provider_event_id)` unique constraint remains the
concurrency-safety boundary — `WebhookIngestionService` always attempts an
`INSERT` first and catches `UniqueConstraintViolationException`, exactly
the same pattern already established in `BillingCustomerService`/
`PlanPriceMappingService`, never a check-then-create race. No `livemode`
column was added to the constraint — Stripe event IDs are globally unique
across test and live mode already, so `(provider, provider_event_id)`
alone is sufficient.

- **Identical redelivery** (same `provider_event_id`, same `payload_hash`):
  no new row, existing row completely untouched, `billing.webhook.duplicate`
  logged, 200.
- **Payload-mismatch conflict** (same `provider_event_id`, different
  `payload_hash` — should never happen from Stripe itself, but defended
  against regardless): the original row's `payload_json`/`payload_hash`/
  `received_at`/`attempt_count`/`processed_at`/`failed_at`/`failure_message`
  are **never** overwritten. If the original row is still `received` (not
  yet processed), `processing_status` is promoted to `conflict` — safe,
  since nothing meaningful is lost. If the original row is already
  `processed` (or `processing`/`failed`/`ignored`/already `conflict`), the
  row is left **completely untouched** — the conflict is recorded only via
  a concise `billing.webhook.conflict` ActivityLog entry plus an
  application `Log::warning()`, never by overwriting real historical
  processing state. Either way: 200 (acknowledged, not retried
  indefinitely) — `conflict` is a terminal, operational-review state; only
  a deliberate future reconciliation action would move a row out of it.

### Event ordering

`provider_created_at` (Stripe's `event.created`) is persisted specifically
so a future processing checkpoint can reject/handle stale events correctly
— delivery order is never assumed to equal event order. **The future
processing checkpoint must use `provider_created_at`, not `received_at`,
as the `TransitionContext::occurredAt` source for any provider-originated
lifecycle command.** This checkpoint does not set
`subscriptions.last_transition_occurred_at` at all — no transition occurs
here.

### Checkout Session drift readiness

`checkout.session.completed` and `checkout.session.expired` (among any
other event type) are verified, hashed, and persisted exactly like every
other event — inert. Neither this checkpoint nor `WebhookIngestionService`
ever reads or writes a `BillingCheckoutSession` row. The stored
`payload_json` retains the provider checkout session ID
(`data.object.id`) a future processing checkpoint needs to correlate the
event back to the local `billing_checkout_sessions.provider_checkout_session_id`
row and finally close the drift the Checkout checkpoint deliberately left
open (trusting local `expires_at` rather than live provider state).

### CSRF — confirmed unnecessary, not added

`bootstrap/app.php` registers no CSRF middleware against the `api`
middleware group at all (`VerifyCsrfToken`-equivalent protection in this
Laravel 11 app only ever applies to the `web` group, which `routes/api.php`
never uses). Confirmed via `php artisan route:list -v` that the new route
carries only the `api` group's middleware (rate limiting), nothing
CSRF-related — no exclusion was added because none was ever needed.

### ActivityLog events added (checkpoint 6)

`billing.webhook.received`, `billing.webhook.duplicate`,
`billing.webhook.conflict`, `billing.webhook.mode_mismatch` — metadata
limited to `provider_event_id`/`event_type` (plus `existing_status` for
conflicts, `event_livemode`/`application_livemode` for mismatches) —
**never** the raw payload, never the signature header, never a secret.
`billing_webhook_events.payload_json` remains the sole authoritative
ledger for the verified event body — ActivityLog is operational
signal only.

## Persisted Webhook Event Processing and Lifecycle Dispatch (checkpoint 7)

Interprets an already-verified, already-persisted `billing_webhook_events`
row and applies the narrow set of local effects a supported Checkout/
subscription event implies. This layer never processes an HTTP request
directly — every input is a ledger row `WebhookIngestionService` already
verified and stored; the controller still never calls it either (only
ingestion runs synchronously on the HTTP path — see "Not queued yet"
below for why).

### Supported events and explicit dispatch

`checkout.session.completed`, `checkout.session.expired`,
`customer.subscription.created`, `customer.subscription.updated`,
`customer.subscription.deleted` — a plain `match()` over `event_type`
inside `WebhookEventProcessor::dispatch()`, no reflection, no dynamic
method construction, no class name ever taken from payload data. Every
other valid, verified event type (`invoice.*`, `payment_intent.*`,
`charge.*`, `refund.*`, `customer.*`, `entitlements.*`, etc.) becomes
`ignored`, never `failed` — an unsupported event is not an error.

### Claim matrix

| `processing_status` | Claimable? | Result if not |
|---|---|---|
| `received` | Yes | — |
| `processing` | No | `not_claimable_already_processing` |
| `processed` | No | idempotent `already_processed` |
| `ignored` | No | idempotent `already_ignored` |
| `conflict` | Never | `not_claimable_conflict_requires_manual_review` |
| `failed` | Only if `retryable = true` | `not_claimable_non_retryable_failure` |

### Claiming, locking, and concurrency

A single `DB::transaction()`: `SELECT ... FOR UPDATE` the ledger row,
decide claimability, promote to `processing` + increment `attempt_count`,
run the explicit handler, persist the final outcome, commit. This is safe
specifically because nothing in this class or the services it calls
(`SubscriptionLifecycleService`, `CheckoutSessionLifecycleService`) ever
makes an external provider API call — every input is already sitting in
`payload_json`, every correlation/mutation is a local database operation.
A two-phase claim/finalize split exists specifically to keep lock
duration short when a provider call sits in between; since none occurs
here, one transaction is simpler and equally safe. A concurrent second
`process()` call for the same row blocks on the row lock until the first
commits, then observes the now-terminal status and returns the same
idempotent result — two processors can never both invoke a business
action for one event.

### Retryable field — one genuinely new column

Inspection confirmed `billing_webhook_events` had no field distinguishing
a permanently-failed row from one safe to retry, and encoding that as a
string convention inside `failure_message` would mean structured state
living in free text (a pattern this codebase avoids — `processing_status`
itself is a dedicated column, not a note). One additive, nullable
`retryable` boolean was added
(`2026_07_30_000001_add_retryable_to_billing_webhook_events_table`) — only
meaningful when `processing_status = failed`; `WebhookEventProcessor` is
the only writer. No other schema change was needed for this checkpoint.

### Checkout Session ordering — no migration needed, confirmed by design

A real Stripe Checkout Session produces exactly ONE of
`checkout.session.completed` or `checkout.session.expired` in its
lifetime, never both. `CheckoutSessionLifecycleService` treats `completed`
and `expired` as mutually exclusive terminal states: a request to apply
the "other" one on top of an already-terminal session is refused
outright as a conflict, regardless of the incoming event's own timestamp
— this fully satisfies "no overwrite of a completed session by an older
expired event" (and vice versa) without adding a last-provider-event
timestamp column to `billing_checkout_sessions`. `markCompleted()`/
`markExpired()` are the sole authoritative, transactional, row-locked,
idempotent entry points for these two transitions — nothing else in the
codebase mutates `billing_checkout_sessions.status`/`completed_at`.

### Provider normalization — one shared implementation, not duplicated

`BillingProviderInterface` gained two methods:
`normalizeSubscriptionFromWebhookPayload(array $subscriptionObject): array`
and `normalizeCheckoutSessionFromWebhookPayload(array $checkoutSessionObject): array`
— both take an already-decoded, already-verified webhook `data.object`
array, never a raw HTTP body or a `\Stripe\*` SDK object.
`StripeBillingProvider::normalizeSubscription()` (the existing SDK-object
path used by `retrieveSubscription()`) was refactored to delegate to a
new shared `normalizeSubscriptionArray(array $subscription): array` —
the SAME period-from-primary-item extraction (`items.data[0].current_period_start`/
`_end`, per the checkpoint-4 SDK bug fix) is read once, from one place,
whether the source is a live SDK object (via `->toArray()`) or a decoded
webhook payload — never duplicated. The webhook-payload shape additionally
carries `price_id`, `product_id`, `cancelled_at`, `ended_at`, and
`metadata` — fields `retrieveSubscription()`'s narrower shape doesn't need
but `WebhookEventProcessor` does for commercial-snapshot validation and
correlation. `FakeBillingProvider` implements both new methods with the
identical field layout so processor tests exercise the same interpretation
a real Stripe payload would get.

### Correlation strategy

**Checkout events** correlate via
`billing_checkout_sessions.provider_checkout_session_id` — never a
success-URL query parameter. Once found, the linked subscription's
`livemode`, the session's `organization_id`/`pricing_plan_id`/
`billing_interval`/`amount`/`currency` are validated against the event's
own trusted `metadata`/`amount_total`/`currency` (for `.completed`) —
any mismatch is `conflict`, never a best-effort match. An unknown
checkout session ID is a **retryable failure**
(`checkout_session_not_found_locally`), not a conflict — the local row is
always created before Stripe ever completes the session
(`CheckoutSessionService`), so "not found" most plausibly means a
replication/visibility delay, not a real integrity problem.

**Subscription events** correlate first via
`subscriptions.provider_subscription_id` — populated once `activate()`
has run once. For `customer.subscription.created` specifically, that
column is by definition not yet populated on a brand-new subscription, so
correlation falls back to the organisation's `BillingCustomer` mapping
(`provider_customer_id` + `livemode`) and requires **exactly one**
`pending_payment` subscription for that customer: zero is a retryable
"not yet visible" failure, more than one is `conflict` (ambiguous). The
same fallback (widened to `pending_payment`/`incomplete`) covers
`.updated`/`.deleted` arriving before `.created` has been processed
(genuine out-of-order delivery risk with Stripe).

**Known limitation, documented rather than silently worked around**:
`CheckoutSessionService` does not pass `subscription_data.metadata` when
creating a Checkout Session, so the resulting Stripe Subscription object
carries no `suresign_subscription_id` metadata of its own — the
metadata-based correlation this checkpoint's brief lists as the preferred
method (#2) is not actually available for `customer.subscription.created`.
Modifying `CheckoutSessionService` (a previously approved, shipped
checkpoint) to add that metadata was judged out of scope here. The
BillingCustomer-mapping fallback is safe in practice today because
`SubscriptionLifecycleService::hasConflictingSubscription()` already
guarantees at most one non-terminal subscription per organisation/
livemode — but adding `subscription_data.metadata` to Checkout creation is
recommended follow-up work (see final report) to make correlation direct
rather than inferred.

### Livemode isolation

A ledger row's own `livemode` always already matches the application's
configured mode (guaranteed by `WebhookIngestionService`). This layer
additionally requires the CORRELATED local record's own `livemode` to
match — a `Subscription`/`BillingCheckoutSession` whose `livemode`
disagrees is never mutated; the event becomes `conflict`, never a silent
no-op and never a guess.

### Event ordering and stale-event handling

Every `TransitionContext` built here sources `occurredAt` from
`billing_webhook_events.provider_created_at` — never `received_at`, never
`now()`. Before calling any subscription lifecycle transition,
`WebhookEventProcessor` independently compares `provider_created_at`
against `subscriptions.last_transition_occurred_at` (the exact comparison
`SubscriptionLifecycleService::assertNotStale()` also performs internally)
and short-circuits to `ignored` ("safely obsolete") without ever calling
the lifecycle service — rather than letting the shared
`SubscriptionLifecycleConflictException` surface and trying to distinguish
"stale" from a genuine identity conflict by matching its message text. Any
`SubscriptionLifecycleConflictException` that still reaches this class
after that pre-check therefore represents a genuine conflict, not
staleness, and is mapped to `conflict` accordingly.

### `checkout.session.completed`

Correlates, validates (livemode/organisation/pricing-plan/billing-interval/
amount/currency), then calls `CheckoutSessionLifecycleService::markCompleted()`.
**Does NOT activate the subscription.** The subscription remains
`pending_payment` — a Stripe Checkout Session completing means the
customer finished the Stripe-hosted flow, not that payment definitely
succeeded (SCA/3-D-Secure, delayed payment methods); the forthcoming
`customer.subscription.created`/`.updated` event is what actually
activates via `SubscriptionLifecycleService`, which requires full
provider subscription period data `checkout.session.completed` doesn't
reliably carry. Confirmed: Checkout completion alone never implies
successful payment, and no browser redirect anywhere in this codebase
activates a subscription.

### `checkout.session.expired`

Correlates (validates livemode/organisation, skips the stricter
commercial checks that only matter for a successful completion), calls
`CheckoutSessionLifecycleService::markExpired()`. The linked subscription
is left completely untouched — it remains in its historical draft/
pending_payment state; a new Checkout attempt creates a new session.

### `customer.subscription.created`

Correlates (provider_subscription_id, falling back to BillingCustomer
mapping), validates the commercial snapshot (`subscriptions.provider_price_id`
must match the event's `price_id` once either is known), checks
staleness, then dispatches by the mapped internal status:
`active` → `SubscriptionLifecycleService::activate()`; `trialing` from a
`draft` subscription → `startTrial()`. Every other raw status
(`incomplete`, `past_due`, anything else) becomes `conflict` — see
"Documented lifecycle gaps" below.

### `customer.subscription.updated`

If the mapped status equals the local status unchanged, calls
`recordProviderState()` (a pure refresh of period dates/
`cancel_at_period_end` — this is how a scheduled `cancel_at_period_end`
flag, or an unchanged-status period renewal, gets recorded). Otherwise
dispatches by (current local status → mapped target): `past_due`/`unpaid`/
`suspended` → `active` calls `restoreToActive()`; `pending_payment`/
`incomplete`/`trialing` → `active` calls `activate()`; `active` → `past_due`
calls `markPastDue()`; `past_due` → `unpaid` calls `markUnpaid()`; → `cancelled`
calls `confirmCancellation()` (if `cancel_at_period_end` was already set on
an `active` subscription) or `cancelImmediately()` otherwise; `draft` →
`trialing` calls `startTrial()`. Any other (from, to) combination —
including the two documented gaps below — is `conflict`, never silently
applied. An unexpected Price/plan change (`provider_price_id` mismatch) is
`conflict`, never auto-applied — `scheduleUpgrade()`/`scheduleDowngrade()`
remain the only path for an *intentional* plan change.

### `customer.subscription.deleted`

Correlates, checks staleness, then: already `cancelled` → idempotent
no-op; `active` with `cancel_at_period_end` already set → `confirmCancellation()`
(a scheduled cancellation reaching its effective point); otherwise →
`cancelImmediately()` with an explicit "Stripe reported the subscription
as deleted" reason (an unscheduled provider-side deletion). The local
subscription row is never deleted.

### Documented lifecycle gaps — mapped to `conflict`, never guessed

Two provider subscription states have **no safe named
`SubscriptionLifecycleService` method to reach them**, confirmed by
direct inspection of its public API (not assumed):

- Stripe status `incomplete` (first payment/3-D Secure still pending) —
  `SubscriptionTransitions::MAP` already allows `pending_payment →
  incomplete`, but no public method on `SubscriptionLifecycleService` ever
  sets `SubscriptionStatus::INCOMPLETE`.
- Stripe status `paused` — `SubscriptionTransitions::MAP` never lists
  `PAUSED` as a valid destination from any status at all, and no method
  sets it either.

Both are real, not rare — an SCA-required card is a mainstream reason a
fresh Checkout produces `incomplete`, and it would arrive as the very
first `customer.subscription.created` event for that subscription. This
checkpoint deliberately routes both to `conflict` for manual review rather
than inventing a new `SubscriptionLifecycleService` method itself (judged
out of scope for an event-processing checkpoint to modify a previously
approved lifecycle service) or silently approximating a different status.
**This is the concrete reason live subscription activation for
SCA/3-D-Secure-required cards does not yet work end-to-end** — see
`App\Services\Billing\WebhookEventProcessor`'s class docblock and the
final checkpoint report's "what now blocks live subscription activation."
Recommended follow-up: add `SubscriptionLifecycleService::markIncomplete()`
and a considered decision on whether `paused` needs a symmetrical method,
as their own reviewed checkpoint.

### Finalization and ledger fields

`processed` → `processing_status = processed`, `processed_at = now()`,
`failed_at`/`failure_message`/`retryable` cleared. `ignored` →
`processing_status = ignored`, `processed_at = now()`. `failed` →
`processing_status = failed`, `failed_at = now()`, a concise sanitized
`failure_message` (action + error code only — never a raw exception
message, stack trace, or payload), `retryable` set from the result.
`conflict` → same as `failed` but `retryable` is always `false`. Every
outcome writes exactly one `ActivityLog` entry per processing attempt (
`billing.webhook.processed`/`.ignored`/`.failed`/`.conflict`,
`billing.checkout.completed`/`.expired` from `CheckoutSessionLifecycleService`
itself) — idempotent short-circuit returns (already `processed`/`ignored`/
not claimable) never write a second log entry for the same outcome. A
`conflict` additionally writes an application `Log::warning()` with the
ledger ID/provider event ID/event type/error code — never the payload.

### Conflict/failed observability

`BillingWebhookEvent::scopeUnresolvedConflicts()` (`processing_status =
conflict`) and `scopeRetryableFailed()` (`processing_status = failed AND
retryable = true`) are the query entry points for manual investigation —
nothing in this codebase automatically moves a row out of `conflict`.
**Manual investigation process**: query `BillingWebhookEvent::unresolvedConflicts()`
(or `::retryableFailed()`), read the row's `failure_message` (a stable
action+error-code label, e.g. `checkout_organisation_mismatch
(organisation_mismatch)`) and `payload_json` (the full original verified
event — untouched by processing) to understand what Stripe actually sent,
cross-reference the correlated local `Subscription`/`BillingCheckoutSession`
by the IDs in the corresponding `ActivityLog` entry's metadata, then take
a deliberate corrective action (a manual `SubscriptionLifecycleService`
call, a data fix, or — for a retryable `failed` row only — re-invoking
`WebhookEventProcessor::process()` once the underlying condition is
resolved). No Super Admin UI exists yet for this — it is a backend/database
investigation today, by design for this checkpoint.

### Dedicated webhook rate limiter

A new named limiter, `billing-webhooks` (`AppServiceProvider::configureRateLimiters()`),
applied via `throttle:billing-webhooks` on `POST /api/billing/webhooks/stripe`
— stacked on top of (not replacing) the generic `api` group throttle every
route gets. Keyed by IP only (no authenticated identity exists on this
public endpoint), `120/minute` — generously above real Stripe traffic
patterns for a single-tenant platform's webhook volume (Stripe delivers a
handful of events per burst, retrying failures on an increasing backoff
over up to ~3 days) while still bounding abuse of a public POST endpoint.
Kept entirely separate from the `api` limiter so neither traffic pattern
can throttle the other. The 429 response carries no detail beyond the
existing shared `TooManyRequestsHttpException` renderer — signature
verification inside `WebhookIngestionService` remains the real trust
boundary regardless of this limit.

### Payload retention and access policy

`billing_webhook_events.payload_json` is operational billing data: it must
never be exposed through generic model serialization, must never appear in
`ActivityLog` metadata (confirmed: every ActivityLog entry this checkpoint
writes carries only stable identifiers — event/provider IDs, action,
error code, affected record IDs), and access is restricted to backend
processing and authorised operational investigation (see "Manual
investigation process" above). Retention duration remains an undecided
future production decision. Any future event type added to the supported
list must be reviewed for whether its payload carries additional personal
data before being stored/processed the same way. Processed payloads must
never be casually surfaced through a future Super Admin API without a
deliberate access-control decision.

### Not queued yet

`WebhookEventProcessor::process()` is a plain synchronous PHP method —
no queue job, no scheduled retry worker exists yet. Nothing in this
checkpoint calls it automatically from the HTTP ingestion path either;
invoking it (per event, or per a batch of `received`/retryable-`failed`
rows) is deliberately left to whatever calls it (a future queued job, an
artisan command, or a Super Admin "process now" action) — building that
automatic trigger was judged out of scope ("queue infrastructure unless
the existing application already requires a minimal job wrapper" — it
doesn't yet) and is recommended as the next checkpoint's first concern
once this one is reviewed.

### Testing (checkpoint 7)

67 new tests: `WebhookEventProcessorTest` (36 — claiming/concurrency,
unsupported events, checkout completed/expired including mutual-exclusion
ordering, subscription created/updated/deleted, finalization, operational
visibility), `CheckoutSessionLifecycleServiceTest` (8),
`StripeBillingProviderWebhookNormalizationTest` (6), plus 3 new rate-limiter
assertions added to `StripeWebhookIngestionTest`. Full Billing filter
(Feature+Unit): 301/301. Full backend suite: 1140 tests, 1115 passed, 1
failure + 21 errors — all pre-existing, unrelated to Billing (storage-
directory file permission issues in this environment affecting
`SupportTicketControllerTest`/`FileSecurityServiceTest`/
`AdjudicationDocumentTenantIsolationTest`/`AiAnalysisErrorMessageTest`/
`ContractAnalysisDedupTest`, plus one unrelated `payment_applications`
seed/data issue) — no new regressions.

### Documentation updated (checkpoint 7)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md` (service table: `WebhookEventProcessor`/
`CheckoutSessionLifecycleService` added, `WebhookIngestionService`'s
description updated to point at the new processing layer, Database
Guidelines note on the new `retryable` column, Commercial Strategy note
on what's built), `project-context.md` (new checkpoint 7 log entry). No
public documentation, no release notes, no version bump.

## Subscription Event Hardening and Checkout Metadata Correlation (checkpoint 8)

Closes the three specific gaps checkpoint 7 exposed and reported: no
trusted Checkout metadata for deterministic subscription correlation, no
`incomplete` lifecycle representation, and no claim recovery / rate-limiter
isolation hardening. Existing architectural boundaries (Checkout → Stripe
→ webhook ingestion → ledger → processor → lifecycle service) are
completely unchanged — this checkpoint only strengthens what already
passes through them.

### Trusted Checkout metadata — `subscription_data.metadata`

`CheckoutSessionService::checkoutMetadata()` now generates ONE identifier
dictionary used for BOTH the Checkout Session's own session-level
`metadata` AND (new this checkpoint) Stripe's `subscription_data.metadata`
— i.e. it's stamped directly onto the resulting Subscription object, not
just the Checkout Session. Contains: `suresign_organization_id`,
`suresign_subscription_id`, `suresign_subscription_reference`,
`suresign_checkout_session_id` (new — the pre-generated Checkout internal
reference; see "Checkout Session internal reference now generated before
the provider call" below), `suresign_billing_customer_id`,
`suresign_pricing_plan_id`, `suresign_provider_price_mapping_id`,
`suresign_billing_interval`, `suresign_livemode`,
`suresign_correlation_reference`. Every value is a stable internal
ID/reference — never a price, amount, card detail, email, token, or
secret (verified by test).

**Naming deviation, deliberate**: the checkpoint brief suggested
`suresign_organisation_id` (British spelling); this codebase already uses
`suresign_organization_id` (American spelling) everywhere metadata
identifies an organisation. Introducing a second, differently-spelled key
for the same concept would itself be an inconsistency this checkpoint is
trying to remove, so the existing spelling was kept and this is called
out explicitly rather than silently diverging from the brief.

**Provider interface change**: `BillingProviderInterface::createCheckoutSession()`
gained an optional `subscription_metadata` param, separate from `metadata`
(the two Stripe API fields — Checkout Session metadata and
`subscription_data.metadata` — are independent dictionaries; an
implementation may pass the same array for both but must never conflate
the fields). `StripeBillingProvider` passes it through as
`subscription_data.metadata` in the real Stripe API call.
`FakeBillingProvider` stores it on the fake session record
(`subscription_metadata` key) for test introspection.

**Checkout Session internal reference now generated before the provider
call**: previously `internal_reference` was generated inline inside
`BillingCheckoutSession::create()`, AFTER the Stripe API call — too late
to include in the metadata sent to Stripe. `BillingReferenceService::generate()`
is now called once, earlier, and the same value is used for both the
metadata and the eventual local row — no double-generation, no wasted
sequence numbers, no behavioural change to the reference format itself.

### Metadata validation chain

Webhook processing's correlation order, exactly as required:

```text
provider subscription/checkout session ID (if already linked)
        ↓
trusted SureSign metadata (suresign_subscription_id / suresign_organization_id)
        ↓
BillingCustomer mapping (exceptional fallback only)
        ↓
organisation validation
        ↓
provider Price / commercial snapshot validation
        ↓
livemode validation
        ↓
provider identity validation (never relink to a different provider ID)
```

Every step must agree — implemented as `validateTrustedIdentifiers()`
(`WebhookEventProcessor`), called immediately after ANY of the three
correlation steps resolves a candidate, regardless of which one found it.
Metadata is treated as trusted only because it arrives already inside a
signature-verified, ledger-persisted, processor-claimed event (see
checkpoint 6/7) — but it never authorizes a transition by itself: every
identifier is independently re-checked against the resolved local record,
and any disagreement becomes `conflict`, never a silent pick of "whichever
source looks more convenient" and never an automatic repair.

### Subscription correlation — metadata now primary

`correlateForCreated()`/`correlateForUpdateOrDelete()` now resolve a
candidate in this order:

1. `subscriptions.provider_subscription_id` (redelivery / already-linked).
2. Trusted metadata (`suresign_subscription_id`) — the PRIMARY path for a
   genuinely new subscription, since step 1 is by definition unpopulated
   for one. A metadata identifier pointing to a subscription that doesn't
   exist locally at all is `conflict` (not retryable — the metadata was
   generated FROM that exact local row at Checkout time, so it cannot
   legitimately predate it). A metadata identifier pointing to a
   subscription already linked to a DIFFERENT provider subscription ID is
   `conflict` (never relinked).
3. `BillingCustomer` mapping — now the EXCEPTIONAL fallback, reached only
   when metadata is absent: a Checkout Session created before this
   checkpoint shipped, or a provider subscription that never originated
   from SureSign's own Checkout at all. Remains exactly as safe as
   checkpoint 7 left it (`SubscriptionLifecycleService::hasConflictingSubscription()`
   guarantees at most one non-terminal subscription per organisation/
   livemode) — proven directly by a new test that manufactures the
   otherwise-prevented ambiguous case via direct row insertion (bypassing
   that invariant) and confirms it becomes `conflict`, not a guess.

### Checkout correlation improvements

`checkout.session.completed`/`.expired` now additionally validate (on top
of checkpoint 7's livemode/organisation/pricing-plan/interval/amount/
currency checks): the session's own `suresign_subscription_id` metadata
against the linked local subscription, and the event's `customer` field
against the `BillingCustomer.provider_customer_id` already linked to that
subscription. **Provider Price is validated indirectly**, via the existing
amount/currency/billing-interval/plan checks — Stripe's
`checkout.session.*` webhook payload does not include the session's
line-item Price ID without expanding `line_items`, which would require an
additional Stripe API call this checkpoint's "no external calls during
processing" invariant excludes; this is documented rather than silently
worked around.

### `SubscriptionLifecycleService::markIncomplete()`

The only path into `SubscriptionStatus::INCOMPLETE` — no other method,
and no direct field assignment anywhere in the codebase, may set it.
Valid only from `pending_payment` (existing `SubscriptionTransitions::MAP`
entry). Deliberately narrower than `activate()`: does not require period
dates (an incomplete subscription's billing period isn't meaningful yet —
no invoice has been paid) and grants no access/entitlement — commercial
state only, like every other transition method. Preserves provider
subscription identity the same way `activate()` does (a conflicting
repeat throws rather than silently relinking), populates
`TransitionContext`, updates `last_transition_occurred_at`, is idempotent
on an exact repeat, and rejects a stale event via the same
`assertNotStale()` every other transition method uses.

`WebhookEventProcessor` calls it from both `customer.subscription.created`
(Stripe status `incomplete` on a brand-new subscription — a mainstream
outcome for an SCA/3-D-Secure-required card) and `customer.subscription.updated`
(a `pending_payment` subscription transitioning to `incomplete` after the
fact).

### `incomplete_expired` — remapped to `EXPIRED`, not `CANCELLED`

`SubscriptionStatusMapper::fromStripeStatus('incomplete_expired')` now
returns `SubscriptionStatus::EXPIRED` (previously `CANCELLED`). Stripe's
own documentation describes `incomplete_expired` as genuinely terminal
("the open invoice will be voided and no further invoices will be
generated") reached when a first payment is never completed within the
retry window — semantically an abandoned/lapsed attempt (SureSign's
`expired`), not a deliberate commercial termination (`cancelled`).
`SubscriptionTransitions::MAP[INCOMPLETE]` gained `EXPIRED` as a valid
destination (kept alongside the existing `CANCELLED`, for the separate,
real case of an `incomplete` subscription being explicitly cancelled via
Stripe's distinct `canceled` raw status). `WebhookEventProcessor` routes
the mapped `EXPIRED` target through `SubscriptionLifecycleService::expire()`
— the existing method, no new one needed — via a new `applyExpiredTransition()`
dispatch case. Provider subscription identity, ActivityLog history, and
`last_transition_occurred_at` are all preserved exactly as `expire()`
already guaranteed; nothing is deleted.

### `paused` — still `conflict`, a deliberate commercial-policy decision

Not changed. Stripe's `paused` only occurs when a trial ends without a
payment method attached — SureSign's current commercial model has no
equivalent concept, and `SubscriptionStatus::SUSPENDED` is a distinct,
deliberate SureSign-only decision (an operator-initiated action with a
required reason) that Stripe's own state must never silently trigger.
`SubscriptionTransitions::MAP` still never lists `PAUSED` as a valid
destination from any status, and no method sets it. Before `paused` could
be supported, a deliberate commercial decision is needed on: whether a
Stripe-paused subscription should retain, lose, or partially retain
platform access; whether it should count toward the one-non-terminal-
subscription-per-organisation invariant; and whether it needs its own
grace-period-style policy the way `past_due` has. None of that exists
today — this checkpoint does not invent an answer.

### Claim recovery — `processing_started_at` and a 15-minute lease

Additive, nullable `billing_webhook_events.processing_started_at`
timestamp (`2026_08_01_000001_...`), set whenever a row is promoted to
`processing`, cleared on every `finalize()` outcome. A `processing` row
is reclaimable once `processing_started_at` is more than
`WebhookEventProcessor::PROCESSING_LEASE_MINUTES` (15) old, or null (a
defensive case that shouldn't occur for a row this class itself wrote).

**Why 15 minutes is safe, and why this is a defence-in-depth measure, not
a fix for an existing bug**: under this class's single-transaction design
(unchanged since checkpoint 7 — claim, dispatch, and finalize all commit
or roll back together, since no external API call ever happens
mid-processing), a row genuinely CANNOT be left durably at `processing`
by a normal `process()` call crashing — the promotion and the final
outcome are the same commit. The lease exists for the one scenario that
design doesn't cover: a future caller (a queued job, a differently
structured invocation) that doesn't preserve the single-transaction
invariant, or a genuinely orphaned database session. No such caller
exists yet (queue infrastructure remains explicitly out of scope — see
"Deferred" below), so this establishes the domain model and rule only, as
instructed, not a background sweep.

**Double-claiming remains impossible regardless of the lease**: `process()`
acquires `SELECT ... FOR UPDATE` on the exact ledger row BEFORE the
claim-lease check ever runs. A genuinely still-running processor holding
that lock blocks a second caller entirely until its own transaction
concludes — the lease is only ever consulted once a lock has actually
been acquired, i.e. once any prior claimant's transaction has already
ended one way or another. Proven by a new test with two sequential
`process()` calls against a within-lease `processing` row, both returning
the same not-claimable result.

### Webhook rate limiter — isolation confirmed and corrected

Inspection (`php artisan route:list -v` plus
`Router::gatherRouteMiddleware()`, the method that actually resolves group
aliases and applies exclusions — `Route::gatherMiddleware()` alone does
NOT, and was confirmed to give a misleadingly-passing result if used for
this check) found the Stripe webhook route was carrying BOTH the generic
`api` group throttle (`Illuminate\Routing\Middleware\ThrottleRequests:api`,
wired in globally via `bootstrap/app.php`'s `throttleApi()`) AND the
dedicated `billing-webhooks` limiter — genuinely stacked, not just
apparently so. Since the generic `api` limiter keys an unauthenticated
request (this route has no user) by IP — identical to how
`billing-webhooks` already keys it — the two buckets were redundant, not
additive protection, while adding confusion about which limit actually
governs Stripe's deliveries. The route now calls
`->withoutMiddleware('throttle:api')` before applying
`->middleware('throttle:billing-webhooks')`, confirmed via
`gatherRouteMiddleware()` to fully exclude the generic throttle while
`billing-webhooks` remains the sole rate limit on this endpoint. The
existing `StripeWebhookIngestionTest` rate-limiter test was corrected to
assert against `gatherRouteMiddleware()`'s resolved output rather than
`gatherMiddleware()`'s declared-only list, which would have passed either
way and proven nothing about the actual stacking.

### Testing (checkpoint 8)

31 new/updated tests across: `WebhookEventProcessorTest` (+21 — trusted
metadata correlation priority, metadata/organisation/livemode mismatch
conflicts, ambiguous BillingCustomer fallback, `incomplete`/
`incomplete_expired` lifecycle through the full webhook path, stale
`incomplete` rejection, duplicate `incomplete` idempotency, `paused`
remaining `conflict`, strengthened checkout provider-customer/metadata
validation, claim-lease recovery and double-claim safety),
`SubscriptionLifecycleServiceTest` (+10 — `markIncomplete()` coverage:
valid/invalid source states, identity preservation, livemode validation,
idempotency, conflicting-identity rejection, activation and expiry from
`incomplete`, stale-event rejection), `SubscriptionTransitionsTest` (+2),
`SubscriptionStatusMapperTest` (updated for the `incomplete_expired`
remap), `CheckoutSessionServiceTest` (+3 — subscription metadata
propagation, no sensitive values, session/subscription metadata parity),
`StripeWebhookIngestionTest` (rate-limiter assertion corrected, not
added, per above). Full Billing filter (Feature+Unit): 332/332 (up from
301). Pricing filter: 39/39 (unaffected, confirmed). Full backend suite:
1171 tests, 1146 passed, 1 failure + 21 errors — the identical
pre-existing, unrelated failures from every prior baseline this session
(storage-directory file permission issues in this environment, plus one
unrelated `payment_applications` seed/data issue) — no new regressions.

### Documentation updated (checkpoint 8)

`internal-docs/super-admin/subscription-billing.md` (this section; status
line; `SubscriptionLifecycleService` Public API list), `CLAUDE.md`
(Database Guidelines note on `processing_started_at`; service table notes
on strengthened correlation/`markIncomplete()`), `project-context.md` (new
checkpoint 8 log entry). No public documentation, no release notes, no
version bump.

## Automatic Webhook Processing and Recovery Orchestration (checkpoint 9)

Connects verified webhook ingestion and `WebhookEventProcessor` (checkpoints
6–8, both approved and unchanged) into a safe automatic execution flow — a
new queued job, an after-commit dispatch point, and a scheduled recovery
command. No new billing domain behaviour: `WebhookEventProcessor` and the
lifecycle services remain the only components that claim, normalize,
correlate, or mutate anything.

```text
Stripe → StripeWebhookController → WebhookIngestionService
    → billing_webhook_events (commit)
    → ProcessBillingWebhookEventJob (queue: billing-webhooks)
    → WebhookEventProcessor → SubscriptionLifecycleService / CheckoutSessionLifecycleService
```

### Ownership table

| Component | Responsibility |
|---|---|
| `WebhookIngestionService` | Verifies, persists, deduplicates, dispatches after commit — nothing else |
| `ProcessBillingWebhookEventJob` | Invokes the processor, applies queue-level retry orchestration for infrastructure failures only — no billing decisions |
| `WebhookEventProcessor` | Claims, normalizes, correlates, dispatches domain handlers, invokes lifecycle services, finalizes ledger status — unchanged from checkpoint 8 |
| `RecoverBillingWebhookEvents` (`billing:webhooks:recover`) | Discovers recoverable ledger rows, dispatches jobs — no lifecycle logic |
| Scheduler (`routes/console.php`) | Invokes the recovery command on a cadence — no billing logic |

### Queue job — `ProcessBillingWebhookEventJob`

Accepts only `billing_webhook_event_id` (an int) — never the raw payload,
a Stripe object, or a Subscription/BillingCustomer/BillingCheckoutSession
model (matching this codebase's existing convention —
`SendAppointmentEmailJob`/`AnalyseContractWithAiJob` also pass IDs, never
models, precisely so a job re-fetches current state rather than risking
stale serialized data). `handle()` is one line:
`$processor->process($this->billingWebhookEventId)`. Contains no
normalization, correlation, dispatch-mapping, or lifecycle logic.

**Queue**: dedicated `billing-webhooks` queue (set via `onQueue()` in the
constructor, not a redeclared `$queue` property — Laravel's `Queueable`
trait already declares that property, and PHP treats a redeclared trait
property with a different type as a fatal incompatible-composition error,
discovered while first implementing this and fixed). Isolates billing
processing from slower jobs (AI analysis, appointment emails) sharing
`default`. **Worker configuration**: `php artisan queue:work
--queue=billing-webhooks,default` (or a dedicated worker process for
`billing-webhooks` alone) — no worker is started/enabled by this
checkpoint; that remains a deployment decision.

**Timeout/tries/backoff**: `$timeout = 30` (no external provider call ever
occurs during processing — see `WebhookEventProcessor`'s own docblock —
so this is generously short, comfortably below the `database` queue
connection's `retry_after` of 600s in `config/queue.php`, meaning a
still-running job is never re-reserved and executed twice). `$tries = 3`,
`$backoff = [10, 60]` — this retry budget covers ONLY a genuine
infrastructure exception escaping `process()` itself (a missing row via
`firstOrFail()`, a DB deadlock, a lost connection); `WebhookEventProcessor`
already catches every exception arising from normalization/correlation/
lifecycle calls internally and converts it to a terminal
`WebhookProcessingResult`, so `process()` normally never throws at all.

### After-commit dispatch — which outcomes dispatch

Only `WebhookIngestionResult::CREATED` (a genuinely new, newly persisted
row) dispatches — via
`ProcessBillingWebhookEventJob::dispatch($event->id)->afterCommit()`,
called from inside `persist()`'s own `DB::transaction()` closure so the
job is only pushed once that transaction actually commits, and never at
all if it rolls back. `DUPLICATE` (identical redelivery) and `CONFLICT`
(payload-mismatch) outcomes never dispatch — see "Duplicate delivery
policy" below. Invalid signature, malformed event, missing webhook secret,
and livemode mismatch never reach `persist()` at all (they throw or return
before it), so they never dispatch either.

**Proven, not assumed**: `Queue::fake()` cannot verify `afterCommit()`
behaviour — `QueueFake::push()` records a job as pushed immediately
regardless of transaction state, bypassing the real connection logic that
registers a `DB::afterCommit()` callback (confirmed by inspecting
`QueueFake` directly after an initial test written against it gave a
false-positive "pushed" result inside a rolled-back transaction).
`WebhookDispatchAfterCommitTest` instead uses the REAL `sync` queue
connection (this suite's `QUEUE_CONNECTION`) and asserts on a pre-committed
row's own `attempt_count`/`processing_status` as the observable proof.

### Duplicate delivery policy — Option A, strict no-redispatch

A duplicate or payload-conflicting delivery never dispatches a second job
— chosen deliberately over "redispatch if the original row is still
`received`" (Option B). Reasoning: a duplicate arriving while the original
is still `received` is exactly the scenario the recovery command's
stranded-`received` sweep already exists to catch on its own schedule —
adding a second, ingestion-triggered redispatch path for the same
underlying "missing dispatch" problem would mean two different triggers
for one condition, the exact "multiple retry systems causing retry storms"
risk this checkpoint's brief warns against. The scheduled recovery command
is the single source of truth for anything that ends up stranded, full
stop — ingestion never second-guesses it.

### Job idempotency

The job performs no state check itself — `WebhookEventProcessor`'s
existing row-locked claim matrix (unchanged since checkpoint 7) is the
only thing that decides whether a business action runs. Re-running the
job for the same event ID (queue redelivery, worker restart, duplicate
dispatch from the recovery command) is always safe: `received` processes
normally, `processing` with an active lease is untouched, `processed`/
`ignored`/`conflict`/non-retryable-`failed` are no-ops, a retryable
`failed` or an abandoned `processing` lease is reclaimed exactly as
checkpoint 8 defined. Proven by `ProcessBillingWebhookEventJobTest`,
including a lifecycle-transition test confirming exactly one
`billing.checkout.expired` ActivityLog entry across two dispatches of the
same event.

### Processor result handling — one rule, no domain retry in the job

The job throws ONLY when an infrastructure exception escapes
`process()` itself — every `WebhookProcessingResult` outcome (`processed`,
`ignored`, `conflict`, `failed` regardless of `retryable`, or
not-claimable) is treated as the job completing successfully; none of
these ever appear in `failed_jobs`. A `conflict` is a quarantined domain
state, not a queue failure — the job never retries it, resets it, or
treats it as an error. A retryable `failed` row is deliberately NOT
retried by the job itself (see "Duplicate delivery policy" reasoning
above, generalized) — the scheduled recovery command is the single
source of truth for redispatching it, avoiding two systems racing to
retry the same row on different schedules.

### Unexpected exceptions

Logged with `billing_webhook_event_id`, `attempt` (`$this->attempts()`),
and `exception_class` only — never the raw payload, signature, or secret.
Re-thrown so Laravel's own `$tries`/`$backoff` apply. `failed()` logs the
permanent-failure case the same way. Because any partial claim was rolled
back with the failing transaction (single-transaction design, unchanged
since checkpoint 7), the ledger row is exactly as reclaimable after this
job fails permanently as before it ran — the scheduled recovery command's
stranded-`received`/stale-`processing` sweep is the ultimate safety net
beneath the job's own retries, not a duplicate one. Confirmed by test: a
non-existent event ID causes `process()` to throw `ModelNotFoundException`,
which propagates out of the job rather than being swallowed.

### Recovery command — `billing:webhooks:recover`

```text
--limit=200      Maximum rows to recover PER CATEGORY
--provider=      Only recover rows for this provider
--event-id=      Manual reprocessing — target exactly one row
--dry-run        Report what would be dispatched without dispatching
```

Recovers three categories, each a strict subset of what
`WebhookEventProcessor`'s claim matrix already considers claimable/
reclaimable (so a dispatched job can never do anything unsafe even if the
command's own selection were stale by the time the job runs):

1. **Stale `processing`** — `processing_started_at` older than
   `WebhookEventProcessor::PROCESSING_LEASE_MINUTES` (15, now `public`
   specifically so this command references the one source of truth rather
   than duplicating the literal), or null. An active lease is skipped.
2. **Retryable `failed`** — `processing_status = failed AND retryable =
   true`, via the existing `BillingWebhookEvent::retryableFailed()` scope.
   A non-retryable failure is never touched — manual investigation only,
   exactly like `conflict`.
3. **Stranded `received`** — older than a 2-minute grace threshold
   (`RECEIVED_GRACE_MINUTES`), so a row still mid-flight through the
   normal after-commit path is never mistaken for one needing recovery.

Never touched: `processed`, `ignored`, `conflict`, non-retryable `failed`,
active `processing`. `--dry-run` reports counts/IDs per category without
dispatching anything. `--event-id` is the manual-reprocessing path (Part
14): it looks up the exact row and applies the identical recoverability
check the sweep uses — refuses `conflict` and non-retryable `failed`
outright (no force option exists for either in this checkpoint; a
`conflict` requires deliberate investigation, not reprocessing), never
accepts raw payload, never resets `processed`/`ignored` rows, never
bypasses livemode checks, never calls lifecycle methods — it only ever
dispatches a job, exactly like the scheduled sweep would eventually do
itself.

### Recovery command concurrency — no dispatch-tracking column added

Deliberately no new schema: dispatching a job for a row is not a
mutation, and `WebhookEventProcessor`'s own `SELECT ... FOR UPDATE` claim
is the actual correctness boundary regardless of how many times a job is
dispatched for the same row — a second dispatch for an already-claimed-or-
terminal row is always a safe, cheap no-op (proven directly by
`ProcessBillingWebhookEventJobTest`'s duplicate-execution coverage). Two
concurrent runs of this command can therefore dispatch duplicate
(harmless) jobs for the same row in the worst case, but can never
duplicate a lifecycle transition. Scheduler-level `withoutOverlapping()`
is the primary safeguard against that worst case even occurring — matching
this codebase's existing convention for every other scheduled command
(`SendDeadlineReminders`, `SendAppointmentReminders`: neither uses a
dispatch-tracking column either, relying on their own domain-level
idempotency instead, exactly as this command relies on
`WebhookEventProcessor`'s).

### Scheduler integration

```php
Schedule::command('billing:webhooks:recover')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
```

Five minutes is conservative relative to both thresholds it recovers
against (well above the 2-minute received-grace and comfortably inside
the 15-minute processing lease window, so a genuinely stuck row is caught
within one or two ticks). **No `onOneServer()`** — matches every other
scheduled command in this codebase (`suresign:send-deadline-reminders`,
`calendar:sync`, `suresign:send-appointment-reminders`), none of which use
it; this application's deployment/scheduler configuration is single-
instance, and adding it here would be new, unproven infrastructure ahead
of an actual multi-server need rather than a correctness requirement —
`WebhookEventProcessor`'s own row locking is what actually prevents
duplicate processing regardless of how many scheduler instances ever
exist. Confirmed via a test that inspects the REAL registered
`Illuminate\Console\Scheduling\Event` (not a source-string grep).

### Queue uniqueness — not implemented, deliberately

`ShouldBeUnique` was considered and rejected for this checkpoint: it would
only ever be an optimization against duplicate QUEUE WORK, never a
correctness boundary (the database processor claim already is one), and
introducing it raises real interaction questions with retryable-failed
redispatch, released jobs, and stale locks that this checkpoint's actual
requirement (safe automatic processing, not queue-work minimization)
doesn't need answered yet. No cache-based uniqueness lock was added.

### Logging policy

Queue/recovery-command logs carry only: `billing_webhook_event_id`,
`attempt`, `exception_class`, category counts, and (for the recovery
command's dispatch log) the list of dispatched IDs. Never: raw payload,
signature, secret, customer email, metadata contents, Checkout URL, or
tokens — identical restriction to `WebhookEventProcessor`'s own logging
policy from checkpoint 7, now extended to this orchestration layer.
`ActivityLog` gains no new entries for queue mechanics — the existing
`billing.webhook.*`/`billing.checkout.*` entries `WebhookEventProcessor`/
`CheckoutSessionLifecycleService` already write remain the sole
authoritative domain audit trail.

### Operational visibility

No dashboard, no new metrics platform. Identify:

- Unprocessed `received` events: `BillingWebhookEvent::where('processing_status', 'received')`
- Stale `processing`: the same query `staleProcessingQuery()` uses (see command source)
- Retryable `failed`: `BillingWebhookEvent::retryableFailed()` (existing scope, checkpoint 7)
- Non-retryable `failed`/unresolved conflicts: `BillingWebhookEvent::unresolvedConflicts()` (existing scope, checkpoint 7)
- Recently processed: `BillingWebhookEvent::where('processing_status', 'processed')->latest('processed_at')`
- `php artisan billing:webhooks:recover --dry-run` — a live, zero-side-effect operational report of everything currently recoverable, right now

### Direct-mutation audit

Audited `ProcessBillingWebhookEventJob`, `RecoverBillingWebhookEvents`,
the scheduler registration, and the `WebhookIngestionService` change:
none assign `subscriptions.status`, `billing_checkout_sessions.status`,
`provider_subscription_id`, any commercial snapshot field, any lifecycle
timestamp, or any entitlement field directly. Only `WebhookEventProcessor`
and the two lifecycle services it calls perform domain mutation — this
checkpoint's new code exclusively reads ledger rows and calls `dispatch()`.

### Database changes

One new file only in the sense of new infrastructure — zero new
migrations. Every field this checkpoint needed (`processing_status`,
`retryable`, `processing_started_at`, `attempt_count`, `received_at`,
`processed_at`, `failed_at`, `failure_message`, `provider_created_at`) was
already added by checkpoints 7–8; inspection confirmed no new column was
genuinely required (see "Recovery command concurrency" above for the one
place a new column was seriously considered and rejected).

### Testing (checkpoint 9)

42 new tests: `WebhookDispatchAfterCommitTest` (2 — the real-`sync`-queue
proof described above), `ProcessBillingWebhookEventJobTest` (12),
`RecoverBillingWebhookEventsTest` (17), `BillingWebhookRecoverySchedulerTest`
(5), plus 6 new "Automatic dispatch" tests added to the existing
`StripeWebhookIngestionTest` (which also gained `Queue::fake([ProcessBillingWebhookEventJob::class])`
in `setUp()` — necessary once ingestion started dispatching, so those
existing ingestion-isolation tests, e.g. "checkout.session.expired is
stored but not applied", continue testing ingestion alone rather than
becoming full synchronous end-to-end tests via this environment's
`QUEUE_CONNECTION=sync`). Full Billing filter (Feature+Unit): 374/374 (up
from 332). Pricing filter: 39/39, unaffected. Full backend suite: 1213
tests, 1188 passed, 1 failure + 21 errors — the identical pre-existing,
unrelated failures from every prior baseline this session (storage-
directory file permission issues, plus one unrelated `payment_applications`
seed/data issue) — no new regressions, confirmed by inspecting every
failing stack trace individually.

### Documentation updated (checkpoint 9)

`internal-docs/super-admin/subscription-billing.md` (this section;
status line), `CLAUDE.md` (service table entries for the new job/command,
Database Guidelines unchanged — no new columns), `project-context.md`
(new checkpoint 9 log entry). No public documentation, no release notes,
no version bump — this remains internal infrastructure, not yet released.

## Stripe Paused Subscription Policy and Billing Operations Readiness (checkpoint 10)

A policy-and-review checkpoint, not an engineering one — **zero code
changes**. Resolves the one commercial policy intentionally left open
(Stripe `paused`) and reviews the now-functionally-complete billing
architecture (Pricing → BillingCustomer → Subscription Lifecycle →
Checkout → Verified Webhook Ingestion → Persisted Event Ledger → Webhook
Processing → Automatic Queue Processing → Recovery Orchestration) from an
operational/production-readiness angle.

### Stripe `paused` — findings

Confirmed by direct inspection of the installed `stripe-php` SDK docblock
(`vendor/stripe/stripe-php/lib/Subscription.php`, already quoted in
checkpoint 8's report) and this codebase's own Checkout configuration:

- Stripe's subscription `status: paused` is a DIFFERENT mechanism from
  `pause_collection` (a separate field that pauses invoicing while status
  stays unchanged, e.g. `active`) — **`pause_collection` is not referenced
  anywhere in this codebase** (confirmed by search) and remains completely
  out of scope; this checkpoint only concerns the `status: paused` value.
- Per Stripe's own documentation, `status: paused` is reachable **only**
  when a subscription's trial ends without a payment method attached,
  **and only if that subscription's `trial_settings.end_behavior.missing_payment_method`
  was explicitly set to `'pause'` at creation** (Stripe's own default for
  this setting is `'create_invoice'`, not `'pause'`).
- `CheckoutSessionService::resolveOrCreateCheckoutSession()`'s call to
  `createCheckoutSession()` passes no `trial_period_days`, no
  `subscription_data.trial_settings`, and no trial configuration of any
  kind to Stripe (confirmed by inspection — the only `subscription_data`
  key ever sent is `metadata`, added in checkpoint 8). SureSign's own
  trials are **sales-assisted and Super-Admin-granted directly from
  `draft` to `trialing` locally** (per
  [SureSign Commercial Strategy v1](../commercial/suresign-commercial-strategy-v1.md)
  §8 — "no new [Stripe-side trial mechanism]"), never a Stripe-native
  trial.
- **Conclusion: Stripe cannot organically produce `status: paused` for
  any subscription this integration creates, under the current Checkout
  configuration.** This is stronger than "rare" — it is currently
  unreachable, not merely unlikely, given how Checkout Sessions are
  actually created today.
- `paused` does not generate invoices while active (per Stripe's own
  docs) and resumes automatically once a payment method is added — Stripe
  itself drives the resume, not a merchant-initiated API call.

### Commercial policy decision — Option C (conflict / manual review), confirmed

**Decision: `paused` continues to map to `conflict` — unchanged from
checkpoint 8.** Rationale, weighed against Options A/B:

- **Option A (remain active, pause collection only)** rejected: silently
  treating a Stripe-paused subscription as still fully active would let
  SureSign's own commercial record diverge from Stripe's actual
  collection state — the platform would represent something that isn't
  true, which is precisely the "SureSign is source of truth, Stripe is
  the payment provider" principle inverted (Stripe's state is real and
  observed; SureSign choosing to ignore it isn't the same as SureSign
  authoritatively deciding a different local outcome).
- **Option B (suspend, remove access)** rejected: this platform has no
  access-enforcement layer at all yet (explicitly deferred across every
  checkpoint so far) — mapping `paused` to `SUSPENDED` would be inventing
  a customer-facing consequence (a real commercial/access decision) with
  no entitlement system built to carry it out consistently, and no sales/
  support process defined for how a paused-then-resumed customer is
  treated in between.
- **Option C (conflict, manual review)** confirmed: correctly surfaces an
  anomaly if `paused` ever DOES occur (a future Stripe account-level
  default change, a manual Dashboard action, or a future SureSign
  decision to opt into Stripe-native trials) rather than silently
  mishandling a state nothing in the current architecture was designed to
  interpret. Given the state is currently unreachable in practice, the
  operational cost of this choice is effectively zero, while the
  safety benefit (never guessing) is retained unconditionally.

No changes to `SubscriptionTransitions::MAP` (still no valid destination
into `PAUSED`), `SubscriptionStatusMapper` (still maps raw `paused` →
`SubscriptionStatus::PAUSED`, feeding `WebhookEventProcessor`'s existing
conflict path), or `WebhookEventProcessor` (its existing docblock already
documented this exact reasoning in checkpoint 8-9's language — this
checkpoint's review confirms it rather than superseding it).

### Lifecycle impact

None — per Part 4's own instruction ("If the commercial review concludes
paused should remain unsupported: leave current conflict behaviour
unchanged"). No direct status mutation was introduced or considered.

### Resume policy — future implementation requirements (not built)

Documented for a future checkpoint, should Stripe-native trials with
`trial_settings.end_behavior.missing_payment_method = 'pause'` ever become
a deliberate SureSign commercial decision:

- A `paused → active` (or `paused → trialing`) transition would need
  adding to `SubscriptionTransitions::MAP` and a new named
  `SubscriptionLifecycleService` method (e.g. `resumeFromPause()`),
  following the exact pattern `markIncomplete()`/`restoreToActive()`
  already establish — never a direct field assignment.
- Stale/duplicate resume events would need the same
  `provider_created_at`-vs-`last_transition_occurred_at` staleness check
  every other transition already uses.
- Provider subscription identity, audit history
  (`subscription.resumed_from_pause`-style `ActivityLog` action), and
  `last_transition_occurred_at` would all need preserving exactly as
  every existing transition method already does.
- A genuine commercial decision would still be needed first: does a
  paused-then-resumed subscription retain its original commercial terms
  unchanged, or does resuming require a fresh checkout? SureSign's
  "sales-assisted trial" model suggests the former, but this has never
  been decided and is explicitly not decided by this checkpoint.

### Billing operations review — architecture confirmed intact

Walked the full chain end to end against the current codebase (not
assumed from memory): Webhook → Queue → Processor → Lifecycle → Recovery
→ Scheduler → Logs → Model scopes → Commands → Documentation. Every link
confirmed present and consistent with checkpoints 6–9's own documentation
— no drift found. One genuine operational gap was found in the queue/
worker link specifically — see "Critical finding" below, not a gap in the
application architecture itself but in how the EXISTING deployment
configuration was never updated for the NEW queue name checkpoint 9
introduced.

### Queue deployment review

Inspected `docker-compose.prod.yml`, `backend/docker/entrypoint.sh`,
`config/queue.php` directly (not assumed):

| Aspect | Finding |
|---|---|
| Worker command | `backend/docker/entrypoint.sh`: `exec php artisan queue:work --tries=1 --timeout=480 --sleep=3` (no `--queue=` flag at all) |
| Queue connection | `database` (`QUEUE_CONNECTION=database`, `docker-compose.prod.yml`) — Redis is present in the stack but only for cache, confirmed via its own compose comment ("Redis (for queues & cache)" is aspirational/stale wording — `QUEUE_CONNECTION` is explicitly `database`, not `redis`) |
| `retry_after` | 600s (`config/queue.php`, sized for `AnalyseContractWithAiJob`'s 480s timeout) — `ProcessBillingWebhookEventJob`'s 30s timeout sits comfortably under this |
| Scheduler | Separate `scheduler` container running `schedule:work` (not cron-triggered `schedule:run`) — `billing:webhooks:recover`'s `Schedule::command()` registration is picked up automatically, no separate wiring needed |
| Restart strategy | `restart: unless-stopped` on both `queue` and `scheduler` containers |
| Graceful shutdown | `queue` container: `stop_grace_period: 490s` (sized for the 480s AI job; `pcntl` is installed, `queue:work` handles `SIGTERM` by finishing the current job then exiting). `scheduler`: `stop_grace_period: 30s` |
| Deployment sequence | Migrations are a deliberate, separate release step (`entrypoint.sh`'s `migrate` branch) — never run automatically on container start, specifically to avoid two rolling-deploy replicas both running `migrate --force` concurrently |
| Per-job override confirmed by reading Laravel's own `Worker` source | `Worker::markJobAsFailedIfWillExceedMaxAttempts()`/`timeoutForJob()` both prefer the JOB's own `$tries`/`$timeout` property over the worker CLI's `--tries=1`/`--timeout=480` when the job declares one — confirmed by direct inspection, not assumed. `ProcessBillingWebhookEventJob`'s own `$tries=3`/`$timeout=30` are therefore respected regardless of the worker's CLI flags |

### 🔴 Critical finding — the production queue worker never listens to the `billing-webhooks` queue

**This is the single most important finding of this checkpoint.**
`ProcessBillingWebhookEventJob` (checkpoint 9) dispatches onto a dedicated
`billing-webhooks` named queue (via `onQueue('billing-webhooks')`,
specifically to isolate it from slower AI/appointment jobs). The
production worker command in `backend/docker/entrypoint.sh` —
`php artisan queue:work --tries=1 --timeout=480 --sleep=3` — has **no
`--queue=` flag**, which means it listens ONLY to Laravel's default queue
name (`default`, per `config/queue.php`'s
`'queue' => env('DB_QUEUE', 'default')`). **A job dispatched onto
`billing-webhooks` will never be picked up by this worker — it will sit
in the `jobs` table indefinitely**, whether dispatched by
`WebhookIngestionService`'s after-commit path or by
`billing:webhooks:recover`'s scheduled sweep (the scheduler container
itself is unaffected — it only dispatches jobs, it doesn't need to
consume the queue — but nothing would ever consume what it dispatches).

This is a genuine architectural weakness exposed by this checkpoint's
review, not a hypothetical — it exists in the current, already-committed
configuration right now, for any environment that deploys checkpoint 9's
code as-is. It was not caught in checkpoint 9 because none of that
checkpoint's tests exercise the real production `queue:work` invocation
(tests use `QUEUE_CONNECTION=sync`, which has no queue-name concept at
all).

**This checkpoint makes NO deployment changes, per its own explicit
instruction ("No deployment changes should be made. Only document and
verify")** — this is reported as the top item for a deliberate, reviewed
fix before any production Stripe activation, with two equally valid
remediation options for that future step to choose between:

1. Add `--queue=billing-webhooks,default` to `entrypoint.sh`'s queue
   command (preserves the isolation checkpoint 9 intended).
2. Remove the dedicated queue name from `ProcessBillingWebhookEventJob`
   (dispatch onto `default` like every other existing job) — simpler,
   loses the isolation benefit, but requires no deployment file change at
   all.

Recommended: option 1, since queue isolation was a deliberate, reasoned
checkpoint 9 decision (a burst of billing-webhook load must never starve
AI-analysis jobs sharing a worker) — but this is a call for the operator/
next checkpoint to make, not decided here.

### Failure scenario review

| Scenario | Handling |
|---|---|
| Worker crash | Single-transaction claim design (checkpoint 7): a crash mid-processing rolls the ledger row back to its pre-claim state, never stuck. Database-backed queue means undelivered jobs survive the crash in the `jobs` table |
| Webhook duplicate delivery | `(provider, provider_event_id)` unique constraint (checkpoint 6) — deduplicated before any job is even considered |
| Database restart | An in-flight transaction fails cleanly (rolled back by the DB itself); Stripe's own webhook retry (backoff over ~3 days) naturally redelivers if the HTTP response wasn't a 2xx; the DB-backed `jobs` table itself is unavailable during the outage, so no new jobs can be dispatched or consumed until it returns — no work is lost, only delayed |
| Queue container restart | `restart: unless-stopped` + graceful `SIGTERM` handling (subject to the critical finding above — currently would restart a worker not even consuming billing jobs) |
| Scheduler stopped | The after-commit dispatch path is entirely independent of the scheduler — new webhooks still process normally. Only the SAFETY NET (stale-processing/retryable-failed/stranded-received recovery) stops running; affected rows simply wait, silently, until the scheduler resumes or an operator runs `billing:webhooks:recover` manually |
| Stripe retries | Naturally absorbed by the same unique-constraint dedup as any duplicate delivery |
| Delayed / out-of-order webhook | `provider_created_at` + `subscriptions.last_transition_occurred_at` staleness rejection (checkpoints 7–8) — a delayed event can never roll a subscription backward |
| Server reboot | All three containers (`backend`/`queue`/`scheduler`) are `restart: unless-stopped`; the database-backed `jobs` table means no in-flight dispatched-but-unprocessed job is lost across a reboot |
| Application deployment | Migrations are a separate, deliberate release step — never automatic on container start, specifically to avoid two replicas racing `migrate --force`. Whether the actual container replacement is a rolling or full-stop deploy is a Dokploy-level operational behaviour outside this codebase's own control — not verified further here (out of scope: "do not solve unrelated future problems") |

No new architectural weakness found beyond the queue-name gap above — every other scenario is already correctly handled by the design established in checkpoints 6–9.

### Production readiness assessment

| Dimension | Assessment |
|---|---|
| Security | Signature verification, mode isolation, dedicated rate limiter, no payload/secret leakage in logs — all confirmed in place from checkpoints 6–9 |
| Concurrency | Row-locked claim (`SELECT ... FOR UPDATE`), per-organisation locks (`Cache::lock`), claim-lease recovery — confirmed |
| Financial correctness | No direct status mutation anywhere; every transition through named lifecycle methods; commercial-snapshot/Price/livemode validation on every event — confirmed |
| Auditability | `ActivityLog` on every domain transition and processing outcome; payload retained (not exposed) — confirmed |
| Observability | Model scopes (`unresolvedConflicts()`, `retryableFailed()`), `--dry-run` reporting — confirmed |
| Recovery | Claim-lease + scheduled sweep — confirmed, but see the critical finding: recovery dispatches into a queue nothing currently consumes |
| Idempotency | Proven at every layer (ingestion, processor, job, recovery command) by existing test suites |
| Commercial correctness | `paused` policy now explicit; `incomplete`/`incomplete_expired` handled; every other unsupported event ignored, never guessed |

**Overall: the billing architecture is functionally complete and
correctly designed, but NOT YET SAFE to activate with live Stripe
credentials** — specifically and only because of the queue-worker gap
above. Once that is deliberately fixed and verified, live activation
still additionally requires the separate, deliberate go-live steps below
(none of which are blocked by any code defect — they are simply
undone/off by design).

### Production checklist

- [ ] **Fix the `billing-webhooks` queue/worker mismatch** (see critical
      finding) — the one genuine blocker found by this review.
- [ ] Set `BILLING_ENABLED=true` (currently `false` by default,
      `config/billing.php`/`.env.example`) — a deliberate go-live switch,
      not flipped by any checkpoint so far.
- [ ] Configure real `STRIPE_KEY`/`STRIPE_SECRET`/
      `STRIPE_WEBHOOK_SECRET_LIVE` (live-mode) — `BillingConfigGuard`
      already refuses to boot with a live key present in local/testing,
      confirming the safety direction is already correct.
- [ ] Register the live-mode Stripe webhook endpoint in the Stripe
      Dashboard pointed at `/api/billing/webhooks/stripe`.
- [ ] Decide and communicate the `paused` policy operationally (documented
      above — no code action needed, but Support/Sales should know a
      `conflict` row requires manual investigation if it ever appears).
- [ ] Confirm a real worker process is actually running and consuming the
      correct queue in the target environment (verify, don't assume —
      `pgrep -f 'artisan queue:work'` plus checking its actual `--queue`
      argument).
- [ ] Decide whether `BILLING_ENFORCEMENT_ENABLED` should ever become
      `true` — currently `false` by design; this is an access-enforcement
      decision explicitly out of scope for every checkpoint so far and
      remains a separate, future, deliberate decision.

### Known unsupported Stripe features (deliberate, documented)

`paused` (see above — conflict by design, currently unreachable in
practice), `pause_collection` (never referenced, no plan to support),
Stripe-native trials (`trial_settings`/`trial_period_days` — SureSign
uses its own sales-assisted trial model instead), invoices, payments,
`payment_intent.*`, `charge.*`, refunds, disputes, Stripe Tax, coupons/
discounts/promotion codes, Customer Portal, metered/usage billing — all
explicitly out of scope across every checkpoint to date, not gaps.

### Operational runbook (reference)

- **Investigate a conflict**: query
  `BillingWebhookEvent::unresolvedConflicts()`, read `failure_message`
  (stable action+error-code label) and the untouched `payload_json`;
  cross-reference the correlated `Subscription`/`BillingCheckoutSession`
  via the corresponding `ActivityLog` entry's metadata.
- **Check for anything stuck right now**: `php artisan
  billing:webhooks:recover --dry-run` — a live, zero-side-effect report.
- **Manually reprocess one event**: `php artisan billing:webhooks:recover
  --event-id=<id>` (refuses `conflict`/non-retryable-`failed` rows by
  design).
- **Confirm the worker is consuming the right queue**: inspect the
  running `queue:work` process's actual arguments in the target
  environment — do not assume from this documentation alone once a fix
  has been applied, verify directly.

### Rollback guidance

No schema rollback is implied by this checkpoint (no migrations).
Application-level rollback (reverting to pre-checkpoint-9 code) would
simply stop automatic dispatch — verified webhook events would still be
recorded (checkpoint 6 behaviour), just not automatically processed,
which is a safe (if inactive) state, not a broken one. No data
migration or backfill is required in either direction.

### Documentation updated (checkpoint 10)

`internal-docs/super-admin/subscription-billing.md` (this section;
status line), `CLAUDE.md` (paused-policy confirmation, queue/worker gap
flagged), `project-context.md` (new checkpoint 10 log entry). No public
documentation, no release notes, no version bump.

## Billing Worker Alignment and Offline Production-Readiness Validation (checkpoint 11)

Fixes the exact deployment gap checkpoint 10 found (`ProcessBillingWebhookEventJob`
dispatched to `billing-webhooks`; the production worker only ever
consumed `default`) and proves the fix — and the full webhook-to-lifecycle
pipeline — entirely offline, since the Stripe account is still being
onboarded. No Stripe credential, webhook endpoint, or Checkout was
required or used anywhere in this checkpoint.

### The fix

`backend/docker/entrypoint.sh`'s `queue` branch:

```bash
# before
exec php artisan queue:work --tries=1 --timeout=480 --sleep=3

# after
exec php artisan queue:work --queue=billing-webhooks,default --tries=1 --timeout=480 --sleep=3
```

**Chosen policy: the Preferred policy (single worker, `billing-webhooks`
listed first)** — not a separate dedicated worker service. Reasoning: the
existing container architecture already runs exactly one `queue`
container image; adding a second worker service would duplicate an
entire container (build, healthcheck, restart policy, resource
allocation) for a queue whose current load (occasional webhook events) is
tiny compared to `AnalyseContractWithAiJob`'s. Laravel's `queue:work
--queue=a,b` drains queue `a` completely before ever checking `b`, so
listing `billing-webhooks` first fully achieves the isolation checkpoint
9 intended (a burst of billing events is never delayed behind a slow AI
job) without any new infrastructure. The dedicated queue NAME itself
(`billing-webhooks`) was kept exactly as checkpoint 9 defined it — only
the worker's own `--queue` argument was wrong, not the job's queue
assignment.

No `QUEUE_NAMES` environment variable was introduced (the checkpoint
brief offered this as an option "only if this matches existing repository
conventions" — it doesn't: every other queue-related worker flag in this
same `entrypoint.sh` line, `--tries`/`--timeout`/`--sleep`, is already
hardcoded, not env-configurable — adding configurability for just the
queue list would be a new, inconsistent pattern for one call site).

### Worker retry settings reconciled and confirmed, not changed

Confirmed by reading Laravel's own `Worker::markJobAsFailedIfWillExceedMaxAttempts()`/
`timeoutForJob()` directly (same finding as checkpoint 10, re-verified
here since it's directly load-bearing for this checkpoint's fix): both
prefer a job's own `tries()`/`timeout()` over the worker CLI's
`--tries=1`/`--timeout=480` when the job declares one.
`ProcessBillingWebhookEventJob`'s `$tries=3`/`$timeout=30`/`$backoff=[10,60]`
are therefore respected regardless — the worker flags remain correct
*defaults* for jobs that don't declare their own (every existing AI/
appointment job), not a ceiling that silently overrides billing's
stricter settings. A comment was added at the fix site itself explaining
this precisely, since the flags read misleadingly out of context
otherwise. `config/queue.php`'s `retry_after` comment was similarly
extended with one line confirming the billing job's 30s timeout sits
comfortably under the existing 600s value — no value was changed.

**Timeout-vs-retry_after validated**: `30s < 600s` ✓. **Concurrent
processing of the same event by two workers validated**: unaffected by
this checkpoint — still governed entirely by `WebhookEventProcessor`'s
own `SELECT ... FOR UPDATE` row lock (checkpoints 7–9), never by queue
configuration; a worker restart mid-job cannot cause concurrent
processing of the same ledger row for the same reason it couldn't before
this fix.

### Scheduler — verified unchanged, no problem found

`scheduler` container still runs `schedule:work` (not cron-triggered
`schedule:run`), `billing:webhooks:recover` is still registered at
`everyFiveMinutes()->withoutOverlapping()->runInBackground()`
(`routes/console.php`, unchanged since checkpoint 9). The scheduler's own
consumption path is entirely independent of the queue-worker fix above —
it only ever *dispatches* jobs; it was never the broken half of this
pipeline, and remains untouched.

### Offline/synthetic end-to-end validation — no Stripe account required

`BillingWebhookQueueIntegrationTest` (new) proves the actual named-queue
behaviour end to end using only local fixtures — no Stripe, no network
call, no factory config change:

1. Overrides `config(['queue.default' => 'database'])` for this test
   class only — every other Billing test deliberately keeps
   `QUEUE_CONNECTION=sync` (`phpunit.xml`) for speed; this is the one
   place that trade-off would hide the exact regression this checkpoint
   exists to fix, since `sync` has no queue-name concept at all and
   `Queue::fake()` (used elsewhere, e.g. `RecoverBillingWebhookEventsTest`)
   records a push without ever routing it anywhere a worker could fail to
   consume.
2. Creates a synthetic, already-verified `BillingWebhookEvent` row
   directly (`received` status, a `payload_json` marker string used only
   to prove it never leaks) — never a real Stripe payload, never
   constructed via signature verification (this checkpoint does not fake
   or bypass that boundary; it starts one step past it, exactly where
   `WebhookEventProcessor` itself starts).
3. Dispatches the REAL `ProcessBillingWebhookEventJob` — proven to land
   in the real `jobs` table with `queue = 'billing-webhooks'`
   (`assertDatabaseHas`).
4. Drives `php artisan queue:work --queue=... --once --stop-when-empty`
   via `Artisan::call()` — a single, bounded, deterministic reservation
   attempt, never a long-running background process (which would make
   the suite flaky) — the SAME command `entrypoint.sh` execs into,
   invoked once.
5. Proves a worker consuming only `default` cannot reserve the job (row
   count unchanged, ledger row untouched) — the exact failure mode this
   checkpoint fixes, reproduced and then shown fixed in the next test.
6. Proves a worker consuming `billing-webhooks,default` DOES reserve,
   process (via the real `WebhookEventProcessor` → lifecycle services —
   no shortcut writes the final state directly), and remove the job.
7. Proves exactly one lifecycle-adjacent transition occurs (a
   `checkout.session.expired` fixture with no linked local session
   correlates to a retryable failure — the point being the QUEUE
   mechanics, not correlation, which `WebhookEventProcessorTest` already
   covers exhaustively).
8. Proves duplicate dispatch + duplicate processing over the real queue
   remains idempotent (`attempt_count` increments, `ActivityLog` entry
   count does not double).
9. Proves `billing:webhooks:recover` redispatches a deliberately
   stranded `received` row through the real queue end to end.
10. Proves no raw payload appears in the `jobs` table's own serialized
    payload (only the integer ID) nor in `Log::error` calls.

`DeploymentQueueConfigurationTest` (new) closes the loop: it reads
`backend/docker/entrypoint.sh` and `docker-compose.prod.yml` DIRECTLY (no
duplicated test-only constant) and parses the actual `queue:work`
command line's `--queue` argument, asserting `billing-webhooks` is
present and listed before `default` — this is what actually prevents the
checkpoint-9-era regression ("jobs dispatched correctly, nothing deployed
consumes them") from silently recurring; without it, every other test in
this suite would keep passing even if the fix above were later reverted
by accident.

### Database queue integration test — summary of what's proven

| Claim | Proof |
|---|---|
| Job is stored on `billing-webhooks` | `assertDatabaseHas('jobs', ['queue' => 'billing-webhooks'])` |
| A `default`-only worker cannot consume it | job count unchanged, ledger untouched after `--queue=default --once` |
| The deployed policy (`billing-webhooks,default`) consumes it | job removed, ledger reaches its final status after `--queue=billing-webhooks,default --once` |
| Lifecycle transition occurs exactly once | single `attempt_count` increment, no duplicate `ActivityLog` entry across two dispatches |
| Recovery command reaches the real queue | `billing:webhooks:recover` → real `jobs` row → real worker reservation → final ledger status |
| No raw payload in queue storage or logs | serialized `jobs.payload` column and `Log::error` calls both inspected directly |

### Queue and scheduler health — documented, no new command

No health command was added (`SystemStatusService`/`SystemStatusController`
is a PUBLIC-facing Help-Centre status surface — the wrong audience for
internal billing-queue operational detail, so it was deliberately left
untouched rather than extended). Operational visibility remains exact
queries/commands, extending checkpoint 9's runbook:

- **Queue worker running?** `pgrep -f 'artisan queue:work'` inside the
  `queue` container (already the existing healthcheck).
- **Scheduler running?** `pgrep -f 'artisan schedule:work'` inside the
  `scheduler` container (already the existing healthcheck).
- **Oldest queued `billing-webhooks` job age**: `DB::table('jobs')->where('queue', 'billing-webhooks')->min('created_at')`.
- **Stranded `received` count**: `BillingWebhookEvent::where('processing_status', 'received')->count()`.
- **Stale `processing` count**: the same query `RecoverBillingWebhookEvents::staleProcessingQuery()` uses.
- **Retryable failed count**: `BillingWebhookEvent::retryableFailed()->count()` (existing scope).
- **Unresolved conflicts**: `BillingWebhookEvent::unresolvedConflicts()->count()` (existing scope).
- **Failed jobs for this job class**: `DB::table('failed_jobs')->where('payload', 'like', '%ProcessBillingWebhookEventJob%')->count()`.
- **One-shot live report**: `php artisan billing:webhooks:recover --dry-run` (existing, checkpoint 9).

None of these expose a webhook payload — every query above touches only
identifiers, timestamps, and counts.

### Safe deployment sequence (documented, not executed)

1. Deploy the updated image (this checkpoint's `entrypoint.sh` change is
   part of the normal image build — no separate step).
2. Run migrations as the existing separate release step (`docker compose
   -f docker-compose.prod.yml run --rm backend migrate`) — unchanged by
   this checkpoint (no new migrations).
3. Restart `backend` containers (rolling, per existing Dokploy/compose
   behaviour — unverified further, outside this codebase's control).
4. Restart the `queue` container — `restart: unless-stopped` +
   `stop_grace_period: 490s` already guarantee a graceful `SIGTERM` (finish
   current job, then exit) rather than a hard kill; unchanged by this
   checkpoint.
5. **Confirm** the running worker's actual process arguments include
   `--queue=billing-webhooks,default` (e.g. `ps -p $(pgrep -f 'artisan
   queue:work') -o args=`) — do not assume from documentation alone,
   verify directly in the target environment.
6. Confirm the `scheduler` container is running (existing healthcheck).
7. Run this checkpoint's offline synthetic queue validation (`php artisan
   test --filter=BillingWebhookQueueIntegrationTest`) against the target
   environment if practical, or rely on the equivalent having passed in
   CI.
8. Confirm no stranded billing jobs: `php artisan billing:webhooks:recover
   --dry-run` should report nothing already piled up from before the fix.
9. Only once all of the above hold: proceed to Stripe Test Mode account
   setup (separate, future checkpoint).
10. Only after Test Mode validation succeeds: consider Live Mode (separate,
    future, deliberate decision).

### Rollback procedure

Reverting `entrypoint.sh` to the pre-checkpoint-11 command (no `--queue`
flag) does **not** delete or corrupt anything already in the
`billing-webhooks` queue — Laravel's database queue driver just leaves
unclaimed rows sitting in the `jobs` table indefinitely; a worker that
stops consuming a queue never removes what it doesn't reserve. **Do not
manually delete queued `billing-webhooks` rows during a rollback** — once
the fix is reapplied (or `billing:webhooks:recover` is run once the
underlying ledger rows are old enough to qualify as stranded), they
resume processing exactly as if the rollback had never happened. No data
migration, backfill, or manual queue-table cleanup is implied by a
rollback in either direction.

### Stripe account readiness checklist (deferred — not performed)

For the future checkpoint once Stripe onboarding completes — none of the
following were performed or required here:

- [ ] Stripe business profile completed
- [ ] Required business verification completed
- [ ] Authorised account owner confirmed
- [ ] Test Mode secret key obtained
- [ ] Test Mode publishable key obtained (if the frontend needs it)
- [ ] Test Mode webhook endpoint created in the Stripe Dashboard
- [ ] Test Mode webhook signing secret stored in the deployment secret
      manager (never committed to the repository)
- [ ] Expected webhook event types selected (`checkout.session.completed`/
      `.expired`, `customer.subscription.created`/`.updated`/`.deleted` —
      the five this integration actually supports; see checkpoint 7)
- [ ] Application base URL confirmed for Checkout success/cancel URLs
- [ ] Test products and Prices mapped via `PlanPriceMappingService`
- [ ] No Live Mode credentials used during Test Mode work
      (`BillingConfigGuard` already refuses to boot with a live-looking
      key in local/testing — confirmed working, checkpoint 1)
- [ ] Webhook endpoint signature validation tested against the real Test
      Mode signing secret
- [ ] One complete real Test Mode Checkout performed end to end
- [ ] 3-D Secure / `incomplete` flow tested against a real Test Mode card
- [ ] Cancellation and expiration tested against real Test Mode events
- [ ] Duplicate webhook delivery tested against real Stripe redelivery
      (not just the synthetic dedup tests already in this suite)
- [ ] Queue worker confirmed (again, in the live target environment) to
      actually process `billing-webhooks` under real Stripe traffic
- [ ] `billing:webhooks:recover` exercised against a real stranded event
      at least once
- [ ] Secrets stored only in the deployment secret manager
- [ ] No Stripe secret committed to the repository (confirmed already
      true — `.env.example` contains no real values)

Where Stripe onboarding/account restrictions currently prevent a step
(all of them, at present — the account isn't ready), that step is marked
waiting on account setup, not an application defect.

### Production activation — still blocked

Even with this checkpoint's fix applied, production activation remains
blocked on: Stripe account setup completing, Test Mode credentials
becoming available, a Test Mode webhook endpoint being registered, one
real Test Mode Checkout succeeding end to end through the now-fixed
deployed queue, the unrelated pre-existing backend test failures being
resolved or formally accepted as out of scope, live credentials being
configured separately, and a reviewed go-live/rollback approval. **`BILLING_ENABLED`
remains `false`** (unchanged default, `config/billing.php`/`.env.example`)
— this checkpoint does not touch it.

### Documentation updated (checkpoint 11)

`internal-docs/super-admin/subscription-billing.md` (this section;
status line), `CLAUDE.md` (worker command/queue-policy note, paused
cross-reference retained), `project-context.md` (new checkpoint 11 log
entry). No public documentation, no release notes, no version bump.

## Deferred (not part of this checkpoint)

Invoice sync, payment sync, refunds, credit notes, Stripe Tax, Customer
Portal, `subscription_entitlements`/`subscription_overrides` migrations,
`EntitlementService`, entitlements enforcement, usage counters, access
enforcement middleware, notifications, Super Admin `/admin/billing` UI,
organisation-facing `/settings/billing` UI, and any billing
controller/frontend page are all still deferred to later checkpoints.
`CheckoutSessionService`'s checkout flow has no route/controller exposing
it yet either.

**Resolved in checkpoint 9**: queue infrastructure and an automatic
trigger that calls `WebhookEventProcessor` — both previously listed here
— now exist (`ProcessBillingWebhookEventJob`, after-commit dispatch,
`billing:webhooks:recover`).

**Resolved in checkpoint 10**: the `paused` commercial-policy decision —
confirmed as `conflict`/manual-review, deliberately, with the concrete
finding that it is currently unreachable given Checkout's lack of
Stripe-native trial configuration (see checkpoint 10's section above for
the full reasoning). No longer an open question.

**Resolved in checkpoint 11**: the queue/worker mismatch checkpoint 10
found — `backend/docker/entrypoint.sh`'s `queue` branch now runs
`queue:work --queue=billing-webhooks,default`, proven correct with real
database-queue integration tests (`BillingWebhookQueueIntegrationTest`)
and a deployment-configuration test that reads `entrypoint.sh` directly
(`DeploymentQueueConfigurationTest`) — see checkpoint 11's section above.
No longer an open blocker. Remaining before production activation:
Stripe account onboarding, Test Mode credentials, and the standard go-live
checklist — none of which are application defects.

**Resolved this checkpoint (no longer deferred)**: `subscription_data.metadata`
propagation (checkpoint 8) and `SubscriptionLifecycleService::markIncomplete()`
(checkpoint 8) — both previously listed here as blocking live 3-D-Secure
activation. Still deferred: the commercial-policy decision required
before `paused` could be supported (see "`paused` — still `conflict`"
above) and a queued/scheduled automatic trigger that actually invokes
`WebhookEventProcessor` (still a plain synchronous method with no
automatic caller — see "Claim recovery" above for why the claim-lease
exists ahead of that trigger existing, not because of it).

Also explicitly deferred from this checkpoint: actually *applying* a
scheduled plan change (see "Plan changes — preparation only" above), the
Super Admin-facing repair workflow for a `BillingCustomerReconciliationException`
raised by the now-conservative `reconcile()`, and a smarter provider-
reconciliation dispatcher (`recordProviderState()` deliberately throws
on any status mismatch rather than guessing which named transition to
apply — a future reconciliation checkpoint builds that dispatch logic on
top of the named transition methods here, with its own reasoning about
which one is correct for a given mismatch).

Organisation-facing billing access remains blocked on a still-undecided
question: who counts as an "Organisation Admin" on this platform's actual
role model (Admin is platform-level, Client is organisation-scoped — see
`internal-docs/roles/`) — this must be answered before that phase starts.

Form Requests for billing endpoints were deliberately not created in this
checkpoint, since no controller/route exists yet for them to validate — they
will be added alongside the controller that needs them, per phase.

---

# Plan Entitlements & Feature Access Architecture (checkpoint 12)

The layer that sits **above** Subscription & Billing and **below** every
application module — implements
[SureSign Entitlement Specification v1](../commercial/suresign-entitlement-specification-v1.md),
approved the same day this checkpoint was built. Architecture only, per
explicit instruction: no controller, middleware, or module calls any of
this yet, no user-facing behaviour changed, and no billing enforcement
was introduced. Stripe remains only the payment provider — nothing in
this layer makes a Stripe call or holds a Stripe dependency of any kind
(proven by `FeatureGateTest::test_entitlement_classes_never_import_a_stripe_or_billing_provider_class()`).

```text
Stripe → Subscription → Plan → Entitlement Snapshot → FeatureGate → Application Modules
```

## Reconciliation: feature catalogue scope

The checkpoint brief's Part 2 requested a broad platform feature
inventory (Projects, Contracts, Trade Packages, RFIs, Meetings, Site
Reports, QA, Snagging, Delay Events, EOT, Loss & Expense, Final Accounts,
reporting, notifications, exports, etc.) and Part 3 illustrated a
`Feature` catalogue with examples like `UNLIMITED_PROJECTS`. **The
implemented catalogue deliberately covers only the ten keys the approved
Entitlement Specification defines** (Section 4), not a `Feature` case per
module. This is a direct application of that document's own explicit
constraints, found during Part 1 inspection rather than invented here:

- Section 2, principle 10: "dormant future entitlement keys must not
  create current commercial promises."
- Section 4: "No entitlement keys beyond these ten are proposed... the
  existing role/permission system... must not be turned into billing
  entitlements. A user's ability to edit a Site Instruction is an
  authorization concern, not a commercial entitlement."
- Commercial Strategy §6: every module listed in the brief is available
  uniformly to any active subscription today — there is no
  module-granularity commercial gating anywhere in the approved business
  model; the three plans differ only on the ten registry
  dimensions (projects/AI/storage/branding/reporting/support/exports/API,
  plus two dormant seat/org keys).

**The module inventory itself is still recorded** (see "Feature
inventory" below) — categorised, not discarded — but the *only* things
represented as `Feature` cases callable through `FeatureGate` are the ten
approved keys. Inventing a `Feature::RFI_MANAGEMENT` or
`Feature::SITE_DIARY` case with no corresponding registry entry would
silently create exactly the kind of unapproved commercial promise Section
2 principle 10 warns against — the checkpoint's own "do not duplicate
existing concepts" instruction (Part 1) applies here precisely, since the
registry already existed as an approved specification before this
checkpoint wrote a line of code.

## Repository findings (Part 1)

- No existing code represented entitlements, quotas, or licensing before
  this checkpoint — `pricing_plans` carries only public commercial
  presentation fields (name, price, description); nothing on it or on
  `subscriptions` encodes "what can this org actually do."
- Spatie roles/permissions and each controller's own `authorize()` method
  are a **separate, pre-existing, and correctly-scoped** system —
  authorization (can THIS USER do X within their organisation), not
  entitlement (can THIS ORGANISATION'S SUBSCRIPTION do X at all). This
  checkpoint does not touch either.
- No feature-flag system, no module-licensing concept, no usage-counter
  infrastructure existed anywhere in the codebase.
- `Organization::liveSubscription()` (a `hasOne` returning the latest
  subscription in a `SubscriptionStatus::LIVE` status) already existed and
  is reused directly by `FeatureGate` — not duplicated.
- **Finding, not fixed (out of scope)**: `database/seeders/PricingSeeder.php`
  seeds a plan with `code => 'starter'`, contradicting the Commercial
  Strategy's explicit "Three plans only: Essential, Professional,
  Enterprise — no Starter" decision. This seeder is not wired into
  `DatabaseSeeder` (confirmed by inspection — it's not called anywhere),
  so it does not affect any real environment's actual plan codes; flagged
  here rather than silently corrected, since touching Pricing Management
  seed data is outside this checkpoint's scope.

## Feature inventory and categorisation (Part 2)

| Category | Examples | Commercially gated? |
|---|---|---|
| **Modules** (core workflow) | Projects, Contracts, Trade Packages, Variations, RFIs, Meetings, Site Reports, QA, Snagging, Delay Events, EOT, Loss & Expense, Final Accounts, Notifications | No — uniform across every active subscription |
| **Capabilities** (the ten approved entitlement keys) | AI analyses (usage), active projects (usage), storage (usage), custom branding, advanced reporting, priority support | Yes — see `Feature` registry |
| **Future limits** (not yet enforced, keys already reserved) | `max_users`, `max_organisations` | Dormant — never enforced, sold, or shown |
| **Future usage-based features** | Accounting exports, API access | Reserved keys; feature doesn't exist yet, so `not_applicable` on every plan |
| **Future Enterprise-only options** | Negotiated overrides on any of the above | Via `EntitlementOverrideRepository` (Part 8/9's seam) |

## Feature catalogue design (Part 3)

`App\Support\Entitlements\Feature` — a plain class with string constants
(`Feature::CUSTOM_BRANDING`, etc.), matching this codebase's existing
vocabulary convention (`SubscriptionStatus`, `WebhookProcessingStatus`)
rather than a native PHP `enum` (no other part of the codebase uses one
yet, and the brief explicitly permits "an equivalent existing project
convention"). Each key carries display name, category
(`EntitlementCategory::FEATURE`/`USAGE`/`RESERVED`), value type
(`EntitlementValueType::BOOLEAN`/`INTEGER`/`DECIMAL`), unit, enforcement
level (`EnforcementLevel::*`, descriptive metadata only — nothing reads
it to actually enforce anything), sold/customer-visible/overrideable
flags — transcribed directly from Entitlement Specification v1 Section
4's table, not re-derived.

`EntitlementValue` (a final, immutable value object) enforces Section
5/6's typing discipline at construction: a value must match its declared
`value_type`, and `is_unlimited = true` must always pair with
`value = null` — never a magic number standing in for "unlimited."
`EntitlementSource` mirrors Section 8's six named sources plus a `NONE`
value for "nothing was resolved from anywhere" (not one of the
specification's named sources, added because `EntitlementDecision::source`
must never be null or an ad hoc string).

## Plan entitlement definitions (Part 4)

`App\Support\Entitlements\PlanEntitlements` — the single authoritative
registry, keyed by plan code (`essential`/`professional`/`enterprise`,
the Commercial Strategy's approved three-tier naming). **All figures are
indicative placeholders**, mirroring the Entitlement Specification's own
explicit caveat that its Section 4 table contains no approved values —
changing them is a founder/business decision, not a code change. Every
key gets an explicit resolved entry per plan (never a sparse "missing =
use default" table, per Section 8) except the two dormant keys, which
never appear in ANY plan's entitlement set at all (confirmed by test).
Enterprise's defaults are explicitly documented as a BASELINE only —
Section 18 explicitly warns against defaulting Enterprise to
automatically unlimited "for convenience"; a real Enterprise deal is
expected to immediately layer negotiated overrides on top.

## Entitlement snapshot recommendation (Part 5)

**Recommendation: yes, build `subscription_entitlements` (Section 8) —
but not in this checkpoint.** The specification itself defers the exact
persistence structure to "a later, separate implementation checkpoint,"
and this checkpoint's own brief says "do not implement unnecessary
persistence unless clearly justified." Neither `subscription_entitlements`
nor `subscription_overrides` (Section 9) was created.

**Known, accepted, explicitly documented limitation this creates**:
without a snapshot table, `FeatureGate` resolves a subscription's
entitlements by calling `PlanEntitlements::forPlanCode($subscription->plan_code_snapshot)`
LIVE, every time. `plan_code_snapshot` itself is already frozen at
subscription-creation time (protects against a `pricing_plans` row being
edited later), but the VALUES `PlanEntitlements` returns for a given code
are read from the current deployed code, not a stored-at-creation-time
snapshot. A future edit to `PlanEntitlements`'s numbers would therefore
retroactively change what an already-existing subscription resolves to —
directly contradicting Entitlement Specification v1 §2 principle 4
("later pricing-plan changes must not silently alter existing
subscriptions"). This is called out prominently, in the code itself
(`PlanEntitlements`'s own class docblock) and here, as a deliberate,
temporary trade-off of building the architecture ahead of real customer
data existing to snapshot — not an oversight. Closing it is exactly what
building `subscription_entitlements` in a future checkpoint would do:
`FeatureGate` would read the frozen snapshot row instead of calling
`PlanEntitlements` live, with no change to any future module call site.

## FeatureGate architecture (Part 6)

`App\Services\Entitlements\FeatureGate` — the single central service.
Public API: `allows()`, `limit()`, `isUnlimited()`, `requireFeature()`
(throws `FeatureNotEntitledException`), `explain()` (Section 14's
`explainDecision()`, typed as `EntitlementDecision` rather than an
untyped array). Deliberately does NOT implement `getUsage()`/
`canConsume()`/`isNearLimit()` — Section 11 identifies these as needing
real usage-measurement infrastructure this checkpoint explicitly excludes
("do not implement... usage tracking"); their signatures are documented
here as the acknowledged future contract, not built.

**Resolution order** (highest precedence first): dormant key → always
`notApplicable()`, checked before anything else, so nothing below can
ever make `max_users`/`max_organisations` resolve to a real value →
active override (`EntitlementOverrideRepository`, Part 8/9) → status-
driven profile (trial profile while `trialing`; plan defaults while
`active`/`past_due`; "not entitled" for every other status) → no
subscription or unrecognised plan code → "not entitled."

**Status policy, deliberately conservative and explicitly labelled as
such**: Section 16/24 explicitly defer the `unpaid`/`suspended`/
`cancelled`/`expired` access policy to "a separate, dedicated lifecycle
and access-policy review." `FeatureGate::statusGrantsEntitlements()`
therefore only returns true for `active`/`past_due` (grace) today — every
other status resolves to "not entitled," a safe placeholder with zero
live effect (nothing calls `FeatureGate` yet), not a claim that the
harder policy question is settled. This governs COMMERCIAL entitlement
resolution ONLY — Section 15's absolute guarantee (viewing/downloading
existing records must never be gated by any entitlement decision, at any
status) deliberately does not run through `FeatureGate` at all; it is a
separate guarantee this layer does not implement or interfere with.

## Future limit architecture (Part 7)

Usage entitlements (`max_active_projects`, `ai_analyses_per_month`,
`storage_gb`) already resolve through `FeatureGate::limit()` exactly like
feature flags — the same call shape, the same resolution order, the same
override seam. What's missing for real enforcement is USAGE MEASUREMENT
(Section 11), not a different `FeatureGate` API: a future
`getUsage()`/`canConsume()` would read from a live `COUNT()` (active
projects — no new infrastructure needed), a maintained counter (AI
analyses — cost-sensitive, needs atomic increment-and-check similar to
`DocumentNumberService`'s existing lock pattern), or a periodically
computed aggregate (storage). None of this requires changing
`PlanEntitlements`, `Feature`, or any future module's
`FeatureGate::allows(...)` call site — only `FeatureGate`'s own internals
grow new methods.

## Enterprise overrides (Part 8) / Manual overrides (Part 9)

Both share one extension point: `App\Services\Entitlements\EntitlementOverrideRepository`
(an interface), consulted by `FeatureGate` before falling back to plan
defaults. `App\Services\Entitlements\NullEntitlementOverrideRepository`
(bound in `App\Providers\EntitlementServiceProvider`, the only
implementation this checkpoint ships) always reports "no override" —
every subscription resolves purely from `PlanEntitlements`/the trial
profile today. A future checkpoint building real `subscription_overrides`
persistence (Enterprise negotiated terms, Section 18, or a Super Admin
manual grant like "enable AI Programme for Organisation X for 30 days")
only needs to bind a different implementation here — proven by
`FeatureGateTest::test_an_active_override_takes_precedence_over_the_plan_default()`,
which injects a stub override directly and shows it winning over
Essential's plan default with zero change to `FeatureGate` itself or to
any future module call site.

## Trial architecture (Part 10)

`PlanEntitlements::trialProfile()` — a DEDICATED profile (Section 17), not
Essential/Professional defaults reused: generous on active-projects and
feature flags (demonstrates real workflow value — proven equal to
Professional's `advanced_reporting` value in
`PlanEntitlementsTest::test_trial_profile_is_dedicated_not_reused_from_a_standard_plan()`),
capped more tightly than any standard plan specifically on AI analyses
(proven by `test_trial_ai_allowance_is_capped_more_tightly_than_any_standard_plan()`)
— the one dimension with real, uncontrolled Anthropic cost exposure
during an unpaid period. `FeatureGate` resolves the trial profile whenever
`subscription->status === SubscriptionStatus::TRIALING`, entirely
compatible with the existing `SubscriptionLifecycleService::startTrial()`
— no change to that service was needed or made.

## Example future module integrations (Part 11) — illustrative only, not wired in

None of the following exist as real code in any controller — they
demonstrate the intended call shape for when a future checkpoint actually
integrates:

```php
// AI Contract Analysis — a feature flag today has no gate (AI analysis
// itself isn't one of the ten keys; ai_analyses_per_month is the USAGE
// entitlement that would eventually gate it once usage counting exists):
if (!$featureGate->limit($organization, Feature::AI_ANALYSES_PER_MONTH)->isUnlimited
    && /* future: current month's usage >= limit */ false) {
    // future: soft-limit warning, per Entitlement Specification §13 —
    // never block unrelated contract administration functionality.
}

// API Access (reserved key — feature doesn't exist yet):
$featureGate->requireFeature($organization, Feature::API_ACCESS);
// today: always throws FeatureNotEntitledException, since every plan
// resolves API_ACCESS as not_applicable — correct, since no public API
// exists in this codebase at all yet (see Feature registry).

// Custom Branding (already a real, working feature — DocumentGenerationService
// already resolves branding via BrandingService; this shows how a FUTURE
// gate would sit alongside it, not replace it):
if ($featureGate->allows($organization, Feature::CUSTOM_BRANDING)) {
    // future: apply organisation branding — BrandingService's existing
    // resolution logic is untouched by this checkpoint.
}

// Advanced Reporting:
if (!$featureGate->allows($organization, Feature::ADVANCED_REPORTING)) {
    // future: 403, or hide the cross-project report in the UI — the
    // backend decision is authoritative either way (Section 14: "never
    // only in frontend UI").
}
```

## Testing (checkpoint 12)

48 new tests, all without any Stripe/HTTP dependency: `FeatureTest` (10 —
registry completeness, dormant/sold/visible flags, category/value-type
classification), `EntitlementValueTest` (8 — typing discipline,
unlimited-representation validation), `PlanEntitlementsTest` (10 — plan
defaults, Enterprise-baseline-not-automatically-unlimited, dormant keys
absent from every plan, trial profile distinctness and AI cap), and
`FeatureGateTest` (20 — no subscription, active/past_due/trialing/draft/
pending_payment/incomplete/expired/cancelled/suspended resolution,
dormant-key immunity to overrides, unknown feature key, unrecognised plan
code, `requireFeature()`/`explain()`, override-seam precedence, provider
independence via constructor reflection and import-statement inspection).
Only `FeatureGateTest` touches the database (`Organization`/`Subscription`
models are unavoidable for resolving a real subscription's status) — every
other new test file is pure, fast, DB-free unit testing, per the
checkpoint's own "no database dependency unless justified" instruction.

Full Billing filter (Feature+Unit): 319+69 (up from 317+69 — the +2 is
filter overlap from two new test method names containing the substring
"billing_provider", not new Billing-domain tests). Pricing: 39/39,
unaffected. Full backend suite: 1273 tests, 1248 passed, 1 failure + 21
errors — the identical pre-existing, unrelated failures from every prior
baseline this session — no new regressions, and (critically for this
checkpoint) no test anywhere in the existing suite changed behaviour,
confirming no user-facing behaviour changed as required.

## Documentation updated (checkpoint 12)

`internal-docs/super-admin/subscription-billing.md` (this section;
status line), `CLAUDE.md` (new entitlements section in the services
table), `project-context.md` (new checkpoint 12 log entry). No public
documentation, no release notes, no version bump.

---

# Subscription Lifecycle and Entitlement Access Policy Review (checkpoint 13)

Defines the authoritative answer to "given this organisation's current
subscription state, what commercial entitlement profile should
`FeatureGate` resolve" — the policy `FeatureGate` needed before it could
safely be integrated anywhere (still not integrated anywhere — this
checkpoint remains architecture only). Primarily a commercial-policy
review; a small, justified amount of non-enforcing code was added (Part
17) because this review surfaced a real safety gap in the previous
checkpoint's shipped code, not because new enforcement was wanted.

## Repository findings

No scattered status-checking logic exists anywhere outside
`App\Support\Billing`/`App\Support\Entitlements`/`App\Services\Entitlements`
— confirmed by a repository-wide search; `Organization::liveSubscription()`
remains the only status-aware query outside those namespaces, unchanged.
Two genuine automation gaps were found, both pre-existing (not introduced
by this checkpoint, and not fixed — fixing them would be enforcement/
scheduling work, out of scope):

- **`SubscriptionLifecycleService::startGracePeriod()` is never called by
  anything.** It exists, is fully implemented, and sets
  `grace_period_ends_at` — but no webhook handler, command, or scheduled
  job invokes it. A `past_due` subscription today has `grace_period_ends_at
  = null` forever unless a Super Admin calls it manually. `SubscriptionAccessPolicy`
  is designed to behave safely regardless (see "Grace-period architecture"
  below) rather than assuming it will ever be set.
- **Scheduled cancellation/plan-change/suspension have no automatic
  "apply at the effective moment" trigger.** `cancel_at_period_end`,
  `pending_pricing_plan_id`/`plan_change_effective_at`, and
  `metadata_json['planned_suspension_reason']` all sit dormant on an
  otherwise-unchanged subscription until an explicit, separate lifecycle
  method (`confirmCancellation()`, an unbuilt "apply scheduled plan
  change" step, `suspend()`) is called — nothing currently calls any of
  them automatically when their effective date arrives. This turns out to
  be exactly why `FeatureGate` never needs its own effective-date logic
  (see "Scheduled actions" below) — but it also means these scheduled
  actions currently never take effect on their own in production; a
  future scheduled command would be needed to actually apply them at the
  right moment, which is explicitly NOT built here.

## Existing lifecycle statuses (confirmed, not renamed)

The eleven statuses already defined in `SubscriptionStatus` are used
unchanged: `draft`, `pending_payment`, `incomplete`, `trialing`, `active`,
`past_due`, `unpaid`, `paused`, `suspended`, `cancelled`, `expired`. No
inconsistency was found that would justify renaming or inventing a
status — this checkpoint is a resolution-policy layer on top of the
existing set, not a change to it.

## Access modes (Part 3)

Five modes — deliberately not eleven (one per status):

| Mode | Meaning |
|---|---|
| `NONE` | No commercial relationship currently active — no subscription, or a pre-activation status |
| `TRIAL` | The dedicated trial entitlement profile |
| `FULL` | The subscription's full plan entitlement set |
| `GRACE` | A temporary payment-collection problem — resolves identically to `FULL` today, kept distinct for future messaging/policy hooks |
| `RESTRICTED` | No paid entitlements resolve — existing-record access (Section 15) is unaffected regardless, since it doesn't run through this mode at all |

## Full lifecycle-to-access matrix (Part 4)

| Status | Mode | Commercial meaning | Reachable how | Temporary/terminal | Payment/commercial/operational |
|---|---|---|---|---|---|
| `draft` | `NONE` | Internal commercial record only — no relationship has started | `createDraftSubscription()` | Temporary (moves on) | Commercial (pre-relationship) |
| `pending_payment` | `NONE` | Checkout in progress, not yet verified | `markPendingPayment()` | Temporary | Commercial |
| `incomplete` | `NONE` | First payment attempt unresolved (e.g. 3-D Secure pending) | `markIncomplete()` via webhook | Temporary | Payment |
| `trialing` | `TRIAL` | Sales-assisted trial, independent of any selected paid plan | `startTrial()` | Temporary | Commercial |
| `active` | `FULL` | Normal, fully-entitled relationship | `activate()`/`restoreToActive()` | Ongoing | Commercial |
| `past_due` | `GRACE` (or `RESTRICTED` if grace has technically elapsed — see below) | Payment collection problem, not yet resolved | `markPastDue()` via webhook | Temporary | Payment |
| `unpaid` | `RESTRICTED` | Worse collection state than `past_due` | `markUnpaid()` via webhook | Temporary (moves to `suspended` or recovers) | Payment |
| `paused` | `NONE` (fail-safe; see below) | Outside normal resolution — approved policy unchanged | Should not occur — no lifecycle method sets it | N/A | N/A |
| `suspended` | `RESTRICTED` | A deliberate business decision, separate from raw payment failure | `suspend()` (always requires an operator reason) | Ongoing until restored | Operational |
| `cancelled` | `RESTRICTED` | The relationship has ended — its effective date has already passed by construction | `cancelImmediately()`/`confirmCancellation()` | Terminal (can `expire()`) | Commercial |
| `expired` | `RESTRICTED` | Post-cancellation archival state | `expire()` | Terminal | Commercial/operational |

### `paused` (Part 4)

**Confirmed unchanged**: `paused` continues to be routed to `conflict` by
`WebhookEventProcessor` (checkpoint 8's decision) — it never reaches
`SubscriptionAccessPolicy` in practice, since no lifecycle method ever
sets a subscription's status to `paused`. `SubscriptionAccessPolicy` still
defines a defensive fallback (`NONE`) for the case where it somehow did,
purely as a fail-safe, not as a policy change. No approved commercial
decision has changed this.

## Grace-period architecture (Part 5)

`grace_period_ends_at` exists, `startGracePeriod()` exists, but (per the
repository finding above) nothing calls it automatically. **Recommended,
now-implemented rule**: `past_due` resolves `GRACE` (full entitlement
values) UNLESS `grace_period_ends_at` is set AND has already passed, in
which case it resolves `RESTRICTED`. This is a genuinely useful safety
net given the finding above — without it, a `past_due` subscription could
sit indefinitely past its own recorded grace deadline and still resolve
full access forever, with nothing else in the codebase ever moving it
onward. A `past_due` subscription with NO recorded deadline (the common
case today, since nothing sets one) continues to resolve `GRACE`
unconditionally — never fails closed just because a deadline was never
set. What automatically calls `startGracePeriod()` (and what actually
transitions `past_due` onward once grace truly expires — `markUnpaid()`
or `suspend()`) remains unbuilt and is recommended as a distinct future
scheduled-command checkpoint, not decided further here.

## Scheduled actions and effective dates (Part 6) — the central finding

**`FeatureGate`/`SubscriptionAccessPolicy` never need to inspect
`cancel_at_period_end`, `plan_change_effective_at`, or
`metadata_json['planned_suspension_reason']` directly, and deliberately
don't.** Every scheduled action in this codebase is represented as a
field on an otherwise-UNCHANGED status, and takes effect only once a
separate, explicit lifecycle method later flips that status:

- Scheduled cancellation: `cancel_at_period_end = true` on an otherwise-
  `active` subscription. Status stays `active` (→ `FULL`) until
  `confirmCancellation()` is actually called — which, by construction, is
  only ever invoked at or after the effective date.
- Scheduled plan change: `pending_pricing_plan_id`/`plan_change_effective_at`
  sit on an otherwise-unchanged `active` subscription. Nothing currently
  applies them at all (checkpoint 4's own "preparation only" scope) — so
  this cannot prematurely affect access either, by the same reasoning.
- Scheduled suspension: `metadata_json['planned_suspension_reason']` on
  an otherwise-unchanged status. Status doesn't change until `suspend()`
  is explicitly called.

**The consequence**: "current status alone (plus `plan_code_snapshot`)
is sufficient for correct, effective-date-respecting access resolution
today" — provided whatever future automation eventually applies these
scheduled actions does so exactly at the effective moment (a requirement
on that FUTURE automation, which doesn't exist yet per the repository
finding above, not a requirement on `FeatureGate`). This is why the
"Effective-date evaluation" step in Part 12's resolution-flow diagram
collapses into "trust the current status" for now, documented explicitly
as conditional on that future automation actually existing and being
correct.

## Cancellation versus expiry semantics (Part 7)

`cancel_at_period_end` (a scheduling flag) → `confirmCancellation()`/
`cancelImmediately()` (`status = cancelled`, `cancelled_at`/`ended_at`
set) → `expire()` (`status = expired`, an explicit later archival step).
Commercial access stops (`RESTRICTED`) the moment status becomes
`cancelled` — never before, per the reasoning above. Reactivating a
`cancelled` or `expired` subscription is not currently supported by
`SubscriptionTransitions::MAP` (no incoming transitions to either from
outside the map's existing `cancelled → expired` and `suspended →
cancelled` paths) — a new subscription would need to be created via the
normal Checkout flow; this checkpoint does not change that. Historical
entitlement snapshots (once they exist — see Part 11) remain immutable
regardless of later cancellation/expiry, per Entitlement Specification
v1 §20's grandfathering guarantee.

## Read-only policy review (Part 8) — recommendation: yes, as a future checkpoint

A genuine future need, not implemented here. Entitlement Specification
v1 §15's absolute guarantee (viewing/downloading existing records,
audit/activity history) already applies regardless of access mode and
does NOT run through `FeatureGate`/`SubscriptionAccessPolicy` at all —
this is intentional and unchanged. What a dedicated future "read-only
mode" checkpoint would need to design, which this one does not: which
specific actions are blocked for a `RESTRICTED` organisation (creating
new projects? new AI analyses? editing existing records? — construction
contract administration has ongoing legal/audit obligations that make
"read-only" a genuinely nuanced question, not a blanket switch), how
public collaboration links and client-facing views behave, and how
notifications should be scoped. Recommended as its own future checkpoint
specifically because it is a genuine design question, not a mechanical
follow-on from this one.

## Trial policy (Part 9)

Triggered by `status = trialing` only (`SubscriptionLifecycleService::startTrial()`).
Resolves `PlanEntitlements::trialProfile()` — independent of any
selected/eventual paid plan, per checkpoint 12's design (unchanged here).
`trial_ends_at` exists on `Subscription` but nothing currently acts on it
automatically (same class of gap as grace-period expiry) — trial
expiry-handling (moving to `pending_payment`, `active` via direct sales-
assisted activation, or `expired`) remains a future scheduled-command
concern, not decided further here. No Stripe-native trial logic exists
or was added — SureSign trials remain local and sales-assisted, matching
Commercial Strategy §8, unchanged.

## Override resolution policy (Part 10) — real safety fix applied

**Finding**: the previous checkpoint's `FeatureGate` consulted
`EntitlementOverrideRepository` BEFORE the status check — meaning, once
real override persistence exists in a future checkpoint, a manual/
negotiated override could have silently granted access to a `suspended`,
`cancelled`, or `draft` organisation. Nothing exercises this today
(`NullEntitlementOverrideRepository` always returns null), so no live
behaviour was ever affected — but the ORDERING itself was unsafe and is
corrected in this checkpoint.

**Fix**: `FeatureGate` now resolves the `SubscriptionAccessDecision`
(via `SubscriptionAccessPolicy`) FIRST, and only consults an override at
all when `SubscriptionAccessMode::allowsOverrides($mode)` is true — true
for `FULL`/`GRACE`/`TRIAL`, false for `NONE`/`RESTRICTED`. An override
can only ever adjust WHAT an already-existing commercial relationship
includes; it can never resurrect one the access mode says doesn't
currently exist. Proven by two new tests
(`test_override_cannot_bypass_a_suspended_subscription`,
`test_override_cannot_bypass_a_draft_subscription`) using a stub override
that would otherwise grant `advanced_reporting`/`custom_branding`.

**Override categories, recommended for the future repository
implementation** (not built): `commercial` (Enterprise negotiated terms),
`operational`/`support` (a Super Admin manual grant, e.g. "enable AI
Programme for Organisation X for 30 days"), and a deliberately SEPARATE
`emergency` category for the one case where bypassing a `RESTRICTED`/
`NONE` mode might ever be justified (e.g. a payment dispute needing
temporary read access) — never a default capability of an ordinary
override, always its own explicit, distinctly-audited mechanism should
it ever be built. The future repository's data model should carry:
grant/deny/custom-limit, start/end date, reason, actor, audit history,
and an explicit "may this override a dormant/suspended state" flag
defaulting to false.

## Entitlement snapshot timing (Part 11) — recommendation, still not built

**Recommended authoritative moment: subscription ACTIVATION**
(`SubscriptionLifecycleService::activate()`), not draft creation or
Checkout Session creation. Reasoning: "a draft subscription may never be
paid" (the checkpoint brief's own framing) — snapshotting at draft
creation would produce unused commercial records for every abandoned
checkout attempt, and `createDraftSubscription()`/`markPendingPayment()`
already snapshot the commercial TERMS (`plan_code_snapshot`, amounts) at
draft time regardless (Section 7's existing behaviour, unchanged) — only
the ENTITLEMENT snapshot specifically should wait for confirmed
activation, since that's the first point a real, paid commercial
relationship is confirmed to exist. **Trial**: snapshot at
`startTrial()`, using the trial profile, not a plan default — trials can
begin from `draft` without ever reaching `pending_payment`.

**Future change events, recommended handling** (none built): upgrade/
downgrade → a new snapshot row set at the change's effective time,
`source = migration`, prior rows superseded (`effective_until`) not
deleted; renewal → no new snapshot needed unless entitlements
themselves changed (a renewal alone doesn't change what's entitled);
Enterprise amendment → `subscription_overrides`, never a fresh plan-
default snapshot (Part 19 of the specification, unchanged); manual grant
→ an override row, not a new snapshot; trial conversion → a new snapshot
at the point `activate()` actually succeeds, replacing the trial profile
row.

**Still not built this checkpoint** — no `subscription_entitlements`/
`subscription_overrides` migration was added. `FeatureGate` continues
resolving live from `PlanEntitlements`/the trial profile via
`plan_code_snapshot`, with the same known, documented limitation
checkpoint 12 already recorded (a future edit to `PlanEntitlements`'s
numbers would retroactively affect existing subscriptions until the
snapshot table exists).

## Effective entitlement resolution order (Part 12)

```text
Organisation
    ↓
Subscription (Organization::liveSubscription() or latest by any status)
    ↓
SubscriptionAccessPolicy::resolve() → SubscriptionAccessDecision (mode + reason)
    ↓
[mode grants overrides?] → EntitlementOverrideRepository::findActiveOverride()
    ↓ (no override, or mode doesn't allow one)
mode's own profile: trial profile (TRIAL) / plan defaults (FULL, GRACE) / not entitled (NONE, RESTRICTED)
    ↓
EntitlementValue / EntitlementDecision
```

Fails safe throughout: an unknown feature key throws before anything is
resolved; an unrecognised subscription status resolves `NONE` (never
guesses a mode); an unrecognised plan code resolves "not entitled" (never
guesses a plan's entitlements); a dormant key always resolves
`not_applicable` regardless of every other step, checked first.

## Explainability (Part 13)

`EntitlementDecision` gained one new field this checkpoint: `accessMode`
(alongside the existing `value`/`subscriptionStatus`/`reason`) —
`FeatureGate::explain()` now reports which of the five modes applied,
not just the raw subscription status, and `SubscriptionAccessPolicy`'s
own `reason` strings are written to already read naturally as the
worked examples in the brief (e.g. "Subscription is past due; grace
continues until 2026-08-12T00:00:00+00:00."). `toArray()`'s shape is
internal-only (Section 22) — never returned to a customer verbatim.

## Provider independence (Part 14)

`SubscriptionAccessPolicy` reads only `Subscription::$status` (already a
`SubscriptionStatus` value, never a raw Stripe string) and
`$grace_period_ends_at`. Confirmed by a new test
(`SubscriptionAccessPolicyTest::test_policy_never_imports_a_stripe_or_billing_provider_class()`)
inspecting its actual `use` imports, matching the pattern
`FeatureGateTest` already established in checkpoint 12.

## `starter` seeder investigation (Part 15) — left unchanged

Investigated fully: `code => 'starter'` appears in `PricingSeeder.php`
(unused/unwired, confirmed again) AND in three Pricing test files
(`PricingCacheTest`, `PricingPlanLifecycleTest`, `PricingValidationTest`)
AND in a migration comment's illustrative example
(`2026_07_25_000002_create_pricing_plans_table.php`). This is NOT one
stale reference to a mis-named real plan — it is an arbitrary,
consistently-reused generic test/example plan CODE across the Pricing
test suite, functionally equivalent to using "foo" or "acme" as a
placeholder; none of these tests assert anything about which three plans
are commercially real. **Outcome: left unchanged.** Correcting it would
require touching a seeder plus three test files for zero behavioural
benefit, and risks exactly the "broaden into a pricing-data rewrite" this
checkpoint's own instruction warns against — this is a generic test
fixture value, not evidence of a real commercial-naming bug anywhere
customer-facing.

## Minimal code introduced (Part 17)

Justified by a real safety gap found during this review (Part 10), not
by a desire to add enforcement:

- `App\Support\Entitlements\SubscriptionAccessMode` — the five-mode
  vocabulary (matches existing class-constant convention).
- `App\Support\Entitlements\SubscriptionAccessDecision` — the resolved
  mode + reason value object.
- `App\Services\Entitlements\SubscriptionAccessPolicy` — the single
  authoritative lifecycle-status-to-access-mode service (Part 16's
  recommended separate domain policy, sitting between
  `SubscriptionLifecycleService` and `FeatureGate` — neither of those two
  services was touched).
- `App\Services\Entitlements\FeatureGate` — refactored (not functionally
  expanded) to delegate status logic to the new policy service instead of
  its own inline `statusGrantsEntitlements()`, and to fix the override-
  precedence gap.
- `App\Support\Entitlements\EntitlementDecision` — gained the `accessMode`
  field.

No controller, middleware, frontend, or Stripe-facing code was touched.
No status mutation logic was added — `SubscriptionAccessPolicy` only
ever READS `$status`/`$grace_period_ends_at`, never writes either.

## Direct-mutation audit

`SubscriptionAccessPolicy` and the `SubscriptionAccessMode`/
`SubscriptionAccessDecision` value objects perform no writes at all —
confirmed by inspection (no `->save()`, no property assignment on a
persisted model anywhere in these three files). `FeatureGate`'s
refactor is a pure internal reordering; its public API is unchanged
except for the new `accessMode` field appearing in `EntitlementDecision`.

## Testing (checkpoint 13)

20 new tests: `SubscriptionAccessPolicyTest` (18 — every one of the
eleven statuses including `paused`'s fail-safe and an unrecognised
status, the grace-period boundary using `Date::setTestNow()` for
deterministic dates, provider independence) and 2 new tests added to
`FeatureGateTest` (the override-precedence safety fix, against
`suspended` and `draft`). All entitlement tests remain database-free
except `FeatureGateTest` itself (needs a real `Subscription` row to
resolve `liveSubscription()`); `SubscriptionAccessPolicyTest` constructs
`Subscription` instances in memory only, never persisted. Full
Entitlements filter: 68/68. Full Billing filter (Feature+Unit): 319+70
(the Unit count rose by one purely from the reflection-based provider-
independence test's method name overlapping the "Billing" filter
substring, same class of overlap as checkpoint 12). Pricing: 39/39,
unaffected. Full backend suite: 1293 tests, 1268 passed, 1 failure + 21
errors — the identical pre-existing, unrelated failures from every prior
baseline this session — no new regressions, and no existing test's
behaviour changed (confirming no user-facing behaviour changed, as this
checkpoint's brief required).

## Documentation updated (checkpoint 13)

`internal-docs/super-admin/subscription-billing.md` (this section;
status line), `CLAUDE.md` (updated entitlements table entries reflecting
the new policy service and the override-precedence fix),
`project-context.md` (new checkpoint 13 log entry). No public
documentation, no release notes, no version bump.

---

# Subscription Commercial State Automation & Immutable Entitlement Snapshot Foundation (checkpoint 14)

Turns checkpoint 13's architecture-only lifecycle/access/entitlement layer
into a self-operating one for the paths that are genuinely safe to
automate today, and lays the immutable entitlement-snapshot foundation
checkpoint 12's `PlanEntitlements` docblock always said a future checkpoint
would need. No customer-facing behaviour changed — nothing before this
checkpoint calls any Billing/Entitlements code from a controller, and
nothing does after it either.

## Part 1 — Repository findings (confirming checkpoint 13's two gaps)

Both automation gaps checkpoint 13 documented were re-confirmed present,
unchanged, before any code was written:

1. `SubscriptionLifecycleService::startGracePeriod()` was still never
   called by anything — fully implemented, but no caller existed.
2. Scheduled cancellation/plan-change/suspension still had no automatic
   "apply at the effective moment" trigger — `cancel_at_period_end`,
   `pending_pricing_plan_id`/`plan_change_effective_at`, and
   `metadata_json['planned_suspension_reason']` all still sat dormant
   until an explicit, separate method was called.

A third, previously undocumented finding surfaced during this checkpoint's
own repository review: `scheduleSuspension()` records operator INTENT
(`metadata_json['planned_suspension_reason']`) but was never designed with
an effective-date field at all — there is no `suspension_effective_at`
column to evaluate "is this due" against, unlike cancellation and plan
changes. See "Scheduled suspension — deliberately not automated" below.

## Part 2 — Automation architecture

One dedicated orchestration service, `App\Services\Billing\
SubscriptionAutomationService`, sitting alongside (never inside)
`SubscriptionLifecycleService`:

- Discovers due subscriptions per category with a plain, deterministic,
  indexed query (`status` + the relevant date/flag column).
- Never writes `subscriptions.status` or any other commercial field
  itself — every transition is a call to an existing, already-reviewed
  named method on `SubscriptionLifecycleService`
  (`startGracePeriod()`/`markUnpaid()`/`expire()`/`confirmCancellation()`).
  No new lifecycle method was needed for any automated path.
- Wraps every per-subscription attempt so one failure never aborts the
  batch — conflicts/invalid transitions are caught and reported as
  `conflicted`, unexpected exceptions as `terminal_failure`, and the loop
  continues to the next row.
- Returns a flat list of `App\Support\Billing\AutomationActionResult`
  (Part 13's explainability requirement — category, subscription id,
  outcome, previous/new status, effective date, snapshot outcome, human
  reason) plus tallied counters and the blocked-path observability counts.

## Part 3 — Scheduler discovery / what is automated

| Category | Discovery query | Destination |
|---|---|---|
| Grace period start | `status = past_due AND grace_period_ends_at IS NULL` | `startGracePeriod()` |
| Grace period expiry | `status = past_due AND grace_period_ends_at <= now()` | `markUnpaid()` |
| Trial expiry | `status = trialing AND trial_ends_at <= now()` | `expire()` |
| Scheduled cancellation | `status = active AND cancel_at_period_end = true AND current_period_ends_at <= now()` | `confirmCancellation()` |

Each query is deterministic (ordered by `id`), resumable (a `--limit`
per category, same shape as `billing:webhooks:recover`), and tenant-safe
(no organisation-specific branching — the same query runs across every
organisation in one pass, exactly like every other scheduled command in
this codebase).

## Part 4 — Grace period automation

**Start**: anchored on `last_transition_occurred_at` (the moment the
subscription actually became `past_due`), not "now" — `grace_period_ends_at
= last_transition_occurred_at + config('billing.grace_period_days')` (7
days by default). Anchoring on the authoritative past-due timestamp rather
than the discovery tick means re-running this on a later tick always
computes the SAME deadline, never pushes it forward. `startGracePeriod()`
itself already no-ops when called twice with the same `graceEndsAt` — no
duplicate `ActivityLog` entries.

**Expiry**: `past_due` subscriptions whose recorded `grace_period_ends_at`
has passed are marked `unpaid`. `unpaid` was chosen over `suspend()`
deliberately — `suspend()` requires an operator-supplied reason and is
documented elsewhere as "a deliberate business decision, separate from raw
payment failure," not something automation should invent a reason for.
`unpaid` is the strictly worse, reason-free collection state
`SubscriptionTransitions::MAP` already allows from `past_due` — no
ambiguity, no new transition needed.

No commercial reason to change `SubscriptionAccessPolicy`'s existing
grace-expiry safety net (`past_due` past its own `grace_period_ends_at`
already resolved `RESTRICTED` before this checkpoint) — this checkpoint
makes the underlying status transition actually happen automatically
rather than leaving the access-policy safety net as the only thing
standing between an expired grace window and indefinite full access.

## Part 5 — Trial automation

Only trial EXPIRY is automated: `trialing` subscriptions whose
`trial_ends_at` has passed, never converted to `pending_payment`, are
moved to `expired` via `expire()` — an explicitly valid `trialing ->
expired` transition per `SubscriptionTransitions::MAP`, never inferred.
Both manual sales-assisted trials (`startTrial()` called directly by a
Super Admin) and any future self-serve trial path are covered identically
— this checkpoint reads only `status`/`trial_ends_at`, with no knowledge of
how the trial began. No Stripe-native trial concept is introduced or
assumed anywhere in this path. "Trial nearing expiry" reminders were not
built — no notification/reminder infrastructure for Billing exists yet,
and inventing one was out of this checkpoint's scope (see "Explicit
exclusions" in the checkpoint brief: no module integration, no
notifications).

## Part 6 — Scheduled cancellation (automated) / suspension & plan changes (deliberately NOT automated)

**Scheduled cancellation** is fully automated: `active` subscriptions with
`cancel_at_period_end = true` whose `current_period_ends_at` has passed
call `confirmCancellation()` — an existing, already-idempotent method
requiring no changes.

**Scheduled suspension — deliberately not automated.** `scheduleSuspension()`
records intent only; there is no effective-date field to evaluate "due"
against (see Part 1's third finding). Auto-calling `suspend()` the instant
`planned_suspension_reason` is set would make "scheduling" a no-op and
would suspend an organisation without the deliberate operator timing the
method's own docblock describes — this is a genuine architecture blocker
matching this checkpoint's own stop condition ("a scheduled field is
ambiguous or cannot determine an authoritative effective date"). This
checkpoint only reports `countPendingSuspensions()` (observability) and
never calls `suspend()`. Closing this gap properly would require adding an
actual effective-date column to the suspension-scheduling flow — a
deliberate schema/behaviour decision for a future checkpoint, not
something to improvise here.

**Scheduled upgrade/downgrade — deliberately not automated.**
`SubscriptionLifecycleService::preparePlanChange()`'s own docblock is
explicit that actually applying a pending plan change requires "resolving/
creating the new provider Price relationship on the live Stripe
subscription" — a real outbound Stripe write. This checkpoint runs
explicitly "before Stripe Test Mode" and must not introduce a new Stripe
API interaction. This is the other genuine architecture blocker matching
this checkpoint's stop conditions ("the existing lifecycle service lacks a
safe named transition required by the automation" — none exists that
avoids a provider call). This checkpoint only reports
`countPendingPlanChanges(due: true|false)` (observability, split by
whether the effective date has already passed) and never mutates
`pricing_plan_id`/`pending_pricing_plan_id`. `EntitlementSnapshotService::
snapshotForUpgrade()`/`snapshotForDowngrade()` exist as ready call shapes
for whichever future Checkout/webhook-integrated checkpoint actually
applies these changes, but neither is called anywhere yet.

## Part 7/8 — Immutable entitlement snapshot foundation

New table `billing_entitlement_snapshots` (migration
`2026_07_23_143613_create_billing_entitlement_snapshots_table.php`),
model `App\Models\SubscriptionEntitlementSnapshot` — enforces immutability
at the model level (`static::updating()` throws `LogicException`; a new
commercial event always produces a NEW row). Columns: `subscription_id`,
`organization_id`, `pricing_plan_id` (nullable), `plan_code_snapshot`,
`entitlements_json` (the full resolved `Feature::*`-keyed set, each entry
`{value_type, value, is_unlimited, unit, source}`), `effective_from`,
`lifecycle_reason` (`activation`/`trial_start`/`upgrade_applied`/
`downgrade_applied`/`enterprise_amendment`), `source_transition` (the exact
lifecycle transition that produced it, e.g. `subscription.activated`).

**Uniqueness/idempotency boundary**: `(subscription_id, source_transition,
effective_from)`, unique-indexed. Deliberately excludes `plan_code_snapshot`
— two distinct commercial events for the same subscription always differ
in `source_transition` or `effective_from` (a renewal/upgrade/downgrade
always has a new effective instant), so this key can never block a
legitimate future snapshot while still rejecting an exact duplicate of one
already recorded. `App\Services\Entitlements\EntitlementSnapshotService`
is the sole writer — every public method (`snapshotForActivation()`/
`snapshotForTrialStart()`/`snapshotForUpgrade()`/`snapshotForDowngrade()`/
`snapshotForEnterpriseAmendment()`) reads-then-creates under a row lock,
reusing an existing matching row rather than duplicating it.

**Timing — integrated at the authoritative lifecycle boundary, not only
the scheduler**: `SubscriptionLifecycleService::activate()` and
`::startTrial()` both call the snapshot service directly, AFTER their own
transition has committed, using the subscription's own `activated_at` (for
activation) or the transition's `occurredAt` (for trial start) as
`effective_from` — never the automation-tick time. This is required
because activation can be reached from a verified webhook, a sales-assisted
path, or (in the future) Checkout completion — not only from a scheduler —
so snapshot creation must not depend on `SubscriptionAutomationService`
running at all. Only these two transitions create a snapshot this
checkpoint; `snapshotForUpgrade()`/`snapshotForDowngrade()`/
`snapshotForEnterpriseAmendment()` exist but are not called anywhere
(see Part 6 — upgrade/downgrade application, and any Enterprise-amendment
workflow, remain unbuilt).

**Never created for**: `draft`, checkout creation, `incomplete` — matching
the brief's explicit exclusion list. Only `activate()`/`startTrial()`
create one.

## Part 9 — FeatureGate snapshot resolution (plumbing only)

Resolution order updated: dormant-key check → access mode
(`SubscriptionAccessPolicy`) → **current effective snapshot** (new) →
override (unchanged position/precedence) → mode's own profile. Concretely,
`FeatureGate::resolveFromSnapshotOrLive()` is consulted for `TRIAL`/`FULL`/
`GRACE` modes:

- **No snapshot exists** → falls back to live `PlanEntitlements`/
  `trialProfile()`, exactly as every version of `FeatureGate` has behaved
  before this checkpoint. This is the explicit, documented COMPATIBILITY
  path for a subscription activated/trial-started before this checkpoint
  shipped — nothing backfills historical snapshots. Since `activate()`/
  `startTrial()` now always create one going forward, this path should
  only ever be reached for pre-checkpoint subscriptions in practice.
- **A snapshot exists but is missing the requested key** → NEVER falls
  back to live `PlanEntitlements`. Resolves "not entitled" and logs a
  warning instead — every `PlanEntitlements`/`trialProfile()` array covers
  all eight non-dormant keys, so a snapshot missing one is a genuine data
  inconsistency, and Part 10's explicit instruction is that this must fail
  safe, never silently grant broader access than what was actually
  recorded.
- `Subscription::currentEntitlementSnapshot()` picks the most recent row
  with `effective_from <= now()` — a snapshot whose effective date is still
  in the future (a prepared-but-not-yet-effective upgrade, once a future
  checkpoint creates one) is correctly ignored in favour of whatever
  resolves without it.

No module, controller, or middleware was touched — `FeatureGate`'s public
API is unchanged; only its internal resolution source for the plan/trial
profile step changed.

## Part 10 — Recovery / concurrency / idempotency

No new recovery COMMAND exists — automation reuses the pattern
`billing:webhooks:recover` established rather than inventing a second one.
Discovery queries take no lock; the actual correctness boundary is
`SubscriptionLifecycleService::transition()`'s own `lockForUpdate()` plus
its same-status no-op short circuit (already existing, unmodified). Two
overlapping runs (or a retried job) can safely discover and attempt the
same row twice: the second attempt finds the subscription already at its
target status and returns a safe no-op — never a duplicate transition,
duplicate `ActivityLog` entry, or duplicate snapshot (snapshot idempotency
is `EntitlementSnapshotService`'s own unique-index boundary, checked under
a row lock). `withoutOverlapping()` (no `onOneServer()`, matching every
other scheduled command in this codebase) is the scheduler-level
safeguard, not the only one.

## Part 11 — Command / scheduler registration

One command, `billing:subscriptions:process-automation` (`--limit`,
`--dry-run`), registered hourly in `routes/console.php` with
`withoutOverlapping()` + `runInBackground()`. Deliberately ONE command for
all four automated categories (per the checkpoint brief: "avoid
unnecessary scheduler fragmentation") — every category shares the same
recovery/idempotency/observability shape, and
`SubscriptionAutomationService::processDue()` already runs them in a fixed
order per invocation. Hourly matches every other lifecycle-adjacent
scheduled command — every automated category here is date/day-grained
(`grace_period_ends_at`, `trial_ends_at`, `current_period_ends_at`), never
a sub-hour precision requirement.

**Single-server today; what multi-server would need**: exactly the same
position as `billing:webhooks:recover` — `SubscriptionLifecycleService`'s
own row locking (not the scheduler) is what actually prevents a duplicate
transition, so `onOneServer()` is not a correctness requirement today. A
future multi-instance deployment would still benefit from adding it purely
to reduce redundant discovery-query load across instances, but that is an
efficiency decision, not a safety one — not introduced here per the
brief's explicit "do not introduce Redis-distributed scheduler assumptions
unless the existing deployment and cache configuration support them."

## Part 12/13 — Observability & explainability

`AutomationActionResult` (one per subscription touched) and aggregate
counters (`discovered`/`transitioned`/`skipped_not_due` (implicit — simply
not discovered)/`skipped_already_applied`/`conflicted`/`terminal_failure`)
are logged via `Log::info`/`Log::warning`/`Log::error` and printed by the
command. Blocked-path counts (`pending_suspensions`,
`pending_plan_changes_due`, `pending_plan_changes_future`) are reported
every run alongside the executed categories, so an operator sees both what
automation did and what still needs a manual decision, in one place.

## Testing (checkpoint 14)

New test files, all frozen-time/no-Stripe-dependency:

- `SubscriptionAutomationServiceTest` (grace start/expiry, trial expiry,
  scheduled cancellation, idempotent repeat runs, blocked-path
  observability, aggregate `processDue()` tally) — 19 tests.
- `EntitlementSnapshotServiceTest` (creation, trial-vs-plan payload,
  idempotent reuse, distinct-effective-date creates a new row, immutability
  enforcement) — 6 tests.
- `SubscriptionLifecycleSnapshotIntegrationTest` (activation and trial
  start create a snapshot at the lifecycle boundary itself; duplicate
  activation does not duplicate the snapshot) — 3 tests.
- `FeatureGateSnapshotResolutionTest` (snapshot-first resolution, live
  fallback when no snapshot, fail-safe on an inconsistent snapshot, a
  not-yet-effective snapshot is ignored, most-recent-effective-snapshot
  wins) — 5 tests.
- `SubscriptionAutomationSchedulerTest` (registration, hourly cadence,
  overlap protection, background execution, single-server assumption,
  mirroring `BillingWebhookRecoverySchedulerTest`'s approach) — 5 tests.
- `ProcessSubscriptionAutomationCommandTest` (dry-run makes no changes,
  a real run executes due transitions, a second run is a safe no-op) — 3
  tests.

41 new tests, all passing. Existing suites unaffected: full Billing +
Entitlements + Pricing + Webhook filter — 412/412 (up from the pre-existing
319+70+39+webhook baseline, plus this checkpoint's new Billing/Entitlements
tests). Full backend suite: 1329 tests, 1304 passed, 1 failure + 21 errors
— the identical pre-existing, unrelated failures (storage-directory
permission errors under `storage/framework/testing/disks/...` and
`storage/app/private/...`, plus one pre-existing `payment_applications`
factory/DB issue) present in every prior checkpoint's baseline in this
session — no new regressions, and no existing test's behaviour or
assertion changed.

## Direct-mutation audit

`SubscriptionAutomationService` performs no `subscriptions.status`
mutation of any kind — confirmed by inspection (every mutation happens
inside `SubscriptionLifecycleService`'s existing methods, called by name).
`EntitlementSnapshotService` only ever `create()`s a
`SubscriptionEntitlementSnapshot` row; it never updates one (enforced at
the model level, not just by convention). `FeatureGate`'s changes are
read-only additions to its resolution path. No controller, middleware,
route, or frontend file was touched.

## Documentation updated (checkpoint 14)

`internal-docs/super-admin/subscription-billing.md` (this section; status
line), `CLAUDE.md` (new service-table rows for
`SubscriptionAutomationService`/`EntitlementSnapshotService`, and updated
entries for `SubscriptionLifecycleService`/`FeatureGate` reflecting their
new collaborators), `project-context.md` (new checkpoint 14 log entry). No
public documentation, Help Centre, User Guide, onboarding, or release
notes were touched — nothing in this checkpoint is customer-facing or
reachable by a real customer.

## Remaining operational risks / recommended next checkpoint

- Scheduled suspension has no effective-date field at all — a future
  checkpoint must decide how "scheduled suspension" should actually be
  timed (a new column? an operator-supplied date at scheduling time?)
  before it can be automated safely.
- Scheduled upgrade/downgrade application remains entirely unbuilt — it is
  the natural next checkpoint once Stripe Test Mode exists, since it
  genuinely requires a provider call this checkpoint correctly refused to
  add.
- `PlanEntitlements`'s "retroactive edit" gap (documented since checkpoint
  12) is now PARTIALLY closed for subscriptions activated/trial-started
  after this checkpoint (they resolve from a frozen snapshot), but still
  open for every subscription that predates it (legacy fallback still
  reads live `PlanEntitlements`). Fully closing it would require a
  one-time backfill command creating a snapshot for every currently-active
  legacy subscription — deliberately not done here (no backfill/migration-
  of-data was in scope, and guessing an `effective_from`/`source_transition`
  for a historical activation risks fabricating audit history).
- Recommended next checkpoint: **Scheduled Suspension Effective-Date
  Design & Stripe Test Mode Preparation** — resolve the suspension-timing
  gap above, then move toward the Stripe Test Mode checkpoint this whole
  automation layer was built ahead of.

---

# Subscription Suspension Completion, Snapshot Integrity & Commercial Automation Hardening (checkpoint 15)

Closes the local (non-Stripe) commercial-state gaps checkpoint 14 left
open: scheduled suspension now has a real effective-date field and full
scheduling/rescheduling/cancellation/automation, and `FeatureGate`'s
snapshot fallback is hardened so it can no longer treat a genuinely broken
modern subscription the same as a documented legacy one. Scheduled
upgrade/downgrade PROVIDER APPLICATION remains the one deliberately
unautomated path — still correctly deferred to Stripe Test Mode, not
touched here.

## Part 1 — Repository findings

Confirmed via direct inspection before any code was written:
`scheduleSuspension()` recorded an operator's reason in
`metadata_json['planned_suspension_reason']` with **no effective-date field
of any kind** — not merely "not yet automated" as checkpoint 14 described
it, but structurally unable to represent "when." No `resume()`/"unsuspend"
method existed separately from `restoreToActive()` (which already handles
`suspended → active`, clearing `suspended_at`/`suspension_reason`).
`SubscriptionTransitions::MAP` allows `SUSPENDED` from `active`/`past_due`/
`unpaid` only — `trialing` has no path to `suspended`, confirmed
deliberate (nothing in the approved commercial model requires suspending a
trial rather than letting it expire) and NOT changed.

## Suspension transition graph (Part 3 of this report's numbering)

Unchanged from checkpoint 4's `SubscriptionTransitions::MAP` — this
checkpoint added no new status or transition, only real scheduling FIELDS
and lifecycle METHODS around the existing `→ suspended →` transitions:

```text
active/past_due/unpaid → suspended → active (restoreToActive(), the resume path)
                                   → cancelled
```

## Files created

- `App\Support\Entitlements\SnapshotIntegrityClassification` — the
  five-value classification vocabulary.
- `App\Services\Entitlements\SnapshotIntegrityClassifier` — pure,
  read-only classifier; the single source both `FeatureGate` and the
  integrity service consult.
- `App\Services\Entitlements\EntitlementSnapshotIntegrityService` —
  scan/repair orchestration.
- `App\Console\Commands\CheckSubscriptionEntitlementIntegrity`
  (`billing:subscriptions:check-integrity`).
- Migration `2026_08_02_000001_add_suspension_scheduling_fields_to_subscriptions_table.php`.
- 6 new test files (56 new tests — see Part 39).

## Files modified

`SubscriptionLifecycleService` (suspension methods rewritten/added),
`SubscriptionAutomationService` (`processScheduledSuspensions()` added,
`countPendingSuspensions()` replaced by `countScheduledSuspensions(bool)`),
`ProcessSubscriptionAutomation` command (reports the new category),
`FeatureGate` (classifier-aware fallback, `describeResolutionPath()`),
`EntitlementDecision` (new nullable `resolutionPath` field),
`Subscription` model (no relation changes needed — reused existing
`currentEntitlementSnapshot()`), `config/billing.php` (new
`entitlement_snapshot_introduced_at` boundary),
`AutomationOutcome`/`AutomationActionResult` (new `no_longer_applicable`
outcome). One pre-existing test factory fixed (Part 37).

## Database changes

`subscriptions` gains two additive, nullable columns:
`pending_suspension_reason` (string) and
`pending_suspension_effective_at` (timestamp, indexed) — mirrors the
existing `pending_pricing_plan_id`/`plan_change_effective_at` convention
for scheduled plan changes. `metadata_json['planned_suspension_reason']`
is retired (no data migration needed — a request-intent field, not
purchased commercial history).

## Suspension schema

Exactly two new columns, per Part 3's requirement to answer "who/when/
why/pending/cancelled/effective" without over-building: `who requested`
and `when requested` are already covered by `ActivityLog` (every
scheduling/rescheduling/cancellation call logs the actor via
`TransitionContext`) — mirroring how `pending_pricing_plan_id` itself
carries no separate `requested_by` column either. Adding one here would
have been a new, inconsistent pattern for a single call site.

## Immediate-suspension policy

`scheduleSuspension($subscription, $reason, $context, $effectiveAt = null)`
— when `$effectiveAt` is omitted, it defaults to `$context->occurredAt`
("now"). This IS how an immediate suspension request is represented: the
same method, the same pending fields, just an effective date that is
already due. It is **never applied synchronously inside this call** —
`SubscriptionAutomationService` picks it up on its next scheduled tick,
exactly like every other automated transition in this checkpoint series.
A caller needing a genuinely synchronous status change bypassing
automation entirely still has `suspend()` directly — both are valid,
distinct, intentionally-preserved entry points.

## Scheduled-suspension policy

`scheduleSuspension()` throws `SubscriptionLifecycleConflictException` if
a suspension is ALREADY pending (Part 4's explicit "never silently
overwrite a different pending commercial action" requirement) — callers
must use `rescheduleSuspension()` to change an existing pending request's
date/reason instead. Suspension may only be scheduled for
`active`/`past_due`/`unpaid` (whatever `SubscriptionTransitions::MAP`
already allows into `suspended`) — `trialing` was deliberately NOT added.

## Suspension effective-date rules

`SubscriptionAutomationService::processScheduledSuspensions()` discovers
`pending_suspension_effective_at <= now()`, never early. Boundary tests
cover one second before/at/after the effective instant (see
`SubscriptionAutomationServiceTest`). One canonical time basis throughout
— `CarbonImmutable::now()`, matching every other automated category.

## Suspension cancellation

`cancelScheduledSuspension($subscription, $context, $auditReason = '...')`
— idempotent: calling it with nothing pending is a safe no-op (Part 7's
explicit requirement), never an exception. A genuinely EFFECTIVE
suspension cannot be "cancelled" as if it never happened — cancellation
only ever clears the two PENDING fields, never touches `status`/
`suspended_at`/`suspension_reason`; an already-suspended subscription
calling this simply finds nothing pending and no-ops, remaining suspended.
The `$auditReason` parameter lets automation distinguish "an operator
deliberately cancelled this" from "automation discarded this because the
subscription's status changed" in the `ActivityLog` trail, without two
separate methods.

## Suspension rescheduling

`rescheduleSuspension($subscription, $newEffectiveAt, $context, $newReason = null)`
— throws `SubscriptionLifecycleConflictException` if nothing is currently
pending (the mirror-image of `scheduleSuspension()`'s "already pending"
guard), so "reschedule" and "schedule new" can never be silently
conflated in either direction.

## Suspension automation

`SubscriptionAutomationService::processScheduledSuspensions()` — reuses
the exact same discovery/attempt/report shape as every other automated
category (no parallel scheduler). Re-checks `SubscriptionTransitions::
canTransition($subscription->status, SUSPENDED)` at execution time before
calling `suspend()`; if the status changed since scheduling (e.g. the
subscription was cancelled in the meantime) and can no longer reach
`suspended`, the pending request is discarded via
`cancelScheduledSuspension()` with an explanatory audit reason and
reported as `no_longer_applicable` — never silently dropped, never
forced through an invalid transition. `suspend()` itself always clears
any pending suspension fields on success, whether invoked by automation
or a direct caller.

## Suspension recovery (this Part maps to "duplicate protection", not data recovery)

Two overlapping runs discovering the same due row twice: the second
attempt's `suspend()` call finds the subscription already `SUSPENDED` and
returns a safe no-op (the pre-existing `transition()` short-circuit,
unmodified) — never a duplicate transition or `ActivityLog` entry. A crash
between claiming a row and reporting its result never duplicates anything,
because nothing about discovery itself is a mutation — the actual
correctness boundary remains `SubscriptionLifecycleService::transition()`'s
row lock, exactly as documented in checkpoint 14.

## Resume/reactivation policy

**No separate `resume()` method was added.** `restoreToActive()` already
correctly implements "resume a suspended subscription" — clearing
`suspended_at`/`suspension_reason`/`grace_period_ends_at` and landing on
`ACTIVE` — and inventing a second method for the same transition would
duplicate it for zero behavioural difference. Resuming does **not** create
a new entitlement snapshot: no commercial entitlement changed, only an
operational access restriction was lifted, so the pre-suspension snapshot
remains authoritative (confirmed, not merely assumed — see Part 9).

## Interaction with scheduled cancellation / grace / trial / pending plan changes

No special-case code was added for any of these — the existing
per-transition status re-check already handles every interaction
correctly by construction:

- If a scheduled cancellation becomes effective (→ `cancelled`) before a
  pending suspension's date, `processScheduledSuspensions()`'s
  `canTransition($status, SUSPENDED)` check fails (no `cancelled →
  suspended` path exists) and the pending suspension is discarded as
  `no_longer_applicable`.
- If a suspension becomes effective FIRST while a cancellation is also
  scheduled (`cancel_at_period_end = true`), the subsequent
  `confirmCancellation()` attempt requires `status === active` and throws
  `SubscriptionLifecycleConflictException` — already caught and reported
  as `conflicted` by the existing automation `attempt()` wrapper. This is
  the "more restrictive/operational state wins" precedence rule in
  practice, with zero new code.
- Grace period and trial expiry never interact with suspension scheduling
  at all — different statuses, disjoint discovery queries.
- Pending plan changes (`pending_pricing_plan_id`/`plan_change_effective_at`)
  remain entirely orthogonal — they sit on an otherwise-unchanged `active`
  subscription and are never inspected by suspension logic.

`FeatureGate` was NOT changed to inspect any pending-suspension field —
it only ever reads current `status` (unchanged from checkpoint 13).

## Suspension access-policy behaviour

Unchanged: `SubscriptionAccessPolicy` already resolves `SUSPENDED` to
`RESTRICTED` (checkpoint 13) and `SubscriptionAccessMode::allowsOverrides()`
already excludes `RESTRICTED` (so a manual override can never bypass a
suspension — re-confirmed by existing test
`test_override_cannot_bypass_a_suspended_subscription`, still passing
unmodified).

## Suspension snapshot behaviour

Confirmed by test (`test_resume_restores_to_active_and_clears_suspension_fields`
plus the FeatureGate suite), not merely assumed: `suspend()` and
`restoreToActive()` create ZERO entitlement snapshots. The subscription's
existing purchased snapshot (from its original `activate()`/`startTrial()`)
remains untouched and immutable throughout a suspend → resume cycle —
`SubscriptionAccessPolicy` returning `RESTRICTED` while suspended is what
actually blocks entitlement resolution, not any snapshot manipulation.

## Snapshot integrity invariants

Implemented in `SnapshotIntegrityClassifier`/`SnapshotIntegrityClassification`
exactly as specified: only `active`/`past_due`/`trialing` (the three
statuses `FeatureGate` ever consults a snapshot for) are classified as
needing one at all; every other status is `not_applicable` regardless of
snapshot presence. A subscription with a current snapshot is
`expected_snapshot_present` (healthy). Snapshot rows remain immutable
(unchanged from checkpoint 14's model-level enforcement) and multiple
historical rows per subscription remain fully supported (unchanged).

## Legacy-subscription classification

`starts_at` (already set by both `activate()`/`startTrial()`) is the
authoritative anchor — deliberately not `created_at`, per this
checkpoint's own instruction not to rely on it when a more authoritative
lifecycle timestamp exists. Compared against
`config('billing.entitlement_snapshot_introduced_at')` — **one fixed,
honest, global boundary** (the real date immutable snapshots shipped, not
a fabricated per-row value): `starts_at` earlier than the boundary →
`legacy_pre_snapshot`; on/after the boundary with no snapshot → either
`expected_snapshot_missing_recoverable` or `_ambiguous` depending on
whether reconstruction inputs are complete (see next section). No
`starts_at` at all → always `expected_snapshot_missing_ambiguous`,
regardless of `created_at` — an unknown authoritative timestamp is
unknown, not approximated.

## Missing-snapshot detection / recoverable vs. ambiguous policy

**Exact recoverability rule** (`SnapshotIntegrityClassifier::isRecoverable()`):
a `trialing` subscription is always recoverable given a non-null
`starts_at` (the trial profile is fixed/global, never plan-dependent); an
`active`/`past_due` subscription additionally requires a `plan_code_snapshot`
that `PlanEntitlements::isKnownPlanCode()` recognises — without one,
entitlement VALUES cannot be reconstructed at all, and the subscription is
`expected_snapshot_missing_ambiguous` instead, never guessed at.
`EntitlementSnapshotIntegrityService::repair()` re-checks the FULL
classification (not just `isRecoverable()`) before writing anything — this
was a real bug caught by its own test suite during this checkpoint (a
legacy subscription that happens to also carry a known plan code would
otherwise satisfy the narrower `isRecoverable()` check and be wrongly
repaired instead of using its correct live-fallback path; fixed before
this report by gating `repair()` on
`classify() === EXPECTED_SNAPSHOT_MISSING_RECOVERABLE` specifically).
Repair reuses `EntitlementSnapshotService`'s existing idempotent creation
(`snapshotForActivation()`/`snapshotForTrialStart()`), so it inherits the
same unique-index duplicate protection — repairing the same subscription
twice reuses the first snapshot.

## FeatureGate fallback rule (exact)

`FeatureGate::resolveFromSnapshotOrLive()`: if a current snapshot exists,
use it (unchanged from checkpoint 14). If none exists, consult
`SnapshotIntegrityClassifier::classify()`:
`legacy_pre_snapshot`/`not_applicable` → live `PlanEntitlements`/
`trialProfile()` fallback (the documented compatibility path). Anything
else (`expected_snapshot_missing_recoverable` OR `_missing_ambiguous`) →
"not entitled," logged as a warning, **never** falls back to live values —
per this checkpoint's explicit "do not let all missing snapshots use the
same live-plan fallback" instruction. `FeatureGate::explain()` now reports
which of `no_subscription`/`not_entitled_by_access_mode`/`override`/
`snapshot`/`legacy_live_plan_fallback`/`missing_required_snapshot`
actually applied, via a new nullable `EntitlementDecision::$resolutionPath`
field (additive — existing callers unaffected).

## Integrity command

`billing:subscriptions:check-integrity` — `--repair`, `--dry-run`,
`--subscription=`, `--limit=`. Default (no flags) is non-destructive
inspection only. **Deliberately NOT registered on the scheduler** — see
the command's own docblock: repeated hourly logging of the same
persistent ambiguous finding would be exactly the "noisy duplicated logs"
this checkpoint's brief warns against, and nothing about missing-snapshot
detection is time-critical the way webhook/lifecycle automation is. It is
a manual/on-demand operational tool, matching the brief's explicit "no
frontend dashboard" instruction in spirit — structured command
output/logs are sufficient.

## Observability

`ProcessSubscriptionAutomation`'s output now includes
`scheduled_suspensions_future` (informational — not "blocked," since
suspension IS automated; simply reports how many are scheduled but not
yet due) alongside the unchanged plan-change blocked counters.
`billing:subscriptions:check-integrity` reports scanned/healthy/legacy/
missing-recoverable/missing-ambiguous/not-applicable/repaired/
repair-failed counters.

## Activity and audit logging

New `ActivityLog` actions: `subscription.suspension_rescheduled`,
`subscription.suspension_cancelled` (used for both a deliberate operator
cancellation and an automation-triggered discard, distinguished by the
logged `audit_reason`), `subscription.entitlement_snapshot_repaired`. No
log entry is ever written for a healthy scan result — only genuine
findings (ambiguous/ missing) and actual repairs are logged, avoiding the
noisy-duplicate-logs anti-pattern the brief warns against, especially
since the integrity command is manually invoked rather than scheduled.

## Concurrency / idempotency

Unchanged mechanism from checkpoint 14, extended to the new methods:
`scheduleSuspension()`/`rescheduleSuspension()`/`cancelScheduledSuspension()`/
`suspend()` all lock the subscription row and re-check state under that
lock before mutating. `EntitlementSnapshotIntegrityService::repair()`
relies on `EntitlementSnapshotService`'s existing unique-index-backed
idempotency — a duplicate repair invocation (manual retry, or two
concurrent `--repair` runs) reuses the first snapshot rather than
duplicating or erroring.

## Scheduler registration changes

None to the automated-transitions scheduler entry itself
(`billing:subscriptions:process-automation` — still hourly,
`withoutOverlapping()`, no `onOneServer()`) — it now additionally executes
`processScheduledSuspensions()` as part of its existing fixed-order run.
`billing:subscriptions:check-integrity` is intentionally NOT scheduled
(see "Integrity command" above).

## Single-server assumptions / future multi-server requirements

Identical position to checkpoints 9/14: `SubscriptionLifecycleService`'s
row locking (not the scheduler) is the actual correctness boundary, so
`onOneServer()` remains unnecessary for correctness today; a future
multi-instance deployment would only need it to reduce redundant
discovery-query load, not to prevent a duplicate transition.

## Test-baseline issues investigated (Part 36-38)

1. **Storage-directory permission errors** (21 errors + part of the 1
   pre-existing failure across `FileSecurityServiceTest`,
   `AiAnalysisErrorMessageTest`, `ContractAnalysisDedupTest`,
   `SupportTicketControllerTest` ×16, `AdjudicationDocumentTenantIsolationTest`).
   **Root cause**: `storage/framework/testing/disks/local/support-tickets`,
   `storage/framework/testing/disks/public/suresign`, and
   `storage/app/private/adjudication/1`'s parent are owned by `root:root`
   with `0700` permissions — leftover from some earlier root-privileged
   process, unrelated to this or any prior checkpoint's code. Verified this
   is a genuine ownership/permission problem, not an application defect: as
   the non-root `julz` user, even `rm -rf` on the specific stale
   directories fails with `Permission denied` (the directories themselves
   are inaccessible to read/traverse, not just to write), and `sudo`
   requires a password not available non-interactively in this session.
   **Fix**: deferred — requires a one-time manual
   `sudo chown -R <user>:<user> storage/framework/testing storage/app/private`
   (or `sudo rm -rf` the specific stale root-owned subdirectories and let
   Laravel recreate them) outside this session's privileges. **Reason
   deferred**: fixing this safely requires root access this session
   genuinely does not have — not a judgement call to skip it, a hard
   privilege boundary. **Result**: unchanged from every prior baseline
   this session — same 21 errors, none touched by this checkpoint's code.
2. **`PaymentApplicationExcelDisclosureTest::test_a_real_generation_failure_returns_a_generic_message_and_is_logged`**.
   **Root cause**: the test's fixture never set `application_date`, a
   NOT-NULL `date` column on `payment_applications` since the original
   `2026_01_01_000006_create_commercial_tables` migration — a genuinely
   invalid test factory that contradicts the current schema, unrelated to
   any commercial-automation behaviour. **Fix applied**: added
   `'application_date' => now()->toDateString()` to the fixture — a
   one-line, deterministic, test-only correction. **Result**: the test no
   longer fails at the database-insert layer, but now fails at a DIFFERENT
   assertion (`expected 500, got 201`) — the malformed `breakdown` value
   this test relies on to force a real `\TypeError` inside
   `ExcelGenerationService::buildMeasuredWorks()` apparently no longer
   causes one (something in that unrelated service may have become more
   defensive since this test was written). Investigating THAT further
   would mean reading/reasoning about `ExcelGenerationService`'s array
   handling — unrelated application code this checkpoint's brief
   explicitly warns against broadening into. **Left deferred**, reported
   honestly rather than weakening the assertion or reverting the (still
   correct) schema fix. Net effect on the full-suite count: unchanged (22
   total; one failure simply moved from a DB-constraint message to an
   HTTP-status-code message for the same test).

## Regression review (Part 40-46)

Entitlements filter: 78/78 → unaffected beyond the deliberate FeatureGate
constructor/fixture updates (4 direct `new FeatureGate()` call sites in
tests updated for the new constructor parameter; two existing fixtures
given an explicit pre-boundary `starts_at` to keep representing the
legacy-fallback case they were written to test, since a subscription
created "now" with no `starts_at` override is correctly no longer treated
as legacy by the new classifier). Lifecycle filter (part of Billing):
covered by `SubscriptionLifecycleServiceTest` (1 assertion updated for the
new `pending_suspension_reason` column) + new
`SubscriptionSuspensionLifecycleTest` (14 tests). Automation filter:
`SubscriptionAutomationServiceTest` (extended in place — old
metadata-based suspension test replaced with 7 real suspension-automation
tests) + `ProcessSubscriptionAutomationCommandTest` (unchanged, still
passing against the new `processDue()` shape). Billing filter
(Feature+Unit): all passing. Pricing: unaffected, all passing. Webhook:
unaffected, all passing. **Full backend suite**: 1381 tests, 1356 passed,
2 failures + 20 errors — did NOT reach zero (see "Test-baseline issues"
above for exactly why, and confirmation that the total defect count is
identical to every prior baseline this session, just redistributed by one
test's fix). No existing test's PASSING behaviour changed; every touched
test either needed updating for an intentional, documented behaviour
change (suspension scheduling storage, FeatureGate constructor/fallback
rule) or was a pre-existing defect this checkpoint's own instructions
scoped as investigate-but-don't-necessarily-fully-fix.

## Documentation updated (checkpoint 15)

`internal-docs/super-admin/subscription-billing.md` (this section; status
line), `CLAUDE.md` (suspension/snapshot-integrity service table rows and
updated entries for `SubscriptionLifecycleService`/
`SubscriptionAutomationService`/`FeatureGate`, `pending_suspension_reason`/
`pending_suspension_effective_at` column documentation), `project-context.md`
(new checkpoint 15 log entry). No public documentation, Help Centre, User
Guide, onboarding, or release notes were touched.

## Remaining operational risks

- The storage-permission baseline issue requires a one-time manual root
  action outside any session's normal privileges — flagged clearly rather
  than worked around.
- `PaymentApplicationExcelDisclosureTest`'s deeper assertion failure
  suggests `ExcelGenerationService` may have become more defensive against
  malformed `breakdown` data at some point — worth a dedicated, unrelated
  investigation, explicitly NOT undertaken here.
- Legacy subscriptions (predating the snapshot boundary) still resolve
  live `PlanEntitlements` forever unless a future checkpoint builds a
  deliberate one-time backfill — unchanged risk from checkpoint 14,
  re-confirmed still open.

## Remaining Stripe-dependent work

Scheduled upgrade/downgrade provider application — unchanged, still
requires Stripe Test Mode before it can be built safely.

## Overall critique

The real value of this checkpoint is closing gaps with an HONEST
boundary condition (the snapshot-support timestamp) rather than a guessed
one, and catching its own `repair()`/`isRecoverable()` classification gap
via its own test suite before it could have silently mis-repaired a
legacy subscription. The test-baseline work stayed appropriately narrow —
one real fix, one honestly-reported deferral, no scope creep into
unrelated Excel-generation code, and an honest admission that root-owned
directories are outside this session's privilege to fix.

## Recommended next checkpoint

Stripe Test Mode preparation for scheduled upgrade/downgrade application —
the one remaining piece this and the previous checkpoint deliberately
left for it.

---

# Stripe Test Mode Integration, Provider Synchronisation & End-to-End Billing Validation (checkpoint 16)

Closes the "principal remaining commercial gap" checkpoint 15 identified:
a scheduled upgrade/downgrade requested in SureSign had no provider-side
Stripe Price update executed, so it could never become commercially
effective. This checkpoint builds the complete provider-synchronisation
layer for that gap, plus the invoice/payment-history foundation,
Customer Portal abstraction, and reconciliation tooling — all built and
tested against `FakeBillingProvider` (adapter-mocked), since **no Stripe
Test Mode credentials are configured in this environment** (see "Mode
safety check" below). Nothing in this checkpoint was executed against a
real Stripe account.

## Mode safety check (performed before any implementation)

```text
STRIPE_KEY                          → empty
STRIPE_SECRET                       → empty
STRIPE_WEBHOOK_SECRET_TEST/LIVE     → empty
STRIPE_API_VERSION                  → empty (null)
Resolved Stripe account mode        → UNRESOLVABLE (no key to construct a client with)
Resolved Stripe account identity    → UNRESOLVABLE
Configured Product/Price mappings   → not inspected against a live Stripe account (no key)
```

Per this checkpoint's own explicit instruction ("if credentials are Live
Mode, ambiguous, invalid, or belong to an unexpected account: do not
perform provider writes → continue with implementation and mocked tests
→ report external validation as blocked"), no outbound Stripe call was
ever attempted. The user confirmed proceeding on this basis. This is a
harder stop than "ambiguous" — there is no credential of any kind to
evaluate, so no partial mode check was possible either.

## Provider abstraction additions

One new method on `BillingProviderInterface` (implemented in both
`StripeBillingProvider` and `FakeBillingProvider`):

- `updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, string $prorationBehavior, string $idempotencyKey): array` —
  retrieves the CURRENT provider subscription immediately before writing
  (never trusts a locally-cached item ID), validates exactly one
  recurring item exists (throws `UnexpectedSubscriptionItemStructureException`
  otherwise — Part 11's explicit invariant, SureSign has no per-seat/
  multi-item billing), and updates that one item's Price. Never passes
  `billing_cycle_anchor` — omitting it preserves the existing anchor,
  satisfying the "do not reset the billing cycle" policy with no explicit
  parameter needed.
- `normalizeInvoiceFromWebhookPayload(array $invoiceObject): array` — the
  same "plain array in, plain array out" boundary every other
  `normalize*FromWebhookPayload()` method already uses.

No `Stripe\Subscription`/`Stripe\Invoice`/`Stripe\Customer`/
`Stripe\Checkout\Session` object leaves `StripeBillingProvider` — unchanged
from the existing architecture.

## Plan-change domain policy (approved, not invented — supplied by the checkpoint brief)

| Question | Answer |
|---|---|
| Upgrades immediate by default? | Yes |
| Downgrades always period-end? | Yes, always — `requestDowngrade()` builds its own `TransitionContext` stripped of any caller-supplied `effective_at`, so this can never be overridden |
| Proration enabled for upgrades? | Yes (`create_prorations`) |
| Prorated credits permitted? | Governed entirely by Stripe's own `create_prorations` behaviour — SureSign never calculates a prorated amount |
| Invoice generated immediately for an upgrade? | Governed by Stripe, not decided here |
| Can `past_due`/`unpaid` change plan? | No — fails safe (`SubscriptionLifecycleConflictException`), payment recovery required first |
| Can `suspended`/`cancelled`/etc. change plan? | No — `InvalidSubscriptionTransitionException` |
| Can `trialing` upgrade/downgrade? | **Explicitly deferred** — `PlanChangeNotSupportedException`, never guessed at (Part 9 Q8: no existing approved rule) |
| Can a pending cancellation coexist with a plan change? | No — rejected outright; cancel the scheduled cancellation first |
| Can a pending downgrade be replaced? | Yes, by another downgrade or by an upgrade — `supersede: true` marks the old row `SUPERSEDED`, never silently overwritten |
| Can a pending change be cancelled? | Yes — `cancelPending()`, idempotent no-op if nothing pending |
| Authoritative timestamp for period-end application? | The subscription's own `current_period_ends_at` at the moment of the request (`scheduleDowngrade()`'s existing default, unchanged) |
| Stripe reports a different period end? | Reconciliation reports `TERMINAL_ERROR` only if chronologically implausible; otherwise not treated as an error — periods naturally drift with renewals |
| When is the entitlement snapshot created? | Only after webhook confirmation — never at request or send time |

## Plan-change state machine (exact)

```text
REQUESTED → SENT → CONFIRMED → APPLIED   (success path)
    │          │
    ├──────────┴──→ FAILED       (terminal — see failure_code)
    ├──────────────→ CANCELLED   (terminal)
    └──────────────→ SUPERSEDED  (terminal — replaced by a newer request)
```

`billing_plan_changes` (new table) carries both the state machine AND
the provider-operation idempotency tracking in one row — a deliberate
choice over a second, generic "provider operations" table (see
`SubscriptionPlanChangeService`'s class docblock): every other outbound
provider write already has adequate idempotency through an existing
mechanism (customer creation via the unique `billing_customers` row,
Checkout via `correlation_reference`, Product/Price via
`PlanPriceMappingService`'s supersession model) — a plan-change request
is the only genuinely NEW commercial operation needing fresh tracking.

**Idempotency key**: `plan_change:{billing_plan_changes.id}` — assigned
once, immediately after row creation, and reused by every retried
`send()` call for that row. A subscription may have at most one
non-terminal (`requested`/`sent`/`confirmed`) row at a time, enforced
under an application-level row lock (the same pattern
`hasConflictingSubscription()` already uses — MySQL has no partial
unique index).

## Named lifecycle methods added

- `SubscriptionLifecycleService::applyConfirmedPlanChange(Subscription, PricingPlan, PricingPlanProviderPrice, string $changeType, TransitionContext): Subscription` —
  the ONLY place a plan change actually takes local commercial effect.
  Requires `pending_pricing_plan_id` to still match the target (else
  `SubscriptionLifecycleConflictException` — the caller's idempotent
  "already applied" signal). Updates `pricing_plan_id`, `billing_interval`,
  **`provider_price_id`** (a real gap fix — this field was previously only
  ever set at draft creation and never updated on a plan change; without
  this fix, `WebhookEventProcessor::validateCommercialSnapshot()` would
  have permanently conflicted on every subsequent webhook after a
  legitimate plan change), `currency`/`unit_amount`/`plan_code_snapshot`/
  `plan_name_snapshot`, clears the pending fields, does NOT change
  `status`, and creates the new entitlement snapshot afterward (outside
  the transaction, same "authoritative boundary" pattern as `activate()`).
- `SubscriptionLifecycleService::cancelScheduledPlanChange(Subscription, TransitionContext): Subscription` —
  clears pending plan-change fields; safe no-op if nothing pending.

## Webhook confirmation rule (exact)

`WebhookEventProcessor::reconcilePlanChangeIfPending()` runs only from the
existing "pure refresh" branch of `processSubscriptionUpdated()` (status
unchanged — a plan change never changes `status`). If a pending
`BillingPlanChange` (`sent`/`confirmed`) exists and the webhook's reported
Price matches its target exactly, it calls
`SubscriptionPlanChangeService::confirmFromProvider()`, which applies the
change and marks the row `APPLIED`. `validateCommercialSnapshot()` (the
existing guard against an unexplained Price mismatch) was extended with
ONE exception: a reported Price matching a pending change's target is
never treated as a mismatch — everything else (no pending change, or a
price matching neither the stored one nor a pending target) still
`conflict`s exactly as before, which IS the "unknown Price"/provider-drift
safeguard (Part 23) — a genuinely unexplained Price change was always
going to `conflict`; this checkpoint didn't need a second, separate drift
code path for that case.

## Scheduled plan-change automation

`SubscriptionAutomationService::processDuePlanChanges()` sends the
outbound Stripe update for every `REQUESTED` row whose
`requested_effective_at` has passed (covers scheduled downgrades reaching
their period boundary, and any `REQUESTED` row that failed to send
synchronously). Immediate upgrades are sent synchronously by whatever
caller invokes `requestUpgrade()` — not via this tick — but a row still
`REQUESTED` past its effective moment is safely picked up here too.
Sending is NEVER treated as the plan taking effect (Non-negotiable
Principle 11) — only webhook confirmation does that.

`billing:subscriptions:process-automation`'s "blocked" plan-change
reporting from checkpoint 14/15 is now retired — plan changes are fully
automated (send-side); `plan_changes_pending_future` remains as a plain
informational count (scheduled but not yet due), not "blocked."

## Invoice / payment-history foundation

`billing_invoices`/`billing_payments` (already existed as schema from the
Phase 1–4 foundation, unused until now) are populated by
`InvoiceSyncService::syncFromWebhook()` from `invoice.paid`/
`invoice.payment_failed` webhooks only — chosen over "retrieve live from
Stripe" for resilience and audit-trail consistency with every other
billing record. Idempotent via the tables' existing unique provider-id
constraints (`updateOrCreate()`-equivalent upsert). Never stores card
details — only the normalized invoice/payment-intent identifiers and
amounts.

**Payment-failure/recovery lifecycle** (Part 17):
`invoice.payment_failed` on an `active` subscription → `markPastDue()`.
`invoice.paid` on a `past_due`/`unpaid` subscription → `restoreToActive()`.
A `suspended` subscription is deliberately NOT auto-restored by a paid
invoice — suspension is "a deliberate business decision, separate from
raw payment failure" (existing `suspend()` docblock); recovery from it
always requires an explicit operator action.

**Events added** (only these two — Part 15's "add only what's required,
never merely because Stripe offers them"): `invoice.paid`,
`invoice.payment_failed`. `invoice.created`/`.finalized`/`.voided`/
`.marked_uncollectible` and `payment_method.attached`/`.detached` were
deliberately NOT added — no local purpose for them exists yet.

## Customer Portal abstraction (Slice E2 — restricted, endpoint-exposed)

`BillingPortalService::createSession()` — validates the Organisation has
a `BillingCustomer` in the matching provider mode, uses ONLY
`config('billing.portal_return_url')` (never a caller-supplied URL),
resolves/verifies a **restricted Portal Configuration** (see below), and
audits session creation (`billing.portal_session_created`). Exposed via
`POST /billing/portal` (`BillingPortalController`) — empty body only; no
Stripe Customer ID, Organisation ID, Portal Configuration ID, or return
URL is ever accepted from the frontend.

**Enabled Portal capabilities**: payment-method management, invoice
history, billing-details management (address/phone/tax ID on the Stripe
Customer only — never email or company name, which stay SureSign-
authoritative). **Disabled**: plan upgrades/downgrades, subscription
cancellation, subscription quantity changes — all require SureSign's own
Pricing/plan-change/webhook-confirmation/snapshot workflow and would
otherwise let a customer bypass the entire architecture this and prior
checkpoints built.

As of Slice E2, this is **enforced programmatically**, not left as an
unverifiable Stripe Dashboard setting. `BillingProviderInterface` gained
`createPortalConfiguration()`/`listPortalConfigurations()`/
`retrievePortalConfiguration()`; `BillingPortalService` creates (once) or
discovers (by a stamped `suresign_restricted_billing_portal=v1` metadata
key, never by name or `is_default`) a dedicated restricted
`billing_portal.configuration` object per provider mode, and passes its ID
into every `createPortalSession()` call. Only the configuration's **ID**
is cached (`Cache::rememberForever`, keyed by livemode) — its **safety
verdict is never cached**: every `createSession()` call re-fetches the
configuration from Stripe and fails CLOSED (refuses to create a session,
throws `SubscriptionLifecycleConflictException`, logs `Log::critical`) if
`payment_method_update`/`invoice_history` are not both enabled or
`subscription_cancel`/`subscription_update` are not both disabled. A
manual on-demand check exists too — `billing:portal:verify-configuration`
(deliberately not scheduled, mirroring
`billing:subscriptions:check-integrity`'s reasoning: nothing here is
auto-repairable, and there is nothing time-critical about checking it).

Verified against a real Stripe Test Mode account (synthetic customer,
cleaned up after): the created configuration reports exactly
`payment_method_update=true`, `invoice_history=true`, `customer_update=true`
(`allowed_updates=["address","phone","tax_id"]`), `subscription_cancel=false`,
`subscription_update=false`; a real Portal Session was created against it
(`billing.stripe.com` host, Test Mode, `configuration` field set to that
exact ID); a deliberately-unsafe fixture configuration (`subscription_cancel:
true`) was confirmed to fail the same safety check the service uses, then
deactivated. If a Portal-driven change ever reached Stripe anyway (e.g. an
operator manually edits the configuration in the Dashboard faster than the
next session-creation call catches it), it still flows through the exact
same `WebhookEventProcessor`/`SubscriptionPlanChangeService` verification
as any other provider-originated event — no separate, weaker trust path
exists for "it came from the Portal."

## Reconciliation

`StripeReconciliationService`/`billing:stripe:reconcile` — non-destructive
inspection only, no `--repair` option at all (every finding requires a
human decision). Scans FORWARD from local `subscriptions` rows only —
does NOT enumerate Stripe's own subscription list for "provider-only"
orphans (a documented, deliberate scope limit — bulk-listing an entire
Stripe account with no local reference to filter against was judged
unsafe/impractical for this checkpoint). Findings:
`healthy`/`local_only`/`provider_subscription_deleted`/`mode_mismatch`/
`customer_mismatch`/`price_mismatch`/`unknown_price`/
`pending_change_confirmed`/`pending_change_stale`/`missing_snapshot`
(reuses `SnapshotIntegrityClassifier` — the same authority `FeatureGate`
itself consults, never a second divergent definition)/`retryable_error`/
`terminal_error`. A Price mismatch matching a pending plan change's
target is `pending_change_confirmed`/`_stale` (informational), never
`price_mismatch` — that distinction only exists for a genuinely
unexplained difference. **Deliberately NOT scheduled** — reconciliation
is a manual/low-frequency operational tool; webhook processing already
provides authoritative normal operation.

## Concurrency / idempotency (extended)

Every new write path (`SubscriptionPlanChangeService`'s five public
methods) locks its own row(s) under a transaction and re-checks state
before mutating, exactly the established pattern. `send()`'s retryable-
vs-terminal distinction: `UnexpectedSubscriptionItemStructureException`
is terminal (`FAILED`, never retried — it can't succeed on retry); any
other exception leaves the row `REQUESTED` for the next automation tick,
reusing the same `idempotency_key`. `confirmFromProvider()` is idempotent
against duplicate webhook redelivery (an already-`APPLIED` row is
returned unchanged) and against a concurrent duplicate confirmation (caught
via `applyConfirmedPlanChange()`'s own conflict exception).

## Security review

Stripe secret/webhook-mode keys remain env-only, server-only, never
logged (confirmed — every new `Log::*` call here logs only
subscription/plan-change IDs, classification strings, and exception
messages, never a key or payload). `BillingPortalService` never accepts a
caller-supplied return URL. `SubscriptionPlanChangeService` never accepts
a raw Stripe Price ID string from a caller — only a resolved, approved
`PricingPlanProviderPrice` model instance. No card details or payment-
method objects are ever persisted. No new route/controller/public
endpoint was introduced this checkpoint at all — every service built here
remains architecture only, exactly matching the brief's exclusions
(no billing UI, no controller/middleware enforcement). Webhook signature
verification and Test/Live webhook-secret separation are unchanged from
the existing, previously-reviewed implementation.

## Queue / scheduler review

No new queue was introduced — `processDuePlanChanges()` runs inside the
existing `billing:subscriptions:process-automation` hourly tick (no
provider API call happens inside webhook ingestion itself; sending a plan
change's Price update happens from the scheduled command, matching the
existing "provider calls never run inside the webhook HTTP request"
principle). `billing:stripe:reconcile` and
`billing:subscriptions:check-integrity` remain deliberately unscheduled.

## Stripe CLI / Test Clock / end-to-end scenario status

Not evaluated or used this checkpoint — no Stripe CLI authentication was
available, and with no credentials at all there was nothing to forward
webhooks from or to. **Every scenario in this checkpoint's brief (A
through K) is `Automated with adapter mocks` only** — see the automated
test files listed below for each scenario's mocked coverage. **None were
executed against real Stripe Test Mode.** A manual validation runbook
(Stripe CLI login → `stripe listen --forward-to <local-endpoint>` using
`STRIPE_WEBHOOK_SECRET_TEST` → create a Test Mode Product/Price matching
an existing `pricing_plans` row → run through Scenarios A–K by hand) is
the recommended next step once real Test Mode credentials exist — not
performed here.

## Tests added

`SubscriptionPlanChangeServiceTest` (18), `PlanChangeWebhookReconciliationTest`
(4), `InvoiceWebhookSyncTest` (7), `BillingPortalServiceTest` (5),
`StripeReconciliationServiceTest` (8), `ReconcileStripeSubscriptionsCommandTest`
(2) — 44 new tests, all against `FakeBillingProvider`, no network access.
Existing `WebhookEventProcessorTest`/`ProcessBillingWebhookEventJobTest`/
`BillingWebhookQueueIntegrationTest` fixtures using `invoice.paid` as a
generic "harmless unsupported event" placeholder were updated to
`payment_method.attached` (still genuinely unsupported), since
`invoice.paid` is now a real, processed event type.

Full Billing+Entitlements+Pricing+Webhook filter: 621/621. Full backend
suite unaffected beyond the pre-existing, unrelated baseline (see
checkpoint 15's documented storage-permission/Excel-generation findings,
unchanged this checkpoint — not re-investigated, out of this checkpoint's
scope).

## Documentation updated (checkpoint 16)

`internal-docs/super-admin/subscription-billing.md` (this section; status
line), `CLAUDE.md` (new service-table rows), `project-context.md` (new
checkpoint 16 log entry), `.env.example` (added the missing
`BILLING_ENTITLEMENT_SNAPSHOT_INTRODUCED_AT` placeholder from checkpoint
15, and confirmed every other required variable was already present with
empty placeholders). No public documentation, Help Centre, User Guide,
onboarding, or release notes touched — nothing here is customer-facing or
reachable by a real customer; no route or controller exists for any of
it yet.

## Remaining work before Live Mode

Real Stripe Test Mode account setup and credential configuration; running
the manual validation runbook above; Stripe Dashboard Customer Portal
configuration matching the "enabled/disabled capabilities" table;
Subscription Schedules API evaluation was explicitly NOT done (this
checkpoint chose SureSign's own scheduler + direct Price update for
downgrades, per Part 12's "if SureSign's local scheduler remains the
executor" option, to avoid depending on an API surface that could not be
verified against a real account this checkpoint).

## Remaining work before module enforcement

Unchanged from every prior checkpoint — `FeatureGate` module rollout,
controller/middleware enforcement, quota enforcement, and Billing UI all
remain unbuilt and out of scope.

---

# Stripe Sandbox Activation — Repository Audit & Read-Only Billing API (checkpoint 17)

Real Stripe Test Mode credentials now exist (checkpoint 16 above ran
entirely against `FakeBillingProvider` with no credentials at all). This
checkpoint (a) audited the repository against real Stripe Sandbox state
and (b) built the first authenticated, organisation-facing Billing
endpoints — read-only only. No Checkout Session, Customer Portal
session, subscription mutation, or any other provider write was
performed or exposed.

## Infrastructure fix (found and corrected before any audit could proceed)

`suresign_backend` was crash-looping: migration
`2026_07_23_143613_create_billing_entitlement_snapshots_table` had
created its table (0 rows) without a corresponding `migrations` tracking
row — almost certainly an interrupted prior `migrate --force` — which
also blocked 4 later genuinely-pending migrations behind it (`billing_webhook_events.retryable`/`processing_started_at`,
`subscriptions`' suspension-scheduling fields, and the entire
`billing_plan_changes` table). Verified the live table matched the
migration file exactly (same columns/FKs/unique index, empty) before
inserting one tracking row (batch 7) and letting the container's normal
boot apply the 4 real pending migrations. No schema was altered, no data
touched — a pure bookkeeping correction. Backend now boots healthy.

## Stripe Sandbox verification (read-only calls only)

```text
Account (GET /v1/account)           → acct_1Tw1p3HatZTi1dzs, "SureSign Contracts sandbox", livemode: false
Key prefixes                        → pk_test_/sk_test_, consistent with CLI's configured account
STRIPE_API_VERSION                  → unset (account default applies)
Stripe CLI                          → `stripe listen --forward-to localhost:8000/api/billing/webhooks/stripe` running
STRIPE_WEBHOOK_SECRET_TEST           → present
Dashboard-registered webhook endpoints (GET /v1/webhook_endpoints) → none (expected — CLI forwarding used instead)
Products (GET /v1/products)          → none
Prices (GET /v1/prices)              → none
pricing_plan_provider_prices (local) → empty (0 rows) — PlanPriceMappingService has never been run
```

No Product, Price, Customer, Checkout Session, or Portal session has
ever been created against this Stripe account. Portal configuration was
not yet checked live — deferred to the slice that actually launches
Portal (Slice E).

## `starter` → `essential` plan canonicalisation

`pricing_plans` held a plan coded `starter` (id 3), contradicting the
approved three-plan policy (Essential/Professional/Enterprise — no
Starter). Verified via direct query that it had **zero** live
references — no subscriptions, no `billing_entitlement_snapshots`, no
`pricing_plan_provider_prices` mappings, no `billing_plan_changes` — so
this was a seed-data naming bug, not a data-migration problem. Fixed by:
updating `PricingSeeder.php` (`code`/`slug`/`name`, and the FAQ string
mentioning "Starter"), correcting the one live DB row directly (`id=3`
→ `code/slug/name = essential/essential/Essential`), updating the
illustrative comment in `2026_07_25_000002_create_pricing_plans_table.php`,
and clearing the `pricing.public` cache (`Cache::forget`, confirmed via
`php artisan cache:clear` + a live `GET /api/pricing` check). Test files
using the literal string `'starter'` (`PricingPlanLifecycleTest`,
`PricingCacheTest`, `PricingValidationTest`) were deliberately left
unchanged — they construct their own ad hoc `PricingPlan` rows to test
generic lifecycle/cache/validation behaviour and have no relationship to
the seeded commercial plan; renaming them would be cosmetic scope creep,
not part of this canonicalisation.

## Slice A — read-only Billing backend API

New files:

- `app/Services/Billing/BillingOverviewService.php` — the one new
  read-only service, following `CommercialOverviewController`'s existing
  `build(User $user): array` convention. Every method resolves scope from
  `$user->organization` only — never accepts a caller-supplied
  organisation id. Uses `Organization::subscriptions()->latest('id')->first()`
  as "the current subscription" (deliberately NOT
  `Organization::liveSubscription()`, which excludes terminal statuses
  like `cancelled`/`expired` that a Billing overview must still display).
- `app/Http/Controllers/Api/BillingController.php` — thin, delegates to
  `BillingOverviewService`; the only endpoint needing an ownership check
  (`GET /billing/invoices/{invoice}`) uses the same
  `authorize(Request, Model): void` private-method convention as
  `FinalAccountController`/`AdjudicationDeadlineController`.
- `app/Support/Billing/BillingPresenter.php` extended with
  `accessDecision()`, `planChange()`, and `purchasablePlan()` — the
  existing presenter (built in checkpoint 16, unused until now) already
  excluded `provider_payload_json`/raw Stripe fields by design; these
  additions follow the same hand-whitelisting discipline and never
  expose a `provider_price_id`.

New routes (all under the existing `auth:sanctum, account.status,
password.current, track.usage` group — no new middleware invented):

```text
GET /billing/overview
GET /billing/subscription
GET /billing/plans
GET /billing/pending-plan-change
GET /billing/invoices
GET /billing/invoices/{invoice}
GET /billing/payments
```

`GET /billing/plans` resolves the organisation's currency via
`CurrencyService::resolveOrganizationCode()` (not a new currency
mechanism) and the provider's current livemode via
`BillingProviderInterface::isLivemode()`, pairing each active
`pricing_plans` row with its `monthly`/`annual`
`pricing_plan_provider_prices` mapping if one exists — a plan with no
mapping yet (all three, currently) returns `monthly`/`annual: null`
rather than fabricating a price. No endpoint here can accept an
arbitrary Stripe Price ID or plan identifier from the caller — plan
selection for Checkout remains entirely a later-slice concern.

Permissions: read access requires only organisation membership (matching
`CommercialOverviewController`'s existing pattern for "my own
organisation" data) — no dedicated "billing administrator" sub-role
exists yet in this codebase; that distinction is deferred to whichever
slice adds mutating endpoints (Checkout/upgrade/downgrade/cancellation),
where it actually matters.

## Tests added

`tests/Feature/Billing/BillingOverviewApiTest.php` (6 tests): no-subscription
state, active-subscription overview shape (translated status/access mode,
latest invoice/payment), pending plan-change surfaced on both `overview`
and the dedicated endpoint, plan listing marks the current plan and never
leaks a `provider_price_id`, single-invoice IDOR rejection across
organisations, and invoice/payment list isolation across organisations.

Full `tests/Feature/Billing`, `tests/Feature/Pricing`,
`tests/Feature/Entitlements` filter: 513/514 passed. The one failure
(`DeploymentQueueConfigurationTest`) is pre-existing and environmental —
it reads `docker-compose.prod.yml` via a path relative to the container
filesystem, which isn't mounted inside `suresign_backend`; unrelated to
this checkpoint's changes.

## Documentation updated (checkpoint 17)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md` (new `BillingOverviewService` row, `pricing_plans` canonical
codes note). No public documentation, Help Centre, User Guide,
onboarding, or release notes touched — nothing here is customer-facing
yet (no frontend consumes these endpoints).

## Remaining work before the next slice (Slice B — Billing overview/plan-selection UI)

Frontend build against the endpoints above. Slice C (Checkout) remains
blocked on creating at least one real Test Mode Product/Price via
`PlanPriceMappingService` first — no mapping exists yet for any plan.
Portal configuration (Slice E) has not been checked against the live
Stripe account. `FeatureGate` module enforcement, Billing UI beyond
Slice A, and Live Mode activation remain untouched, per the checkpoint's
explicit exclusions.

---

# Slice B — Customer Billing Overview & Plan Selection UI

Frontend-only, built entirely against Slice A's existing read-only
endpoints. No Stripe Product/Price, no Checkout Session, no subscription
mutation, no migration — nothing in this slice writes anywhere.

## What was built

- `frontend/src/hooks/useBilling.ts` — React Query hooks for all seven
  Slice A endpoints, with TypeScript interfaces mirroring
  `BillingPresenter` exactly (never inventing a field the backend
  doesn't return, never reading `provider_price_id`).
- `frontend/src/lib/billingStatus.ts` — short label/tone translations for
  `SubscriptionStatus`/`PlanChangeState`/`SubscriptionAccessMode` values.
  The longer explanatory copy shown to the user is the backend's own
  `access.reason` prose (already customer-safe, written into
  `SubscriptionAccessPolicy`) — this module deliberately does not
  re-author that reasoning, only supplies badge labels/tones.
- `frontend/src/components/billing/` — `AccessStatusBanner`,
  `SubscriptionSummaryCard`, `PendingPlanChangeCard`,
  `PlanComparisonSection`, `InvoiceListSection`, `PaymentListSection`.
  All built on the existing `Card`/`Badge`/`EmptyState`/`PaginationBar`
  primitives — no new generic UI primitive introduced.
- `frontend/src/app/app/settings/billing/page.tsx` — the composed page,
  following the same `/app/settings/<subpage>` convention as
  `releases`/`terms`/`privacy`.
- Navigation: a "Billing" link added next to "Settings" in
  `AppSidebar`'s profile menu (covers both desktop and mobile — the same
  component renders both, gated only by `useIsMobile()` internally), and
  a "Billing" pill added to the existing tabbed Settings page
  (`(dashboard)/settings/page.tsx`) that navigates to the new route
  rather than becoming a fifth in-page tab, since Billing's content is
  materially larger than the existing four form tabs.
- `PaginationBar` gained one optional, backward-compatible prop
  (`showPerPageSelect`, default `true`) — Billing's list endpoints paginate
  at a fixed server-side page size with no `per_page` override, so
  showing a working-looking per-page dropdown that silently does nothing
  would have been misleading. Every existing caller is unaffected.
- `Badge`'s `Tone` type was exported (previously module-private) so
  `billingStatus.ts` could type against it — no behavioural change.

## Permissions

No dedicated "billing administrator" sub-role exists in this codebase —
confirmed during Slice A's audit. Billing visibility therefore matches
Settings' own visibility (any authenticated organisation member); the
backend remains the actual authority via `BillingController`'s
organisation scoping. This is a known, deliberate gap to revisit if a
future checkpoint introduces finer-grained organisation roles — not
something this slice invented a workaround for.

## No-subscription / disabled-action wording

Exact copy used: "Your organisation doesn't have an active subscription
yet." / "Online subscription checkout is being prepared. Choose a plan
below to see what's included. Checkout will be available soon." Plan
action buttons are real `disabled` elements (not links to a placeholder
page, not a fake success flow) labelled "Current plan" / "Checkout coming
next" / "Upgrade flow coming next" / "Downgrade available at renewal"
depending on the plan's relationship to the organisation's current plan
(compared by the plans array's own `order`, not invented client-side).

## Frontend verification

- `npx tsc --noEmit` via `next build`: clean, no type errors introduced.
- `eslint` scoped to every new/touched file: clean (two real issues found
  and fixed — an unescaped apostrophe in the no-subscription copy, and one
  unused import — everything else reported was pre-existing and unrelated,
  confirmed by isolating the new/changed files and re-running against
  baseline).
- Production build (`next build`): fails at the `/_global-error` prerender
  step with `TypeError: Cannot read properties of null (reading
  'useContext')` — **confirmed pre-existing and unrelated** by temporarily
  reverting every Slice B file and rebuilding: the identical failure
  reproduces on baseline. TypeScript compilation itself (the step before
  prerendering) succeeds cleanly both with and without Slice B's changes,
  and the new `/app/settings/billing` route is correctly registered
  (page count went from 57 to 58). Not investigated further — a
  pre-existing `_global-error` boundary issue is out of this slice's
  scope to fix.
- **No frontend test infrastructure exists in this repository at all** —
  no Jest/Vitest/Playwright/Testing Library config anywhere in
  `frontend/` or `marketing/`, confirmed by searching for config files
  and any existing `*.test.ts(x)`. Stage 11's test list could not be
  implemented against "existing frontend test infrastructure" because
  none exists; introducing a testing framework from scratch is a
  separate infrastructure decision this slice did not make unilaterally.
  Backend contract correctness for the underlying data is instead covered
  by Slice A's `BillingOverviewApiTest` (6 tests, still passing).

## Documentation updated (Slice B)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md`, `project-context.md`, and a new customer-facing Help Centre
page (`docs/settings/billing.md`, linked from `docs/settings/overview.md`
and added to `mkdocs.yml`'s nav) describing what Billing shows today and
what remains unavailable until later slices. No release notes — this UI
is not part of an announced public release.

## Remaining gaps before Slice C (Checkout)

No Test Mode Product/Price mapping exists yet (unchanged from Slice A) —
Checkout cannot be wired up until `PlanPriceMappingService` creates at
least one. No frontend Checkout-initiation call exists; `PlanComparisonSection`'s
disabled buttons are structurally ready to become real actions without a
visual redesign (same card layout, same button positions).

## Remaining gaps before Slice D (upgrade/downgrade/cancellation)

No frontend action exists yet to request an upgrade/downgrade, cancel a
pending change, or cancel a subscription — `PendingPlanChangeCard` is
display-only by design this slice. No permission model exists yet to
distinguish who within an organisation may request a commercial change
versus merely view Billing.

---

# Slice C1 — Stripe Sandbox Product/Price Mapping & Checkout Readiness

Real Stripe Sandbox writes (Products/Prices only) — no Checkout Session,
no test-card payment, no subscription created or activated, no
entitlement snapshot. Executed via `PlanPriceMappingService::syncPlanPrice()`
through `php artisan tinker` (no controller/command wrapper existed or
was needed — the service itself is the complete, already-idempotent
write path).

## Infrastructure issues found and fixed before any Stripe write

Two real, pre-existing environment bugs were found while re-verifying
preflight state (both closed before proceeding, both unrelated to this
checkpoint's actual mandate):

1. **`suresign_queue`'s running process predated the `entrypoint.sh`
   fix already on disk** (from checkpoint 11 — `queue:work
   --queue=billing-webhooks,default`) — it was still running the OLD
   `queue:work` invocation with no `--queue` flag at all (default queue
   only), so every `billing-webhooks` job sat unprocessed. Restarting the
   container alone didn't fix it (the image's OWN baked-in entrypoint
   at `/usr/local/bin/entrypoint.sh` was also stale) — required
   rebuilding the `suresign-backend` image (`docker compose build
   backend`) and recreating `queue`/`scheduler` with
   `--renew-anon-volumes` (their anonymous `vendor/` volumes were also
   stale, predating `stripe/stripe-php` being added to
   `composer.json`/`lock` — without this flag Docker Compose reuses the
   old anonymous volume content on container recreation rather than the
   fresh image content).
2. **Rebuilding/recreating the containers attached them to the wrong
   Docker network** (`docker-compose.prod.yml` — itself modified,
   uncommitted, ahead of what the currently-running stack was started
   with — now names its network explicitly `suresign_shared` for demo-
   environment compatibility, but the live `mysql`/`redis` containers
   are still on the OLDER default-named network `suresign_suresign`
   from before that rename reached disk). Fixed by `docker network
   connect suresign_suresign` for `backend`/`queue`/`scheduler` after
   each recreation, rather than continuing to invoke `docker compose up`
   against a file state that doesn't match the live stack — that
   mismatch is itself a separate, pre-existing issue for whoever
   reconciles the demo-environment network rename, not something this
   checkpoint resolved.

Ten webhook events from this checkpoint's own Product/Price creation
calls (`product.created`/`price.created`/`plan.created`) were stuck in
`received`/`failed_jobs` as a direct symptom of bug #1 — all ten
correctly drained to `ignored` once the queue was fixed, with zero
subscription/entitlement side effects (verified below).

## Stage 1 — Preflight verification

```text
STRIPE_KEY / STRIPE_SECRET       → pk_test_/sk_test_ prefixes confirmed
Live key in local/testing env    → BillingConfigGuard passed (backend booted)
Resolved account (GET /v1/account) → acct_1Tw1p3HatZTi1dzs, "SureSign Contracts sandbox", livemode: false
STRIPE_WEBHOOK_SECRET_TEST       → present
Stripe CLI forwarding            → confirmed to /api/billing/webhooks/stripe
PlanPriceMappingService          → confirmed correct, already-idempotent write path
pricing_plan_provider_prices schema → already supports Test/Live separation (`livemode` column)
```

Test Mode conclusively proven before any write — see the two infra fixes
above, both resolved before the first `syncPlanPrice()` call.

## Stage 2 — Local Pricing inventory

| Plan | Code | Monthly (GBP) | Annual (GBP) | Mapping required |
|---|---|---|---|---|
| Essential | `essential` | £299.00 (29900) | £3,050.00 (305000) | Yes |
| Professional | `professional` | £799.00 (79900) | £8,150.00 (815000) | Yes |
| Enterprise | `enterprise` | NULL | NULL | **No — deliberately excluded** |

Enterprise's `monthly_price`/`annual_price` are genuinely `NULL`
(`price_prefix = 'Custom'`, `cta_text = 'Contact Sales'`) — a designed
"contact sales" plan, not a data error. Correctly excluded from Stripe
Price creation rather than treated as a blocking ambiguity; a new
regression test (`PricingSeederCanonicalPlansTest`) now guards this.

## Stage 3 — Stripe Sandbox inventory (before creation)

Confirmed via read-only `GET /v1/products`/`GET /v1/prices`: zero
Products, zero Prices existed. No reuse candidates possible; proceeded
directly to controlled creation. (`PlanPriceMappingService`'s own
architecture treats the LOCAL `pricing_plan_provider_prices` table —
also empty — as the source of truth for "does a mapping already exist,"
rather than searching Stripe by metadata/name at call time; this is
deliberately safer than fuzzy provider-side matching and was already the
approved design, not something added this checkpoint.)

## Stages 4–7 — Product/Price creation and mapping persistence

Executed `syncPlanPrice()` once per plan/interval (Essential monthly,
Essential annual, Professional monthly, Professional annual) via the real
`StripeBillingProvider` (APP_ENV=local, not testing). One Stripe Product
per plan (matching Stage 4's recommended structure), metadata
`{suresign_pricing_plan_id, suresign_plan_code, suresign_source}` on both
Products and Prices — no secrets, no mutable commercial values. Re-ran
all four `syncPlanPrice()` calls a second time immediately after:
identical mapping IDs returned, zero new Stripe objects created,
confirming the idempotent-reuse path. Final Stripe state: exactly 2
Products, 4 Prices — no duplicates.

## Stage 8 — Mapping verification (and one real gap closed)

All 4 mappings resolve deterministically via `resolveActivePrice()`;
`reconcileMapping()` confirms exact agreement with the live Stripe object
for all 4. Enterprise and an unmapped currency (USD) both correctly
resolve `null` (fail-safe, not an exception). An unsupported interval
throws, as before.

**One real gap found and closed**: `resolveActivePrice()` had no
protection against more than one active mapping existing for the same
plan/interval/currency/mode (no unique DB constraint enforces this — only
`(provider, provider_price_id)` is unique) — it would have silently
returned the most recently created row via `->latest('id')->first()`,
violating this checkpoint's explicit "do not silently select the first
matching row" requirement. `syncPlanPrice()`'s own supersede-before-create
flow never produces this state in practice, but a manual/out-of-band row
could. Fixed by counting active matches and throwing
`PlanPriceMappingException` if more than one exists — a minor, contained
fix to mapping resolution only (no migration, no Checkout Session, no
subscription state change), with a new regression test.

## Stage 9 — Checkout readiness audit (review only — no session created)

`CheckoutSessionService::startCheckout()` already satisfies every
criterion: accepts a `PricingPlan` + interval/currency (never a raw
Price ID), resolves the Price server-side via `PlanPriceMappingService`,
delegates Organisation/BillingCustomer resolution, uses
`SubscriptionLifecycleService`'s conflicting-subscription check
untouched, has a full idempotent reuse/locking strategy for both the
draft subscription and the Checkout Session itself, validates
success/cancel URLs via `SafeUrl`, and never activates a subscription
from the redirect (`markPendingPayment()` only). **One gap noted, not
fixed this checkpoint**: `startCheckout()` accepts `successUrl`/`cancelUrl`
as caller-supplied strings rather than reading
`config('billing.checkout_success_url')`/`checkout_cancel_url')` itself —
whichever controller Slice C2 adds MUST pass only the configured values,
never anything derived from the request. No code changes made here (would
require altering an already-correct service's public signature outside
this checkpoint's mandate).

## Stage 10 — Webhook smoke check

Real, live-verified end-to-end: `stripe trigger customer.created` → CLI
delivered the event → `WebhookIngestionService` verified the signature →
persisted → `ProcessBillingWebhookEventJob` (queue `billing-webhooks`)
processed it → `WebhookEventProcessor` correctly classified it `ignored`
(not one of the 5 explicitly handled event types). Confirmed zero rows in
`subscriptions`/`billing_entitlement_snapshots` throughout. The same
outcome was independently confirmed for all 10 stranded events from
Stages 4–7 once the queue infrastructure fix landed.

## Tests added

`PlanPriceMappingServiceTest::test_resolve_active_price_throws_on_duplicate_active_mapping`
(the Stage 8 fix) and `PricingSeederCanonicalPlansTest` (3 tests — exact
canonical code set, Enterprise has no fixed price, Essential/Professional
have complete monthly+annual pricing). Full
Billing+Pricing+Entitlements filter: 517/518 (the one failure is the
same pre-existing, unrelated `DeploymentQueueConfigurationTest` container-path
issue from every prior checkpoint this session). Webhook-filtered suite:
186/186. `CheckoutSessionServiceTest` (29 tests, unchanged, still
passing) already covers every Checkout-readiness scenario Stage 11 asked
for — arbitrary Price rejection, missing-mapping rejection, no premature
activation, idempotent reuse — no new Checkout tests were needed.

## Documentation updated (Slice C1)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md`, `project-context.md`. No public documentation or release
notes — nothing customer-facing changed.

## Remaining blockers before Slice C2 (Checkout implementation)

None from a mapping-readiness standpoint — Essential and Professional are
fully mapped and verified; `CheckoutSessionService` is ready to be wired
to a controller. Slice C2 must: build the controller/route (none exists
yet), ensure it reads success/cancel URLs from config only (Stage 9's
noted gap), and decide how the frontend's disabled "Checkout coming
next" buttons in `PlanComparisonSection` become real actions. The
`docker-compose.prod.yml` network-name/live-stack mismatch found during
this checkpoint's infra fix is unrelated to Billing and should be
reconciled separately by whoever owns the demo-environment work.

---

# Slice C2 — First Subscription Checkout & Webhook-Confirmed Activation

The first real, mutating Billing endpoint: `POST /billing/checkout`. Full
real Stripe Sandbox execution up to (not including) the hosted payment
page itself — see "Real Sandbox execution" below for the exact boundary
and why.

## What was built

- `App\Http\Requests\StoreCheckoutSessionRequest` — accepts only
  `plan_code` + `billing_interval`. No Stripe Price/Product ID, amount,
  currency, or return URL is ever accepted.
- `App\Http\Controllers\Api\CheckoutController::store()` — resolves the
  plan server-side, resolves currency via the existing `CurrencyService`,
  pre-checks `CheckoutSessionService::findReusableCheckoutForPlan()` (new,
  see below) before calling `startCheckout()`, and translates its
  exceptions into sanitised HTTP responses (409 conflict, 422 unavailable
  plan, 502 provider error — never a raw Stripe/SDK exception or stack
  trace to the caller).
- `CheckoutSessionService::findReusableCheckoutForPlan()` — new, small,
  read-only method: an OPEN/CREATED unexpired session for the exact same
  organisation/plan/interval. Added because a genuine double-click/two-
  tab retry against an already-`pending_payment` draft otherwise surfaced
  `startCheckout()`'s (correct, for a genuinely different subscription)
  `SubscriptionLifecycleConflictException` — unhelpful for the identical
  in-flight attempt this method exists to detect. `startCheckout()` itself
  was not modified.
- `billing_checkout_sessions.checkout_url` (new nullable `text` column,
  migration `2026_08_04_000001`) — a genuine gap found during Stage 1's
  repository review: the table stored our own success/cancel redirect
  targets but never the Stripe-hosted Checkout page URL itself, so
  nothing could give a browser a redirect target on the *reuse* path (an
  existing OPEN session is reused with zero provider calls, so there was
  nowhere to re-derive the URL from). `text`, not `string`, after a real
  live Sandbox run hit `Data too long for column` — a genuine Stripe
  Checkout URL's opaque fragment exceeds 255 characters.
- Frontend: `useCreateCheckout()` mutation (`hooks/useBilling.ts`),
  `PlanComparisonSection` wired for real (Essential/Professional
  self-serve buttons call Checkout and redirect via
  `window.location.href`; Enterprise renders a real "Contact Sales" link
  from the plan's own `cta_url`, never a hardcoded plan-code check —
  works for any future non-self-serve plan); two new pages,
  `/app/settings/billing/checkout/success` (polls `GET /billing/overview`
  conservatively — max 15 polls / ~45s — stops once the subscription
  reaches active or a terminal status, never claims activation itself)
  and `/app/settings/billing/checkout/cancelled` (purely informational).
- `BillingPresenter` extended with `checkout_url` (checkoutSession) and
  `is_self_serve`/`cta_text`/`cta_url` (purchasablePlan) — the latter is
  what lets the frontend distinguish Enterprise from Essential/
  Professional without hardcoding a plan code.

## Real bugs found and fixed via live Stripe Sandbox execution (Stage 17)

1. **Managed Payments tax-code requirement** — the very first live
   Checkout Session creation attempt failed:
   `Invalid line_items[0]: the product tax code is missing... Managed
   Payments... requires an eligible tax code`. SureSign has no tax
   policy or logic of any kind yet (per CLAUDE.md's explicit exclusions).
   Fixed by passing `managed_payments: {enabled: false}` on session
   creation (`StripeBillingProvider::createCheckoutSession()`) rather
   than fabricating a tax code — a deliberate opt-out, not a workaround,
   revisit only alongside an actual tax-handling decision.
2. **`checkout_url` column too short** — see above; caught on the very
   next live attempt, immediately after fixing bug #1.
3. **Raw Stripe SDK exceptions leaking to the API response** — bug #1's
   error response included a full stack trace and internal file paths.
   Fixed: `CheckoutController::store()` now explicitly catches
   `Stripe\Exception\ApiErrorException`, logs the real detail server-side
   (organisation/plan/interval + Stripe's own error code, never a card or
   secret), and returns a generic sanitised message with a 502 status.
4. **Two orphaned `draft` subscriptions** — a direct symptom of bugs #1/#2
   failing mid-transaction after `createDraftSubscription()`'s own
   (separate, already-committed) transaction had already succeeded.
   Confirmed via `SubscriptionLifecycleService::hasConflictingSubscription()`
   that a `draft` with no attached OPEN/CREATED checkout session never
   blocks a new attempt (only `draft` + an active checkout session
   conflicts) — so no architecture gap, just untidy test rows. Deleted
   directly (this session's own synthetic test data, not customer data)
   rather than left lying around.

## Two real regressions found and fixed (Stage 18 full regression)

1. An `assertFalse(class_exists(CheckoutController::class))` test in
   `SubscriptionLifecycleServiceTest` was a deliberate historical marker
   ("no such controller exists yet") — now outdated since this checkpoint
   built one. Replaced with a real structural assertion: `CheckoutController`'s
   source never calls `->activate(` and never references
   `lifecycleService` — the actual invariant that test was protecting
   (activation only ever happens via a verified webhook, confirmed
   separately: `->activate(` appears in exactly two places in `app/`, both
   inside `WebhookEventProcessor`).
2. An initial (reverted) attempt to relax `App\Rules\SafeUrl` to accept
   `http://` in local/testing broke `PricingValidationTest`'s
   `plain_http_url_is_rejected` test — Pricing Management's CTA/link
   field validation shares that same rule class and expects `http://` to
   always be rejected, in every environment. Fixed correctly: `SafeUrl`
   itself was left untouched; the local-dev relaxation lives ONLY in
   `CheckoutSessionService::assertSafeUrl()` (config-derived
   success/cancel URLs only, never user input), scoped to that one
   caller.

## Checkout parameters (exact)

`mode: subscription`, one `line_items` entry (the resolved Price,
quantity 1), `customer` (resolved `BillingCustomer`), `success_url`
(config + `?session_id={CHECKOUT_SESSION_ID}`), `cancel_url` (config,
unmodified), top-level `metadata` AND `subscription_data.metadata`
(identical — `suresign_organization_id`/`subscription_id`/
`subscription_reference`/`checkout_session_id`/`billing_customer_id`/
`pricing_plan_id`/`provider_price_mapping_id`/`billing_interval`/
`livemode`/`correlation_reference`), `managed_payments.enabled: false`.
No coupons, promotion codes, trials, taxes, or additional line items —
none were enabled or invented.

## Real Sandbox execution — exact boundary

Executed for real, live, against the actual Stripe Sandbox account:
Checkout Session creation (via the real HTTP API, a real synthetic
Organisation/User, real Sanctum token), idempotent duplicate-request
reuse (identical response, same session, confirmed via two consecutive
real HTTP calls), and Enterprise rejection (real 422). **Not executed**:
completing the hosted Stripe Checkout payment page itself — this
requires a real browser (Stripe.js tokenizes card details client-side
inside the hosted page; there is no server-side "complete this Checkout
Session" API call, unlike PaymentIntents). No browser-automation
tool was available in this environment. This is reported precisely as a
tooling boundary, not glossed over: Scenario A is **partially executed**
(session creation through) rather than claimed complete.

The full webhook-confirmed activation chain (the other half of Scenario
A) is instead proven by `CheckoutToActivationIntegrationTest` — a new
test that calls the REAL `CheckoutSessionService::startCheckout()` (not
a fixture shortcut) against `FakeBillingProvider`, then feeds a
`customer.subscription.created` event carrying the exact metadata a real
Checkout-created subscription would produce through `WebhookEventProcessor`,
confirming exactly one activation and exactly one entitlement snapshot,
plus duplicate-replay idempotency and "redirect alone never activates."

## Cleanup performed

The synthetic test Organisation/User/Subscription/BillingCustomer created
for real Sandbox validation were deleted after use (this session's own
disposable fixtures — see Stage 13/Cleanup in the report). One real
Stripe Customer and one real (uncompleted, will auto-expire) Checkout
Session remain in the Sandbox account — harmless Test Mode artifacts;
`BillingProviderInterface` deliberately has no delete capability for
either object type (confirmed by an existing test), consistent with this
architecture never deleting provider objects.

## Tests added

`CheckoutControllerTest` (11 — auth, authorization boundary via
organisation scoping, approved-plan creation, no provider fields leaked,
missing-mapping rejection, unknown plan, unsupported interval, active-
subscription conflict, cancelled-subscription non-blocking, duplicate-
request reuse, caller cannot override URLs, raw provider ID ignored),
`CheckoutToActivationIntegrationTest` (3 — full checkout-to-activation-
to-snapshot chain, duplicate webhook replay, redirect-alone-never-
activates). Full Billing+Pricing+Entitlements filter: 531/532 (the one
failure is the same pre-existing, unrelated `DeploymentQueueConfigurationTest`
container-path issue). Full backend suite could not run to completion in
this environment — a pre-existing, unrelated `FileSecurityServiceTest`
zip-bomb test exhausts the CLI's 128M memory limit; every test before it
(531+ across Billing/Pricing/Entitlements, plus the full webhook/
lifecycle/snapshot filter at 380/380) passed cleanly.

## Frontend verification

`eslint` clean on every new/touched file. `next build`'s TypeScript step
clean (page count 58→60, confirming both new checkout routes registered).
Production build itself still fails at the same pre-existing,
unrelated `/_global-error` prerender step (confirmed identical on
baseline in Slice B). No frontend test infrastructure exists in this
repository (unchanged from Slice B) — manual verification was via direct
HTTP calls against the real backend (see Real Sandbox execution above),
not a browser session against the frontend itself in this environment.

## Documentation updated (Slice C2)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md`, `project-context.md`, `docs/settings/billing.md` (Checkout
availability). No public release notes — this capability is not yet
approved for a public release (Scenario A is not fully executed end to
end with a real payment).

## Remaining gaps before Slice D (upgrade/downgrade/cancellation/Portal)

Unchanged in scope from Slice B/C1's own notes — no upgrade/downgrade/
cancellation/Portal action exists yet, deliberately, per this
checkpoint's explicit exclusions. Additionally now known: real hosted-
page payment completion requires either a browser-automation tool being
added to this environment, or accepting mocked-activation coverage
(already comprehensive) as sufficient proof for this kind of scenario
going forward.

## Remaining operational risk

The `docker-compose.prod.yml` network/live-stack mismatch (found in C1,
unresolved, out of Billing's scope) remains. The real Stripe Customer
and Checkout Session created during this checkpoint's Sandbox validation
remain in the account (harmless, will auto-expire) — noted for whoever
next audits the Sandbox account's object inventory.

---

# Slice D — Existing Subscription Upgrades, Scheduled Downgrades & Pending Plan-Change Management

Everything downstream of "decide whether this is an upgrade or a
downgrade" already existed from checkpoint 16 —
`SubscriptionPlanChangeService` (request/send/confirm state machine,
supersede support, cancellation), the webhook confirmation path
(`WebhookEventProcessor::reconcilePlanChangeIfPending()`, unknown-Price
drift detection), snapshot creation
(`SubscriptionLifecycleService::applyConfirmedPlanChange()`), and
reconciliation (`StripeReconciliationService::reconcilePrice()`, already
plan-change-aware). This checkpoint's real contribution is the missing
classification layer, the controller wiring it to existing services, one
safety fix, and full real Stripe Sandbox validation of the upgrade/
downgrade/cancel paths.

## What was built

- `App\Support\Billing\PlanChangeClassifier` / `PlanChangeClassification`
  (new, pure, stateless) — the classification step that decides which of
  `SubscriptionPlanChangeService::requestUpgrade()`/`requestDowngrade()`
  to call, since that service takes the caller's word for which one
  applies (nothing decided this before). Ranks by `PricingPlan::$order` —
  the same field already used everywhere else in Pricing/Billing to
  sequence Essential/Professional/Enterprise. Same plan + same interval →
  `NO_CHANGE`; same plan + different interval →
  `AMBIGUOUS_INTERVAL_CHANGE` (Stage 4 explicitly forbids inventing a
  financial policy for interval-only changes — no approved rule exists in
  this codebase, confirmed by searching the commercial strategy/
  entitlement spec docs, so this stops that one sub-path rather than
  guessing); otherwise `UPGRADE`/`DOWNGRADE` by plan order, independent of
  any interval change riding along with a genuine plan change.
- `App\Http\Controllers\Api\PlanChangeController` — `store()` (`POST
  /billing/plan-change`) resolves the target plan/mapping/classification
  server-side, checks for an identical-already-pending request (returns
  it unchanged rather than duplicating), then calls `requestUpgrade()`
  immediately followed by `send()` for an upgrade (matching
  `send()`'s own documented "immediate upgrades call this synchronously"
  contract), or `requestDowngrade()` alone for a downgrade (left
  `REQUESTED` — sent later by the existing
  `billing:subscriptions:process-automation` schedule when
  `requested_effective_at` is due). `cancel()` (`POST
  /billing/plan-change/{id}/cancel`) verifies the route-bound plan change
  is still the subscription's CURRENT pending one (not a stale/superseded
  ID) before calling `cancelPending()`.
- `StorePlanChangeRequest` — `plan_code` + `billing_interval` only. No
  Stripe Price/Product/subscription-item ID, no amount, no currency, no
  effective date, no proration parameter, no billing-cycle anchor.
- `TransitionSource::CUSTOMER_BILLING_ACTION` (new constant) — the
  existing vocabulary had no entry for "an authenticated organisation
  member's own self-service Billing action" (`CHECKOUT` names the
  first-subscription Checkout flow specifically, not customer self-
  service in general) — used for both the plan-change request and its
  cancellation.
- Frontend: `useRequestPlanChange()`/`useCancelPlanChange()` hooks,
  `PlanComparisonSection` wired for the already-subscribed case (upgrade/
  downgrade buttons open `PlanChangeConfirmDialog` — a new, small
  confirmation dialog explaining the exact commercial consequence per
  Stage 11 — before submitting), and `PendingPlanChangeCard` gained a
  Cancel action (only shown while `state === 'requested'`, matching the
  backend's own cancellation-eligibility rule) plus a note that selecting
  a different plan below transparently replaces (supersedes) the pending
  one — no separate "Replace" UI was built; the existing plan-comparison
  section already does this via the same endpoint.

## One real safety fix: `cancelPending()` state guard

`SubscriptionPlanChangeService::cancelPending()` had no check on the
pending row's own state before cancelling it locally — a `SENT` or
`CONFIRMED` row (meaning `updateSubscriptionPrice()` already changed the
price at Stripe itself, a direct synchronous write, not a staged one)
could have been marked `CANCELLED` locally while Stripe still reported
the new price, with no webhook ever able to reconcile that self-
contradiction. Fixed: `cancelPending()` now throws
`SubscriptionLifecycleConflictException` for anything other than
`REQUESTED`, both at the service level and surfaced as a 409
(`no_longer_cancellable`) from the controller. New tests at both layers.

## Commercial classification (exact)

| Current → Target | Same interval | Different interval |
|---|---|---|
| Same plan | `NO_CHANGE` (422) | `AMBIGUOUS_INTERVAL_CHANGE` (422, stopped, not guessed) |
| Target ranks higher | `UPGRADE` | `UPGRADE` (interval change rides along, not separately classified) |
| Target ranks lower | `DOWNGRADE` | `DOWNGRADE` (ditto) |

Enterprise is never reachable through this endpoint — it has no active
provider mapping (Slice C1), so `PlanPriceMappingService::resolveActivePrice()`
returns `null` and the controller responds `plan_change_unavailable`
before any classification even matters.

## Eligible / ineligible lifecycle states

Unchanged from `SubscriptionPlanChangeService::validateEligibility()`
(already existed, already correct — verified, not modified): `active`
only. `past_due` fails safe (`SubscriptionLifecycleConflictException` —
payment recovery required first). `trialing` throws
`PlanChangeNotSupportedException` (explicitly deferred). Every other
status throws `InvalidSubscriptionTransitionException`. A pending
cancellation (`cancel_at_period_end = true`) always rejects a new
request — verified live in this checkpoint's own test suite.

## Real Stripe Sandbox validation — genuinely complete, no browser needed

Unlike Checkout (Slice C2), plan changes are pure server-to-server API
operations — no browser interaction required at all, so this checkpoint
achieved FULL real execution, not a partial one. Created a real Stripe
Test Mode subscription directly via the Subscriptions API using Stripe's
documented test payment-method token (`pm_card_visa`, attached to a real
test Customer) — a standard, supported pattern for exercising
subscription-lifecycle scenarios without a hosted Checkout page.

- **Scenario D1 (immediate upgrade)**: real `POST /billing/plan-change`
  Essential → Professional. Real Stripe subscription item price updated
  live. Real webhook (`customer.subscription.updated`) arrived and was
  processed within ~1 second (`stripe listen` already running). Local
  plan updated from Essential to Professional, `billing_plan_changes`
  row reached `applied`, exactly 2 entitlement snapshots exist total for
  this subscription (1 `activation` + 1 `upgrade_applied` — both
  legitimate, one per real commercial event, confirmed by direct query).
- **Scenario D3 (period-end downgrade)**: real request, Professional →
  Essential. Confirmed live: `state: requested`, `sent_at: null` (never
  sent to Stripe — waits for the automation tick), local
  `pricing_plan_id` unchanged (still Professional), `pending_pricing_plan_id`
  set to Essential, `plan_change_effective_at` set to the real current
  period end (one month out), real Stripe subscription's Price
  unchanged, snapshot count still 2 (no snapshot from merely scheduling).
- **Scenario D4 (cancel pending downgrade)**: real cancellation of the
  D3 downgrade. Confirmed: `state: cancelled`, `pending_pricing_plan_id`
  cleared, snapshot count still 2, current plan remains Professional.
- **Scenario D2 (duplicate upgrade)**: not independently observable in
  real time — the real webhook for D1 arrived and applied within ~1
  second, leaving no practical window to submit a genuine duplicate
  before confirmation. Proven instead via `PlanChangeControllerTest::test_identical_repeated_request_returns_the_existing_pending_change`
  (deterministic, same outcome).
- **Scenario D5 (replace a pending downgrade)**: the real 3-plan matrix
  only offers one downgrade direction from Professional (→ Essential) —
  there is no second, distinct lower self-service plan to replace it
  with, and the only higher plan (Enterprise) has no self-serve mapping,
  so upgrade-supersedes-downgrade couldn't be exercised for real either.
  Exactly the limitation Stage 13 anticipated — exercised instead via
  `PlanChangeControllerTest::test_new_downgrade_supersedes_a_pending_one`
  (fake 4-tier scenario: Enterprise subscriber → request Professional →
  request Essential, first superseded) and the pre-existing
  `SubscriptionPlanChangeServiceTest::test_replacing_a_pending_downgrade_with_an_upgrade_marks_the_old_one_superseded`.
- **Scenario D6 (unknown provider Price)**: not exercised against the
  real subscription (deliberately — would require corrupting its actual
  state for no benefit over existing coverage). Already comprehensively
  covered by the pre-existing (checkpoint 16) `PlanChangeWebhookReconciliationTest`
  and `WebhookEventProcessorTest`, which directly test
  `reconcilePlanChangeIfPending()`'s drift detection — confirmed still
  passing, unmodified.
- **Scenario D7 (incomplete-payment upgrade)**: not executed — forcing a
  real decline on a proration invoice needs a second dedicated test card/
  customer setup beyond this checkpoint's scope. The underlying guarantee
  (local plan never changes until `confirmFromProvider()` runs) is
  structural, not event-specific — already proven by D1/D3's observed
  behaviour and unrelated to which webhook does or doesn't arrive.

**Bonus, unplanned, real confirmation**: cleaning up the real test
subscription (`DELETE` on the Stripe subscription) fired a genuine
`customer.subscription.deleted` webhook, which was correctly received,
verified, and processed — confirming subscription cancellation's webhook
path also works end-to-end, though implementing/exposing cancellation
itself remains explicitly out of this slice's scope.

## Cleanup performed

Real Stripe subscription cancelled (`DELETE /v1/subscriptions/...`) after
validation. Synthetic Organisation/User/Subscription/BillingCustomer/
BillingPlanChange/SubscriptionEntitlementSnapshot rows deleted. The real
Stripe test Customer and its attached test payment method remain in the
Sandbox account (harmless, no delete capability exists for either by
design).

## Tests added

`PlanChangeControllerTest` (13), `PlanChangeClassifierTest` (5, unit),
plus one new test on `SubscriptionPlanChangeServiceTest`
(`cancel_pending_after_send_is_rejected`, the safety fix). Full
Billing+Pricing+Entitlements filter: 545/546 (same pre-existing,
unrelated `DeploymentQueueConfigurationTest` failure). Unit
Billing+Entitlements filter: 118/118. A combined full-suite run hit a
pre-existing, unrelated memory-exhaustion issue in an unrelated Stripe
SDK normalization test when run alongside the full test list — confirmed
unrelated by running the relevant filters separately, both clean.

## Frontend verification

`eslint` clean on every new/touched file. `next build`'s TypeScript step
clean (page count unchanged at 60 — no new routes this slice, only
existing components updated). Production build itself still fails at the
same pre-existing, unrelated `/_global-error` prerender step. No frontend
test infrastructure exists (unchanged) — verification was via the real
HTTP scenarios above plus code review.

## Documentation updated (Slice D)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md`, `project-context.md`, `docs/settings/billing.md` (upgrade/
downgrade availability, pending-change cancellation). No public release
notes — not approved for a public release (Checkout's own Scenario A
still lacks a real completed-payment run, per Slice C2's carried-forward
gap).

## Remaining gaps before Slice E (cancellation / Customer Portal)

No subscription cancellation or Customer Portal action exists yet,
deliberately, per this checkpoint's explicit exclusions. No dedicated
billing-admin role exists — any organisation member can request a plan
change, matching every prior slice's documented governance gap, not
newly introduced here.

---

# Billing Architecture Audit + Slice E1 — Subscription Cancellation

## Part 1 — Integrated Architecture Audit

Reviewed every service/controller/model listed in the checkpoint brief
across Slices A–D as one system. Findings below, classified and each
marked fixed-now or deferred per the checkpoint's own "audit rule"
(contained fixes only, no migration, no delay to cancellation).

### Critical

**F1 — `BillingProviderInterface::cancelSubscription($id, atPeriodEnd:
false)` silently means immediate, irreversible Stripe deletion, not
"undo a scheduled cancellation."** A future maintainer wiring up
"resume" against this method's `false` branch would genuinely cancel the
customer's subscription outright. Confirmed unused by any domain service
(only exercised by its own unit test) — no live bug today, but a real
trap.
**Fixed now**: added a loud warning docblock to the interface method,
and two new, unambiguous methods (`scheduleCancellationAtPeriodEnd()`/
`resumeSubscription()`, both always plain updates, never a delete) that
Slice E1 uses instead. `cancelSubscription()` itself untouched (still
architecture-only, reserved for a possible future immediate-cancellation
feature).

### High

**F2 — `StripeReconciliationService` had no `cancel_at_period_end`
check at all.** A missed/failed webhook could leave local cancellation
state permanently disagreeing with Stripe with no detection path.
**Fixed now** (small, additive, no migration): new
`ReconciliationFinding::CANCELLATION_STATE_MISMATCH`, never
auto-corrected, matching the existing conservative pattern exactly. New
test.

**F3 — The webhook pure-refresh path (`processSubscriptionUpdated`)
already synced `cancel_at_period_end` correctly, but reported a generic
`subscription_provider_state_recorded` action for it** — functionally
complete but operationally opaque (an operator scanning webhook
processing logs couldn't distinguish "a cancellation was just confirmed"
from "period dates refreshed for no interesting reason").
**Fixed now**: the pure-refresh branch now detects a genuine
before/after change and returns
`subscription_cancellation_confirmed_by_provider`/
`subscription_cancellation_undo_confirmed_by_provider` instead — purely
additive to the processing-result label, zero change to what actually
gets synced or when. Existing test's assertion updated to the new,
correct label; two new tests added (undo direction; "no change" still
returns the generic label).

### Medium

**F4 — No dedicated cancellation-workflow service existed**, unlike
Checkout/plan-changes (`CheckoutSessionService`/`SubscriptionPlanChangeService`).
Building the controller directly against `SubscriptionLifecycleService`
would have duplicated eligibility/conflict/idempotency logic inline.
**Fixed now**: new `SubscriptionCancellationService` — deliberately thin
(no dedicated tracking table; `subscriptions.cancel_at_period_end` +
`current_period_ends_at` already fully represent pending cancellation,
confirmed sufficient during this audit, so no migration was needed at
all for Stage 7).

**F5 — No governance for who may request cancellation** — same
documented gap as every prior slice (no dedicated billing-admin role).
**Deferred** — explicitly out of this checkpoint's scope per the brief;
tracked in every slice's own "remaining gaps" section since Slice A.

### Low / Informational

**F6 — `BillingProviderInterface::retrieveSubscription()` and
`cancelSubscription()` are the only two provider methods with zero call
sites in any domain service** (confirmed via `grep` across `app/`)
— pure architecture-ahead-of-need, consistent with this codebase's
established pattern (e.g. `BillingPortalService` before it had a
caller). Not dead code in the sense of "should be deleted" — genuinely
reserved for a documented future feature (Customer Portal, immediate
cancellation). **No action** — informational only.

**F7 — Naming consistency check**: `TransitionSource` had no entry for
"an authenticated organisation member's own self-service action" before
Slice D added `CUSTOMER_BILLING_ACTION` — confirmed this checkpoint
reuses it correctly for cancellation rather than inventing a second,
overlapping constant. **No action** — already correct.

**F8 — Idempotency key patterns are NOT uniform across services**
(`PlanPriceMappingService` keys off explicit business parameters;
`SubscriptionPlanChangeService` keys off the `BillingPlanChange` row's
own auto-increment ID; this checkpoint's `SubscriptionCancellationService`
keys off `subscription->updated_at`'s timestamp, since no tracking row
exists to key off instead). Each is locally sound for its own service's
shape, but a future engineer skimming all three could reasonably wonder
if there's a missing shared abstraction. **Deferred, recommended future
checkpoint**: if a fourth commercial workflow needs its own idempotency
scheme, consider extracting a small shared helper documenting the "key
off whatever changes only when the underlying commercial state
genuinely changes" principle common to all three, rather than each
service re-deriving it independently. Not done now — would be
speculative abstraction for a problem that doesn't exist yet with only
three instances.

**F9 — No FeatureGate/Customer-Portal-bypass risk found.** Reviewed
specifically for "places where Customer Portal could later bypass
SureSign domain rules" (the brief's own concern) — `BillingPortalService`
already documents that Portal plan-change/cancellation capabilities must
stay disabled in Stripe's own Dashboard configuration, which this
codebase cannot verify from application code. This remains a real,
already-documented operational dependency (not a code gap) — reconfirmed
still accurate, no new finding.

**No other duplication, dead code, weak scoping, or stale documentation
found.** Controllers reviewed (`CheckoutController`, `PlanChangeController`,
`BillingController`) all remain thin and delegate correctly; every
lifecycle/plan mutation goes through `SubscriptionLifecycleService`/
`SubscriptionPlanChangeService`, confirmed by `grep`-ing for direct
`->status =`/`->pricing_plan_id =` assignments outside those two classes
(none found outside migrations/factories/tests). Organisation scoping is
consistent everywhere (derived from the authenticated user, never a
request parameter). No speculative refactoring was performed beyond F1–F3
above.

## Part 2 — Slice E1: Subscription Cancellation

Confirms the audit's F4 finding: everything below Stage 6 (provider
scheduling) already existed and required zero changes —
`SubscriptionLifecycleService::scheduleCancellation()`/`confirmCancellation()`/
`cancelImmediately()`/`expire()`, `SubscriptionAutomationService::processScheduledCancellations()`
(the existing hourly automation that already calls `confirmCancellation()`
once `current_period_ends_at` has passed), and both
`customer.subscription.updated` (status-unchanged pure refresh) and
`customer.subscription.deleted` (`processSubscriptionDeleted`, already
distinguishing "confirms a schedule" from "cancels immediately") were
already built, tested, and correct from prior checkpoints. This slice's
real contribution: the missing "undo" local method, the two correctly-
named provider methods (see audit F1), the `SubscriptionCancellationService`
orchestration layer, the controller/routes, one `can_resume_cancellation`
presenter field, the reconciliation check (audit F2), the clearer webhook
audit labelling (audit F3), and full real Stripe Sandbox validation.

### What was built

- `SubscriptionLifecycleService::cancelScheduledCancellation()` — mirrors
  `cancelScheduledSuspension()`/`cancelScheduledPlanChange()`'s exact
  shape (idempotent no-op if nothing pending, no status change, no
  snapshot).
- `BillingProviderInterface::scheduleCancellationAtPeriodEnd()`/
  `resumeSubscription()` — both plain `subscriptions->update()` calls
  with an idempotency key, implemented in `StripeBillingProvider` and
  `FakeBillingProvider`.
- `SubscriptionCancellationService` (new) — `requestCancellation()`/
  `resumeCancellation()`. Provider-call-then-local-write ordering
  (opposite of `SubscriptionPlanChangeService`'s request-then-send
  split) — since `cancel_at_period_end` is a synchronous, immediately-
  effective write with no separate "confirm later" step needed at
  request time, calling the provider FIRST means a failed provider call
  leaves local state completely untouched. Idempotency key:
  `"cancel-{action}:{subscription->id}:{subscription->updated_at->timestamp}"`
  — stable across retries of the same attempt (row hasn't saved yet),
  naturally distinct for the next genuinely new request (which can only
  happen after the previous one committed and changed `updated_at`) —
  avoiding Stripe returning a stale 24h-cached response for a later,
  different request.
- `App\Http\Controllers\Api\SubscriptionCancellationController` —
  `POST /billing/subscription/cancel` / `/resume`, both accept an empty
  body. Catches `SubscriptionLifecycleConflictException` (409) and
  `Stripe\Exception\ApiErrorException` (sanitised 502, real detail
  logged server-side only).
- `BillingOverviewService` — added `can_resume_cancellation` (true only
  while `cancel_at_period_end` AND still `active`).
- Frontend: `useCancelSubscription()`/`useResumeSubscription()` hooks,
  `CancelSubscriptionConfirmDialog` (danger-styled, not visually
  equivalent to a primary action, no dark patterns — states plainly what
  happens and when), `SubscriptionSummaryCard` gained the Cancel action
  (shown only when active, not already pending, and no pending plan
  change) and an inline Undo action on the existing pending-cancellation
  banner. Also fixed a pre-existing em dash in that banner's copy
  (user-facing-copy convention: no em/en dashes outside numeric/date
  ranges) while editing the same lines.

### Commercial policy (exact, confirmed unchanged from pre-existing services)

Cancellation is ALWAYS period-end (`scheduleCancellation()`'s own
`InvalidSubscriptionTransitionException` requires `active`; there is no
"immediate" path in `SubscriptionCancellationService` at all —
`cancelImmediately()` remains a separate, distinct method this slice
never calls). Eligible state: `active` only. Trialing was explicitly
reviewed — no existing policy supports self-service trial cancellation,
so it is NOT supported (falls through to the generic "not active"
rejection) rather than inventing one. A pending plan change always
rejects a new cancellation request (Stage 9); a pending cancellation
always rejects a new plan-change request (pre-existing from Slice D,
reconfirmed still enforced).

## Documentation updated (this checkpoint)

`internal-docs/super-admin/subscription-billing.md` (this section),
`CLAUDE.md`, `project-context.md`, `docs/settings/billing.md`
(cancellation/undo availability). No release notes — Checkout's own
Scenario A still lacks a real completed-payment run (carried forward
from Slice C2), so nothing customer-facing in Billing is approved for
public release yet.

## Remaining gaps before Slice E2 (Customer Portal / payment methods)

No Customer Portal session, payment-method management, or payment
recovery exists yet, deliberately. No dedicated billing-admin role
exists (unchanged governance gap, documented every slice since A).
Effective-boundary termination (Scenario E1-F) was validated via
existing, unmodified automated coverage rather than a real month-long
wait or a Stripe Test Clock — documented precisely as that boundary,
not glossed over.

## Slice E2 — restricted Customer Portal, VAT-exclusive pricing wording

Delivered: `POST /billing/portal` (empty body, Organisation-scoped,
never accepts a Stripe Customer/Configuration ID or return URL);
programmatic restricted Portal Configuration creation/discovery/drift
verification (see "Customer Portal abstraction" above);
`billing:portal:verify-configuration` operational command; frontend
"Manage payment methods & invoices" action on the Billing page (shown
only when the Organisation has a `BillingCustomer`), explicitly worded
to say plan changes/cancellation stay in SureSign. Verified against a
real Stripe Test Mode account — see above for the exact result.

VAT (separate, small commercial requirement bundled into this slice):
`pricing_plans.monthly_price`/`annual_price` remain the VAT-EXCLUSIVE
commercial price — no code change to that; Stripe alone calculates tax
during Checkout, exactly as before. Every customer-facing self-serve
price surface now says so explicitly: `pricing_plans.price_suffix`
(marketing Pricing page, via `PricingCards`) — seeded value changed from
`/month` to `/month + VAT`, plus a one-off data migration
(`2026_08_05_000001_update_pricing_plan_vat_exclusive_suffix`) updating
any existing row still carrying the exact old default (never overwrites
an operator-customised suffix); the Billing page's self-serve plan cards
(`PlanComparisonSection`) now render `/month + VAT`/`/year + VAT`; and
the current-subscription summary's interval suffix
(`billingIntervalSuffix()` in `frontend/src/lib/billingStatus.ts`) now
renders `/month + VAT`/`/year + VAT`. Invoice/payment history rows were
deliberately left unchanged — those are settled, already-tax-inclusive
Stripe amounts, not a projected plan price, so "+ VAT" wording does not
apply there. The Organisation's existing `vat_number` field is untouched
and remains available for a future Stripe Tax integration, exactly as
before.

**Remaining gaps before Slice F**: no dedicated billing-admin role exists
(unchanged governance gap, documented every slice since A); no real
Stripe Test Mode invoice exists yet to visually verify the Portal's
invoice-history list against (no subscription/Checkout was run in this
slice — creating one was out of scope); the Portal action's real Stripe-
hosted page was never opened in a browser (validated instead via direct
Stripe API calls proving the exact same configuration/session a browser
click would use — see the scenario results above); Test/Live Portal
Configuration isolation was verified by code review + the cache key
being livemode-scoped, not by ever running against a Live Mode account
(explicitly out of scope, per this slice's own stop conditions).

## Phase E3 — Commercial & Tax Readiness Review

A review-only phase (Slices A–E2 considered feature-complete for V1) —
verifying commercial/tax/finance consistency across the whole Billing
surface before Production Readiness, not adding functionality.

**Stage 1 (pricing consistency)**: confirmed structurally sound — both the
marketing Pricing page and the Billing page's self-serve plan cards read
plan name/price/currency from the same `pricing_plans` +
`pricing_plan_provider_prices` rows (via `BillingOverviewService`/
`BillingPresenter::purchasablePlan()`), so there is one source of truth,
not two independently-maintained copies. **One low-risk finding, left
unfixed**: VAT-exclusive wording exists in two independent places —
`pricing_plans.price_suffix` (admin-editable, drives the marketing page)
and a literal `+ VAT` string in `PlanComparisonSection.tsx` (drives the
Billing page's self-serve cards, which correctly vary `/month` vs `/year`
by the selected interval — something `price_suffix` alone cannot do, since
it's a single static string). Unifying them would require either
sacrificing that correct interval differentiation or adding new
config/data plumbing; both are disproportionate to this review. Both
currently agree ("+ VAT"); flagged for awareness, not fixed.

**Stage 2 (VAT)**: confirmed clean. `pricing_plans` values remain
VAT-exclusive (no code touches them to add tax). Searched `frontend/src`,
`marketing/src`, and all of `backend/app` for a hardcoded VAT rate/
calculation in the Subscription & Billing module — none exists; Stripe
alone calculates tax at Checkout. **Found, correctly out of scope**:
`PaymentApplication.vat_rate`/`vat_amount` (defaulting to 20%) is a
real, user-editable field for UK construction payment-application VAT — a
completely different business domain (a subcontractor's payment
application to a contractor) from SureSign's own SaaS subscription
billing. Not touched; conflating the two would be a genuine scope error.

**Stage 3 (Organisation billing fields)**: all fields the review asked to
confirm already exist on `Organization` — `vat_number`, `name`,
`address`/`city`/`state`/`postcode`/`country`, `email` (serves as billing
email), `phone`, plus `registration_number`. Nothing missing that's
realistically required before production; no new field added
(speculative additions explicitly avoided per this phase's brief).

**Stage 4 (Stripe Customer sync — direction now explicitly documented)**:
outbound, `BillingCustomerService::syncOrganizationDetails()` pushes only
`name`/`email` from `Organization` → Stripe Customer, one-directional,
and only pushes a field that has actually changed (never overwrites with
null). Address/phone/`vat_number` are NEVER pushed outbound. Inbound: a
customer CAN edit address/phone/tax ID directly on the Stripe Customer
via the Slice E2 restricted Portal (`customer_update` capability) — but
no webhook consumes `customer.updated`, so none of that flows back into
`Organization`/`BillingCustomer`. This was a real gap in explicit
documentation (not previously written down), now fixed by documenting it
here and in `CLAUDE.md`'s `BillingCustomerService` row. Per this phase's
explicit instruction ("do not introduce bidirectional synchronization
unless it already exists architecturally"), no `customer.updated` handler
was added — this is recorded as a known consequence and a candidate for a
future, deliberately-scoped checkpoint, not silently left undocumented.

**Stage 5 (Invoices) — one genuine fix applied**: `BillingInvoice`'s
`invoice_number` was, and remains, SureSign's own internal correlation
reference (`INV-000001` via `BillingReferenceService`) — it was never
Stripe's own invoice number, the one actually printed on the hosted
invoice page/PDF a customer or accountant sees. The frontend showed only
`invoice_number` under a generic "Invoice" column header, which could
read as if it were the same number that appears on the Stripe document —
a genuine finance-reconciliation risk. Fixed: added
`billing_invoices.provider_invoice_number` (additive migration,
`2026_08_06_000001`), populated as a pure passthrough of Stripe's own
`invoice.number` in `StripeBillingProvider`/`FakeBillingProvider`'s
`normalizeInvoiceFromWebhookPayload()` and persisted by
`InvoiceSyncService` — never generated locally. Exposed via
`BillingPresenter::invoice()` and shown in `InvoiceListSection` under a
renamed "Reference" column, with Stripe's own number shown distinctly
underneath when present. Confirmed: no duplicate invoice SOURCE was
introduced — `InvoiceSyncService` remains the sole writer, still driven
only by verified `invoice.paid`/`invoice.payment_failed` webhooks; this
is an additional passthrough field on the existing single writer, not a
second invoicing system.

**Stage 6 (Checkout)**: re-confirmed, unchanged since Slice C2/D — no
local price calculation (Stripe Price ID resolved via
`PlanPriceMappingService` only), no duplicate subscriptions
(`SubscriptionLifecycleService::hasConflictingSubscription()`), duplicate
Checkout protection via `CheckoutSessionService::findReusableCheckoutForPlan()`.

**Stage 7 (Portal)**: re-confirmed, unchanged since Slice E2 — restricted
configuration still enforces `payment_method_update`/`invoice_history`/
`customer_update` (address/phone/tax_id only) enabled and
`subscription_cancel`/`subscription_update` disabled, re-verified live on
every session creation. All 17 `BillingPortalServiceTest`/
`BillingPortalControllerTest` cases still pass unchanged.

**Stage 8 (Currency)**: confirmed clean. Billing's own currency handling
(`pricing_plans.currency`, Stripe Price currency, `BillingCustomer`/
`Subscription`/`BillingInvoice`.currency) is deliberately separate from
`App\Services\CurrencyService` — that service resolves a construction
**project's** contract currency (project → organisation → platform →
GBP), an unrelated concept to what SureSign charges an organisation for
its own subscription. Frontend `formatMoney()`/`currencySymbol()` (in
`frontend/src/lib/currency.ts`) is used consistently across every Billing
component — no hardcoded `£` found anywhere in Billing/pricing frontend
surfaces. `BILLING_DEFAULT_CURRENCY=GBP` is a configured, override-able
commercial default (matching the current UK-only market), never a
hardcoded literal in Billing service/controller code.

**Stage 10 (finance-readiness lifecycle walkthrough)**: every step in the
lifecycle (plan choice → Checkout → subscription created → invoice
generated → VAT calculated → payment succeeds → activation → payment-
method update → invoice history → upgrade → downgrade → scheduled
cancellation → resume → renewal → future cancellation) has exactly one
authoritative source, confirmed step-by-step against the services already
documented above (`CheckoutSessionService` →
`WebhookEventProcessor`/`SubscriptionLifecycleService` → `InvoiceSyncService`
→ Stripe (VAT) → `WebhookEventProcessor` (payment) →
`SubscriptionLifecycleService::activate()` → `BillingPortalService` →
`BillingOverviewService` (invoice history read) →
`SubscriptionPlanChangeService` (both directions) →
`SubscriptionCancellationService` → `SubscriptionAutomationService`
(renewal is a Stripe-side event, reflected via the existing subscription
webhook path, not a local recompute) → `SubscriptionCancellationService`
again). No step was found with two competing authorities.

**Testing**: 2 new `InvoiceWebhookSyncTest` assertions/tests for the new
`provider_invoice_number` field. Full Billing filter: 565/565 before the
fix, unchanged count with new assertions added inline (not new test
files) plus the one new dedicated test — all passing.

**Documentation updated**: this section, `CLAUDE.md` (`BillingCustomerService`
and `InvoiceSyncService` rows).

**Remaining recommendations before Production Readiness**: (1) decide,
deliberately, whether a future checkpoint should sync Portal-driven
address/phone/tax-ID edits back into `Organization` (e.g. for accounting
exports) — currently intentionally one-directional; (2) the VAT-wording
duplication noted in Stage 1 is low-risk but could be revisited if the
wording policy ever needs to change without a frontend deploy; (3) no
dedicated billing-admin role still exists (a governance gap carried
forward from every prior slice); (4) `BillingProviderInterface::retrieveInvoice()`
was found to be dead code (defined, implemented, never called) — noted,
not removed, since removing unused interface surface was outside this
review's scope.

## Phase E4 — Pending Checkout Recovery & Billing UX Completion

Real user testing surfaced a genuine UX gap (not an architecture gap): a
customer who abandoned Stripe Checkout before paying got permanently
stuck — their plan showed as "Current" on the Billing page, every plan
card became disabled, and there was no way to continue, restart, or
cancel the attempt short of administrative intervention.

**Root cause, confirmed by reading the actual code (Stage 1)**:
`BillingOverviewService::availablePlans()` computed its `is_current` flag
from the organisation's most recent subscription regardless of STATUS —
a `pending_payment` subscription (an abandoned Checkout, never
webhook-confirmed) marked its plan current exactly the same as a genuinely
`active` one. Separately, `SubscriptionLifecycleService::hasConflictingSubscription()`
correctly treats `pending_payment` as blocking (by design — a genuine
in-flight Checkout must not let a second one start), but nothing ever
un-blocked it once the underlying Stripe Checkout Session itself expired:
`WebhookEventProcessor::processCheckoutExpired()` already marks the LOCAL
`BillingCheckoutSession` row `expired` on the real `checkout.session.expired`
webhook (unchanged, still correct), but by design leaves the linked
subscription "completely untouched... in its historical draft/pending_payment
state" — so nothing ever moved the SUBSCRIPTION itself out of
`pending_payment`, and it blocked every future Checkout attempt forever.
No customer-facing cancellation path for a `pending_payment` subscription
existed at all — `SubscriptionCancellationController` only ever handles an
already-`active` subscription's scheduled, period-end cancellation, a
different commercial operation entirely.

**Fix 1 — `is_current` (the actual reported bug)**: `availablePlans()` now
only marks a plan current when the subscription's status is genuinely a
real commercial relationship — `trialing`/`active`/`past_due`/`unpaid`/
`paused`/`suspended` — never `draft`/`pending_payment`/`incomplete`
(pre-activation) and never `cancelled`/`expired` (over).

**Fix 2 — self-healing stale Checkout (Stage 5)**: `CheckoutSessionService::startCheckout()`,
when called with no `$correlationReference` (the ONLY way the real
`CheckoutController` ever calls it — the correlationReference path is an
internal idempotency mechanism with its own, separate, tested reuse
guarantee that must never be pre-empted), now checks whether the
organisation's most recent `pending_payment` subscription has any still-
resumable (created/open, unexpired) Checkout Session left. If not, it
calls the existing, unchanged `SubscriptionLifecycleService::expire()` on
it BEFORE proceeding — a `pending_payment -> expired` transition already
valid per `SubscriptionTransitions::MAP`, requiring no new status, no new
transition, no schema change. A still-resumable session is left
completely untouched (no auto-invalidation) — that case still surfaces
the pre-existing `SubscriptionLifecycleConflictException` unchanged, on
purpose (see Stage 8 below).

**Fix 3 — explicit "Cancel Pending Checkout" (Stage 6)**: new
`CheckoutSessionService::cancelPendingCheckout(Subscription, User)`,
exposed via `POST /billing/checkout/cancel-pending`
(`CheckoutController::cancelPending()`, empty body, only valid from
`pending_payment`). Two new pieces of provider surface support this: a
new interface method, `BillingProviderInterface::expireCheckoutSession()`
(implemented in both `StripeBillingProvider`, a real
`checkout.sessions.expire` API call, and `FakeBillingProvider`), called
best-effort (a Stripe API failure is logged but never blocks the local
cancellation — SureSign remains authoritative for its own commercial
state) to close the residual "customer still has the old Checkout tab
open" window. The local `BillingCheckoutSession` is marked `expired`
SYNCHRONOUSLY via the existing `CheckoutSessionLifecycleService::markExpired()`
(cannot wait for the real, still-fired `checkout.session.expired` webhook
that follows — `findReusableCheckoutForPlan()` must stop offering it the
instant this method returns). The subscription itself is then cancelled
immediately via the existing, unchanged
`SubscriptionLifecycleService::cancelImmediately()` — a valid
`pending_payment -> cancelled` transition, freeing the organisation to
start a new Checkout right away without waiting on any webhook.

**Stage 8 decision (documented, as required)**: the auto-invalidation in
Fix 2 applies ONLY to a genuinely expired Checkout attempt — nothing was
ever charged, nothing is lost. For a customer selecting a DIFFERENT plan
while a still-VALID (resumable) Checkout exists, this checkpoint chose
Option A (explicit prompt) over Option B (silent auto-invalidation): the
frontend's `PendingCheckoutConflictDialog` asks the customer to either
continue the existing attempt or explicitly cancel it first, rather than
silently discarding a Stripe Checkout Session the customer might still
complete (e.g. in another browser tab). The backend's existing conflict
exception remains the unchanged safety net if the frontend prompt is ever
bypassed.

**Frontend**: `BillingOverviewService`/`BillingPresenter` expose a new
`pending_checkout` object on the subscription payload (`plan_code`,
`plan_name`, `billing_interval`, `is_resumable`, `expires_at` — never a
provider checkout session ID or URL) whenever status is `pending_payment`.
`SubscriptionSummaryCard` renders a distinct "Pending Checkout" state
(never "Current Subscription") with plain-language copy (no payment
taken, no activation, no access change) and both "Continue Payment"/
"Start New Checkout" and "Cancel pending Checkout" actions.
`PlanComparisonSection` no longer disables every plan card while a
Checkout is pending — the pending plan gets its own "Continue Payment"/
"Start New Checkout" state (badge: "Awaiting Payment", matching the
existing status vocabulary — never a fourth, conflicting term), every
OTHER plan remains genuinely selectable, and selecting a different plan
while a still-resumable Checkout exists shows `PendingCheckoutConflictDialog`
(Stage 8, Option A) instead of either silently blocking or silently
discarding it.

**Testing**: 2 new tests in `CheckoutSessionServiceTest` (scoped the
pre-existing `test_cancel_url_does_not_cancel_the_subscription` structural
guard to `startCheckout()`'s own method body specifically, via
`ReflectionMethod` line ranges, rather than the whole file — Fix 3
legitimately adds a `cancelImmediately()` call elsewhere in the same
class, in an unrelated, explicitly-invoked method never reachable via the
Stripe `cancel_url` redirect the original test protects against; added a
new test confirming exactly one `cancelImmediately()` call site exists and
it's inside `cancelPendingCheckout()`). 4 new tests in `BillingOverviewApiTest`
(the `is_current` fix; `pending_checkout` presence/absence, resumable vs.
expired). 4 new tests extending `CheckoutControllerTest` (self-heal for
the same plan, self-heal for a different plan, still-blocked while
resumable). New `CancelPendingCheckoutControllerTest` (9 tests: guard
conditions, real cancellation + local/provider session expiry,
immediate re-availability, double-cancel safety, organisation isolation,
never touching an active subscription). Full Billing filter: 582/582.
Full backend suite: 1499/1524 — all 25 failures/errors are the same
pre-existing, unrelated local storage-permission issues documented in
every prior slice's report.

**Documentation updated**: this section, `CLAUDE.md` (`CheckoutSessionService`
and `BillingOverviewService` rows), `docs/settings/billing.md`,
`project-context.md`. No release notes (current-version policy).

## Phase E5 — Billing Transition Experience & Customer Journey Polish

Frontend-only UX phase — no backend service, endpoint, migration, webhook,
or lifecycle behaviour changed. Full Billing backend suite re-run
unchanged as a regression check (582/582). The objective: the redirect
into/out of Stripe (Checkout and the Customer Portal) was functionally
correct but visually abrupt — an instant `window.location.href` with no
context — and the return trip gave no visible acknowledgement that
anything had happened.

**New component**: `frontend/src/components/billing/BillingRedirectOverlay.tsx`
— a full-screen, portal-rendered (`document.body`, same reasoning as
`components/ui/Modal.tsx`) branded reassurance screen with two copy
variants (`checkout`/`portal`), shown only while the actual
Checkout/Portal-session-creation mutation is in flight. Its visible
lifetime IS the real network/processing time — no `setTimeout`-based
artificial minimum was added anywhere in this phase, deliberately, per
the brief's own Stage 11. Wired into all three Stripe redirect call
sites: `SubscriptionSummaryCard` ("Continue Payment"/"Start New
Checkout"), `PlanComparisonSection` (plan card subscribe/continue
actions), `BillingPortalCard` ("Manage payment methods & invoices").
`role="status"`/`aria-live="polite"` announces it to screen readers; the
spinner respects `prefers-reduced-motion` via the existing global rule.

**Portal return experience**: the Stripe Customer Portal's `return_url`
is one fixed, server-configured value with no per-request query string
(see `BillingPortalService`), so the frontend cannot distinguish a
genuine Portal return from an ordinary page visit via the URL alone.
Solved client-side only, no backend change: `PORTAL_RETURN_FLAG_KEY`
(`frontend/src/hooks/useBilling.ts`) is a sessionStorage flag set
immediately before the Portal redirect (`BillingPortalCard`) and
consumed exactly once, on mount, by the Billing page — which then shows
a brief "Welcome back — refreshing your billing information…" banner
tied to the real `['billing']` query-key invalidation/refetch, clearing
itself the instant that settles (via the refetch promise's own
`.finally()`, not a polling effect — see the page's own comment on why
this avoids the `react-hooks/set-state-in-effect` anti-pattern the
lint rule catches).

**Checkout success/cancelled pages**: unchanged logic (the existing
webhook-driven overview-polling loop remains the sole source of truth
for "is the subscription active" — nothing here second-guesses it).
Added a purely presentational three-step journey indicator ("Payment
received → Confirming with Stripe → Subscription active") derived from
the exact same state the page already computed, plus consistent icon-chip
treatment matching the rest of the Billing surface (`SubscriptionSummaryCard`,
`BillingRedirectOverlay`).

**Modal focus management** (Stage 8, a real pre-existing gap found during
this review): `components/ui/Modal.tsx` did not move focus into the
dialog on open or trap Tab/Shift+Tab within it. Fixed — focus moves to
the panel on open, returns to the triggering element on close, and a
minimal Tab-cycle keeps keyboard focus from silently escaping to the
dimmed page behind the dialog. This benefits every dialog built on
`Modal` (all four Billing confirm dialogs from Phase E4), not just this
phase's own additions.

**Testing**: frontend lint clean on every file touched, `tsc --noEmit`
clean, production build succeeds. Backend Billing suite re-run
unchanged (582/582) — confirms zero backend impact, as expected for a
frontend-only phase.

**Documentation updated**: this section, `docs/settings/billing.md`,
`project-context.md`. `CLAUDE.md` was not touched this phase — nothing
backend changed, and CLAUDE.md's Billing section is backend-architecture
focused.

**Remaining recommendations before Phase F**: the Modal focus trap is a
minimal hand-rolled implementation (Tab-cycle only) rather than a full
`inert`/focus-trap-library treatment — sufficient for this app's four
simple confirm dialogs, worth revisiting if a future dialog needs richer
interactive content. No dedicated billing-admin role still exists
(carried forward every phase since Slice A).

## Phase E6 — Subscription State Classification & Terminal State Audit

A correctness-only audit, triggered by a real manually-reproduced bug: a
customer who explicitly cancelled a pending Checkout (via
`cancelPendingCheckout()`) subsequently saw their Billing page present it
as "Current Subscription — Cancelled — Ended", with a cancellation
timestamp, and in one path an internal implementation string leaked into
the UI. No architecture was redesigned; every fix is either a new derived
presentation field or a copy fix.

**Root cause (three independent, compounding defects):**

1. **`BillingOverviewService::currentSubscription()`** deliberately
   returns the organisation's most recent subscription row regardless of
   status (needed to show terminal states at all), but that same value
   backed both "what to render as the subscription card" and the
   frontend's "does an existing subscription block Subscribe" gating.
   `SubscriptionLifecycleService::hasConflictingSubscription()` already
   treats `cancelled`/`expired` as non-blocking by design (see its own
   docblock), so the backend never actually blocked a fresh Checkout —
   but the frontend's `PlanComparisonSection` gated every plan button on
   `overview.has_subscription` (true for any row, including a terminal
   one), disabling the entire Plans grid until this phase's fix.
2. **No field distinguished "cancelled after genuinely going live" from
   "cancelled/expired before any activation".** `Subscription::$activated_at`
   existed (set only by `SubscriptionLifecycleService::activate()`) but
   `cancelPendingCheckout()`/`cancelImmediately()` never consulted it,
   `BillingPresenter::subscription()` never exposed it, and
   `SubscriptionSummaryCard`/`billingStatus.ts` rendered `status ===
   'cancelled'` identically either way — same "Current Subscription"
   title, same "Cancelled"/"Ended" fields with real timestamps.
3. **`SubscriptionAccessPolicy::resolve()`'s `CANCELLED` reason string
   literally named the implementing class and methods** ("...its
   effective date has already passed by construction (see
   SubscriptionLifecycleService::confirmCancellation()/cancelImmediately()).")
   — rendered verbatim to the customer by `AccessStatusBanner`, which
   trusts this string as already customer-safe. This is the literal
   "internal implementation message" from the reported bug. The same
   sweep found every Billing controller (`CheckoutController`,
   `PlanChangeController`, `SubscriptionCancellationController`,
   `BillingPortalController`) echoing raw `$e->getMessage()` from
   `SubscriptionLifecycleConflictException`/`PlanChangeNotSupportedException`
   straight into JSON responses — messages written for logs (organisation
   ids, plan-change ids/states, "commercially conflicting", etc.), not
   customers.

**Subscription state matrix** (commercial meaning / ever paid / grants
access / can be "current" / plan cards / Checkout resume-restart /
cancellation wording):

| Status | Commercial meaning | Ever activated? | Access mode | "Current Subscription"? | Blocks new Checkout? | Cancellation wording |
|---|---|---|---|---|---|---|
| `draft` | Pre-Checkout placeholder | No | NONE | No | Only if it has an open checkout session | n/a |
| `pending_payment` | Checkout in progress | No | NONE | Shown as "Pending Checkout", not "Current Subscription" | Yes | n/a |
| `incomplete` | First payment (SCA) unresolved | No | NONE | No | Yes | n/a |
| `trialing` | Dedicated trial profile | No (but had real access) | TRIAL | Yes | Yes | n/a |
| `active` | Live paid subscription | Yes | FULL/GRACE-adjacent | Yes | Yes | "Cancellation scheduled" (if `cancel_at_period_end`) |
| `past_due` | Payment recovery window | Yes | GRACE (or RESTRICTED past grace deadline) | Yes | Yes | n/a |
| `unpaid` | Payment recovery exhausted | Yes | RESTRICTED | Yes | Yes | n/a |
| `paused` | Unreachable in practice (policy: `conflict`) | Yes | NONE (fails safe) | Yes | Yes | n/a |
| `suspended` | Manual operator action | Yes | RESTRICTED | Yes | Yes | "Suspended" |
| `cancelled` (`activated_at` set) | Real commercial cancellation | Yes | RESTRICTED | Yes | **No** | "Cancelled" |
| `cancelled` (`activated_at` null, `trial_ends_at` null) | **Abandoned Checkout** | No | RESTRICTED (access unaffected either way — org never had access) | **No — presented as "Checkout Cancelled"** | **No** | "Checkout Cancelled", never "Cancelled"/"Ended" |
| `expired` (`activated_at` set or `trial_ends_at` set) | Lapsed after real activation/trial | Yes | RESTRICTED | Yes | No | "Expired" |
| `expired` (`activated_at` null, `trial_ends_at` null) | **Abandoned/self-healed Checkout** | No | RESTRICTED | **No** | No | "Checkout Cancelled" |

**Fixes applied:**

- `App\Support\Billing\SubscriptionStatus::BLOCKS_NEW_CHECKOUT` /
  `blocksNewCheckout()` — the shared, single-source list of statuses that
  block a fresh Checkout, extracted from
  `SubscriptionLifecycleService::hasConflictingSubscription()`'s
  previously-inline `$alwaysConflicting` array (that method now consumes
  the same constant — behaviour-preserving, deduplicated).
- `BillingOverviewService`: new `overview()` field
  `can_start_new_checkout` (organisation may start Checkout right now —
  never conflate with `has_subscription`, which only means "a row
  exists"), and new `presentSubscription()` field
  `is_abandoned_checkout` via the new private `isAbandonedCheckout()`
  (`cancelled`/`expired` AND `activated_at === null` AND `trial_ends_at
  === null` — the trial exclusion matters because `startTrial()` never
  sets `activated_at`, so a cancelled/expired trial that never converted
  must still read as "had real access", not "abandoned Checkout").
- `BillingPresenter::subscription()` now exposes `activated_at` — the one
  reliable signal for the above, never inferred from status/timestamps.
- Frontend (`useBilling.ts`, `billing/page.tsx`,
  `checkout/cancelled/page.tsx`): `PlanComparisonSection`'s
  `hasSubscription` prop is now driven by `!can_start_new_checkout`, not
  `has_subscription` — the Plans grid is immediately usable again after
  any cancelled/expired subscription, abandoned or real.
  `SubscriptionSummaryCard` is no longer rendered at all for an abandoned
  Checkout; a distinct, neutral notice ("Your previous Checkout was
  cancelled before payment was taken... choose a plan below") replaces
  it, matching the existing "no subscription yet" empty state's tone
  rather than the "Cancelled"/red-adjacent styling a real cancellation
  uses.
- `SubscriptionAccessPolicy::resolve()`: `cancelled`/`paused`/
  unrecognised-status reason strings rewritten to remove all
  class/method names and internal phrasing — the `cancelled` reason is
  now simply "This subscription has been cancelled." The access
  **mode** decision itself (RESTRICTED vs NONE) was deliberately left
  unchanged — see Remaining recommendations below.
- `CheckoutController::cancelPending()`, `PlanChangeController::store()`/`cancel()`,
  `SubscriptionCancellationController::handle()`, `BillingPortalController::store()`:
  every raw `$e->getMessage()` response replaced with a fixed,
  customer-safe message; the real exception message is now
  `Log::warning()`'d with organisation/subscription/plan-change ids for
  operator diagnosis instead.

**Entitlements** (Stage 8): confirmed unchanged. No code in
`FeatureGate`/`SubscriptionAccessPolicy`/`EntitlementSnapshotService` was
touched beyond the two reason-string rewrites above — the RESTRICTED vs
NONE **mode** decision for a cancelled subscription is untouched, so
real entitlement resolution behaves exactly as before. A
`pending_payment`/abandoned-`cancelled` subscription already resolved to
NONE/RESTRICTED (no access) before this phase; it still does.

**Historical records** (Stage 9): the underlying `subscriptions` row is
never hidden, deleted, or altered by this phase — `is_abandoned_checkout`
is a presentation-only classification computed at read time. A
dedicated "subscription history" list view (showing every past
row, not just the latest) still does not exist; only the single latest
row is ever surfaced via `/billing/overview`. Out of scope for a
correctness-only audit — noted as a remaining recommendation.

**Webhooks**: untouched. `WebhookEventProcessor` remains the sole
authoritative path for provider-reported transitions; nothing in this
phase changed what a webhook is allowed to do.

**No Live Mode operation occurred** — every test in this phase runs
against the existing Test Mode-scoped Feature test suite
(`FakeBillingProvider`), no real Stripe API call was made.

**Testing**: expanded `CancelPendingCheckoutControllerTest`,
`BillingOverviewApiTest`, `BillingPresenterTest`,
`SubscriptionAccessPolicyTest`, `SubscriptionCancellationControllerTest`
to cover the new classification fields and the sanitised error copy.
Full backend suite re-run (1528 tests; the only 2 failures + 20 errors
are pre-existing local filesystem-permission issues in unrelated
storage/support-ticket/adjudication tests, not Billing-related and not
caused by this phase). Frontend `tsc --noEmit`, ESLint, and production
build all clean.

**Remaining recommendations:**

- Whether an abandoned-Checkout cancellation should resolve to `NONE`/
  `no_subscription`-style access instead of `RESTRICTED`/`cancelled` (for
  banner tone only — functionally both deny access identically) is a
  policy question, not fixed here, to avoid redesigning
  `SubscriptionAccessPolicy`'s access-mode matrix without a deliberate,
  separate decision.
- No dedicated subscription/Checkout history list view exists yet — only
  the latest row is ever shown to the customer.
- A previously-active-then-cancelled organisation still cannot
  self-serve re-subscribe eligibility beyond what `can_start_new_checkout`
  now correctly unblocks at the API/Plans-grid layer — no gap found here,
  just worth re-confirming once a genuine "win-back" flow is considered.

## Phase G0 — Entitlement Architecture Reconciliation (approved, no code changes)

A review-only phase confirming a mature "Entitlement Specification v1"
architecture already exists (`App\Support\Entitlements\Feature`/
`EntitlementValue`/`EntitlementValueType`/`EntitlementCategory`/
`EnforcementLevel`, `App\Services\Entitlements\FeatureGate`/
`SubscriptionAccessPolicy`/`EntitlementSnapshotService`/
`EntitlementOverrideRepository`/`SnapshotIntegrityClassifier`) — nothing
in this codebase calls `FeatureGate` yet (architecture-only, by design),
and the only real gap was that plan DEFAULTS resolved from a hardcoded
PHP registry (`PlanEntitlements`, exactly 3 plan codes) rather than the
database. Confirmed: `Feature`'s 10-key catalogue stays exactly as-is (no
new keys — Entitlement Specification v1 §2 principle 10 explicitly rules
out inventing a key per platform module); the marketing comparison table
(`pricing_features`/`pricing_plan_features`) is a genuinely separate
system serving a different audience, recommended to stay separate but
gain an optional "fill from entitlement" helper later (Phase G3) rather
than a schema merge; `Admin`/`Super Admin` are both platform-wide roles
(`organization_id = null`) per this app's existing role model, so
widening `admin/pricing` routes from `role:Super Admin` to
`role:Super Admin|Admin` (Phase G2) carries no customer-org exposure
risk; per-plan trial configuration does not exist anywhere today
(`config('billing.trial_days')` is dead, unwired configuration; trial
only ever starts reactively via a Stripe webhook reporting `trial_end`)
and needs its own product decisions before Phase G4. Recommended
sequence: G1 (database-backed plan defaults, this section) → G2 (Pricing
Management entitlement editor) → G3 (marketing reconciliation) → G4
(per-plan trial config) → G5 (usage metering + first real enforcement).

## Phase G1 — Database-backed Plan Entitlement Defaults

Replaces only `PlanEntitlements`'s hardcoded storage layer, exactly as
G0 recommended — the entitlement model itself (`Feature`,
`EntitlementValue`, `FeatureGate`'s resolution order, `SubscriptionAccessPolicy`,
snapshot immutability, the override seam) is completely unchanged.

**New table**: `pricing_plan_entitlements` — one row per (pricing plan,
non-dormant `Feature::*` key). Three states, matching `EntitlementValue`'s
own three constructors exactly: a normal value (`is_applicable=true`,
`is_unlimited=false`, `value` set), unlimited (`is_applicable=true`,
`is_unlimited=true`, `value=null`), and not-applicable (`is_applicable=false`,
`value=null` — e.g. `accounting_exports`/`api_access` today, since neither
feature is built/sold yet). Deliberately does NOT store `value_type` or
`unit` as columns — both are already fixed, deterministic functions of
`feature_key` via `Feature::valueType()`/`Feature::unit()`, so storing
them again would duplicate metadata `Feature` already owns (Phase G0's
explicit instruction). Reserved/dormant keys (`max_users`,
`max_organisations`) never receive rows at all, per Entitlement
Specification v1 §8. A `unique(pricing_plan_id, feature_key)` constraint
prevents duplicate rows outright.

**Seeded with exact fidelity** two ways: (1) the creating migration
transcribes `PlanEntitlements::forPlanCode()` programmatically (never
hand-copied) for any `pricing_plans` row already present when it runs;
(2) `PricingSeeder` calls the same transcription (`PlanEntitlementRepository::seedExactDefaultsForKnownPlan()`)
after creating the three real plans — necessary because seeders always
run AFTER migrations, so a genuinely fresh install has no `pricing_plans`
rows yet at the point the migration's own seed step executes. Both are
no-ops if a plan already has any configured rows — never overwrites.

**New `App\Services\Entitlements\PlanEntitlementRepository`** — the sole
consumer-facing replacement for `PlanEntitlements::forPlanCode()`/
`isKnownPlanCode()`. `FeatureGate`, `EntitlementSnapshotService`, and
`SnapshotIntegrityClassifier` were redirected to call it instead — zero
change to resolution order, override behaviour, snapshot behaviour,
access policy, or explainability; only the source of plan defaults
changed. `PlanEntitlements::trialProfile()` is untouched and still called
directly (per-plan trial configuration is Phase G4's concern, not this
phase's). Request-scoped memoization (keyed by `pricing_plan_id`) avoids
re-querying for the same plan across multiple `FeatureGate` calls in one
request — the only performance change made, no caching layer introduced.

**Temporary compatibility fallback** (Stage 8): if a plan has no
configured rows — either because its `pricing_plans` row doesn't exist
yet, or it exists but nothing has seeded its entitlements — the
repository falls back to the hardcoded `PlanEntitlements` for the three
known codes, **always logging a warning**, never silently. This is what
makes the migration safe to deploy in any order relative to seeding, and
is explicitly temporary: once every real environment's `pricing_plans`
rows are confirmed to have real `pricing_plan_entitlements` rows (which
`PricingSeeder` now guarantees for fresh installs, and the migration
itself guarantees for already-running environments), this fallback path
can be removed — its removal should itself become a small, deliberate
future checkpoint that also finally retires the `PlanEntitlements` class
body (not this phase's job).

**New plans** (Stage 9): `PricingManagementService::createPlan()` now
calls `PlanEntitlementRepository::initializeDefaultsForPlan()`, giving
every brand-new plan an explicit row for each non-dormant `Feature` key
— a conservative, most-restrictive baseline (feature flags `false`,
usage allowances `0`), never a guess at commercial intent (which is
unknown at creation time and belongs to the future Phase G2 entitlement
editor). This closes the G0-identified gap where a 4th/unknown plan code
silently resolved to `[]` (zero entitlements, no error).

**A real bug found and fixed during testing**: `json_encode()` collapses
a whole-number PHP float (e.g. `storage_gb`'s `200.0`) to `"200"` without
the rarely-used `JSON_PRESERVE_ZERO_FRACTION` flag, so it round-trips
back through the `value` column's `json` cast as an `int`, not a `float`
— silently violating `EntitlementValueType::DECIMAL` fidelity. Fixed by
coercing explicitly to the type `Feature::valueType()` declares at read
time (`PlanEntitlementRepository::coerceToDeclaredType()`), which is more
robust than chasing encode-flag correctness at every write site.

**Testing**: 13 new `PlanEntitlementRepositoryTest` cases (all three row
states, both fallback levels, new-plan initialization, idempotency, the
unique-constraint, exact-fidelity seeding). All 129 pre-existing
entitlement tests pass unchanged (4 needed a one-line constructor-arg
fix for tests that manually `new FeatureGate(...)`/`new SnapshotIntegrityClassifier(...)`
instead of resolving via the container). Full Billing filter: 585/585.
Full backend suite: 1516/1541 — all 25 failures/errors are the same
pre-existing, unrelated local storage-permission issues documented in
every prior phase's report.

**Documentation updated**: this section (plus a condensed Phase G0
summary above it, not previously written here), `CLAUDE.md`
(`PlanEntitlements`/new `PlanEntitlementRepository` rows, `pricing_plan_entitlements`
added to the table list with its own separateness note),
`project-context.md`. No release notes.

**Remaining before Phase G2**: the temporary fallback and `PlanEntitlements`
class body itself still need a deliberate future removal checkpoint;
`admin/pricing` routes are still `Super Admin` only (G0's authorization
finding, not yet acted on); no admin UI exists yet to edit
`pricing_plan_entitlements` rows at all — this phase is purely the
storage/resolution layer underneath one.

## Phase G2 — Subscription Plans & Entitlement Management

Builds the administration experience G1 left unbuilt: a dedicated
entitlement editor for each Pricing Plan, a "Copy Existing Plan" workflow,
and the G0-approved authorization widening — all management tooling, zero
changes to the entitlement model itself.

**Authorization widened exactly as G0 recommended**: `/api/admin/pricing/*`
moved from a `role:Super Admin` route group to its own `role:Super
Admin|Admin` group (still a distinct group from Application Monitoring's
`Super Admin`-only one, and from the broader `Super Admin|Admin` group used
by most of `/admin/*` — kept separate so Pricing Management's own route
list stays legible). `PricingAuthorizationTest` updated to assert Admin can
now access; Client remains forbidden, guest remains unauthorized.

**New entitlement editor endpoints** —
`GET`/`PUT /admin/pricing/plans/{plan}/entitlements` — on
`PricingController`, backed by two new `PricingManagementService` methods:

- `entitlementsForPlan()` — generates the full editor payload by iterating
  `Feature::ALL` (skipping dormant keys) and joining each key's registry
  metadata with this plan's current `pricing_plan_entitlements` row (or,
  for a key with no row yet, the same conservative default
  `initializeDefaultsForPlan()` would write — never a live `FeatureGate`
  fallback, since this is the admin editor, not resolution). Adding a new
  `Feature::*` key requires zero UI change — it appears automatically.
- `updateEntitlements()` — replaces a plan's entire entitlement row set in
  one transaction. Deliberately does NOT bust the public pricing cache
  (entitlement defaults are never part of the marketing payload) and
  deliberately never touches `billing_entitlement_snapshots` — editing a
  plan's defaults only changes what a FUTURE activation/upgrade/downgrade
  snapshot will capture, never an existing subscription's already-frozen
  snapshot.

**Validation** (`UpdatePricingPlanEntitlementsRequest`) rejects, both
server-side and mirrored client-side: an unknown or reserved/dormant
feature key, a duplicate row, an incomplete set (missing any non-dormant
key — a `PUT` always replaces the complete set, never a partial patch), a
value whose type doesn't match `Feature::valueType()`, a negative usage
value, and any is_applicable/is_unlimited/value combination that isn't one
of `EntitlementValue`'s three valid shapes.

**Copy Plan** (`POST /admin/pricing/plans/{plan}/copy`,
`CopyPricingPlanRequest`) — `PricingManagementService::copyPlan()` requires
a fresh code/slug/name from the caller (never inherited), copies every
commercial/presentation field
(`PricingManagementService::COPYABLE_COMMERCIAL_FIELDS`) and every
entitlement default row from the source plan in one transaction, and
deliberately never copies `is_popular`/`status`/`published_at` (a copy
always starts as an unranked, unpublished, non-popular draft) or anything
Stripe-related (no such column exists on `PricingPlan` itself — a copied
plan always starts with zero `pricing_plan_provider_prices` rows,
requiring its own new Stripe Product/Price mapping via
`PlanPriceMappingService` before it can be sold). Blank plan creation
(`POST /admin/pricing/plans`) is unchanged from Phase G1 — it still gets
the conservative most-restrictive baseline via
`initializeDefaultsForPlan()`.

**Frontend** (`frontend/src/components/pricing/`): `PlanForm` (in
`PlansTab.tsx`) reorganized into expandable sections (General / Commercial /
Stripe / Entitlements / Visibility / Metadata) — the Stripe section is
read-only (`PricingController::indexPlans()` now eager-loads
`providerPrices`; no mutation endpoint was added, keeping Stripe
configuration independent per the design principles). `EntitlementsEditor.tsx`
renders the dynamic per-feature editor (toggle for booleans, numeric input
with unit suffix for usage allowances, an "Unlimited" toggle, search/filter,
dirty-row highlighting, reset-to-current-default per row) — never a generic
JSON editor. `CopyPlanDialog.tsx` implements the Copy Plan workflow.

**Backward compatibility**: no schema changes; existing plans, existing
subscriptions, Stripe Checkout, the Customer Portal, the Billing Dashboard,
and the marketing Pricing page are all unaffected — verified by the full
existing Billing/Pricing/Entitlements test suites passing unchanged.

**Testing**: `PricingPlanEntitlementEditorTest` (dynamic payload generation,
persistence of every `EntitlementValue` shape, every validation rule) and
`PricingCopyPlanTest` (commercial-field + entitlement duplication, Stripe
non-copying, blank-plan baseline, authorization) — 60 Pricing-suite tests,
142 Entitlements-suite tests, full backend suite unchanged from its
pre-existing baseline (the same local storage-permission
errors/failures documented in every prior phase's report, none introduced
by this phase). Frontend: `tsc --noEmit`, ESLint, and `next build` all pass.

**Explicitly out of scope, unchanged this phase**: the `Feature` registry
(no new keys), `FeatureGate`/`SubscriptionAccessPolicy`/`EntitlementSnapshotService`
(zero enforcement exists yet, unchanged), Stripe integration, the marketing
comparison table (`pricing_features`/`pricing_plan_features`), and the
existing subscription lifecycle. Plan defaults are now fully manageable via
the Super Admin/Admin UI; Client users cannot reach any of it.

**Documentation updated**: this section, `internal-docs/super-admin/pricing-management.md`
(new "Managing plan entitlements"/"Copying and creating plans" sections,
updated authorization statement), `CLAUDE.md`, `project-context.md`. No
release notes (Phase G2 is internal admin tooling, not a customer-visible
change to the shipped product).

## Phase G2, Stage X — Entitlement Category Experience

Uses `EntitlementCategory` metadata — which already existed for
architectural purposes only — to group the entitlement editor, without
introducing any new category or duplicating category information in the
database.

**`EntitlementCategory`** gained a `REGISTRY` (label + description per
category) and `label()`/`description()` accessors, exactly mirroring
`Feature`'s own metadata pattern. **`Feature`** gained a `description()`
accessor (registry-only text, transcribed from the Entitlement
Specification v1 table — no new column, no new source of truth).

**`PricingManagementService::entitlementsForPlan()`** now returns
`{ categories, entitlements }` instead of a flat list: `categories` is
`EntitlementCategory::ALL` mapped through `label()`/`description()` (so a
future category needs zero code change here to appear correctly labelled);
`entitlements` now includes **every** `Feature::ALL` key, including
reserved/dormant ones, each carrying an `is_reserved` flag and a
`description`. Reserved rows always report `is_applicable=false`,
`is_unlimited=false`, `value=null`, `customer_visible=false`,
`currently_sold=false` — computed from `Feature`'s existing dormant-check
methods, never a new "reserved" special case duplicating what `Feature`
already knows. The editable `PUT` set is UNCHANGED — reserved keys are
still rejected outright by `UpdatePricingPlanEntitlementsRequest` and never
get a `pricing_plan_entitlements` row; only the read-only `GET` payload
grew to include them for display.

**Frontend** (`EntitlementsEditor.tsx`, `types/pricing.ts`): sections are
generated by iterating the API's `categories` array and filtering
`entitlements` by `row.category` — no hardcoded category list or feature
order anywhere in the component. Reserved cards render with a lock icon, a
"Reserved — not sold" badge, dashed border, and explanatory copy, and never
render an editable control. Added: search (name/key/description), a
category `<select>` filter, three quick-filter toggle chips (Enabled only /
Unlimited only / Configurable only), a Show/Hide-reserved toggle, and a
per-card expandable "Details" disclosure (description, enforcement level,
customer visibility, currently-sold, overrideable) to keep the primary
scan-path short. Accessibility: semantic `<section aria-labelledby>` per
category, `aria-expanded`/`aria-controls` on every disclosure trigger,
`aria-pressed` on filter toggles, `sr-only` labels on icon-only/visually-
implicit inputs, `role="status"`/`role="alert"` for loading/error text, and
`motion-reduce:transition-none` on the (few, subtle) transitioning
elements. Responsive: the modal now sizes to `w-[92vw] sm:w-[640px]`
instead of a fixed pixel `minWidth` that broke on narrow viewports.

**Testing**: `PricingPlanEntitlementEditorTest` updated for the new
`{categories, entitlements}` shape (was a flat array) plus three new cases
— every `Feature::ALL` key (including reserved) is present, reserved rows
are correctly marked and never get a database row, and `categories` matches
`EntitlementCategory::ALL` with correct labels/descriptions. Full Pricing
suite 62/62. Full backend suite unchanged from its pre-existing baseline.
Frontend `tsc --noEmit`, ESLint, and `next build` all pass.

**Explicitly confirmed**: categories are generated dynamically from
`EntitlementCategory`, never hardcoded in the frontend; no category
metadata is duplicated in the database (`pricing_plan_entitlements` gained
no new column); no feature list is hardcoded — both `Feature::ALL` and
`EntitlementCategory::ALL` drive iteration order end-to-end; a future
approved category will appear in the correct section automatically, with
no UI redesign, the moment it's added to `EntitlementCategory`.

## Phase G3 — Subscription Intelligence Centre

A customer-facing, read-only dashboard (`GET /billing/intelligence`,
rendered on the existing `frontend/src/app/app/settings/billing/page.tsx`)
giving every organisation visibility into its plan, usage, storage, AI
consumption, trial progress, health, and commercial history. This is the
**first real caller of `FeatureGate`** for anything other than
architecture — Phase G0 noted nothing called it yet; every call here is
strictly read-only (`allows()`/`limit()`), never enforcement, and
`FeatureGate`'s resolution order/snapshot behaviour is completely
unchanged.

**New services** (`App\Services\Intelligence\`), each with one clear
responsibility, composed by a single orchestrator:

- `UsageMetricsService` — generates a usage card for every non-dormant
  `EntitlementCategory::USAGE` Feature key (today: `max_active_projects`,
  `ai_analyses_per_month`, `storage_gb`; a future usage key appears
  automatically, with `used = null` — "not yet measurable" — until a
  matching resolver is added). Pairs `FeatureGate::limit()`'s allowance
  with a genuine usage count from authoritative platform data:
  - **Active projects**: `COUNT` of `projects` with status `active`/
    `on_hold` (a display interpretation of "active", not a new enforcement
    decision — the Entitlement Specification's own open question about
    precisely defining "active" for enforcement is untouched).
  - **AI analyses**: `COUNT` of `contract_ai_analyses` excluding `pending`/
    `cancelled` (never sent to the provider), within the current UTC
    calendar month — the exact reset-period definition Entitlement
    Specification v1 Section 12 already decided (2026-07-23), not a fresh
    decision made here.
  - **Storage**: `SUM(file_size)` across `documents`, `document_versions`
    (joined back to `documents` for organisation scoping — it carries no
    `organization_id` of its own), `file_uploads`, and
    `adjudication_documents` — every table that records a real file's size
    at write time. Deliberately never a live filesystem scan (explicit
    instruction). Cached 10 minutes per organisation (`Cache::remember`) —
    the only caching this phase introduces. Deliberately excludes
    organisation branding assets (logo/letterhead) — `branding_settings`
    has no `file_size` column today, so there is nothing authoritative to
    sum; inventing an estimate would be fake analytics. Adding that column
    is a one-line addition to this method once it exists.
- `SubscriptionHealthService` — classifies existing signals (subscription
  access mode via `SubscriptionAccessPolicy`, subscription `status`,
  `BillingCustomer` presence, and `UsageMetricsService`'s own per-key
  status) into a health item list plus one overall status. Introduces no
  new source of truth of its own.
- `SubscriptionRecommendationService` — generates upgrade/unused-capacity/
  trial-ending-soon recommendations strictly from real usage percentages
  and `trial_ends_at`, capped at 5, ranked by severity. No generic upsell
  copy.
- `SubscriptionTimelineService` — reads `ActivityLog` rows
  `SubscriptionLifecycleService` already writes on every real transition
  (`subject_type = Subscription::class`) — no new event tracking
  introduced; this was true before this phase and remains the only source.
- `SubscriptionIntelligenceService` — the thin composer. Delegates
  subscription/access data entirely to the existing
  `BillingOverviewService::subscriptionDetail()` (never reimplemented),
  assembles the trial card (exists if and only if `SubscriptionAccessPolicy`
  currently resolves `TRIAL` — this is what makes the card disappear
  automatically on conversion, with zero separate "was this a trial"
  bookkeeping), and a read-only Stripe summary from already-synced local
  records (`BillingCustomer`/`BillingPayment`/`Subscription` — never a
  fresh Stripe API call, never a raw provider id or secret).

**Authorization**: `GET /billing/intelligence` sits in the same
authenticated, organisation-scoped route group as the rest of
`BillingController` — every method resolves scope from
`$request->user()->organization`, never a caller-supplied organisation id.
Verified: an organisation's usage/timeline/health never includes another
organisation's data (`SubscriptionIntelligenceApiTest`).

**Performance**: no live filesystem scan (storage is a `SUM` aggregate
over indexed FKs, cached 10 minutes); the whole dashboard is a single
composed request (`GET /billing/intelligence`) rather than N round trips;
`FeatureGate`'s existing request-scoped memoization (Phase G1) still
applies. No other caching was added — the rest of the payload
(subscription detail, health, recommendations, timeline) is cheap enough
to compute per request.

**Future compatibility** (explicitly prepared for, not built): usage-based/
overage billing and add-ons have a real, already-measured usage number to
key off; annual billing and multiple subscriptions are unaffected (this
phase reads whichever subscription `BillingOverviewService` already
resolves as current); Enterprise plans work identically since nothing here
is plan-code-specific; AI usage expansion only needs a new `Feature` key
plus a matching `resolveUsed()` case; organisation analytics/usage-history
trends have a natural persistence point (a future scheduled job snapshotting
`UsageMetricsService`'s output) that this phase deliberately does not
build (Stage 11 — no fake historical trend was invented).

**Testing**: 10 new `SubscriptionIntelligenceApiTest` cases (no-subscription
payload shape, dynamic usage generation, active-project/AI/storage
computation from real rows, trial card appearing and disappearing exactly
on conversion, near-limit health + recommendation generation via a real
entitlement snapshot, timeline reuse of existing `ActivityLog` rows, and
cross-organisation isolation). Full Billing/Entitlements/Pricing/
Intelligence filter: 769/769. Full backend suite unchanged from its
pre-existing baseline. Frontend `tsc --noEmit`, ESLint, and `next build`
all pass.

**Explicitly confirmed**: `Feature` remains the only entitlement
catalogue and no new key was added; no new entitlement architecture was
introduced (`FeatureGate`/`SubscriptionAccessPolicy`/snapshot immutability
are unchanged — this phase only calls them); existing subscriptions and
snapshots are completely unaffected (read-only throughout); Stripe
integration is unchanged (no new outbound call — every Stripe-derived
field comes from already-synced local records); usage/storage/AI figures
are derived from authoritative platform data, never estimated or
fabricated; the Subscription Intelligence Centre's architecture is ready
for usage-based billing, overages, and Enterprise expansion without
further redesign.

## Phase G4 — Organisation Subscription Administration, SureSign AI Credits & Controlled Testing Tools

A large, multi-stage Super Admin/Admin phase covering organisation-level
subscription administration, plan assignment, SureSign AI Credits (the
customer-facing rename/foundation for AI usage), trial administration,
controlled testing/simulation tools, and customer impersonation. Given the
blast radius (real commercial mutations, entitlement resolution changes,
AI credit charging, auth-adjacent impersonation), this phase is being
built incrementally, sub-phase by sub-phase, each requiring explicit
approval before implementation — see the Stage 1 Architecture & Safety
Boundary Report (summarised below) for the full decomposition (G4A–G4F).

### Stage 1 — Architecture & Safety Boundary Report (approved)

Reviewed the existing ownership chain (`Organization` → `BillingCustomer` →
`Subscription` → `PricingPlan`, with `SubscriptionEntitlementSnapshot` as
the immutable per-commercial-event record) and confirmed:

- **The Organisation remains the only billable entity** — no user-level
  subscription concept exists or was introduced.
- **Historical entitlement snapshots are immutable** (enforced at the
  model level, `SubscriptionEntitlementSnapshot::booted()` throws on
  `updating`) and must stay that way — a real commercial event (plan
  change, manual/complimentary grant, trial conversion) always creates a
  **new** snapshot; nothing in G4 should ever bypass this by resolving
  live plan defaults for an organisation that already has one.
- **Temporary QA/testing state must live outside `Subscription` entirely**
  — via an organisation test marker, temporary plan-simulation records, and
  temporary entitlement-override records (all future G4E work), never by
  writing a `testing` value into `Subscription.source` or mutating a real
  subscription row. A test organisation may still hold a genuine `manual`
  subscription — "test" describes the organisation/simulation state, not
  the subscription's commercial origin.
- **Subscription source model (approved for a future G4B migration,
  not yet implemented)**: `stripe` / `manual` / `complimentary` only.
- **AI credit balance (future G4C) must be computed, never a stored
  mutable field**: `plan allowance + valid adjustments − settled usage −
  active reservations`, so the ledger itself stays the single source of
  truth.
- **Cached/reused AI analyses charge zero SureSign AI Credits** by
  default (matching `ContractAnalysisService::analyse()`'s existing
  zero-token-cost cache-hit behaviour) unless a separately approved
  SureSign processing charge is introduced later.
- **Trial restoration (future G4D)** must check for every later commercial
  lifecycle fact (activation, payment, Stripe relationship, manual grant,
  plan change, snapshot, any other transition) — not just "no snapshot
  exists" — before treating an expired trial as safely restorable.
- **Impersonation (future G4F)** will build on Sanctum's existing
  personal-access-token model (a second, ability-scoped token for the
  target user, original Super Admin token kept memory-only client-side)
  — full refresh/expiry/crash/revocation edge cases deferred to G4F itself.

No code, migrations, or Stripe/entitlement-resolution changes were made in
Stage 1 — it was a read-only review.

### G4A — Read-only Organisation Subscription Administration (implemented)

The first, and only currently implemented, G4 sub-phase. Strictly
read-only: no migration, no new write path, no Stripe call, no
entitlement-resolution change, no snapshot created/modified, no AI credit
charged, no trial/testing/impersonation control introduced.

**Backend — reuses existing authoritative services, extended (not
duplicated) to accept an `Organization` directly**:

- `App\Services\Billing\BillingOverviewService::subscriptionDetailForOrganization(Organization)`
  — extracted from the existing `subscriptionDetail(User)`, which now
  delegates to it. Identical presentation (`BillingPresenter::subscription()`
  + access decision), just organisation-scoped instead of user-scoped.
- `App\Services\Intelligence\SubscriptionIntelligenceService::intelligenceForOrganization(Organization)`
  — same extraction pattern from `intelligenceFor(User)`. This is the
  single payload (subscription, trial, usage, storage, AI, health,
  recommendations, timeline, Stripe summary) now reused by three
  surfaces: the customer's own Billing page, the new Organisation
  Subscription Administration page, and the Users page's inherited
  display below.
- `App\Services\Admin\OrganizationSubscriptionAdminService` (new) —
  composes `intelligenceForOrganization()` plus three operator-only
  fields no customer-facing surface should ever see: `organization_detail`
  (name/status/contact/created date), `snapshot` (existence, source
  transition, lifecycle reason, effective date, and
  `SnapshotIntegrityClassifier`'s classification — flagging a genuine
  missing-snapshot integrity problem as distinct from the documented
  legacy/not-applicable compatibility case), and `recent_activity`
  (existing `ActivityLog` rows where `subject_type = Subscription::class`
  — no new audit architecture).
- `App\Http\Controllers\Api\OrganizationController::subscription()` —
  `GET /organizations/{id}/subscription`, added to the existing
  `role:Super Admin|Admin` route group (same gating as `showById()`).
- `App\Http\Controllers\Api\UserController` — `subscription()` method
  (`GET /users/{id}/subscription`, Super-Admin-only route group,
  unchanged from the existing Users API's gating) returns
  `{is_platform_operator: true}` for a user with no organisation, or the
  full `intelligenceForOrganization()` payload for their organisation
  otherwise. `index()` now also eager-loads
  `organization.liveSubscription.pricingPlan` and computes a lightweight
  per-organisation summary (plan name, status, access mode, trial end)
  **once per distinct organisation on the page, from already-eager-loaded
  relations — zero additional queries, and never once per user row**
  (verified by a query-count regression test).

**Frontend — reuses every existing Subscription Intelligence presentational
component rather than reimplementing them**:

- `src/components/billing/intelligence/OrganizationSubscriptionSection.tsx`
  (new) — composes `UsageMeter`/`StorageMeterCard`/`AiUsageMeterCard`/
  `TrialCard`/`HealthOverview`/`StripeInfoCard` (all unchanged, all
  already used by the customer Billing page) plus two new operator-only
  cards (`SnapshotCard`, `ActivityCard`). Originally rendered inline on
  `frontend/src/app/admin/companies/[id]/page.tsx`; moved to its own route,
  `frontend/src/app/admin/companies/[id]/subscription/page.tsx`, so the
  company detail page isn't overloaded with this section's own heavier
  data fetch and Super-Admin-only mutating actions — the company page now
  links out to it via a compact "Subscription & Billing" card instead of
  rendering the full section inline. Both pages share the same
  `['admin-company', id]` React Query key, so navigating from the link is
  instant (cache already warm) and a direct visit to the subscription
  page fetches its own copy.
- `frontend/src/app/admin/users/page.tsx` — a new "Organisation" table
  column (lightweight plan/access-mode pill or "Platform Operator", no
  per-row fetch) and a new "Subscription" section inside the existing
  "Manage User" modal (`InheritedSubscriptionSection`, fetched lazily via
  `useUserInheritedSubscription` only while that modal is open), including
  a "Manage Organization Subscription" link to the Organisation detail
  page above.
- `useOrganizationSubscriptionAdmin()`/`useUserInheritedSubscription()`
  (new hooks, `useBilling.ts`) and `OrganizationSubscriptionAdmin`/
  `UserInheritedSubscription`/`SubscriptionSummaryView` (new types,
  `subscriptionIntelligence.ts`).

**State handling**: no subscription (null payload, no fake plan), active/
trialing/past_due-grace/restricted/cancelled/expired (all resolved via the
unchanged `SubscriptionAccessPolicy`), no `BillingCustomer`, a subscription
with no current snapshot (explicitly distinguished as "legacy fallback" —
expected, not an error — versus "requires attention" — a genuine
integrity gap — via `SnapshotIntegrityClassifier`, never conflated), and
platform operators (`organization_id === null`) shown as "Platform
Operator" with no plan/usage figures fabricated for them.

**Authorization**: Organisation Subscription Administration follows the
existing `role:Super Admin|Admin` gate (unchanged group, matches G0's
Admin-is-platform-wide decision); the Users API (including the new
per-user subscription endpoint) stays within its existing, deliberately
tighter `role:Super Admin`-only group — G4A did not widen it. Client is
denied by the same role middleware in both cases; organisation isolation
requires no additional check since a platform operator may legitimately
view any organisation.

**Performance**: the Organisation Subscription Administration page issues
one composed request; the Users list adds `organization.liveSubscription.
pricingPlan` to its existing eager-load, computes the per-row summary once
per distinct organisation, and a regression test asserts the query count
does not scale with users sharing an organisation (10 users, one
organisation → the same bounded query count as one user).

**Testing**: two new test files —
`tests/Feature/Admin/OrganizationSubscriptionAdminApiTest.php` (9 cases:
no subscription, active+snapshot-present, trialing, cancelled/restricted,
legacy-fallback-vs-requires-attention, activity reuse, Admin allowed,
Client denied, guest denied, cross-organisation isolation) and
`tests/Feature/Admin/UserInheritedSubscriptionApiTest.php` (8 cases:
list summary shape, query-count-does-not-scale-with-shared-org, platform
operator, single-user detail endpoint, platform-operator detail, Client
denied, guest denied). Full backend suite: 1587 tests, 1562 passing, 2
failures (`AdjudicationDocumentTenantIsolationTest`,
`PaymentApplicationExcelDisclosureTest`) and 20 errors
(`FileSecurityServiceTest`, `AiAnalysisErrorMessageTest`,
`ContractAnalysisDedupTest`, `SupportTicketControllerTest`) — all local
filesystem-permission issues in this environment (e.g. `storage/app/
private/adjudication`, `storage/framework/testing/disks/*` permission
denied), unrelated to any file this phase touched — none were introduced
by G4A. Frontend `tsc --noEmit`, ESLint (on every changed file), and
`next build` all pass.

**Explicitly confirmed**: G4A remained entirely read-only; no migration
was created; no subscription state, Stripe state, or organisation access
was changed; no entitlement snapshot was created, modified, or deleted; no
entitlement resolution precedence was changed; no AI credit ledger was
introduced and no AI credits were charged or adjusted; no trial controls,
test organisation marker, testing/simulation overrides, or impersonation
were introduced.

**Remaining work (G4B onward)** — all require their own explicit approval
before implementation: G4B (subscription `source` column + manual/
complimentary plan assignment), G4C (SureSign AI Credits ledger, costing
policy sampled from real token-usage data, estimation/reservation/
settlement), G4D (trial administration actions), G4E (testing/simulation
overlays + test organisation marker), G4F (customer impersonation).

### G4B.1 — Subscription Source Foundation (implemented)

The first schema-changing G4 sub-phase. Introduces `Subscription.source` —
the row's commercial ORIGIN — without any manual/complimentary assignment
write path, and without changing any organisation's access, plan, status,
Stripe identifiers, or entitlement snapshots.

**Environment migrated and tested**: only this session's PHPUnit test
database (`sqlite`, in-memory, forced by `phpunit.xml` regardless of
`.env`) — the only database actually reachable in this session. The
local development database configured in `.env`
(`DB_CONNECTION=mysql`, host `mysql`, database `suresign`, a Docker
container) was confirmed **unreachable** in this session
(`php artisan migrate:status` failed with a DNS resolution error — the
Docker network is not running here) and was therefore **not migrated**.
Nothing was run, or proposed to run, against a production database. The
migration itself was validated indirectly but concretely: the full
backend test suite (which runs every migration from scratch via
`RefreshDatabase` for every test class) passed unchanged, and a dedicated
test (`SubscriptionSourceTest::test_migration_backfills_existing_null_source_rows_to_stripe`)
drops the column, inserts a legacy-shaped row with no `source` at all,
then re-invokes the migration file's own `up()` method directly (not a
re-derived approximation of it) to prove the real add → backfill → enforce
sequence works end to end against sqlite.

**Source constants** (`App\Support\Billing\SubscriptionSource`, matching
the existing plain-class-constant convention of `SubscriptionStatus`/
`TransitionSource`): `stripe` / `manual` / `complimentary` only —
`isValid()` rejects everything else, including `testing`/`trial`/`legacy`
by design (see the class's own docblock for why each is deliberately
excluded). `source` (commercial origin) is kept strictly distinct from
`provider` (which billing integration is authoritative — today always
`'stripe'`, see `BillingProviders`) and `status` (current lifecycle
state, `SubscriptionStatus`) — three different questions, never
conflated.

**Migration** (`2026_08_08_000001_add_source_to_subscriptions_table.php`),
the deployment-safe three-step sequence the brief required, not a bare
NOT NULL default:
1. add `source` (`string(20)`) nullable — never fails against existing rows;
2. explicit `DB::table('subscriptions')->whereNull('source')->update([...'stripe'])`
   backfill — a real, auditable statement, not an implicit default silently
   covering for a missing explanation;
3. only then `->default('stripe')->nullable(false)->change()` — tightening
   to NOT NULL once every row is known to have a real value. Rollback
   (`down()`) drops the column.

**Subscription creation-path audit** — the complete list, not just the
"obvious" service:
- **`SubscriptionLifecycleService::createDraftSubscription()`** — the
  **only** production code path that creates a `Subscription` row
  (confirmed by a repo-wide search for `Subscription::create`/
  `new Subscription(`/`Subscription::factory`/`firstOrCreate`/
  `updateOrCreate`/relationship `->create()` across `app/`, `database/`,
  and every console command). It always receives a real
  `PricingPlanProviderPrice $priceMapping` parameter — there is no calling
  convention that reaches this method without one — so every row it
  creates is unambiguously Stripe-origin. **Now sets `source` explicitly**
  (`SubscriptionSource::STRIPE`) rather than relying on the migration's
  backward-compatibility DB default, per the brief's "production paths
  should still set the value explicitly where practical."
- **No factory** (`database/factories/`) creates a `Subscription` at all —
  every test builds one directly via `Subscription::create()`/the
  lifecycle service.
- **No seeder** creates a `Subscription`.
- **`ProcessSubscriptionAutomation`** (and every other Console command)
  only ever calls `SubscriptionAutomationService`, which itself never
  creates a `Subscription` — it only transitions existing rows via
  `SubscriptionLifecycleService`'s named methods. No creation path there.
- **`FakeBillingProvider`-backed test flows** — these still go through
  `createDraftSubscription()` exactly like production (the fake only
  replaces the outbound HTTP calls the *lifecycle service's other methods*
  make, e.g. `activate()`'s webhook-normalised array) — so they receive
  `source = stripe` through the exact same explicit assignment, no special
  casing needed, confirmed deterministic by
  `SubscriptionSourceTest::test_checkout_created_subscription_receives_stripe_source_explicitly`.
- **Direct test fixtures** (`Subscription::create([...])` in ~20 existing
  test files) — never touch `source` explicitly; each now safely resolves
  to `stripe` via the migration's DB-level default, confirmed unchanged by
  the full suite passing without modifying any of those fixtures.
- **No production creation path produces an ambiguous null source** after
  this migration — the column is NOT NULL with a `stripe` default; the
  only way to get a null value at all would be a raw insert that
  explicitly overrides it, which the DB itself now rejects (see the
  enforcement test).

**Defaulting strategy**: the `stripe` DB default exists **specifically**
as the backward-compatibility net for the ~20 existing test fixtures that
predate this column and will never be individually rewritten to name a
source explicitly — it is not a substitute for the real, auditable
backfill statement above, and the one production path
(`createDraftSubscription()`) sets the value explicitly regardless of the
default, per the brief's own distinction.

**Immutability**: mirrors `SubscriptionEntitlementSnapshot`'s existing
`booted()` pattern. `Subscription::booted()` now throws inside an
`updating` listener **only when `isDirty('source')`** — so creation,
hydration, and every unrelated lifecycle update (status, period dates,
grace period, etc.) are completely unaffected; only an attempt to change
an already-persisted row's `source` fails. A subscription that starts
`manual` and later becomes a genuine Stripe customer must be ended and
replaced by a new, `stripe`-source `Subscription` (G4B.2+, not built) —
never converted in place.

**Stripe reconciliation guard**: `StripeReconciliationService::reconcile()`'s
scan-forward query now includes `->where('source', SubscriptionSource::STRIPE)`
— changes no current behaviour (every row today is still `stripe`-source;
no other creation path exists yet) but prevents a future `manual`/
`complimentary` row from ever being scanned against Stripe's own API once
G4B.2 introduces one. `WebhookEventProcessor`'s subscription-correlation
queries (`correlateForCreated()`/`correlateForUpdateOrDelete()`) were
reviewed and **deliberately left unguarded** — they already key
exclusively on `provider_subscription_id`, trusted Checkout metadata, or
`billing_customer_id` + `PENDING_PAYMENT` status, none of which a future
manual/complimentary subscription would ever populate, so an explicit
`source` filter there would be redundant, not protective.
`SubscriptionAccessPolicy`/`FeatureGate` were deliberately **not**
touched — both continue to resolve purely from status/snapshot, exactly
as the brief requires (source must never affect entitlement resolution).

**Reporting audit**: a repo-wide search for any service outside
`Billing`/`Intelligence`/`Entitlements` querying `Subscription` at all
returned nothing — SureSign's existing "Commercial" module
(`App\Services\Commercial\*`) is entirely about construction Payment
Applications, unrelated to subscription billing revenue; no report
anywhere currently assumes every `Subscription` row is paid Stripe
revenue, because no report reads the table at all outside the
already-reviewed Billing/Intelligence/Entitlements services. One
follow-up flagged for G4B.2: `SubscriptionAutomationService::processDuePlanChanges()`
sends outbound Stripe Price updates for `BillingPlanChange` rows — a
future manual/complimentary plan-change workflow must not create
`BillingPlanChange` rows that flow through this Stripe-outbound path;
this needs a decision in G4B.2, not a change now (no manual plan-change
capability exists yet to be at risk).

**Read-only UI**: `OrganizationSubscriptionAdminService::forOrganization()`
now exposes a new `subscription_source` field — added there specifically,
**not** to `SubscriptionIntelligenceService::intelligenceForOrganization()`'s
shared payload, because that method is also consumed by the customer-facing
Billing page and `source` must never reach an ordinary Client-facing
surface (per the brief). `OrganizationSubscriptionSection.tsx` renders it
as a "Stripe"/"Manual"/"Complimentary" badge next to the access-mode
badge; a `null` value (a not-yet-backfilled row in some other environment)
renders nothing rather than guessing a label.

**Testing**: 14 new cases in `tests/Feature/Billing/SubscriptionSourceTest.php`
(migration backfill via direct re-invocation of the migration's `up()`,
NOT NULL enforcement, DB-default compatibility, explicit source on the
real creation path, value validation, immutability — both the negative
case and a proof that unrelated updates remain unaffected — reconciliation
inclusion/exclusion by source, G4A UI payload exposure, and confirmation
that no snapshot/access-mode side effect exists). Full backend suite:
1601 tests, 1576 passing — the same 2 pre-existing failures and 20
pre-existing errors as G4A (confirmed identical, unrelated filesystem-
permission issues; zero new failures). Frontend `tsc --noEmit`, ESLint,
and `next build` all pass.

**G4B.2 prerequisites and unresolved risks**: (1) the manual/complimentary
write path itself (the actual assignment UI/endpoint) is entirely
unbuilt — G4B.1 only prepared the column; (2) the `BillingPlanChange`/
`processDuePlanChanges()` reporting follow-up above needs a decision
before a manual subscription can safely coexist with the plan-change
automation command; (3) the manual-to-Stripe conversion rule (end the
manual subscription, start a new Stripe one) is documented as the
intended rule but has no implementation yet; (4) this session could not
validate the migration against the real MySQL development database (only
sqlite) — running it there for the first time remains outstanding before
any real deployment.

**Explicitly confirmed**: no manual or complimentary subscription
assignment was introduced; no subscription status, pricing plan, or
Stripe identifier was changed; no organisation access was changed; no
entitlement snapshot was created, modified, or deleted; no `FeatureGate`
precedence was changed; no trial behaviour was changed; no test
organisation marker, testing/simulation overlay, AI credit behaviour, or
impersonation was introduced.

### G4B.1A — MySQL Migration Validation checkpoint (found a blocker, did not fix it)

Attempting to validate G4B.1's migration against a real, disposable MySQL
8.0 database (the project's own dev `suresign_mysql` container, reached at
`localhost:3307` — never the shared `suresign` dev database, never
production) exposed a **pre-existing, unrelated** migration-ordering
defect: `2026_07_23_143613_create_billing_entitlement_snapshots_table.php`
declares a foreign key to `subscriptions`, but `subscriptions` isn't
created until `2026_07_26_000003_create_subscriptions_table.php` — three
days "later." MySQL/InnoDB validates a referenced table's existence at
`CREATE TABLE` time and fails immediately (error 1824); SQLite does not,
which is exactly why this was invisible to the entire SQLite-based test
suite. G4B.1's own migration was never reached from a genuinely empty
database. Per this checkpoint's own decision boundary, this was reported
rather than fixed in-place, and G4B.2 remained blocked pending a separate,
scoped repair.

### G4B.1B — Billing Migration Ordering Repair (implemented)

Repaired the ordering defect G4B.1A found — and, in the course of
actually re-validating against MySQL after the fix, found **two more**
MySQL-only defects hiding in the same file (see below). Introduces no
product behaviour; purely a schema-migration correctness fix.

**Root cause (mapped in full)**: `2026_07_23_143613_create_billing_entitlement_snapshots_table.php`
declares TWO forward-referencing foreign keys — to `subscriptions`
(created `2026_07_26_000003`) and to `pricing_plans` (created
`2026_07_25_000002`) — both after this migration's own timestamp. Every
other migration referencing `subscriptions` via a foreign key
(`subscription_items`, `billing_invoices`, `billing_payments`,
`billing_checkout_sessions`, `billing_adjustments`, `billing_plan_changes`)
is correctly ordered after it; this file was the sole offender. A
read-only inspection of this project's own local MySQL dev database
confirmed both migrations are already recorded as applied there, with
both foreign keys genuinely present — because real environments apply
`migrate` incrementally as files are introduced (order determined by
introduction time, not pure filename timestamp), this ordering defect
never actually manifested outside a genuinely-fresh, from-empty install —
exactly the scenario G4B.1A's checkpoint (and this repair's own
validation) deliberately reproduces. Production's migration-application
state is unknown from this session; the repair strategy below is
correct regardless of that unknown rather than depending on it.

**Repair strategy**: never rename or retime an already-applied migration
file (Laravel identifies migrations by filename in the `migrations`
table; renaming one already recorded elsewhere would make Laravel try to
re-run it under the "new" name, hitting "table already exists"). Instead:
1. `2026_07_23_143613_create_billing_entitlement_snapshots_table.php` still
   creates both foreign-key columns unconditionally, but only declares the
   constraint inline when `Schema::getConnection()->getDriverName() !== 'mysql'`
   (safe for SQLite, which already tolerated the forward reference and is
   unaffected by this change).
2. A new migration, `2026_07_26_000011_add_deferred_foreign_keys_to_billing_entitlement_snapshots_table.php`
   (timestamped after both `subscriptions` and `pricing_plans` exist),
   adds both constraints on MySQL only — **idempotently**: each checks
   `information_schema.TABLE_CONSTRAINTS` first and skips if the
   constraint already exists, so an environment that already has it (via
   the historical, differently-ordered incremental apply) is unaffected,
   while a genuinely fresh install gets it here instead. No-ops entirely
   on SQLite.

**Two further MySQL-only defects found and fixed in the same file while
re-validating**, both invisible on SQLite for the same reason (no
enforcement SQLite would catch):
- A third foreign key (`pricing_plan_id` → `pricing_plans`) had the exact
  same forward-ordering problem — caught immediately by the new static
  regression test below, before ever reaching MySQL a second time.
- The composite index `['subscription_id', 'effective_from']`'s
  Laravel-auto-generated name ("billing_entitlement_snapshots_subscription_id_effective_from_index",
  68 characters) exceeds MySQL's 64-character identifier limit (error
  1059). Fixed with an explicit short name
  (`billing_entitlement_snapshots_sub_effective_idx`) in the original
  migration (safe for every driver, not just MySQL). A read-only check of
  the local dev database confirmed this index was never actually created
  there either (silently absent — same root cause, this migration's
  `CREATE TABLE` statement must have partially failed on it historically)
  — the deferred migration above also backfills this index there,
  idempotently, via an `information_schema.STATISTICS` existence check.
  Unlike the two foreign keys, this backfill is deliberately NOT undone
  in `down()` — an environment missing this index has a genuine
  historical gap, not merely "this migration's own no-op," and reversing
  it on rollback would silently reopen a production-readiness issue.

**Regression test**: `tests/Unit/Migrations/ForeignKeyMigrationOrderTest.php`
— a static, driver-independent scan across every migration FILE (not a
live database), catching this whole class of bug on any engine equally
rather than relying on a live SQLite test run ever proving anything about
MySQL-specific ordering enforcement. Parses `Schema::create()` calls to
build a table-creation order, and both foreign-key declaration styles this
codebase uses (`->constrained()` explicit/implicit, `->foreign()->references()->on()`)
to find every FK reference, asserting the referenced table's creation
index is never later than the referencing migration's own index. Ran
clean (0 violations) after both real fixes landed, with the original
file's now-conditional (SQLite-only) declarations explicitly allowlisted
by filename+table (the one thing a regex scan cannot see through
conditional PHP logic) — anything else is a real, unreviewed violation.

**MySQL validation** (disposable `suresign_g4b1_validation` database,
same container/port as G4B.1A): full fresh `php artisan migrate --force`
now succeeds end-to-end, including G4B.1's own `source` migration.
`SHOW CREATE TABLE` confirms both foreign keys and the correctly-named
index are present. Upgrade-path test (migrate to the state immediately
before G4B.1, insert 5 representative subscriptions — active, trialing,
past_due, cancelled, expired, one with null optional Stripe metadata —
snapshot every column, run only the G4B.1 migration, re-dump): all 5 rows
backfilled to `stripe`, zero nulls, row count unchanged, and a full-row
diff excluding the new `source` field is byte-for-byte identical
before/after. Rollback of the G4B.1 migration removes exactly `source`
and nothing else (verified by the same byte-for-byte diff against the
original pre-migration snapshot); re-running restores the column,
NOT NULL constraint, `stripe` default, and full backfill. Seven MySQL
runtime integration checks (via `artisan tinker` against the same
database, since `phpunit.xml` always forces SQLite regardless of `.env`)
all passed: explicit `source` on real creation, unrelated lifecycle
update unaffected, immutability enforced, `StripeReconciliationService`
runs its `source`-filtered scan without error, the DB-level default fires
for a raw insert, `SubscriptionSource::isValid()` rejects an invalid
value, and the Organisation Subscription Administration payload exposes
the correct source label.

**SQLite regression**: full backend suite unchanged in outcome — 1602
tests (1577 passing; the same 2 pre-existing failures and 20 pre-existing
errors as G4A/G4B.1, zero new failures) plus the one new regression test.

**Cleanup**: `suresign_g4b1_validation` dropped after use; the real local
`suresign` dev database confirmed untouched throughout (3 subscription
rows, 118 tables, before and after).

**Recommendation**: G4B.1A may now be considered satisfied by this
checkpoint's own re-validation (the upgrade-path/fresh-install/rollback/
column-inspection/runtime-check sequence it specified was executed here
in full, after the blocking defect was resolved) — **G4B.2 may proceed**.

## G4B.2 — Manual & Complimentary Subscription Assignment (implemented)

The first write-capable subscription workflow. Super Admin-only assignment
and termination of `manual`/`complimentary` subscriptions, built entirely
on the existing lifecycle/access/entitlement/audit architecture — no new
service class competes with `SubscriptionLifecycleService`, no new
audit system, no change to `FeatureGate`/`SubscriptionAccessPolicy`.

**Explicit, independently-auditable lifecycle methods** (never a generic
`assignSubscription($source)`):
- `SubscriptionLifecycleService::assignManualSubscription()` /
  `assignComplimentarySubscription()` — both delegate to a shared private
  `assignNonStripeSubscription()` which: acquires the same per-organisation
  `Cache::lock("subscription-draft:{$organization->id}", 10)`
  `createDraftSubscription()` already uses (there is no existing row to
  `lockForUpdate()` — this is a brand-new row, so the concurrency
  protection is the lock, not row-level locking); checks
  `hasConflictingSubscription()` once before the lock and again inside the
  DB transaction (closing the TOCTOU window); creates the `Subscription`
  row directly with `status = active`, `source = manual`/`complimentary`,
  `provider = App\Support\Billing\BillingProviders::NONE` (a new constant,
  deliberately excluded from `BillingProviders::ALL` — that list means
  "configured billing integrations," a different question from what a
  non-Stripe row stores here), `provider_subscription_id`/
  `provider_price_id` both `null`; then calls
  `EntitlementSnapshotService::snapshotForActivation()` and logs the
  assignment via `ActivityLog` — **all inside the same transaction**, so a
  snapshot or audit failure rolls back the subscription too. This is a
  deliberate departure from `activate()`'s own contract (which commits the
  status change before snapshotting, correct for a real Stripe activation
  that may be retried independently) — a brand-new manual/complimentary
  row has no such retry concern, and G4B.2 requires full atomicity.
- `SubscriptionLifecycleService::terminateManualOrComplimentarySubscription()`
  — a thin, explicitly-named wrapper around the existing
  `cancelImmediately()`, refusing outright if
  `$subscription->source === SubscriptionSource::STRIPE`. Termination IS
  an immediate cancellation, restricted to non-Stripe sources — no new
  lifecycle state, no new cancellation mechanism.

**Correction is terminate-then-reassign, never in-place edit** — plan and
source remain immutable on an existing row (source's immutability was
already enforced at the model level in G4B.1); there is no dedicated
"correction" endpoint, just the two existing actions used in sequence,
made explicit in the UI copy.

**Provider invariants enforced, not merely documented**: the creation path
never accepts caller-supplied provider identifiers at all (they aren't
request fields), and `provider_subscription_id`/`provider_price_id` are
hardcoded to `null` in the one place a non-Stripe `Subscription` row is
ever created.

**Endpoint/authorization**: `App\Http\Controllers\Api\OrganizationSubscriptionAssignmentController`
— `POST /organizations/{organization}/subscriptions/assign-manual`,
`.../assign-complimentary`, `.../{subscription}/terminate` — a **new**
`role:Super Admin`-only route group (routes/api.php), deliberately
separate from G4A's `role:Super Admin|Admin` read-only group: Admin keeps
read access, never reaches these mutation routes. No `/api/super-admin`
prefix — matches this codebase's existing `organizations/{id}/...`
convention exactly (American spelling, no parallel URL scheme introduced
just because an endpoint mutates).

**Plan assignability**: `OrganizationSubscriptionAdminService::assignablePlans()`
queries `PricingPlan::where('status', 'active')` directly — deliberately
**not** `PricingPlan::scopeActive()`, which additionally requires
`is_visible`/`published_at` (marketing-page concerns). A commercially
active but marketing-hidden plan remains assignable by Super Admin.

**Conflict detection**: reuses `SubscriptionLifecycleService::hasConflictingSubscription()`
directly — no new `SubscriptionConflictService`. Returns `409` with a
stable `code` (`subscription_conflict` / `stripe_termination_not_permitted`)
on refusal, matching `SubscriptionCancellationController`'s existing
sanitised-message convention (never echoes the internal exception message
to the client).

**UI**: extends the existing `OrganizationSubscriptionSection.tsx` (G4A) —
no parallel administration page. Super-Admin-only Assign/Terminate
buttons (gated on the same `useAuthStore` role check `admin/users/page.tsx`
already uses), a plan/interval/reason/confirmation dialog for assignment
(with an explicit "no Stripe charge" statement, stronger for
complimentary), a restricted-access warning + reason + confirmation
dialog for termination, and inline copy explaining that plan/source
corrections require ending the subscription and assigning a replacement.

**Audit**: two new `ActivityLog` action names —
`subscription.manual_assigned` / `subscription.complimentary_assigned` —
distinct from each other and from the pre-existing generic
`subscription.cancelled` (reused unchanged for termination, since
termination genuinely is a cancellation — the `source` field already
visible on the subscription distinguishes it from a Stripe cancellation
in the UI, so no new action name was needed there). Metadata includes
`subscription_source`, `pricing_plan_id`, `reason`
(`TransitionContext::toLogMetadata()`'s existing `reason` field), and
`ends_at`.

**Regression test fix required**: the existing
`SubscriptionLifecycleServiceTest::test_row_locking_prevents_conflicting_simultaneous_transitions`
statically scans every public `SubscriptionLifecycleService` method to
confirm it locks before mutating. Updated its exclusion list to also
skip `assignManualSubscription()`/`assignComplimentarySubscription()`
(same reasoning as the existing `createDraftSubscription()` exclusion —
these create new rows, protected by `Cache::lock()`, not
`lockForUpdate()`), and extended its accepted-substring check to
recognise delegation via `$this->cancelImmediately(` (which itself routes
through the existing `transition()`/`lock()`) — a real, safe pattern the
test's static scan couldn't previously see through.

**Testing**: 29 new cases in
`tests/Feature/Admin/OrganizationSubscriptionAssignmentApiTest.php`
(authorization for all three roles + guest; plan/interval/reason/
confirmation/date validation; marketing-hidden-but-active plan remains
assignable; mutation correctness and null provider identifiers; source
immutability; `FeatureGate` resolution proof via a real
`PricingPlanEntitlement`; exactly-one-snapshot; audit metadata and G4A
`recent_activity` surfacing; conflict refusal for Stripe/manual/
complimentary rows and success against a fully-cancelled historical row;
cross-organisation isolation; termination success/Stripe-rejection/
Admin-denial/history-preservation; the full terminate-then-reassign
correction workflow; Stripe reconciliation exclusion). Full backend
suite: 1631 tests, 1606 passing — identical pre-existing 2 failures/20
errors, zero new (after fixing the row-locking test's exclusion list
above). Frontend `tsc --noEmit`, ESLint, `next build` all pass.

**MySQL validation** (disposable `suresign_g4b1_validation`, same
container as G4B.1A/B): fresh install succeeds through every migration
including G4B.1's `source` column. Ran the full workflow via `artisan
tinker` (phpunit always forces sqlite): manual assignment (correct
source/status/provider/null identifiers, snapshot created, `full` access,
activity logged), complimentary assignment to a separate organisation,
assignment refused against both a Stripe-connected conflict and a
duplicate manual/complimentary conflict (subscription count unchanged in
both cases), termination (status → `cancelled`, source unchanged, access
mode → `restricted`), the terminate-then-reassign correction workflow
(2 rows total, replacement is a distinct row with the new plan),
Stripe-source termination correctly refused, and Stripe reconciliation
scanning exactly the one genuine Stripe row (never the manual/
complimentary ones). Validation database dropped; real dev database
confirmed untouched (3 subscriptions, 3 organisations, before and after).

**Explicitly confirmed**: only Super Admin can assign or terminate;
Admin remains read-only; Client cannot mutate; only `manual`/
`complimentary` sources were introduced (no `testing`/`trial`/`legacy`);
Stripe source behaviour is unchanged; `FeatureGate` precedence and
`SubscriptionAccessPolicy` are unchanged; `EntitlementSnapshotService`
and `ActivityLog` were reused, not duplicated; provider identifiers
remain `null` for non-Stripe subscriptions; manual/complimentary
subscriptions never enter a Stripe workflow (no checkout session, no
webhook, excluded from reconciliation); source and snapshots remain
immutable; Manual→Stripe conversion was not implemented; AI Credits,
trial administration, testing/simulation, test organisations, temporary
entitlement overrides, and impersonation were not implemented.

## G4C.0 — SureSign AI Credits Architecture & Usage Sampling (investigation only)

Full report: `internal-docs/super-admin/ai-credits-architecture.md`.
Investigation-only phase — no code, migration, or database record was
changed; the local dev MySQL database was queried read-only.

**Key findings**: exactly two real, provider-backed AI workflows exist —
Contract AI Analysis and Trade Package AI Analysis, both calling
`ClaudeAiProvider` via the shared `AiProviderInterface`. The AI
chat/conversation feature (`ai_conversations`/`ai_messages`, registered
routes) is confirmed **non-functional** — `AiController` has none of the
methods those routes point at. The Prompt Library makes zero provider
calls. No Commercial/Delivery Document/Risk module has any AI
integration. The local dev database contains **zero** AI analysis rows
of any kind — every usage/cost/duration conclusion is therefore
architectural, not statistical.

An exact cache hit (same `document_hash` + `model`, prior
`completed`/`confirmed` row) is confirmed to make **no provider call** —
safe to treat as zero AI Credits whenever a ledger exists. Recommended
provisional model: hybrid (workflow × token band), marked provisional
pending production telemetry — no numeric band values were set. Proposed
allowance chain (Pricing Plan → Entitlement Snapshot → Organisation → AI
Credit Allowance → Ledger → Remaining Credits) confirmed still correct,
no change needed. Recommended ledger entry shape: typed debit/credit
entries (reservation/settlement/release/adjustment/cache-hit-zero-charge),
never a mutable running balance — the same principle already applied to
`Subscription.source`/entitlement resolution elsewhere in this codebase.

**Recommendation for G4C.1**: collect production telemetry before
building the ledger — add the small set of missing telemetry fields
(document length, cache-hit flag) to the existing analysis tables first,
resolve the duplicate `estimated_cost` computation, then observe real
usage for a full billing cycle before setting any numeric costing value.

**Explicitly confirmed**: no application code, migration, or database
record changed; the dev database was queried read-only; no AI Credit
ledger, reservation, settlement, or deduction was implemented; no
provider-call path, customer-visible behaviour, or AI workflow changed;
no subscription/entitlement/Stripe/trial/manual-complimentary/
simulation/impersonation behaviour changed.
