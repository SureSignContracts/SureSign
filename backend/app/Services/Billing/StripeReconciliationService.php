<?php

namespace App\Services\Billing;

use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Services\Entitlements\SnapshotIntegrityClassifier;
use App\Support\Billing\ReconciliationFinding;
use App\Support\Billing\SubscriptionSource;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Stripe Test Mode Integration checkpoint, Part 22/23 — compares local and
 * provider Test Mode state for selected/batched subscriptions. Default
 * behaviour is non-destructive inspection only — this class never mutates
 * a subscription, a plan change, or a snapshot; it only reports.
 *
 * ─── Scope limitation, deliberate ────────────────────────────────────────
 *
 * This service only ever scans FORWARD from local `subscriptions` rows —
 * it never enumerates Stripe's own subscription list looking for a
 * "provider-only" orphan (a Stripe subscription with no local record at
 * all). Bulk-listing an entire Stripe account's subscriptions to find
 * orphans would require iterating unrelated, potentially large-scale
 * provider data with no safe way to filter to "resources SureSign should
 * know about" without first having a local reference to check against —
 * exactly the chicken-and-egg problem this service exists to avoid.
 * `local_only` (a local row with no `provider_subscription_id` in a status
 * that should have one) IS detected; the reverse direction is a known,
 * documented limitation, not an oversight.
 *
 * ─── Authoritative-side documentation (Part 23) ──────────────────────────
 *
 *   - Status: SureSign lifecycle status is authoritative; a raw provider
 *     status is only ever a hint that a verified webhook must confirm
 *     before anything local changes (Non-negotiable Principle 9/10).
 *   - Plan/Price: SureSign's `plan_code_snapshot`/`provider_price_id` are
 *     authoritative UNLESS a pending `BillingPlanChange` explains a
 *     provider-reported difference — a genuine, unexplained mismatch is
 *     always `conflict`-shaped (`PRICE_MISMATCH`), never auto-resolved by
 *     this service in either direction.
 *   - Entitlements: `SubscriptionEntitlementSnapshot` is authoritative;
 *     this service reuses `SnapshotIntegrityClassifier` (the same
 *     authority `FeatureGate` itself consults) rather than a second,
 *     divergent definition of "missing."
 */
