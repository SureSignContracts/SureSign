<?php

namespace App\Services\Billing;

use App\Models\BillingPlanChange;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Services\Billing\Exceptions\PlanChangeNotSupportedException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\Exceptions\UnexpectedSubscriptionItemStructureException;
use App\Support\Billing\PlanChangePolicy;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\PlanChangeType;
use App\Support\Billing\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stripe Test Mode Integration, Provider Synchronisation & End-to-End
 * Billing Validation checkpoint — the domain service owning the plan-change
 * request state machine (`billing_plan_changes`, `App\Support\Billing\
 * PlanChangeState`) and the ONE new outbound provider write this checkpoint
 * introduces (`BillingProviderInterface::updateSubscriptionPrice()`).
 *
 * Deliberately does NOT track a second, generic "provider operations"
 * table alongside this one. Every other outbound provider write already
 * has adequate idempotency through an existing mechanism: customer creation
 * is keyed by the unique `billing_customers` row per organisation
 * (`BillingCustomerService`), Checkout Session creation is keyed by
 * `correlation_reference` (`CheckoutSessionService`), and Product/Price
 * creation is keyed by `PlanPriceMappingService`'s own supersession model.
 * A plan-change request is the only NEW commercial operation requiring
 * fresh idempotency tracking, so `billing_plan_changes` carries both the
 * domain state machine AND the operation-tracking fields (idempotency_key,
 * attempt_count, sent_at/provider_confirmed_at/applied_at) in one place —
 * see the creating migration's docblock for the full reasoning.
 *
 * ─── Approved commercial policy (fixed by this checkpoint, not invented) ─
 *
 *   - Immediate upgrade: proration enabled ('create_prorations'),
 *     existing billing-cycle anchor preserved (achieved by never passing
 *     `billing_cycle_anchor` to Stripe at all — see
 *     StripeBillingProvider::updateSubscriptionPrice()).
 *   - Downgrade: always effective at the current billing period's end,
 *     no proration ('none') — `requestDowngrade()` deliberately builds its
 *     OWN TransitionContext without an `effective_at`, ignoring whatever
 *     the caller passed, so `SubscriptionLifecycleService::
 *     scheduleDowngrade()`'s own period-end default can never be
 *     overridden — this is a hard policy guarantee, not a default that can
 *     be silently bypassed.
 *   - Eligible states: `active` only. `past_due` fails safe
 *     (`SubscriptionLifecycleConflictException` — payment recovery
 *     required first). `trialing` throws `PlanChangeNotSupportedException`
 *     — explicitly deferred (Part 9 Q8), never guessed at. Every other
 *     status throws `InvalidSubscriptionTransitionException`.
 *   - A pending cancellation (`cancel_at_period_end = true`) always
 *     rejects a new plan-change request.
 *
 * ─── Confirmation rule (Non-negotiable Principle 11) ─────────────────────
 *
 * `send()` marks a row `SENT` the moment Stripe's outbound API call
 * succeeds — this is NEVER treated as the plan change taking local
 * commercial effect. Only `confirmFromProvider()` (called exclusively by
 * `WebhookEventProcessor` from an already-verified webhook payload) moves
 * a row to `CONFIRMED`/`APPLIED` and calls
 * `SubscriptionLifecycleService::applyConfirmedPlanChange()` — the single
 * place the local plan/entitlement snapshot actually changes.
 */
