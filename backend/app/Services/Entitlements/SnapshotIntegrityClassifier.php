<?php

namespace App\Services\Entitlements;

use App\Models\Subscription;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Carbon\CarbonImmutable;

/**
 * Subscription Suspension Completion, Snapshot Integrity & Commercial
 * Automation Hardening checkpoint — the single, pure (no writes, no
 * lifecycle side effects) authority on whether a subscription's missing
 * entitlement snapshot is a genuine problem or a documented compatibility
 * case. Injected into both `FeatureGate` (read-only fallback decision) and
 * `EntitlementSnapshotIntegrityService` (the heavier scan/repair service),
 * so the exact same rule governs both — `FeatureGate` must never use a
 * looser or stricter definition of "legacy" than the integrity command
 * reports.
 *
 * Only `active`/`past_due`/`trialing` are ever classified as needing a
 * snapshot at all — these are the only three statuses `FeatureGate`
 * consults a snapshot for at all (`FULL`/`GRACE`/`TRIAL` access modes).
 * Every other status resolves `NONE`/`RESTRICTED` regardless of snapshot
 * presence, so a missing snapshot there has no live consequence and is
 * never flagged.
 */
class SnapshotIntegrityClassifier
{
    private const SNAPSHOT_RELEVANT_STATUSES = [
        SubscriptionStatus::ACTIVE,
        SubscriptionStatus::PAST_DUE,
        SubscriptionStatus::TRIALING,
    ];

    public function __construct(
        private readonly PlanEntitlementRepository $planEntitlements,
    ) {
    }

    public function classify(Subscription $subscription): string
    {
        if (!in_array($subscription->status, self::SNAPSHOT_RELEVANT_STATUSES, true)) {
            return SnapshotIntegrityClassification::NOT_APPLICABLE;
        }

        if ($subscription->currentEntitlementSnapshot !== null) {
            return SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_PRESENT;
        }

        $startsAt = $subscription->starts_at;

        if ($startsAt === null) {
            // No authoritative timestamp at all to reason about — cannot
            // even determine whether this predates snapshot support.
            return SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS;
        }

        if (CarbonImmutable::instance($startsAt)->lt($this->snapshotSupportBoundary())) {
            return SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT;
        }

        return $this->isRecoverable($subscription)
            ? SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE
            : SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS;
    }

    /**
     * Whether every input `EntitlementSnapshotService` would need to build
     * a snapshot is available and authoritative. Never guesses: a
     * `trialing` subscription is always reconstructable given a
     * `starts_at` (the trial profile is fixed/global, not plan-dependent);
     * an `active`/`past_due` one additionally requires a recognised
     * `plan_code_snapshot` — without one, entitlement VALUES cannot be
     * reconstructed at all, so this must never be treated as recoverable.
     */
    public function isRecoverable(Subscription $subscription): bool
    {
        if ($subscription->starts_at === null) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::TRIALING) {
            return true;
        }

        return $subscription->plan_code_snapshot !== null
            && $this->planEntitlements->isKnownPlanCode($subscription->plan_code_snapshot);
    }

    /**
     * The exact (lifecycle_reason, source_transition, effective_from)
     * `EntitlementSnapshotIntegrityService::repair()` uses to reconstruct
     * a missing snapshot — returns null when not recoverable, so a caller
     * never has to duplicate `isRecoverable()`'s logic to stay consistent.
     *
     * @return array{lifecycle_reason: string, source_transition: string, effective_from: CarbonImmutable}|null
     */
    public function recoveryPlan(Subscription $subscription): ?array
    {
        if (!$this->isRecoverable($subscription)) {
            return null;
        }

        if ($subscription->status === SubscriptionStatus::TRIALING) {
            return [
                'lifecycle_reason' => 'trial_start',
                'source_transition' => 'subscription.trial_started',
                'effective_from' => CarbonImmutable::instance($subscription->starts_at),
            ];
        }

        return [
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
            'effective_from' => CarbonImmutable::instance($subscription->activated_at ?? $subscription->starts_at),
        ];
    }

    private function snapshotSupportBoundary(): CarbonImmutable
    {
        return CarbonImmutable::parse(config('billing.entitlement_snapshot_introduced_at'));
    }
}