class StripeReconciliationService
{
    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly SubscriptionPlanChangeService $planChanges,
        private readonly SnapshotIntegrityClassifier $snapshotClassifier,
    ) {
    }

    /**
     * @return array{
     *     counters: array<string, int>,
     *     records: array<int, array{subscription_id: int, finding: string, detail: ?string}>,
     * }
     */
    public function reconcile(int $limit = self::DEFAULT_LIMIT, ?int $subscriptionId = null): array
    {
        // G4B.1 — this entire service compares LOCAL state against Stripe's
        // own API, so it must never be asked to "reconcile" a manual/
        // complimentary subscription against Stripe (there is nothing there
        // to compare against). Every row today is still source=stripe (no
        // other creation path exists yet), so this changes no current
        // behaviour — it only prevents a future non-Stripe row from being
        // scanned here once G4B.2 introduces one.
        $query = Subscription::query()
            ->where('source', SubscriptionSource::STRIPE)
            ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::PAST_DUE, SubscriptionStatus::TRIALING, SubscriptionStatus::UNPAID])
            ->orderBy('id');

        if ($subscriptionId !== null) {
            $query->where('id', $subscriptionId);
        }

        $subscriptions = $query->limit($limit)->get();

        $counters = array_fill_keys(ReconciliationFinding::ALL, 0);
        $counters['scanned'] = 0;
        $records = [];

        foreach ($subscriptions as $subscription) {
            $counters['scanned']++;
            [$finding, $detail] = $this->reconcileOne($subscription);
            $counters[$finding] = ($counters[$finding] ?? 0) + 1;

            $records[] = [
                'subscription_id' => $subscription->id,
                'finding' => $finding,
                'detail' => $detail,
            ];
        }

        return ['counters' => $counters, 'records' => $records];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function reconcileOne(Subscription $subscription): array
    {
        if ($subscription->provider_subscription_id === null) {
            return [ReconciliationFinding::LOCAL_ONLY, 'No provider_subscription_id recorded for a subscription in a status that should have one.'];
        }

        try {
            $providerSubscription = $this->provider->retrieveSubscription($subscription->provider_subscription_id);
        } catch (Throwable $e) {
            return [ReconciliationFinding::RETRYABLE_ERROR, $e->getMessage()];
        }

        if ($providerSubscription === null) {
            return [ReconciliationFinding::PROVIDER_SUBSCRIPTION_DELETED, 'Provider reports no such subscription, but it is locally ' . $subscription->status . '.'];
        }

        if ($providerSubscription['livemode'] !== $subscription->livemode) {
            return [ReconciliationFinding::MODE_MISMATCH, 'Local livemode does not match the provider subscription.'];
        }

        if ($subscription->billingCustomer !== null
            && $providerSubscription['customer_id'] !== null
            && $subscription->billingCustomer->provider_customer_id !== $providerSubscription['customer_id']) {
            return [ReconciliationFinding::CUSTOMER_MISMATCH, 'Provider customer does not match the local BillingCustomer.'];
        }

        $priceFinding = $this->reconcilePrice($subscription, $providerSubscription);
        if ($priceFinding !== null) {
            return $priceFinding;
        }

        // Billing Architecture Audit + Slice E1 checkpoint — an ACTIVE
        // subscription (both locally and at the provider, by this point)
        // whose cancel_at_period_end disagrees is never silently
        // corrected — a webhook should already have reconciled this in
        // normal operation (see WebhookEventProcessor's pure-refresh
        // path), so a persistent mismatch here indicates a missed/failed
        // webhook worth an operator's attention, not a plan/Price drift.
        if ($subscription->status === SubscriptionStatus::ACTIVE
            && ($providerSubscription['cancel_at_period_end'] ?? null) !== null
            && $providerSubscription['cancel_at_period_end'] !== $subscription->cancel_at_period_end
        ) {
            return [
                ReconciliationFinding::CANCELLATION_STATE_MISMATCH,
                "Provider reports cancel_at_period_end={$this->boolLabel($providerSubscription['cancel_at_period_end'])}, "
                . "locally recorded as {$this->boolLabel($subscription->cancel_at_period_end)}.",
            ];
        }

        if (($providerSubscription['current_period_start'] ?? null) !== null
            && ($providerSubscription['current_period_end'] ?? null) !== null
            && $providerSubscription['current_period_start'] >= $providerSubscription['current_period_end']) {
            return [ReconciliationFinding::TERMINAL_ERROR, 'Provider-reported billing period is not chronologically plausible.'];
        }

        $classification = $this->snapshotClassifier->classify($subscription);
        if (in_array($classification, [SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE, SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS], true)) {
            return [ReconciliationFinding::MISSING_SNAPSHOT, "Classified as {$classification} by SnapshotIntegrityClassifier."];
        }

        return [ReconciliationFinding::HEALTHY, null];
    }

    /**
     * @return array{0: string, 1: ?string}|null
     */
    private function reconcilePrice(Subscription $subscription, array $providerSubscription): ?array
    {
        $reportedPriceId = $providerSubscription['price_id'] ?? null;

        if ($reportedPriceId === null || $subscription->provider_price_id === null || $reportedPriceId === $subscription->provider_price_id) {
            return null;
        }

        $pending = $this->planChanges->pendingFor($subscription);
        $pendingTargetPriceId = $pending?->targetPriceMapping?->provider_price_id;

        if ($pendingTargetPriceId !== null && $pendingTargetPriceId === $reportedPriceId) {
            // The provider already reflects the pending change's target —
            // this is exactly what the next webhook/scheduler tick is
            // expected to confirm. Distinguish "recently sent, on track"
            // from "stale" using the plan change's own requested effective
            // date, purely for operator visibility — never auto-applied by
            // this service either way.
            $isStale = $pending->requested_effective_at !== null
                && CarbonImmutable::instance($pending->requested_effective_at)->lt(CarbonImmutable::now()->subDay());

            return [
                $isStale ? ReconciliationFinding::PENDING_CHANGE_STALE : ReconciliationFinding::PENDING_CHANGE_CONFIRMED,
                "Plan change {$pending->id} target Price matches the provider — awaiting local confirmation.",
            ];
        }

        $knownMapping = PricingPlanProviderPrice::query()
            ->where('provider_price_id', $reportedPriceId)
            ->where('livemode', $subscription->livemode)
            ->first();

        if ($knownMapping === null) {
            return [ReconciliationFinding::UNKNOWN_PRICE, "Provider Price {$reportedPriceId} does not match any approved pricing_plan_provider_prices mapping."];
        }

        return [ReconciliationFinding::PRICE_MISMATCH, "Provider reports Price {$reportedPriceId}, locally recorded as {$subscription->provider_price_id}, with no pending plan change explaining it."];
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
