<?php

namespace App\Services\Intelligence;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingOverviewService;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\SubscriptionAccessMode;

/**
 * Phase G3 — the Subscription Intelligence Centre's single composing
 * service. Deliberately thin: every section either delegates entirely to
 * an existing authoritative service (`BillingOverviewService` for
 * subscription/access data — never reimplemented here) or to one of this
 * phase's own read-only services (`UsageMetricsService`/
 * `SubscriptionHealthService`/`SubscriptionRecommendationService`/
 * `SubscriptionTimelineService`). This class performs no calculation of
 * its own beyond assembling the trial card and the read-only Stripe
 * summary from fields already on `Subscription`/`BillingCustomer`.
 *
 * Every method resolves scope from the authenticated `User`'s own
 * `Organization` — never a caller-supplied organisation id (Stage 14,
 * matching `BillingOverviewService`'s existing convention exactly).
 */
class SubscriptionIntelligenceService
{
    public function __construct(
        private readonly BillingOverviewService $billingOverview,
        private readonly SubscriptionAccessPolicy $accessPolicy,
        private readonly UsageMetricsService $usageMetrics,
        private readonly SubscriptionHealthService $health,
        private readonly SubscriptionRecommendationService $recommendations,
        private readonly SubscriptionTimelineService $timeline,
    ) {
    }

    public function intelligenceFor(User $user): array
    {
        return $this->intelligenceForOrganization($user->organization);
    }

    /**
     * G4A — the Organization-scoped counterpart to intelligenceFor(), for
     * Super Admin/Admin organisation subscription administration
     * (read-only) and for the Users page's inherited-subscription display.
     * intelligenceFor() now delegates here rather than duplicating this
     * logic. Performs no authorization of its own — same convention as
     * every other method on this service; the caller decides who may see
     * an arbitrary organisation's data.
     */
    public function intelligenceForOrganization(Organization $organization): array
    {
        $subscription = $this->currentSubscription($organization);
        $usage = $this->usageMetrics->usageForOrganization($organization);

        return [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'subscription' => $this->billingOverview->subscriptionDetailForOrganization($organization),
            'trial' => $this->trialCard($subscription),
            'usage' => $usage,
            'storage' => $this->pluck($usage, Feature::STORAGE_GB),
            'ai' => $this->aiCard($usage),
            'health' => $this->health->healthForOrganization($organization, $subscription),
            'recommendations' => $this->recommendations->recommendationsForOrganization($organization, $subscription),
            'timeline' => $this->timeline->timelineForOrganization($organization),
            'stripe' => $this->stripeInfo($organization, $subscription),
        ];
    }

    private function currentSubscription(Organization $organization): ?Subscription
    {
        return $organization->subscriptions()->latest('id')->first();
    }

    private function pluck(array $usage, string $featureKey): ?array
    {
        foreach ($usage as $metric) {
            if ($metric['feature_key'] === $featureKey) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * Adds the AI-specific "next reset date" on top of the shared usage
     * row — UTC calendar month, matching UsageMetricsService's own
     * counting window (Entitlement Specification v1 Section 12).
     */
    private function aiCard(array $usage): ?array
    {
        $metric = $this->pluck($usage, Feature::AI_ANALYSES_PER_MONTH);
        if ($metric === null) {
            return null;
        }

        return $metric + ['next_reset_at' => now('UTC')->startOfMonth()->addMonthNoOverflow()];
    }

    /**
     * Stage 6 — exists if and only if `SubscriptionAccessPolicy` currently
     * resolves the TRIAL access mode. This is what makes the card
     * disappear automatically the moment a trial converts (mode becomes
     * FULL) — no separate "was this a trial" bookkeeping needed here.
     */
    private function trialCard(?Subscription $subscription): ?array
    {
        if ($subscription === null || $subscription->trial_ends_at === null) {
            return null;
        }

        $mode = $this->accessPolicy->resolve($subscription)->mode;
        if ($mode !== SubscriptionAccessMode::TRIAL) {
            return null;
        }

        $now = now();
        $start = $subscription->starts_at ?? $subscription->created_at;
        $end = $subscription->trial_ends_at;

        $totalDays = max(1, $start->diffInDays($end));
        $elapsedDays = min($totalDays, max(0, $start->diffInDays($now)));
        $daysRemaining = max(0, (int) $now->startOfDay()->diffInDays($end->copy()->startOfDay(), false));

        return [
            'is_active' => true,
            'starts_at' => $start,
            'ends_at' => $end,
            'days_remaining' => $daysRemaining,
            'percent_elapsed' => round(($elapsedDays / $totalDays) * 100, 1),
        ];
    }

    /**
     * Read-only Stripe summary — never a raw Stripe field, never a
     * secret. `payment_method_type` comes from the latest already-synced
     * `BillingPayment` row (`InvoiceSyncService`, existing), never a fresh
     * Stripe API call.
     */
    private function stripeInfo(Organization $organization, ?Subscription $subscription): array
    {
        $billingCustomer = $organization->billingCustomer;
        $connected = $billingCustomer !== null && $billingCustomer->provider_customer_id !== null;

        $latestPayment = $subscription
            ? BillingPayment::query()->where('organization_id', $organization->id)->latest('id')->first()
            : null;

        return [
            'customer_connected' => $connected,
            'portal_available' => $connected,
            'payment_method_type' => $latestPayment?->payment_method_type,
            'invoice_count' => $subscription ? BillingInvoice::query()->where('organization_id', $organization->id)->count() : 0,
            'current_period_ends_at' => $subscription?->current_period_ends_at,
            'next_renewal_at' => ($subscription && !$subscription->cancel_at_period_end) ? $subscription->current_period_ends_at : null,
        ];
    }
}
