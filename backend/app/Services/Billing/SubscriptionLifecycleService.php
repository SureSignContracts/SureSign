<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionActivationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Entitlements\EntitlementSnapshotService;
use App\Support\Billing\BillingProviders;
use App\Support\Billing\BillingReferenceType;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\SubscriptionSource;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\SubscriptionStatusMapper;
use App\Support\Billing\SubscriptionTransitions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative path for every commercially significant
 * subscription state transition. Checkout, verified webhooks, Super Admin
 * actions, scheduled commands, and future provider-reconciliation
 * processes must all call this service rather than mutating
 * `subscriptions.status` (or any other commercial field) directly —
 * there is deliberately no public arbitrary status setter anywhere in this
 * class.
 *
 * This service never calls Stripe and never receives a raw
 * \Stripe\Subscription — every provider-originated input arrives as a
 * plain normalized array (the shape `BillingProviderInterface::
 * retrieveSubscription()` already returns), so a future webhook processor
 * only ever needs to produce that same normalized shape, never expose a
 * Stripe object to this class. See StripeBillingProvider::
 * normalizeSubscription()'s docblock for the one place period-field
 * normalization is isolated.
 *
 * ─── Status model ───────────────────────────────────────────────────────
 *
 * This checkpoint deliberately RETAINS the existing eleven-status
 * SubscriptionStatus vocabulary rather than adding `grace_period`,
 * `suspension_pending`, or `cancel_at_period_end` as statuses. Each of
 * those concepts is represented through a field instead, because each has
 * a genuinely different lifecycle from "current status":
 *
 *   - Grace period: `status = past_due` + `grace_period_ends_at` — a
 *     subscription in grace is still, structurally, past_due; the grace
 *     window is a policy detail about HOW past_due is being handled, not
 *     a different commercial state. (Live access behaviour during grace
 *     is a future access-policy decision, not decided here — see
 *     Part 16/access-enforcement boundary below.)
 *   - Suspension scheduling: recorded via ActivityLog + the dedicated
 *     `pending_suspension_reason`/`pending_suspension_effective_at`
 *     columns (`scheduleSuspension()` — Subscription Suspension Completion
 *     checkpoint; previously an untyped `metadata_json` note), NOT a
 *     status — the subscription stays in its current status
 *     (active/past_due/unpaid) until `suspend()` actually applies the
 *     SUSPENDED transition, whether called directly or by
 *     `SubscriptionAutomationService` once `pending_suspension_effective_at`
 *     is due. Modelling
 *     "suspension_pending" as a status would mean two different concepts
 *     both compete for "current status" at once (the reason payment
 *     collection is failing, AND the fact that suspension is planned) —
 *     keeping them separate is what lets `restoreToActive()` cleanly undo
 *     just the payment problem without needing a special case for
 *     "was suspension pending" bookkeeping.
 *   - Cancellation scheduling: `cancel_at_period_end` (boolean) on an
 *     otherwise-ACTIVE subscription — the subscription is fully active,
 *     with normal entitlements, right up until the scheduled date; a
 *     "cancel_at_period_end" STATUS would incorrectly suggest reduced
 *     access starts immediately, which it must not.
 *
 * Distinguishing five different axes, deliberately not collapsed into one
 * enum:
 *   1. Current lifecycle status — `SubscriptionStatus` (this class).
 *   2. Requested future action — `cancel_at_period_end`,
 *      `pending_pricing_plan_id`/`pending_billing_interval`/
 *      `plan_change_effective_at` (scheduled, not yet applied).
 *   3. Billing health — a presentation-layer concept (Commercial Strategy
 *      §17) derived FROM status, not stored separately.
 *   4. Provider status — never stored verbatim; translated once via
 *      `SubscriptionStatusMapper` before it ever reaches this service.
 *   5. Access policy / entitlement state — explicitly deferred (Part 16);
 *      this service records commercial state only.
 *
 * ─── Concurrency & idempotency ──────────────────────────────────────────
 *
 * Every transition method locks the subscription row (`lockForUpdate()`)
 * inside a transaction before validating or applying anything — no
 * check-then-update race. A transition to the subscription's CURRENT
 * status is treated as a safe no-op almost everywhere (repeated provider
 * events must never duplicate ActivityLog history) — `activate()` is the
 * one deliberate exception, since a repeat activation must still be
 * checked for conflicting identity before being allowed to no-op (see its
 * own docblock). `last_transition_occurred_at` (new this checkpoint) is
 * compared against `TransitionContext::$occurredAt` on every transition
 * that changes status, so an older provider event arriving after a newer
 * one was already applied is rejected as stale
 * (`SubscriptionLifecycleConflictException`) rather than silently rolling
 * the subscription backward. Full webhook-event-level idempotency
 * (persisting `billing_webhook_events` rows, retry bookkeeping) belongs to
 * the future webhook checkpoint — this service only guarantees that
 * whatever normalized command it's eventually called with is safe to
 * apply more than once.
 */
