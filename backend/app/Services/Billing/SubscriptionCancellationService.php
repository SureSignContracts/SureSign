<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Billing Architecture Audit + Slice E1 checkpoint — first-party
 * subscription cancellation, owned entirely by SureSign (never Stripe
 * Customer Portal). Deliberately thin: unlike
 * `SubscriptionPlanChangeService`, cancellation needs no dedicated
 * tracking table — `subscriptions.cancel_at_period_end` +
 * `current_period_ends_at` already fully represent "pending
 * cancellation" (confirmed during this checkpoint's architecture audit;
 * see the audit report for why no migration was needed). This service
 * exists only to own the provider-call ordering, eligibility/conflict
 * checks, and idempotency the controller must not duplicate.
 *
 * Provider-call-then-local-write ordering (the reverse of
 * `SubscriptionPlanChangeService`'s request-then-send split): unlike a
 * Price change, `cancel_at_period_end` is a synchronous, immediately-
 * effective Stripe write with no separate "confirm later" step needed at
 * request time — calling the provider first means a failed provider call
 * leaves local state completely untouched (simply retry), rather than
 * ever locally claiming "scheduled" when Stripe never actually received
 * it.
 */
class SubscriptionCancellationService
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionPlanChangeService $planChanges,
    ) {
    }

    /**
     * @throws SubscriptionLifecycleConflictException
     */
    public function requestCancellation(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SubscriptionStatus::ACTIVE) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} is not active — cancellation can only be requested from an active subscription."
                );
            }

            // Already pending — idempotent no-op, no second provider call
            // (Stage 5: "return the current authoritative state").
            if ($locked->cancel_at_period_end) {
                return $locked;
            }

            if ($this->planChanges->pendingFor($locked) !== null) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} has a pending plan change — resolve or cancel it before requesting cancellation."
                );
            }

            $this->provider->scheduleCancellationAtPeriodEnd(
                $locked->provider_subscription_id,
                $this->idempotencyKey($locked, 'schedule'),
            );

            return $this->lifecycle->scheduleCancellation($locked, $context);
        });
    }

    /**
     * @throws SubscriptionLifecycleConflictException
     */
    public function resumeCancellation(Subscription $subscription, TransitionContext $context): Subscription
    {
        return DB::transaction(function () use ($subscription, $context) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            // Nothing pending — idempotent no-op, no provider call (Stage 8:
            // "repeated resume requests must be deterministic and safe").
            if (!$locked->cancel_at_period_end) {
                return $locked;
            }

            if ($locked->status !== SubscriptionStatus::ACTIVE) {
                throw new SubscriptionLifecycleConflictException(
                    "Subscription {$locked->internal_reference} is no longer active — cancellation is no longer reversible."
                );
            }

            $this->provider->resumeSubscription(
                $locked->provider_subscription_id,
                $this->idempotencyKey($locked, 'resume'),
            );

            return $this->lifecycle->cancelScheduledCancellation($locked, $context);
        });
    }

    /**
     * Stable across retries of the SAME logical attempt (the subscription
     * row hasn't been saved yet, so `updated_at` hasn't changed), but
     * naturally distinct for the next genuinely new schedule/resume
     * request (which can only happen after the previous one committed and
     * changed `updated_at`) — avoiding Stripe returning a stale 24h-cached
     * idempotent response for a later, genuinely different request.
     */
    private function idempotencyKey(Subscription $subscription, string $action): string
    {
        $version = $subscription->updated_at?->timestamp ?? 'new';

        return "cancel-{$action}:{$subscription->id}:{$version}";
    }
}
