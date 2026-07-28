<?php

namespace App\Services\Entitlements;

use App\Models\ActivityLog;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Subscription Suspension Completion, Snapshot Integrity & Commercial
 * Automation Hardening checkpoint — scans subscriptions for missing
 * entitlement snapshots and repairs ONLY the ones
 * `SnapshotIntegrityClassifier` classifies as authoritatively
 * reconstructable. Never mutates an existing snapshot (immutability is
 * enforced at the model level regardless), never fabricates a historical
 * date or plan, and never "repairs" an ambiguous case — those are always
 * reported, never guessed at.
 *
 * This is a read-mostly service: `check()` alone (no `$repair`) performs
 * zero writes and is safe to run at any time, including in production,
 * without side effects.
 */
class EntitlementSnapshotIntegrityService
{
    private const DEFAULT_LIMIT = 500;

    public function __construct(
        private readonly SnapshotIntegrityClassifier $classifier,
        private readonly EntitlementSnapshotService $snapshots,
    ) {
    }

    /**
     * Scans (optionally repairing) subscriptions and returns a structured
     * summary plus one record per subscription scanned — the shared
     * implementation behind `billing:subscriptions:check-integrity`.
     *
     * @return array{
     *     counters: array<string, int>,
     *     records: array<int, array{subscription_id: int, status: string, classification: string, repaired_snapshot_id: ?int}>,
     * }
     */
    public function check(int $limit = self::DEFAULT_LIMIT, bool $repair = false, ?int $subscriptionId = null): array
    {
        $query = Subscription::query()->orderBy('id');

        if ($subscriptionId !== null) {
            $query->where('id', $subscriptionId);
        }

        $subscriptions = $query->limit($limit)->get();

        $counters = [
            'scanned' => 0,
            SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_PRESENT => 0,
            SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT => 0,
            SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE => 0,
            SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS => 0,
            SnapshotIntegrityClassification::NOT_APPLICABLE => 0,
            'repaired' => 0,
            'repair_failed' => 0,
        ];
        $records = [];

        foreach ($subscriptions as $subscription) {
            $classification = $this->classifier->classify($subscription);
            $counters['scanned']++;
            $counters[$classification] = ($counters[$classification] ?? 0) + 1;

            $repairedSnapshotId = null;

            if ($classification === SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS) {
                Log::warning('EntitlementSnapshotIntegrityService: subscription missing an entitlement snapshot with no authoritative way to reconstruct it — reporting only, never repaired.', [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                ]);
            }

            if ($repair && $classification === SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE) {
                try {
                    $snapshot = $this->repair($subscription);
                    $repairedSnapshotId = $snapshot?->id;

                    if ($snapshot !== null) {
                        $counters['repaired']++;
                    }
                } catch (Throwable $e) {
                    $counters['repair_failed']++;
                    Log::error('EntitlementSnapshotIntegrityService: repair failed', [
                        'subscription_id' => $subscription->id,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            $records[] = [
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'classification' => $classification,
                'repaired_snapshot_id' => $repairedSnapshotId,
            ];
        }

        return ['counters' => $counters, 'records' => $records];
    }

    /**
     * Repairs a single subscription — returns null (never throws, never
     * fabricates) when the subscription is not classified as
     * authoritatively recoverable. Reuses `EntitlementSnapshotService`'s
     * existing idempotent creation, so calling this twice for the same
     * subscription reuses the first repair's snapshot rather than
     * duplicating it (the same unique-index boundary every other snapshot
     * creation path relies on).
     */
    public function repair(Subscription $subscription): ?SubscriptionEntitlementSnapshot
    {
        // Only ever repairs a subscription actually CLASSIFIED as
        // recoverable — never just "has enough data" (`recoveryPlan()`/
        // `isRecoverable()` alone don't consider the legacy boundary or
        // whether a snapshot already exists). A legacy subscription with a
        // known plan code would otherwise satisfy `isRecoverable()` too,
        // which must never be repaired — it uses the documented live-
        // PlanEntitlements fallback instead, not a fabricated snapshot.
        if ($this->classifier->classify($subscription) !== SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE) {
            return null;
        }

        $plan = $this->classifier->recoveryPlan($subscription);

        if ($plan === null) {
            return null;
        }

        $snapshot = $plan['lifecycle_reason'] === 'trial_start'
            ? $this->snapshots->snapshotForTrialStart($subscription, $plan['effective_from'])
            : $this->snapshots->snapshotForActivation($subscription, $plan['effective_from']);

        ActivityLog::record(
            action: 'subscription.entitlement_snapshot_repaired',
            description: 'Repaired a missing entitlement snapshot from authoritative subscription state',
            user: null,
            subject: $subscription,
            organizationId: $subscription->organization_id,
            meta: [
                'subscription_reference' => $subscription->internal_reference,
                'snapshot_id' => $snapshot->id,
                'lifecycle_reason' => $plan['lifecycle_reason'],
                'source_transition' => $plan['source_transition'],
                'effective_from' => $plan['effective_from']->toIso8601String(),
            ],
        );

        return $snapshot;
    }
}