class SubscriptionLifecycleService
{
    private const SUPPORTED_INTERVALS = ['monthly', 'annual'];

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly BillingReferenceService $referenceService,
        private readonly EntitlementSnapshotService $snapshots,
    ) {
    }

    // ─── Creation ────────────────────────────────────────────────────────

    /**
     * Creates a draft subscription from SureSign's own commercial data — a
     * plan and an already-resolved, active, currently-live-mode-matching
     * provider price mapping. Snapshots the plan's code/name and the
     * mapping's currency/amount/interval immediately, so nothing about
     * this subscription's agreed terms ever depends on `pricing_plans`
     * still looking the same later (grandfathering).
     *
     * Idempotent when `$correlationReference` is supplied: a second call
     * with the same organisation + reference returns the existing
     * subscription rather than creating a duplicate.
     */
    public function createDraftSubscription(
        Organization $organization,
        PricingPlan $plan,
        PricingPlanProviderPrice $priceMapping,
        string $billingInterval,
        TransitionContext $context,
        ?string $correlationReference = null,
        ?int $billingCustomerId = null,
    ): Subscription {
        $this->assertSupportedInterval($billingInterval);
        $this->assertPlanIsSyncable($plan);

        if ($priceMapping->pricing_plan_id !== $plan->id) {
            throw new SubscriptionLifecycleConflictException(
                "Provider price mapping {$priceMapping->id} belongs to plan {$priceMapping->pricing_plan_id}, not plan {$plan->id}."
            );
        }

        if (!$priceMapping->is_active) {
            throw new SubscriptionLifecycleConflictException("Provider price mapping {$priceMapping->id} is not active.");
        }

        if ($priceMapping->billing_interval !== $billingInterval) {
            throw new SubscriptionLifecycleConflictException(
                "Provider price mapping {$priceMapping->id} is for {$priceMapping->billing_interval}, not {$billingInterval}."
            );
        }

        if ($priceMapping->livemode !== $this->provider->isLivemode()) {
            $mappingMode = $priceMapping->livemode ? 'live' : 'test';
            $currentMode = $this->provider->isLivemode() ? 'live' : 'test';

            throw new SubscriptionLifecycleConflictException(
                "Provider price mapping {$priceMapping->id} was created in {$mappingMode} mode but the current environment is {$currentMode} mode."
            );
        }

        $lock = Cache::lock("subscription-draft:{$organization->id}", 10);

        return $lock->block(5, function () use ($organization, $plan, $priceMapping, $billingInterval, $context, $correlationReference, $billingCustomerId) {
            if ($correlationReference !== null) {
                $existing = Subscription::query()
                    ->where('organization_id', $organization->id)
                    ->where('metadata_json->correlation_reference', $correlationReference)
                    ->first();

                if ($existing) {
                    return $existing;
                }
            }

            if ($this->hasConflictingSubscription($organization)) {
                throw new SubscriptionLifecycleConflictException(
                    "Organisation {$organization->id} already has a commercially conflicting subscription or a reusable open checkout intent — "
                    . 'resolve or cancel it before creating a new one.'
                );
            }

            return DB::transaction(function () use ($organization, $plan, $priceMapping, $billingInterval, $context, $correlationReference, $billingCustomerId) {
                $subscription = Subscription::create([
                    'organization_id' => $organization->id,
                    'pricing_plan_id' => $plan->id,
                    'billing_customer_id' => $billingCustomerId,
                    'provider' => $priceMapping->provider,
                    // G4B.1 — the only production Subscription-creation
                    // path, and it only ever runs against a real Stripe
                    // provider price mapping (this method's own
                    // `$priceMapping` parameter) — always genuinely
                    // stripe-sourced. Set explicitly rather than relying on
                    // the migration's backward-compatibility DB default.
                    'source' => SubscriptionSource::STRIPE,
                    'provider_price_id' => $priceMapping->provider_price_id,
                    'livemode' => $priceMapping->livemode,
                    'internal_reference' => $this->referenceService->generate(BillingReferenceType::SUBSCRIPTION),
                    'status' => SubscriptionStatus::DRAFT,
                    'billing_interval' => $billingInterval,
                    'currency' => $priceMapping->currency,
                    'unit_amount' => $priceMapping->unit_amount,
                    'quantity' => 1,
                    'subtotal_amount' => $priceMapping->unit_amount,
                    'total_amount' => $priceMapping->unit_amount,
                    'plan_code_snapshot' => $plan->code,
                    'plan_name_snapshot' => $plan->name,
                    'metadata_json' => $correlationReference ? ['correlation_reference' => $correlationReference] : null,
                    'created_by_user_id' => $context->actorUserId,
                    'last_transition_occurred_at' => $context->occurredAt,
                ]);

                $this->log($subscription, 'subscription.created', "Created draft subscription for \"{$organization->name}\" ({$plan->name})", $context);

                return $subscription;
            });
        });
    }

    // ─── G4B.2 — Manual & Complimentary assignment ────────────────────────

    /**
     * The only two production entry points that create a non-Stripe
     * Subscription row — deliberately explicit, not a generic
     * `assignSubscription($source)`, so each commercial origin stays its
     * own independently auditable action (Activity action names below
     * differ accordingly). Both create the row already `active`,
     * immediately create its activation entitlement snapshot, and log the
     * assignment — all inside one transaction (see assignNonStripeSubscription()).
     */
    public function assignManualSubscription(
        Organization $organization,
        PricingPlan $plan,
        string $billingInterval,
        string $reason,
        TransitionContext $context,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        return $this->assignNonStripeSubscription(
            $organization, $plan, $billingInterval, SubscriptionSource::MANUAL, $reason, $context, $startsAt, $endsAt,
            'subscription.manual_assigned', 'Manual',
        );
    }

    public function assignComplimentarySubscription(
        Organization $organization,
        PricingPlan $plan,
        string $billingInterval,
        string $reason,
        TransitionContext $context,
        ?CarbonImmutable $startsAt = null,
        ?CarbonImmutable $endsAt = null,
    ): Subscription {
        return $this->assignNonStripeSubscription(
            $organization, $plan, $billingInterval, SubscriptionSource::COMPLIMENTARY, $reason, $context, $startsAt, $endsAt,
            'subscription.complimentary_assigned', 'Complimentary',
        );
    }

    /**
     * Shared implementation behind the two explicit public methods above —
     * not itself public, so there is still no generic
     * `assignSubscription($source)` entry point callable from outside this
     * service. Reuses the exact same per-organisation lock
     * `createDraftSubscription()` already takes (never two conflicting
     * subscriptions racing into existence), re-checks
     * `hasConflictingSubscription()` a second time once inside the DB
     * transaction (closing the TOCTOU window between the pre-lock check
     * and the transaction acquiring its own locks), and creates the
     * subscription, its activation snapshot, and its ActivityLog entry
     * all inside that one transaction — a snapshot or audit failure rolls
     * back the subscription row too. Deliberately does NOT reuse
     * activate()'s own transaction (that method commits the status change
     * BEFORE snapshotting, the correct contract for a real Stripe
     * activation which may be retried independently — this is a brand new
     * row with no such retry concern, and G4B.2 requires full atomicity
     * instead).
     */
    private function assignNonStripeSubscription(
        Organization $organization,
        PricingPlan $plan,
        string $billingInterval,
        string $source,
        string $reason,
        TransitionContext $context,
        ?CarbonImmutable $startsAt,
        ?CarbonImmutable $endsAt,
        string $activityAction,
        string $sourceLabel,
    ): Subscription {
        $this->assertSupportedInterval($billingInterval);

        if (trim($reason) === '') {
            throw new InvalidSubscriptionTransitionException("A reason is required to assign a {$source} subscription.");
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
            throw new SubscriptionLifecycleConflictException('ends_at must be after starts_at.');
        }

        $lock = Cache::lock("subscription-draft:{$organization->id}", 10);

        return $lock->block(5, function () use ($organization, $plan, $billingInterval, $source, $reason, $context, $startsAt, $endsAt, $activityAction, $sourceLabel) {
            $this->assertNoConflictingSubscription($organization, $source);

            return DB::transaction(function () use ($organization, $plan, $billingInterval, $source, $reason, $context, $startsAt, $endsAt, $activityAction, $sourceLabel) {
                // Re-checked inside the transaction — the lock above already
                // makes this practically redundant for two callers going
                // through this same service, but costs nothing and closes
                // the theoretical window against any other writer.
                $this->assertNoConflictingSubscription($organization, $source);

                $effectiveStart = $startsAt ?? $context->occurredAt;

                $subscription = Subscription::create([
                    'organization_id' => $organization->id,
                    'pricing_plan_id' => $plan->id,
                    'billing_customer_id' => null,
                    'provider' => BillingProviders::NONE,
                    'source' => $source,
                    'provider_subscription_id' => null,
                    'provider_checkout_session_id' => null,
                    'provider_price_id' => null,
                    'livemode' => $this->provider->isLivemode(),
                    'internal_reference' => $this->referenceService->generate(BillingReferenceType::SUBSCRIPTION),
                    'status' => SubscriptionStatus::ACTIVE,
                    'billing_interval' => $billingInterval,
                    'currency' => $plan->currency ?? 'GBP',
                    'unit_amount' => null,
                    'quantity' => 1,
                    'subtotal_amount' => null,
                    'tax_amount' => null,
                    'total_amount' => null,
                    'starts_at' => $effectiveStart,
                    'activated_at' => $effectiveStart,
                    'current_period_starts_at' => $effectiveStart,
                    'current_period_ends_at' => $endsAt,
                    'plan_code_snapshot' => $plan->code,
                    'plan_name_snapshot' => $plan->name,
                    'created_by_user_id' => $context->actorUserId,
                    'last_transition_occurred_at' => $context->occurredAt,
                ]);

                $this->log($subscription, $activityAction, "{$sourceLabel} subscription assigned for \"{$organization->name}\" ({$plan->name})", $context, [
                    'subscription_source' => $source,
                    'ends_at' => $endsAt?->toIso8601String(),
                ]);

                $snapshot = $this->snapshots->snapshotForActivation($subscription, CarbonImmutable::instance($effectiveStart));

                $this->log($subscription, 'subscription.entitlement_snapshot_created', "Entitlement snapshot created for {$subscription->internal_reference}", $context, [
                    'snapshot_id' => $snapshot->id,
                ]);

                return $subscription->fresh();
            });
        });
    }

    /**
     * Super Admin-only termination of a `manual`/`complimentary`
     * subscription — deliberately refuses a `stripe`-source row (that
     * lifecycle remains Stripe's own — see SubscriptionCancellationService).
     * A thin, explicitly-named wrapper around the existing
     * cancelImmediately() rather than a new lifecycle state: termination
     * IS an immediate cancellation, just restricted to non-Stripe sources.
     * Never deletes the row, never touches its entitlement snapshot(s) —
     * cancelImmediately() only ever changes status/cancelled_at/ended_at.
     */
    public function terminateManualOrComplimentarySubscription(Subscription $subscription, string $reason, TransitionContext $context): Subscription
    {
        if ($subscription->source === SubscriptionSource::STRIPE) {
            throw new SubscriptionLifecycleConflictException(
                "Subscription {$subscription->internal_reference} is Stripe-sourced — it must be cancelled through the Stripe subscription lifecycle, not manual termination."
            );
        }

        return $this->cancelImmediately($subscription, $reason, $context);
    }

    /**
     * @throws SubscriptionLifecycleConflictException
     */
    private function assertNoConflictingSubscription(Organization $organization, string $source): void
    {
        if ($this->hasConflictingSubscription($organization)) {
            throw new SubscriptionLifecycleConflictException(
                "Organisation {$organization->id} already has a commercially conflicting subscription — "
                . "it must be terminated or resolved before assigning a new {$source} subscription."
            );
        }
    }

    // ─── Trial ───────────────────────────────────────────────────────────

    public function startTrial(Subscription $subscription, CarbonImmutable $trialEndsAt, TransitionContext $context): Subscription
    {
        $result = $this->transition($subscription, SubscriptionStatus::TRIALING, $context, function (Subscription $locked) use ($trialEndsAt, $context) {
            $locked->trial_ends_at = $trialEndsAt;
            $locked->starts_at ??= $context->occurredAt;
        }, 'subscription.trial_started', 'Started trial');

        // Snapshot creation happens at this authoritative boundary — not
        // only via a scheduler — per the Subscription Commercial State
        // Automation checkpoint (Part 4). A repeat call (already-trialing
        // no-op above) still reaches here, but EntitlementSnapshotService
        // reuses the existing row rather than duplicating it.
        $this->snapshots->snapshotForTrialStart($result, $context->occurredAt);

        return $result;
    }

    // ─── Pending payment ─────────────────────────────────────────────────

    public function markPendingPayment(Subscription $subscription, TransitionContext $context, ?string $providerCheckoutSessionId = null): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::PENDING_PAYMENT, $context, function (Subscription $locked) use ($providerCheckoutSessionId) {
            if ($providerCheckoutSessionId !== null) {
                $locked->provider_checkout_session_id = $providerCheckoutSessionId;
            }
        }, 'subscription.payment_pending', 'Marked pending payment');
    }

    // ─── Incomplete (first payment attempt still pending) ────────────────

    /**
     * Records that Stripe reports the subscription's first payment attempt
     * as still pending (typically 3-D Secure / SCA authentication not yet
     * completed) — the ONLY path into `SubscriptionStatus::INCOMPLETE`
     * (added in the Subscription Event Hardening checkpoint; no other
     * method, and no direct field assignment anywhere in the codebase, may
     * set this status). Valid only from `pending_payment` per
     * `SubscriptionTransitions::MAP` — a subscription reaching `incomplete`
     * from anywhere else indicates a correlation problem the caller
     * (`WebhookEventProcessor`) must treat as a conflict rather than
     * calling this method at all.
     *
     * Deliberately narrower than `activate()`: does NOT require period
     * dates (an incomplete subscription's billing period is not yet
     * meaningful — no invoice has been paid) and does NOT grant access or
     * create any entitlement — this method records commercial state only,
     * exactly like every other transition method in this class.
     *
     * Preserves provider subscription identity the same way `activate()`
     * does: if the subscription already has a DIFFERENT
     * `provider_subscription_id` recorded, this throws rather than
     * silently relinking — a conflicting identity is never repaired
     * automatically.
     *
     * @param array{id: string, livemode: bool} $normalizedProviderSubscription minimal shape — only `id`/`livemode` are read
     */
    public function markIncomplete(Subscription $subscription, array $normalizedProviderSubscription, TransitionContext $context): Subscription
    {
        if (empty($normalizedProviderSubscription['id'])) {
            throw new SubscriptionActivationException('Provider subscription data was missing a required identifier "id".');
        }

        return DB::transaction(function () use ($subscription, $normalizedProviderSubscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if (($normalizedProviderSubscription['livemode'] ?? null) !== null && $normalizedProviderSubscription['livemode'] !== $locked->livemode) {
                throw new SubscriptionActivationException(
                    "Provider subscription {$normalizedProviderSubscription['id']} livemode does not match subscription {$locked->internal_reference}."
                );
            }

            if ($locked->provider_subscription_id !== null && $locked->provider_subscription_id !== $normalizedProviderSubscription['id']) {
                throw new SubscriptionActivationException(
                    "Subscription {$locked->internal_reference} is already linked to provider subscription {$locked->provider_subscription_id}, "
                    . "refusing to relink to {$normalizedProviderSubscription['id']}."
                );
            }

            if ($locked->status === SubscriptionStatus::INCOMPLETE) {
                // Safe repeat — identity already confirmed matching above.
                return $locked;
            }

            if (!SubscriptionTransitions::canTransition($locked->status, SubscriptionStatus::INCOMPLETE)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot mark subscription {$locked->internal_reference} incomplete from status \"{$locked->status}\"."
                );
            }

            $locked->provider_subscription_id = $normalizedProviderSubscription['id'];
            $locked->status = SubscriptionStatus::INCOMPLETE;
            $locked->last_transition_occurred_at = $context->occurredAt;
            $locked->save();

            $this->log($locked, 'subscription.marked_incomplete', 'Marked incomplete (first payment attempt pending)', $context, [
                'provider_subscription_id' => $normalizedProviderSubscription['id'],
            ]);

            return $locked;
        });
    }

    // ─── Activation ──────────────────────────────────────────────────────

    /**
     * Activates a subscription from a normalized provider subscription
     * read (the shape `BillingProviderInterface::retrieveSubscription()`
     * returns) — never a raw \Stripe\Subscription.
     *
     * Deliberately NOT routed through the generic same-status no-op short
     * circuit every other transition method uses: a repeat call while
     * already ACTIVE still validates that the incoming provider
     * subscription ID matches what's already recorded before treating it
     * as an idempotent no-op — a conflicting repeat (different provider
     * subscription ID while already active) throws instead of silently
     * being ignored, per this checkpoint's "conflicting activation details
     * are rejected" requirement.
     *
     * @param array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool} $normalizedProviderSubscription
     */
    public function activate(Subscription $subscription, array $normalizedProviderSubscription, TransitionContext $context): Subscription
    {
        if (empty($normalizedProviderSubscription['id'])) {
            throw new SubscriptionActivationException('Provider subscription data was missing a required identifier "id".');
        }

        if ($normalizedProviderSubscription['current_period_start'] === null || $normalizedProviderSubscription['current_period_end'] === null) {
            throw new SubscriptionActivationException(
                "Provider subscription {$normalizedProviderSubscription['id']} is missing required period dates."
            );
        }

        $activated = DB::transaction(function () use ($subscription, $normalizedProviderSubscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if (($normalizedProviderSubscription['livemode'] ?? null) !== $locked->livemode) {
                throw new SubscriptionActivationException(
                    "Provider subscription {$normalizedProviderSubscription['id']} livemode does not match subscription {$locked->internal_reference}."
                );
            }

            if ($locked->provider_subscription_id !== null && $locked->provider_subscription_id !== $normalizedProviderSubscription['id']) {
                throw new SubscriptionActivationException(
                    "Subscription {$locked->internal_reference} is already linked to provider subscription {$locked->provider_subscription_id}, "
                    . "refusing to relink to {$normalizedProviderSubscription['id']}."
                );
            }

            if ($locked->status === SubscriptionStatus::ACTIVE) {
                // Identity already confirmed matching above — a genuine,
                // safe repeat of an already-applied activation.
                return $locked;
            }

            if (!SubscriptionTransitions::canTransition($locked->status, SubscriptionStatus::ACTIVE)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot activate subscription {$locked->internal_reference} from status \"{$locked->status}\"."
                );
            }

            $locked->provider_subscription_id = $normalizedProviderSubscription['id'];
            $locked->current_period_starts_at = CarbonImmutable::createFromTimestampUTC($normalizedProviderSubscription['current_period_start']);
            $locked->current_period_ends_at = CarbonImmutable::createFromTimestampUTC($normalizedProviderSubscription['current_period_end']);
            $locked->cancel_at_period_end = $normalizedProviderSubscription['cancel_at_period_end'] ?? false;

            if (!empty($normalizedProviderSubscription['trial_end'])) {
                $locked->trial_ends_at = CarbonImmutable::createFromTimestampUTC($normalizedProviderSubscription['trial_end']);
            }

            $locked->activated_at ??= $context->occurredAt;
            $locked->starts_at ??= $context->occurredAt;
            $locked->status = SubscriptionStatus::ACTIVE;
            $locked->last_transition_occurred_at = $context->occurredAt;
            $locked->save();

            $this->log($locked, 'subscription.activated', 'Activated subscription', $context, [
                'provider_subscription_id' => $normalizedProviderSubscription['id'],
            ]);

            return $locked;
        });

        // Snapshot creation at the authoritative activation boundary
        // itself — never only via a scheduler — so it fires identically
        // whether activation came from a verified webhook, a sales-assisted
        // path, or any other approved caller of this method (Part 4).
        $this->snapshots->snapshotForActivation($activated, CarbonImmutable::instance($activated->activated_at ?? $context->occurredAt));

        return $activated;
    }

    // ─── Payment problems ────────────────────────────────────────────────

    public function markPastDue(Subscription $subscription, TransitionContext $context): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::PAST_DUE, $context, function () {
        }, 'subscription.past_due', 'Marked past due');
    }

    /**
     * Records a grace-period window for an already-past_due subscription —
     * does NOT change status (see class docblock on why grace period is a
     * field, not a status).
     */
    public function startGracePeriod(Subscription $subscription, CarbonImmutable $graceEndsAt, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $graceEndsAt, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->status !== SubscriptionStatus::PAST_DUE) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot start a grace period for subscription {$locked->internal_reference} from status \"{$locked->status}\" — requires past_due."
                );
            }

            if ($locked->grace_period_ends_at !== null && $locked->grace_period_ends_at->equalTo($graceEndsAt)) {
                return $locked;
            }

            $locked->grace_period_ends_at = $graceEndsAt;
            $locked->save();

            $this->log($locked, 'subscription.grace_started', 'Started grace period', $context, [
                'grace_period_ends_at' => $graceEndsAt->toIso8601String(),
            ]);

            return $locked;
        });
    }

    /**
     * Recovery path from past_due or suspended back to active — distinct
     * from activate() (first activation after payment) even though both
     * land on ACTIVE, because the required inputs and meaning differ (no
     * provider subscription identity is being established here, just a
     * status recovery).
     *
     * This IS the "resume"/reactivation path a suspended subscription uses
     * (Subscription Suspension Completion checkpoint) — no separate
     * `resume()` method was added; inventing one would duplicate this
     * already-correct transition for no behavioural difference. Resuming
     * deliberately does NOT create a new entitlement snapshot: no
     * commercial entitlement changed, only an operational access
     * restriction was lifted, so the subscription's existing (pre-
     * suspension) snapshot remains the authoritative one — see
     * `EntitlementSnapshotService`'s class docblock and this checkpoint's
     * documentation for the full reasoning.
     */
    public function restoreToActive(Subscription $subscription, TransitionContext $context): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::ACTIVE, $context, function (Subscription $locked) {
            $locked->grace_period_ends_at = null;
            $locked->suspended_at = null;
            $locked->suspension_reason = null;
        }, 'subscription.restored', 'Restored to active');
    }

    public function markUnpaid(Subscription $subscription, TransitionContext $context): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::UNPAID, $context, function () {
        }, 'subscription.unpaid', 'Marked unpaid');
    }

    // ─── Suspension ──────────────────────────────────────────────────────

    /**
     * Subscription Suspension Completion checkpoint — records a suspension
     * REQUEST without changing status (see class docblock: suspension
     * scheduling is a field, not a status), with an authoritative
     * `effective_at`. Requires the subscription to currently be in a
     * status that can actually reach SUSPENDED
     * (`SubscriptionTransitions::MAP` — today that's `active`/`past_due`/
     * `unpaid`; `trialing` has no path to `suspended` and this checkpoint
     * deliberately did not add one, since nothing in the approved
     * commercial model requires suspending a trial rather than simply
     * letting it expire).
     *
     * `$effectiveAt` defaults to `$context->occurredAt` ("now") when
     * omitted — this is how an IMMEDIATE suspension request is
     * represented: the same method, the same pending fields, just an
     * effective date that is already due. It is deliberately NOT applied
     * synchronously inside this call — `SubscriptionAutomationService`
     * picks up anything with `pending_suspension_effective_at <= now()` on
     * its next tick, exactly like every other automated transition in this
     * checkpoint series. A caller that genuinely needs a synchronous,
     * immediate status change (bypassing automation entirely) should call
     * `suspend()` directly instead — both remain valid, distinct entry
     * points for different callers, not duplicates of the same concept.
     *
     * Throws if a suspension is ALREADY pending — call
     * `rescheduleSuspension()` to change an existing pending request's date
     * instead of silently overwriting it here (Part 4's explicit
     * requirement: "never silently overwrite a different pending
     * commercial action").
     */
    public function scheduleSuspension(Subscription $subscription, string $reason, TransitionContext $context, ?CarbonImmutable $effectiveAt = null): Subscription
    {
        if (trim($reason) === '') {
            throw new InvalidSubscriptionTransitionException('A reason is required to schedule a suspension.');
        }

        return DB::transaction(function () use ($subscription, $reason, $context, $effectiveAt) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if (!SubscriptionTransitions::canTransition($locked->status, SubscriptionStatus::SUSPENDED)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot schedule suspension for subscription {$locked->internal_reference} from status \"{$locked->status}\"."
                );
            }

            if ($locked->pending_suspension_effective_at !== null) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} already has a pending suspension scheduled for "
                    . "{$locked->pending_suspension_effective_at->toIso8601String()} — use rescheduleSuspension() to change it, "
                    . 'or cancelScheduledSuspension() to remove it first.'
                );
            }

            $resolvedEffectiveAt = $effectiveAt ?? $context->occurredAt;

            $locked->pending_suspension_reason = $reason;
            $locked->pending_suspension_effective_at = $resolvedEffectiveAt;
            $locked->save();

            $this->log($locked, 'subscription.suspension_scheduled', 'Scheduled suspension', $context, [
                'reason' => $reason,
                'effective_at' => $resolvedEffectiveAt->toIso8601String(),
            ]);

            return $locked;
        });
    }

    /**
     * Changes an already-pending suspension's effective date (and,
     * optionally, its reason) — throws if nothing is currently pending,
     * distinguishing "reschedule an existing request" from "schedule a new
     * one" per Part 4/7's explicit requirement that the two never be
     * silently conflated.
     */
    public function rescheduleSuspension(Subscription $subscription, CarbonImmutable $newEffectiveAt, TransitionContext $context, ?string $newReason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $newEffectiveAt, $context, $newReason) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->pending_suspension_effective_at === null) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} has no pending suspension to reschedule."
                );
            }

            if (!SubscriptionTransitions::canTransition($locked->status, SubscriptionStatus::SUSPENDED)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot reschedule suspension for subscription {$locked->internal_reference} from status \"{$locked->status}\"."
                );
            }

            $locked->pending_suspension_effective_at = $newEffectiveAt;

            if ($newReason !== null) {
                if (trim($newReason) === '') {
                    throw new InvalidSubscriptionTransitionException('A reason cannot be blanked out when rescheduling a suspension.');
                }

                $locked->pending_suspension_reason = $newReason;
            }

            $locked->save();

            $this->log($locked, 'subscription.suspension_rescheduled', 'Rescheduled pending suspension', $context, [
                'reason' => $locked->pending_suspension_reason,
                'effective_at' => $newEffectiveAt->toIso8601String(),
            ]);

            return $locked;
        });
    }

    /**
     * Cancels a pending suspension request BEFORE it becomes effective —
     * never changes subscription status (there is none to undo; the
     * subscription never left its current status while the suspension was
     * only pending). Idempotent: calling this when nothing is pending is a
     * safe, silent no-op (Part 7's explicit requirement) rather than an
     * exception — mirroring every other same-state no-op in this class.
     *
     * `$auditReason` lets a caller (typically
     * `SubscriptionAutomationService`, when it discovers a pending
     * suspension that is no longer applicable because the subscription's
     * status changed in the meantime) record WHY the pending request was
     * discarded, distinct from an operator's deliberate cancellation.
     */
    public function cancelScheduledSuspension(Subscription $subscription, TransitionContext $context, string $auditReason = 'Cancelled before taking effect'): Subscription
    {
        return DB::transaction(function () use ($subscription, $context, $auditReason) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->pending_suspension_effective_at === null) {
                // Safe no-op — nothing pending to cancel.
                return $locked;
            }

            $previousReason = $locked->pending_suspension_reason;
            $previousEffectiveAt = $locked->pending_suspension_effective_at;

            $locked->pending_suspension_reason = null;
            $locked->pending_suspension_effective_at = null;
            $locked->save();

            $this->log($locked, 'subscription.suspension_cancelled', 'Cancelled pending suspension', $context, [
                'previous_reason' => $previousReason,
                'previous_effective_at' => $previousEffectiveAt->toIso8601String(),
                'audit_reason' => $auditReason,
            ]);

            return $locked;
        });
    }

    /**
     * Applies suspension immediately — the direct, synchronous entry point
     * (see `scheduleSuspension()`'s docblock for how this differs from a
     * scheduled/automated suspension). Also clears any pending suspension
     * request fields, whether this call originated from
     * `SubscriptionAutomationService` applying a due one or from a direct
     * caller bypassing scheduling entirely — either way, once actually
     * suspended, nothing should remain "pending."
     */
    public function suspend(Subscription $subscription, string $reason, TransitionContext $context): Subscription
    {
        if (trim($reason) === '') {
            throw new InvalidSubscriptionTransitionException('A reason is required to suspend a subscription.');
        }

        return $this->transition($subscription, SubscriptionStatus::SUSPENDED, $context, function (Subscription $locked) use ($reason, $context) {
            $locked->suspended_at = $context->occurredAt;
            $locked->suspension_reason = $reason;
            $locked->pending_suspension_reason = null;
            $locked->pending_suspension_effective_at = null;
        }, 'subscription.suspended', 'Suspended', ['reason' => $reason]);
    }

    // ─── Cancellation ────────────────────────────────────────────────────

    /**
     * Schedules cancellation for the current period's end — the
     * subscription remains fully ACTIVE (see class docblock: this is a
     * scheduling field, not a status) until confirmCancellation() applies
     * it later.
     */
    public function scheduleCancellation(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->status !== SubscriptionStatus::ACTIVE) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot schedule cancellation for subscription {$locked->internal_reference} from status \"{$locked->status}\" — requires active."
                );
            }

            if ($locked->cancel_at_period_end) {
                return $locked;
            }

            $locked->cancel_at_period_end = true;
            $locked->save();

            $this->log($locked, 'subscription.cancellation_scheduled', 'Scheduled cancellation at period end', $context);

            return $locked;
        });
    }

    /**
     * Undoes a period-end-scheduled cancellation (Billing Architecture
     * Audit + Slice E1 checkpoint) — mirrors `cancelScheduledSuspension()`/
     * `cancelScheduledPlanChange()`'s exact shape: idempotent no-op if
     * nothing is pending, never changes `status`, never touches an
     * entitlement snapshot (nothing commercial changed — the subscription
     * was never anything other than ACTIVE throughout).
     */
    public function cancelScheduledCancellation(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if (!$locked->cancel_at_period_end) {
                // Safe no-op — nothing pending to undo.
                return $locked;
            }

            $locked->cancel_at_period_end = false;
            $locked->save();

            $this->log($locked, 'subscription.cancellation_undone', 'Undid scheduled cancellation', $context);

            return $locked;
        });
    }

    /**
     * Immediate cancellation — always requires an explicit reason, unlike
     * a scheduled cancellation confirming a previously-agreed date.
     */
    public function cancelImmediately(Subscription $subscription, string $reason, TransitionContext $context): Subscription
    {
        if (trim($reason) === '') {
            throw new InvalidSubscriptionTransitionException('A reason is required to cancel a subscription immediately.');
        }

        return $this->transition($subscription, SubscriptionStatus::CANCELLED, $context, function (Subscription $locked) use ($context) {
            $effective = $context->effectiveAt ?? $context->occurredAt;
            $locked->cancelled_at = $effective;
            $locked->ended_at = $effective;
            $locked->cancel_at_period_end = false;
        }, 'subscription.cancelled', 'Cancelled immediately', ['reason' => $reason, 'immediate' => true]);
    }

    /**
     * Confirms a previously-scheduled cancel_at_period_end has now taken
     * effect (the period end was reached) — distinct from
     * cancelImmediately(), which requires no prior scheduling at all.
     */
    public function confirmCancellation(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->status === SubscriptionStatus::CANCELLED) {
                return $locked;
            }

            if ($locked->status !== SubscriptionStatus::ACTIVE || !$locked->cancel_at_period_end) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} has no scheduled cancellation to confirm."
                );
            }

            if (!SubscriptionTransitions::canTransition($locked->status, SubscriptionStatus::CANCELLED)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot confirm cancellation for subscription {$locked->internal_reference} from status \"{$locked->status}\"."
                );
            }

            $effective = $context->effectiveAt ?? $locked->current_period_ends_at ?? $context->occurredAt;
            $locked->cancelled_at = $effective;
            $locked->ended_at = $effective;
            $locked->status = SubscriptionStatus::CANCELLED;
            $locked->last_transition_occurred_at = $context->occurredAt;
            $locked->save();

            $this->log($locked, 'subscription.cancelled', 'Confirmed scheduled cancellation', $context, ['immediate' => false]);

            return $locked;
        });
    }

    // ─── Expiry ──────────────────────────────────────────────────────────

    public function expire(Subscription $subscription, TransitionContext $context): Subscription
    {
        return $this->transition($subscription, SubscriptionStatus::EXPIRED, $context, function (Subscription $locked) use ($context) {
            $locked->ended_at ??= $context->occurredAt;
        }, 'subscription.expired', 'Expired');
    }

    // ─── Plan changes (preparation only — see class docblock) ───────────

    /**
     * Prepares an upgrade — records the intended new plan/interval and an
     * immediate effective date, but does NOT touch the subscription's
     * CURRENT plan/amount fields, and does NOT call the provider. Actually
     * applying the change (resolving/creating the new provider Price
     * relationship on the live Stripe subscription, updating
     * pricing_plan_id/unit_amount, and creating a new entitlement
     * snapshot) requires a provider call this checkpoint explicitly
     * excludes — that belongs to a future Checkout/webhook-integrated
     * checkpoint, which reads pending_pricing_plan_id/
     * pending_billing_interval/plan_change_effective_at from here.
     */
    public function scheduleUpgrade(
        Subscription $subscription,
        PricingPlan $newPlan,
        PricingPlanProviderPrice $newPriceMapping,
        string $newBillingInterval,
        TransitionContext $context,
    ): Subscription {
        return $this->preparePlanChange($subscription, $newPlan, $newPriceMapping, $newBillingInterval, $context, isUpgrade: true, effectiveAt: $context->effectiveAt ?? $context->occurredAt);
    }

    /**
     * Prepares a downgrade — same preparation-only semantics as
     * scheduleUpgrade(), but defaults the effective date to the current
     * billing period's end (renewal-aligned) rather than immediately,
     * consistent with the approved commercial policy that downgrades
     * normally take effect at renewal, not mid-cycle.
     */
    public function scheduleDowngrade(
        Subscription $subscription,
        PricingPlan $newPlan,
        PricingPlanProviderPrice $newPriceMapping,
        string $newBillingInterval,
        TransitionContext $context,
    ): Subscription {
        $effectiveAt = $context->effectiveAt ?? $subscription->current_period_ends_at;

        if ($effectiveAt === null) {
            throw new SubscriptionLifecycleConflictException(
                "Subscription {$subscription->internal_reference} has no known current period end to schedule a downgrade against — supply an explicit effective date."
            );
        }

        return $this->preparePlanChange($subscription, $newPlan, $newPriceMapping, $newBillingInterval, $context, isUpgrade: false, effectiveAt: $effectiveAt);
    }

    private function preparePlanChange(
        Subscription $subscription,
        PricingPlan $newPlan,
        PricingPlanProviderPrice $newPriceMapping,
        string $newBillingInterval,
        TransitionContext $context,
        bool $isUpgrade,
        $effectiveAt,
    ): Subscription {
        $this->assertSupportedInterval($newBillingInterval);
        $this->assertPlanIsSyncable($newPlan);

        if ($newPriceMapping->pricing_plan_id !== $newPlan->id || !$newPriceMapping->is_active) {
            throw new SubscriptionLifecycleConflictException(
                "Provider price mapping {$newPriceMapping->id} is not a valid active mapping for plan {$newPlan->id}."
            );
        }

        if ($newPriceMapping->livemode !== $this->provider->isLivemode()) {
            throw new SubscriptionLifecycleConflictException(
                "Provider price mapping {$newPriceMapping->id} livemode does not match the current environment."
            );
        }

        return DB::transaction(function () use ($subscription, $newPlan, $newBillingInterval, $context, $isUpgrade, $effectiveAt) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->status !== SubscriptionStatus::ACTIVE) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot schedule a plan change for subscription {$locked->internal_reference} from status \"{$locked->status}\" — requires active."
                );
            }

            if ($locked->pricing_plan_id === $newPlan->id) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} is already on plan {$newPlan->id}."
                );
            }

            $locked->pending_pricing_plan_id = $newPlan->id;
            $locked->pending_billing_interval = $newBillingInterval;
            $locked->plan_change_effective_at = $effectiveAt;
            $locked->save();

            $this->log($locked, 'subscription.plan_change_scheduled', $isUpgrade ? 'Scheduled upgrade' : 'Scheduled downgrade', $context, [
                'pending_pricing_plan_id' => $newPlan->id,
                'pending_billing_interval' => $newBillingInterval,
                'plan_change_effective_at' => $effectiveAt instanceof CarbonImmutable ? $effectiveAt->toIso8601String() : (string) $effectiveAt,
                'is_upgrade' => $isUpgrade,
            ]);

            return $locked;
        });
    }

    /**
     * Cancels a pending scheduled plan change BEFORE it has been applied —
     * never changes status or the current plan. Idempotent: nothing
     * pending is a safe no-op (mirrors `cancelScheduledSuspension()`'s
     * shape). The provider-side outbound update (if one was already sent —
     * see `SubscriptionPlanChangeService`) is that service's own concern;
     * this method only ever clears SureSign's own pending-plan-change
     * fields.
     */
    public function cancelScheduledPlanChange(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->pending_pricing_plan_id === null) {
                // Safe no-op — nothing pending to cancel.
                return $locked;
            }

            $previousPendingPlanId = $locked->pending_pricing_plan_id;

            $locked->pending_pricing_plan_id = null;
            $locked->pending_billing_interval = null;
            $locked->plan_change_effective_at = null;
            $locked->save();

            $this->log($locked, 'subscription.plan_change_cancelled', 'Cancelled pending plan change', $context, [
                'previous_pending_pricing_plan_id' => $previousPendingPlanId,
            ]);

            return $locked;
        });
    }

    /**
     * Stripe Test Mode Integration checkpoint — applies a plan change ONLY
     * after a verified webhook has confirmed the provider subscription
     * actually reports the target Price (see
     * `App\Services\Billing\SubscriptionPlanChangeService::confirmFromProvider()`,
     * the sole caller). Never called from an outbound API response alone
     * (Non-negotiable Principle 11). Requires `pending_pricing_plan_id` to
     * still match `$newPlan` — if it doesn't (already applied, or was
     * cancelled/superseded in the meantime), throws
     * `SubscriptionLifecycleConflictException` rather than silently
     * reapplying, giving the caller an explicit "already applied" signal
     * to treat as a safe idempotent no-op.
     *
     * Does NOT change `status` — a plan change never affects which
     * lifecycle status the subscription is in, only which plan/amount it
     * is on. Snapshot creation happens AFTER this transition commits (the
     * same "authoritative lifecycle boundary" pattern as `activate()`/
     * `startTrial()`), using whichever of `snapshotForUpgrade()`/
     * `snapshotForDowngrade()` matches `$changeType`.
     */
    public function applyConfirmedPlanChange(
        Subscription $subscription,
        PricingPlan $newPlan,
        PricingPlanProviderPrice $newMapping,
        string $changeType,
        TransitionContext $context,
    ): Subscription {
        $updated = DB::transaction(function () use ($subscription, $newPlan, $newMapping, $changeType, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->pending_pricing_plan_id !== $newPlan->id) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} has no pending plan change to plan {$newPlan->id} to confirm — already applied, cancelled, or superseded."
                );
            }

            if ($locked->status !== SubscriptionStatus::ACTIVE) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot apply a confirmed plan change for subscription {$locked->internal_reference} from status \"{$locked->status}\" — requires active."
                );
            }

            $locked->pricing_plan_id = $newPlan->id;
            $locked->billing_interval = $locked->pending_billing_interval ?? $locked->billing_interval;
            $locked->provider_price_id = $newMapping->provider_price_id;
            $locked->currency = $newMapping->currency;
            $locked->unit_amount = $newMapping->unit_amount;
            $locked->subtotal_amount = $newMapping->unit_amount;
            $locked->total_amount = $newMapping->unit_amount;
            $locked->plan_code_snapshot = $newPlan->code;
            $locked->plan_name_snapshot = $newPlan->name;
            $locked->pending_pricing_plan_id = null;
            $locked->pending_billing_interval = null;
            $locked->plan_change_effective_at = null;
            $locked->last_transition_occurred_at = $context->occurredAt;
            $locked->save();

            $this->log($locked, 'subscription.plan_change_applied', 'Applied confirmed plan change', $context, [
                'new_pricing_plan_id' => $newPlan->id,
                'change_type' => $changeType,
            ]);

            return $locked;
        });

        $effectiveFrom = CarbonImmutable::instance($context->effectiveAt ?? $context->occurredAt);

        if ($changeType === \App\Support\Billing\PlanChangeType::UPGRADE) {
            $this->snapshots->snapshotForUpgrade($updated, $effectiveFrom);
        } else {
            $this->snapshots->snapshotForDowngrade($updated, $effectiveFrom);
        }

        return $updated;
    }

    // ─── Provider reconciliation (narrow — see class docblock) ──────────

    /**
     * Synchronizes period dates/cancel_at_period_end from an authoritative
     * provider read WITHOUT changing status — used when the provider's
     * reported status still matches the local one (a pure refresh). If
     * the provider's status maps to a DIFFERENT internal status, this
     * throws rather than silently picking a transition to apply — a
     * future reconciliation checkpoint is expected to inspect the
     * mismatch and call the correct named transition method explicitly
     * (markPastDue(), restoreToActive(), etc.), with its own reasoning
     * about which one applies, rather than this method guessing.
     *
     * @param array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool} $normalizedProviderSubscription
     */
    public function recordProviderState(Subscription $subscription, array $normalizedProviderSubscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $normalizedProviderSubscription, $context) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if (($normalizedProviderSubscription['livemode'] ?? null) !== $locked->livemode) {
                throw new SubscriptionLifecycleConflictException(
                    "Provider subscription {$normalizedProviderSubscription['id']} livemode does not match subscription {$locked->internal_reference}."
                );
            }

            $mappedStatus = SubscriptionStatusMapper::isKnownStripeStatus($normalizedProviderSubscription['status'])
                ? SubscriptionStatusMapper::fromStripeStatus($normalizedProviderSubscription['status'])
                : null;

            if ($mappedStatus !== null && $mappedStatus !== $locked->status) {
                throw new SubscriptionLifecycleConflictException(
                    "Provider reports subscription {$locked->internal_reference} as \"{$mappedStatus}\" but it is locally \"{$locked->status}\" — "
                    . 'reconcile explicitly via the appropriate named transition rather than through recordProviderState().'
                );
            }

            if ($normalizedProviderSubscription['current_period_start'] !== null) {
                $locked->current_period_starts_at = CarbonImmutable::createFromTimestampUTC($normalizedProviderSubscription['current_period_start']);
            }

            if ($normalizedProviderSubscription['current_period_end'] !== null) {
                $locked->current_period_ends_at = CarbonImmutable::createFromTimestampUTC($normalizedProviderSubscription['current_period_end']);
            }

            $locked->cancel_at_period_end = $normalizedProviderSubscription['cancel_at_period_end'] ?? $locked->cancel_at_period_end;
            $locked->save();

            $this->log($locked, 'subscription.provider_state_recorded', 'Synchronized provider period state', $context);

            return $locked;
        });
    }

    // ─── Commercial conflict invariant ───────────────────────────────────

    /**
     * Whether this organisation already has a commercially conflicting
     * subscription — the authoritative answer `createDraftSubscription()`
     * enforces internally. Exposed publicly ONLY so a caller (e.g.
     * CheckoutSessionService) can perform an early, cheaper check to
     * return a clearer error before doing further work — that early check
     * is advisory, never a substitute for the enforcement inside
     * `createDraftSubscription()` itself, which re-checks under its own
     * per-organisation lock so a caller can never bypass the rule by
     * skipping this method.
     *
     * Conflict matrix (scoped to the CURRENT provider livemode only — a
     * test-mode subscription never blocks a live-mode one or vice versa,
     * consistent with this codebase's livemode-scoping convention
     * elsewhere):
     *
     *   - `trialing`, `pending_payment`, `incomplete`, `active`,
     *     `past_due`, `unpaid`, `paused`, `suspended` — ALWAYS conflict.
     *     Each represents an existing, unresolved commercial relationship
     *     (including `active` with `cancel_at_period_end = true` — a
     *     scheduled cancellation does not reduce commercial activity
     *     until its effective date, so it still conflicts).
     *   - `cancelled`, `expired` — NEVER conflict. Terminal, historical.
     *   - `draft` — conflicts ONLY when it has at least one associated
     *     `billing_checkout_sessions` row in `created`/`open` status that
     *     either has no expiry recorded or has not yet expired. A draft
     *     with no checkout session at all, or only expired/cancelled/
     *     completed ones, represents an abandoned attempt, not a reusable
     *     checkout intent, and must NOT block a fresh attempt.
     */
    public function hasConflictingSubscription(Organization $organization): bool
    {
        $livemode = $this->provider->isLivemode();

        $hasBlockingStatus = Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('livemode', $livemode)
            ->whereIn('status', SubscriptionStatus::BLOCKS_NEW_CHECKOUT)
            ->exists();

        if ($hasBlockingStatus) {
            return true;
        }

        return Subscription::query()
            ->where('organization_id', $organization->id)
            ->where('livemode', $livemode)
            ->where('status', SubscriptionStatus::DRAFT)
            ->whereHas('checkoutSessions', function ($query) {
                $query->whereIn('status', [CheckoutSessionStatus::CREATED, CheckoutSessionStatus::OPEN])
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            })
            ->exists();
    }

    // ─── Shared internals ────────────────────────────────────────────────

    /**
     * The shared path for every status-changing transition that doesn't
     * need its own bespoke validation beyond "is this a legal transition
     * from the current status" — locks the row, rejects stale events,
     * short-circuits as a safe no-op when already at the target status,
     * validates the transition map, applies the caller's mutation, stamps
     * last_transition_occurred_at, saves, and logs.
     */
    private function transition(
        Subscription $subscription,
        string $targetStatus,
        TransitionContext $context,
        callable $mutate,
        string $activityAction,
        string $activityDescription,
        array $extraMeta = [],
    ): Subscription {
        return DB::transaction(function () use ($subscription, $targetStatus, $context, $mutate, $activityAction, $activityDescription, $extraMeta) {
            $locked = $this->lock($subscription);
            $this->assertNotStale($locked, $context);

            if ($locked->status === $targetStatus) {
                return $locked;
            }

            if (!SubscriptionTransitions::canTransition($locked->status, $targetStatus)) {
                throw new InvalidSubscriptionTransitionException(
                    "Cannot transition subscription {$locked->internal_reference} from \"{$locked->status}\" to \"{$targetStatus}\"."
                );
            }

            $mutate($locked);
            $locked->status = $targetStatus;
            $locked->last_transition_occurred_at = $context->occurredAt;
            $locked->save();

            $this->log($locked, $activityAction, $activityDescription, $context, $extraMeta);

            return $locked;
        });
    }

    private function lock(Subscription $subscription): Subscription
    {
        return Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Rejects an event that is OLDER than the last transition already
     * applied — the core of stale-event handling. A context with no
     * occurredAt information never happens (TransitionContext::make()
     * always defaults it to "now"), so this check always has something to
     * compare against once the subscription has a prior transition.
     */
    private function assertNotStale(Subscription $subscription, TransitionContext $context): void
    {
        if ($subscription->last_transition_occurred_at !== null && $context->occurredAt->lt($subscription->last_transition_occurred_at)) {
            throw new SubscriptionLifecycleConflictException(
                "Stale event for subscription {$subscription->internal_reference}: event occurred at {$context->occurredAt->toIso8601String()}, "
                . "but a transition already applied at {$subscription->last_transition_occurred_at->toIso8601String()}."
            );
        }
    }

    private function assertSupportedInterval(string $billingInterval): void
    {
        if (!in_array($billingInterval, self::SUPPORTED_INTERVALS, true)) {
            throw new SubscriptionLifecycleConflictException("Unsupported billing interval: {$billingInterval}");
        }
    }

    private function assertPlanIsSyncable(PricingPlan $plan): void
    {
        if ($plan->status === 'archived') {
            throw new SubscriptionLifecycleConflictException("Pricing plan \"{$plan->name}\" is archived.");
        }
    }

    private function log(Subscription $subscription, string $action, string $description, TransitionContext $context, array $extraMeta = []): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            user: $context->actorUserId ? User::find($context->actorUserId) : null,
            subject: $subscription,
            organizationId: $subscription->organization_id,
            meta: array_merge([
                'subscription_reference' => $subscription->internal_reference,
                'status' => $subscription->status,
                'pricing_plan_id' => $subscription->pricing_plan_id,
                'billing_interval' => $subscription->billing_interval,
            ], $context->toLogMetadata(), $extraMeta),
        );
    }
}
