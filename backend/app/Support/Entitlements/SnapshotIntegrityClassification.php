<?php

namespace App\Support\Entitlements;

/**
 * Subscription Suspension Completion, Snapshot Integrity & Commercial
 * Automation Hardening checkpoint, Part 11 — the controlled vocabulary
 * `App\Services\Entitlements\SnapshotIntegrityClassifier::classify()`
 * returns for a subscription's entitlement-snapshot state.
 */
class SnapshotIntegrityClassification
{
    /** Predates `config('billing.entitlement_snapshot_introduced_at')` — the documented live-`PlanEntitlements` compatibility fallback applies. */
    public const LEGACY_PRE_SNAPSHOT = 'legacy_pre_snapshot';

    /** Has a current effective snapshot — healthy. */
    public const EXPECTED_SNAPSHOT_PRESENT = 'expected_snapshot_present';

    /**
     * Started after snapshot support existed, has no snapshot, but every
     * input required to reconstruct one authoritatively is available —
     * see `EntitlementSnapshotIntegrityService::repair()`.
     */
    public const EXPECTED_SNAPSHOT_MISSING_RECOVERABLE = 'expected_snapshot_missing_recoverable';

    /**
     * Started after snapshot support existed, has no snapshot, and at
     * least one required input cannot be determined authoritatively (no
     * known plan code, no authoritative effective-date timestamp, etc.) —
     * never repaired; reported only.
     */
    public const EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS = 'expected_snapshot_missing_ambiguous';

    /**
     * A status FeatureGate never consults a snapshot for (`draft`,
     * `pending_payment`, `incomplete`, `unpaid`, `suspended`, `cancelled`,
     * `expired`) — a missing snapshot here has no live entitlement-
     * resolution consequence today, so it is never flagged as an
     * integrity problem.
     */
    public const NOT_APPLICABLE = 'not_applicable';

    public const ALL = [
        self::LEGACY_PRE_SNAPSHOT,
        self::EXPECTED_SNAPSHOT_PRESENT,
        self::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE,
        self::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS,
        self::NOT_APPLICABLE,
    ];
}