class SubscriptionPlanChangeService
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {
    }

    public function requestUpgrade(
        Subscription $subscription,
        PricingPlan $targetPlan,
        PricingPlanProviderPrice $targetMapping,
        TransitionContext $context,
        bool $scheduled = false,
        bool $supersede = false,
    ): BillingPlanChange {
        if ($scheduled && $context->effectiveAt === null) {
            throw new SubscriptionLifecycleConflictException(
                'A scheduled upgrade requires an explicit effective_at on the TransitionContext — it is never inferred.'
            );
        }

        return $this->request($subscription, $targetPlan, $targetMapping, PlanChangeType::UPGRADE, $scheduled, $supersede, $context);
    }

    /**
     * Always period-end — see class docblock. `$context`'s own
     * `effective_at` (if any) is deliberately discarded; only its
     * source/actor/reason/occurred_at survive into the context actually
     * used, guaranteeing the approved downgrade policy can never be
     * silently overridden by a caller.
     */
    public function requestDowngrade(
        Subscription $subscription,
        PricingPlan $targetPlan,
        PricingPlanProviderPrice $targetMapping,
        TransitionContext $context,
        bool $supersede = false,
    ): BillingPlanChange {
        $periodEndContext = TransitionContext::make([
            'source' => $context->source,
            'reason' => $context->reason,
            'actor_user_id' => $context->actorUserId,
            'occurred_at' => $context->occurredAt,
        ]);

        return $this->request($subscription, $targetPlan, $targetMapping, PlanChangeType::DOWNGRADE, true, $supersede, $periodEndContext);
    }

    /**
     * Cancels a pending plan change (any non-terminal state) before it has
     * been applied. Returns null if nothing was pending — a safe no-op,
     * matching `SubscriptionLifecycleService::cancelScheduledSuspension()`'s
     * idempotency shape.
     */
    /**
     * Only a `REQUESTED` row is safely cancellable this way — nothing has
     * reached Stripe yet, so clearing SureSign's own pending-plan-change
     * fields is the complete cancellation. Once a row is `SENT`,
     * `updateSubscriptionPrice()` has already changed the price at Stripe
     * itself (it is a direct, synchronous provider write, not a staged
     * one) — the confirming webhook is already on its way, so there is no
     * safe local-only "cancellation" left to perform; the caller must
     * treat this as no-longer-cancellable, not silently mark it cancelled
     * while Stripe still reports the new price (Stripe Test Mode
     * Integration checkpoint, Stage 7's explicit requirement).
     *
     * @throws SubscriptionLifecycleConflictException if the pending change is not (or no longer) in REQUESTED
     */
    public function cancelPending(Subscription $subscription, TransitionContext $context): ?BillingPlanChange
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $pending = $this->pendingFor($locked);

            if ($pending === null) {
                return null;
            }

            if ($pending->state !== PlanChangeState::REQUESTED) {
                throw new SubscriptionLifecycleConflictException(
                    "Plan change {$pending->id} has already been sent to the provider (state \"{$pending->state}\") and can no longer be cancelled locally."
                );
            }

            $this->lifecycle->cancelScheduledPlanChange($locked, $context);

            $pending->state = PlanChangeState::CANCELLED;
            $pending->cancelled_at = $context->occurredAt;
            $pending->save();

            return $pending;
        });
    }

    public function pendingFor(Subscription $subscription): ?BillingPlanChange
    {
        return BillingPlanChange::query()
            ->where('subscription_id', $subscription->id)
            ->whereIn('state', PlanChangeState::PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Sends the outbound Stripe Price update for a `REQUESTED` row — the
     * one operation this checkpoint's automation calls once a scheduled
     * row's `requested_effective_at` is due (immediate upgrades call this
     * synchronously right after `requestUpgrade()` instead of waiting for
     * a tick). A non-`REQUESTED` row is returned unchanged (safe no-op —
     * covers a retried automation tick finding the row already sent).
     *
     * A structural failure (`UnexpectedSubscriptionItemStructureException`)
     * is terminal — marks the row `FAILED` and returns it rather than
     * throwing, since it can never succeed on retry. Any other exception
     * is recorded (failure_code/message) but RE-THROWN with the row left
     * in `REQUESTED` — the caller (`SubscriptionAutomationService`)
     * classifies it as retryable and will attempt `send()` again next
     * tick, reusing the same `idempotency_key`.
     */
    public function send(BillingPlanChange $planChange): BillingPlanChange
    {
        return DB::transaction(function () use ($planChange) {
            $locked = BillingPlanChange::query()->whereKey($planChange->id)->lockForUpdate()->firstOrFail();

            if ($locked->state !== PlanChangeState::REQUESTED) {
                return $locked;
            }

            $subscription = $locked->subscription;
            $targetMapping = $locked->targetPriceMapping;

            $locked->attempt_count++;

            try {
                $this->provider->updateSubscriptionPrice(
                    $subscription->provider_subscription_id,
                    $targetMapping->provider_price_id,
                    $locked->change_type === PlanChangeType::UPGRADE ? 'create_prorations' : 'none',
                    $locked->idempotency_key,
                );
            } catch (UnexpectedSubscriptionItemStructureException $e) {
                $locked->state = PlanChangeState::FAILED;
                $locked->failure_code = 'unexpected_item_structure';
                $locked->failure_message = $e->getMessage();
                $locked->save();

                return $locked;
            } catch (\Throwable $e) {
                $locked->failure_code = 'provider_error';
                $locked->failure_message = $e->getMessage();
                $locked->save();

                throw $e;
            }

            $locked->state = PlanChangeState::SENT;
            $locked->sent_at = CarbonImmutable::now();
            $locked->save();

            return $locked;
        });
    }

    /**
     * Called ONLY by `WebhookEventProcessor`, from an already-verified
     * webhook payload reporting the provider subscription's current Price
     * — never from an outbound API response. Idempotent: a row already
     * `APPLIED` is returned unchanged (a duplicate/redelivered webhook is
     * always safe). A concurrent duplicate confirmation racing this one is
     * caught via `SubscriptionLifecycleService::applyConfirmedPlanChange()`'s
     * own conflict exception and treated the same way.
     */
    public function confirmFromProvider(BillingPlanChange $planChange, TransitionContext $context): BillingPlanChange
    {
        return DB::transaction(function () use ($planChange, $context) {
            $locked = BillingPlanChange::query()->whereKey($planChange->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === PlanChangeState::APPLIED) {
                return $locked;
            }

            if (!in_array($locked->state, [PlanChangeState::SENT, PlanChangeState::CONFIRMED], true)) {
                throw new SubscriptionLifecycleConflictException(
                    "Plan change {$locked->id} is in state \"{$locked->state}\" — cannot confirm from provider."
                );
            }

            if ($locked->state === PlanChangeState::SENT) {
                $locked->state = PlanChangeState::CONFIRMED;
                $locked->provider_confirmed_at = $context->occurredAt;
                $locked->save();
            }

            try {
                $this->lifecycle->applyConfirmedPlanChange(
                    $locked->subscription,
                    $locked->targetPricingPlan,
                    $locked->targetPriceMapping,
                    $locked->change_type,
                    $context,
                );
            } catch (SubscriptionLifecycleConflictException) {
                // Already applied by a concurrent/duplicate confirmation —
                // safe; this row still needs to reach APPLIED below.
            }

            $locked->state = PlanChangeState::APPLIED;
            $locked->applied_at = $context->occurredAt;
            $locked->save();

            return $locked;
        });
    }

    private function request(
        Subscription $subscription,
        PricingPlan $targetPlan,
        PricingPlanProviderPrice $targetMapping,
        string $changeType,
        bool $scheduled,
        bool $supersede,
        TransitionContext $context,
    ): BillingPlanChange {
        return DB::transaction(function () use ($subscription, $targetPlan, $targetMapping, $changeType, $scheduled, $supersede, $context) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $this->validateEligibility($locked);

            $pending = $this->pendingFor($locked);

            if ($pending !== null) {
                if (!$supersede) {
                    throw new SubscriptionLifecycleConflictException(
                        "Subscription {$locked->internal_reference} already has a pending plan change (id {$pending->id}) — pass supersede: true to replace it, or cancel it first."
                    );
                }

                $pending->state = PlanChangeState::SUPERSEDED;
                $pending->superseded_at = $context->occurredAt;
                $pending->save();
            }

            $updatedSubscription = $changeType === PlanChangeType::UPGRADE
                ? $this->lifecycle->scheduleUpgrade($locked, $targetPlan, $targetMapping, $targetMapping->billing_interval, $context)
                : $this->lifecycle->scheduleDowngrade($locked, $targetPlan, $targetMapping, $targetMapping->billing_interval, $context);

            $planChange = BillingPlanChange::create([
                'subscription_id' => $locked->id,
                'organization_id' => $locked->organization_id,
                'source_pricing_plan_id' => $locked->pricing_plan_id,
                'target_pricing_plan_id' => $targetPlan->id,
                'target_price_mapping_id' => $targetMapping->id,
                'change_type' => $changeType,
                'policy' => $scheduled ? PlanChangePolicy::SCHEDULED : PlanChangePolicy::IMMEDIATE,
                'livemode' => $this->provider->isLivemode(),
                'state' => PlanChangeState::REQUESTED,
                'requested_effective_at' => $updatedSubscription->plan_change_effective_at,
                'requested_by_user_id' => $context->actorUserId,
                'requested_at' => $context->occurredAt,
            ]);

            // Assigned only once the row has an id — the stable, per-row
            // idempotency key every retried send() of THIS row reuses.
            $planChange->idempotency_key = "plan_change:{$planChange->id}";
            $planChange->save();

            return $planChange;
        });
    }

    private function validateEligibility(Subscription $subscription): void
    {
        if ($subscription->cancel_at_period_end) {
            throw new SubscriptionLifecycleConflictException(
                "Subscription {$subscription->internal_reference} has a pending cancellation — resolve it before requesting a plan change."
            );
        }

        match ($subscription->status) {
            SubscriptionStatus::ACTIVE => null,
            SubscriptionStatus::PAST_DUE => throw new SubscriptionLifecycleConflictException(
                "Subscription {$subscription->internal_reference} is past_due — payment recovery is required before a plan change can be requested."
            ),
            SubscriptionStatus::TRIALING => throw new PlanChangeNotSupportedException(
                'Provider plan changes for trialing subscriptions are not implemented by this checkpoint — deferred, not guessed.'
            ),
            default => throw new \App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException(
                "Cannot request a plan change for subscription {$subscription->internal_reference} from status \"{$subscription->status}\"."
            ),
        };
    }
}
