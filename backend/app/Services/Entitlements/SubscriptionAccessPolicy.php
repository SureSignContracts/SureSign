<?php

namespace App\Services\Entitlements;

use App\Models\Subscription;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SubscriptionAccessDecision;
use App\Support\Entitlements\SubscriptionAccessMode;
use Carbon\CarbonImmutable;

/**
 * The single authoritative place that answers "given this organisation's
 * current subscription state, what commercial access mode applies" —
 * Part 16's recommended separate domain policy service, sitting between
 * `SubscriptionLifecycleService` (owns transitions) and `FeatureGate`
 * (owns entitlement-value resolution):
 *
 *   Subscription → SubscriptionAccessPolicy → FeatureGate
 *
 * Deliberately its own service, not folded into either neighbour —
 * `SubscriptionLifecycleService` continues to own transitions and must
 * never be asked "what access does this status imply" (a different
 * question), and `FeatureGate` continues to own entitlement-VALUE
 * resolution and must never re-derive lifecycle-status policy inline
 * (which is exactly the "scattered logic" this checkpoint's repository
 * review confirmed does NOT currently exist, and this class exists to
 * make sure it never needs to).
 *
 * Provider-independent by construction: reads only `Subscription::$status`
 * (already a `SubscriptionStatus` value — never a raw Stripe string, see
 * `SubscriptionStatusMapper`) and `Subscription::$grace_period_ends_at`.
 * No Stripe object, Price ID, Customer ID, or webhook type is ever
 * needed or referenced.
 */
class SubscriptionAccessPolicy
{
    public function resolve(?Subscription $subscription): SubscriptionAccessDecision
    {
        if ($subscription === null) {
            return new SubscriptionAccessDecision(
                SubscriptionAccessMode::NONE,
                null,
                'no_subscription',
                'No subscription exists for this organisation.',
            );
        }

        return match ($subscription->status) {
            SubscriptionStatus::DRAFT => $this->none($subscription, 'draft', 'Subscription is a draft — no commercial relationship has started yet.'),
            SubscriptionStatus::PENDING_PAYMENT => $this->none($subscription, 'pending_payment', 'Checkout has not yet been completed and verified.'),
            SubscriptionStatus::INCOMPLETE => $this->none($subscription, 'incomplete', 'The first payment attempt has not yet succeeded (e.g. 3-D Secure not completed).'),

            SubscriptionStatus::TRIALING => new SubscriptionAccessDecision(
                SubscriptionAccessMode::TRIAL,
                $subscription->status,
                'trialing',
                'Subscription is on the dedicated trial entitlement profile.',
            ),

            SubscriptionStatus::ACTIVE => new SubscriptionAccessDecision(
                SubscriptionAccessMode::FULL,
                $subscription->status,
                'active',
                $subscription->cancel_at_period_end
                    ? 'Subscription is active; a cancellation is scheduled but has not yet taken effect — full access continues until then.'
                    : 'Subscription is active — full plan entitlements apply.',
            ),

            SubscriptionStatus::PAST_DUE => $this->pastDue($subscription),

            SubscriptionStatus::UNPAID => $this->restricted($subscription, 'unpaid', 'Subscription is unpaid — paid entitlements no longer resolve; existing records remain accessible regardless (Entitlement Specification v1 §15).'),
            SubscriptionStatus::SUSPENDED => $this->restricted($subscription, 'suspended', 'Subscription is suspended — paid entitlements no longer resolve; existing records remain accessible regardless.'),
            // Phase E6 fix — this reason string reaches the customer
            // verbatim via AccessStatusBanner; it previously named the
            // implementing class/methods, which must never happen (see
            // Stage 6 of internal-docs/super-admin/subscription-billing.md's
            // Phase E6 section).
            SubscriptionStatus::CANCELLED => $this->restricted($subscription, 'cancelled', 'This subscription has been cancelled.'),
            SubscriptionStatus::EXPIRED => $this->restricted($subscription, 'expired', 'Subscription has expired — paid entitlements no longer resolve; existing records remain accessible regardless.'),

            // `paused` remains outside normal resolution per the approved
            // policy (see WebhookEventProcessor's own docblock) — it
            // should never actually be reached as a stored status (no
            // lifecycle method sets it), but if it somehow were, this
            // fails safe to NONE rather than guessing or granting access.
            SubscriptionStatus::PAUSED => $this->none($subscription, 'paused', 'This subscription is currently paused.'),

            // Unknown/inconsistent status string — fail safe, never grant
            // access to something this policy doesn't recognise. Customer
            // copy stays generic; the raw status is still available to
            // operators via reason_code/logs, never echoed into this prose.
            default => $this->none($subscription, 'unrecognised_status', 'We could not determine your subscription status. Please contact support.'),
        };
    }

    /**
     * `past_due` is `GRACE` (full entitlement values, per Entitlement
     * Specification v1 §16) UNLESS `grace_period_ends_at` is set AND has
     * already passed — a defensive safety net for the fact that nothing
     * in this codebase currently transitions a subscription OUT of
     * `past_due` automatically when its grace window elapses (see the
     * checkpoint's report on `SubscriptionLifecycleService::startGracePeriod()`
     * being unused by any caller today). Without this check, a
     * subscription could sit in `past_due` indefinitely past its own
     * recorded grace deadline and still resolve full access forever.
     */
    private function pastDue(Subscription $subscription): SubscriptionAccessDecision
    {
        if ($subscription->grace_period_ends_at !== null && CarbonImmutable::now()->gt($subscription->grace_period_ends_at)) {
            return $this->restricted(
                $subscription,
                'past_due_grace_expired',
                "Subscription is past due and its grace period ended on {$subscription->grace_period_ends_at->toIso8601String()} — paid entitlements no longer resolve.",
            );
        }

        $reason = $subscription->grace_period_ends_at !== null
            ? "Subscription is past due; grace continues until {$subscription->grace_period_ends_at->toIso8601String()}."
            : 'Subscription is past due; no grace deadline has been recorded yet, so full access continues (a temporary payment hiccup must not immediately disrupt compliance work).';

        return new SubscriptionAccessDecision(SubscriptionAccessMode::GRACE, $subscription->status, 'past_due', $reason);
    }

    private function none(Subscription $subscription, string $reasonCode, string $reason): SubscriptionAccessDecision
    {
        return new SubscriptionAccessDecision(SubscriptionAccessMode::NONE, $subscription->status, $reasonCode, $reason);
    }

    private function restricted(Subscription $subscription, string $reasonCode, string $reason): SubscriptionAccessDecision
    {
        return new SubscriptionAccessDecision(SubscriptionAccessMode::RESTRICTED, $subscription->status, $reasonCode, $reason);
    }
}
