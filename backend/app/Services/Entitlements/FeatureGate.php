<?php

namespace App\Services\Entitlements;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Entitlements\EntitlementDecision;
use App\Support\Entitlements\EntitlementSource;
use App\Support\Entitlements\EntitlementValue;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use App\Support\Entitlements\SubscriptionAccessDecision;
use App\Support\Entitlements\SubscriptionAccessMode;
use Illuminate\Support\Facades\Log;

/**
 * The single central service every application module will eventually
 * call to answer "what is this organisation's subscription actually
 * allowed to do" — Part 6 / Entitlement Specification v1 Section 14. No
 * module calls this yet (this checkpoint is architecture only — see Part
 * 11's worked examples instead), and this class itself makes no Stripe
 * call and holds no Stripe dependency of any kind: it resolves entirely
 * from `Subscription`/`Organization`, SureSign's own local models. Stripe
 * only ever influences a `Subscription`'s `status`/`plan_code_snapshot`
 * fields via `SubscriptionLifecycleService` (through a verified webhook);
 * by the time this class runs, Stripe is already out of the picture
 * entirely.
 *
 * ─── Resolution order (highest precedence first) ───────────────────────
 *
 *   1. A dormant key (`Feature::isDormant()`) — always `notApplicable()`,
 *      regardless of plan, access mode, or override. Checked first,
 *      deliberately, so nothing below this class can ever cause
 *      `max_users`/`max_organisations` to resolve to a real value.
 *   2. The subscription's commercial ACCESS MODE
 *      (`SubscriptionAccessPolicy::resolve()` — added in the
 *      "Subscription Lifecycle and Entitlement Access Policy Review"
 *      checkpoint, replacing this class's own inline status logic from
 *      the previous checkpoint). One of `NONE`/`TRIAL`/`FULL`/`GRACE`/
 *      `RESTRICTED` — see that class and `SubscriptionAccessMode` for the
 *      full lifecycle-status-to-mode matrix.
 *   2a. **Snapshot-resolution plumbing (Subscription Commercial State
 *      Automation checkpoint, Part 9)** — when the mode is `TRIAL`/`FULL`/
 *      `GRACE`, the entitlement value is read from the subscription's
 *      CURRENT immutable `SubscriptionEntitlementSnapshot` (see
 *      `Subscription::currentEntitlementSnapshot()`) instead of live
 *      `PlanEntitlements`, when one exists. Exact fallback rule:
 *        - No snapshot exists at all → fall back to live
 *          `PlanEntitlements`/`trialProfile()`, exactly as before this
 *          checkpoint. This is the explicit, documented COMPATIBILITY
 *          path for a subscription activated/trial-started before this
 *          checkpoint shipped (nothing backfills historical snapshots).
 *          Since `SubscriptionLifecycleService::activate()`/
 *          `startTrial()` now ALWAYS create a snapshot going forward
 *          (Part 4), this path should only ever be reached for
 *          pre-checkpoint subscriptions, never a new one.
 *        - A snapshot exists but does not contain the requested key →
 *          NEVER falls back to live `PlanEntitlements` (Part 10's
 *          explicit instruction: a missing/inconsistent snapshot must
 *          fail safely, never silently grant broader access than what
 *          was actually recorded). Resolves "not entitled" instead and
 *          logs a warning — every `PlanEntitlements`/`trialProfile()`
 *          array covers all eight non-dormant keys, so this indicates a
 *          genuine data inconsistency worth investigating, not a normal
 *          runtime path.
 *      This class still holds no persistence responsibility of its own —
 *      `EntitlementSnapshotService` is the only writer; FeatureGate only
 *      reads.
 *   3. An active negotiated/manual override — but ONLY when
 *      `SubscriptionAccessMode::allowsOverrides()` is true for the
 *      resolved mode (`FULL`/`GRACE`/`TRIAL`). **Corrected this
 *      checkpoint**: the previous checkpoint consulted overrides BEFORE
 *      the status check, which would have let a manual override silently
 *      grant access to a `suspended`/`cancelled`/`draft` organisation —
 *      exactly the unsafe default Part 10 of this checkpoint's brief
 *      warns against. Overrides are now only ever consulted on TOP of an
 *      already-granted commercial relationship, never to resurrect one
 *      the access mode says doesn't currently exist.
 *   4. The mode's own profile: the trial profile for `TRIAL`, the plan's
 *      defaults for `FULL`/`GRACE`, or "not entitled" for `NONE`/
 *      `RESTRICTED` (or an unrecognised plan code).
 *
 * ─── What this class deliberately does NOT do ──────────────────────────
 *
 * No usage counting, no `canConsume()`/`getUsage()`/`isNearLimit()` —
 * Entitlement Specification v1 Section 11 explicitly identifies these as
 * needing real usage-measurement infrastructure this checkpoint excludes
 * ("do not implement... usage tracking"). No blocking, no middleware, no
 * UI hiding — this class only ANSWERS a question; nothing calls it to act
 * on the answer yet.
 */
class FeatureGate
{
    public function __construct(
        private readonly EntitlementOverrideRepository $overrides,
        private readonly SubscriptionAccessPolicy $accessPolicy,
        private readonly SnapshotIntegrityClassifier $snapshotClassifier,
        private readonly PlanEntitlementRepository $planEntitlements,
    ) {
    }

    /**
     * For a `Feature`-category (boolean) entitlement. Throws (via
     * `EntitlementValue::asBoolean()`) if called against a usage-category
     * key — use `limit()` for those instead.
     */
    public function allows(Organization $organization, string $featureKey): bool
    {
        return $this->resolve($organization, $featureKey)->asBoolean();
    }

    /**
     * For a `Usage`-category entitlement — returns the full resolved
     * value object so the caller can inspect `isUnlimited`/`value`
     * together, per Entitlement Specification v1 Section 6 (never call
     * this expecting a bare number without checking `isUnlimited` first).
     */
    public function limit(Organization $organization, string $featureKey): EntitlementValue
    {
        return $this->resolve($organization, $featureKey);
    }

    public function isUnlimited(Organization $organization, string $featureKey): bool
    {
        return $this->resolve($organization, $featureKey)->isUnlimited;
    }

    /**
     * The service-layer equivalent of an authorization check —
     * Entitlement Specification v1 Section 14. Throws when the
     * organisation is not entitled; still performs NO blocking or
     * middleware role itself; a future module's controller/service is
     * what would call this and decide what "throws" means for its own
     * request (Part 11's examples show the intended call shape without
     * wiring it into any real controller this checkpoint).
     */
    public function requireFeature(Organization $organization, string $featureKey): void
    {
        if (!$this->allows($organization, $featureKey)) {
            throw new FeatureNotEntitledException($organization, $featureKey);
        }
    }

    /**
     * Section 14's `explainDecision()` — support/debugging and future
     * Super Admin presentation (Section 21). Never expose this verbatim
     * to a customer (see Section 22 on internal-only commercial detail).
     */
    public function explain(Organization $organization, string $featureKey): EntitlementDecision
    {
        $subscription = $this->resolveSubscription($organization);
        $accessDecision = $this->accessPolicy->resolve($subscription);
        $value = $this->resolveForAccessDecision($subscription, $accessDecision, $featureKey);

        return new EntitlementDecision(
            value: $value,
            subscriptionStatus: $accessDecision->subscriptionStatus,
            reason: $this->explainReason($featureKey, $accessDecision, $value),
            accessMode: $accessDecision->mode,
            resolutionPath: $this->describeResolutionPath($subscription, $accessDecision, $value),
        );
    }

    /**
     * Snapshot Integrity & Commercial Automation Hardening checkpoint,
     * Part 13 — `explain()` must identify which of these actually applied,
     * not just the final value. Pure/read-only, mirrors (never diverges
     * from) the real resolution logic above.
     */
    private function describeResolutionPath(?Subscription $subscription, SubscriptionAccessDecision $accessDecision, EntitlementValue $value): string
    {
        if ($subscription === null) {
            return 'no_subscription';
        }

        if ($value->isNegotiatedOverride) {
            return 'override';
        }

        if (!SubscriptionAccessMode::allowsOverrides($accessDecision->mode)) {
            // NONE/RESTRICTED — access mode alone already denies, before
            // any snapshot/override question is even reached.
            return 'not_entitled_by_access_mode';
        }

        if ($subscription->currentEntitlementSnapshot !== null) {
            return 'snapshot';
        }

        $classification = $this->snapshotClassifier->classify($subscription);

        return match ($classification) {
            SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT, SnapshotIntegrityClassification::NOT_APPLICABLE => 'legacy_live_plan_fallback',
            default => 'missing_required_snapshot',
        };
    }

    private function resolve(Organization $organization, string $featureKey): EntitlementValue
    {
        if (!Feature::isValid($featureKey)) {
            throw new \InvalidArgumentException("Unknown entitlement feature key: \"{$featureKey}\".");
        }

        // 1. Dormant keys never resolve to a real value, regardless of
        // plan, access mode, or override — see Feature::isDormant()'s own
        // docblock.
        if (Feature::isDormant($featureKey)) {
            return EntitlementValue::notApplicable($featureKey);
        }

        $subscription = $this->resolveSubscription($organization);
        $accessDecision = $this->accessPolicy->resolve($subscription);

        return $this->resolveForAccessDecision($subscription, $accessDecision, $featureKey);
    }

    private function resolveForAccessDecision(?Subscription $subscription, SubscriptionAccessDecision $accessDecision, string $featureKey): EntitlementValue
    {
        // 2. Overrides — ONLY when the access mode itself already grants
        // a commercial relationship. See class docblock's "corrected this
        // checkpoint" note.
        if ($subscription !== null && SubscriptionAccessMode::allowsOverrides($accessDecision->mode)) {
            $override = $this->overrides->findActiveOverride($subscription, $featureKey);
            if ($override !== null) {
                return $override;
            }
        }

        // 3. The mode's own profile — snapshot-first, live-PlanEntitlements
        // as the documented compatibility fallback (see class docblock,
        // Part 9).
        return match ($accessDecision->mode) {
            SubscriptionAccessMode::TRIAL => $this->resolveFromSnapshotOrLive($subscription, $featureKey, fn () => PlanEntitlements::trialProfile()),
            SubscriptionAccessMode::FULL, SubscriptionAccessMode::GRACE => $this->resolveFromSnapshotOrLive($subscription, $featureKey, fn () => $this->livePlanEntitlements($subscription)),
            default => $this->notEntitled($featureKey), // NONE, RESTRICTED
        };
    }

    /**
     * @param callable(): array<string, EntitlementValue> $liveFallback
     */
    private function resolveFromSnapshotOrLive(?Subscription $subscription, string $featureKey, callable $liveFallback): EntitlementValue
    {
        $snapshot = $subscription?->currentEntitlementSnapshot;

        if ($snapshot !== null) {
            return $this->resolveFromSnapshot($snapshot, $featureKey);
        }

        // No snapshot exists — Snapshot Integrity & Commercial Automation
        // Hardening checkpoint (Part 13): NOT every missing snapshot means
        // the same thing. Only a genuinely LEGACY subscription (predates
        // snapshot support entirely) may use the live-PlanEntitlements
        // compatibility fallback. A modern subscription that should have
        // received a snapshot but didn't (missing_recoverable/
        // missing_ambiguous) must fail safe instead — never silently
        // resolve broader access than what would actually be recorded.
        if ($subscription !== null) {
            $classification = $this->snapshotClassifier->classify($subscription);

            if ($classification !== SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT
                && $classification !== SnapshotIntegrityClassification::NOT_APPLICABLE) {
                Log::warning('FeatureGate: subscription is missing a required entitlement snapshot and is not a legacy pre-snapshot subscription — failing safe to not entitled.', [
                    'subscription_id' => $subscription->id,
                    'feature_key' => $featureKey,
                    'classification' => $classification,
                ]);

                return $this->notEntitled($featureKey);
            }
        }

        return $liveFallback()[$featureKey] ?? $this->notEntitled($featureKey);
    }

    /**
     * A snapshot exists but is missing the requested key — fails safe to
     * "not entitled" rather than falling back to live `PlanEntitlements`,
     * per Part 10's explicit instruction that an inconsistent snapshot must
     * never silently grant broader access than what was actually recorded.
     */
    private function resolveFromSnapshot(SubscriptionEntitlementSnapshot $snapshot, string $featureKey): EntitlementValue
    {
        $entry = $snapshot->entitlements_json[$featureKey] ?? null;

        if ($entry === null) {
            Log::warning('FeatureGate: entitlement snapshot is missing a requested key — failing safe to not entitled.', [
                'snapshot_id' => $snapshot->id,
                'subscription_id' => $snapshot->subscription_id,
                'feature_key' => $featureKey,
            ]);

            return $this->notEntitled($featureKey);
        }

        return EntitlementValue::make(
            $featureKey,
            $entry['value_type'],
            $entry['value'],
            $entry['is_unlimited'],
            $entry['source'],
            $entry['unit'] ?? null,
        );
    }

    /**
     * @return array<string, EntitlementValue>
     */
    private function livePlanEntitlements(?Subscription $subscription): array
    {
        $planCode = $subscription?->plan_code_snapshot;

        if ($planCode === null) {
            return [];
        }

        // Phase G1 — database-backed plan defaults, replacing the direct
        // PlanEntitlements::forPlanCode() call. See PlanEntitlementRepository's
        // own docblock for the temporary hardcoded-fallback behaviour this
        // preserves exactly (an unknown/unconfigured plan code still
        // resolves to [], same as before).
        return $this->planEntitlements->forPlanCode($planCode);
    }

    private function resolveSubscription(Organization $organization): ?Subscription
    {
        return $organization->liveSubscription ?? $organization->subscriptions()->latest('id')->first();
    }

    private function notEntitled(string $featureKey): EntitlementValue
    {
        return Feature::isFeatureFlag($featureKey)
            ? EntitlementValue::notIncluded($featureKey, EntitlementSource::NONE)
            : EntitlementValue::notApplicable($featureKey);
    }

    private function explainReason(string $featureKey, SubscriptionAccessDecision $accessDecision, EntitlementValue $value): string
    {
        if (Feature::isDormant($featureKey)) {
            return 'Reserved/dormant key — never enforced, sold, or resolved to a real value.';
        }

        if ($value->isNegotiatedOverride) {
            return "Resolved from an active override ({$accessDecision->reason}).";
        }

        return $accessDecision->reason;
    }
}
