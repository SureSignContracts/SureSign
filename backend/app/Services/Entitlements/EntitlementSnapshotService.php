<?php

namespace App\Services\Entitlements;

use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Entitlements\EntitlementValue;
use App\Support\Entitlements\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of `billing_entitlement_snapshots` rows — Subscription
 * Commercial State Automation checkpoint, Part 7/8. Called from the
 * authoritative lifecycle boundary itself (`SubscriptionLifecycleService::
 * activate()`/`startTrial()`), never only from a scheduler, so a snapshot
 * is created regardless of which approved entry point produced the
 * commercial event (verified webhook, sales-assisted activation, a future
 * Checkout-integrated path).
 *
 * Every public method here is idempotent: a repeated call for the same
 * (subscription, source_transition, effective_from) reuses the existing
 * row rather than creating a duplicate — the unique index on
 * `billing_entitlement_snapshots` is the actual correctness boundary; the
 * `firstOrCreate`-style read-then-create here is just the common path
 * that avoids hitting it in the normal case.
 *
 * Deliberately does NOT decide WHETHER a snapshot should be created for a
 * given transition — that decision belongs to the caller (the lifecycle
 * service, or `SubscriptionAutomationService` for the paths this
 * checkpoint automates). This class only knows how to build and
 * idempotently persist one once asked.
 */
class EntitlementSnapshotService
{
    public function __construct(
        private readonly PlanEntitlementRepository $planEntitlements,
    ) {
    }

    public function snapshotForActivation(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'activation', 'subscription.activated', $effectiveFrom);
    }

    public function snapshotForTrialStart(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'trial_start', 'subscription.trial_started', $effectiveFrom);
    }

    /**
     * Not called anywhere yet this checkpoint — applying a scheduled
     * upgrade requires a provider (Stripe) call this checkpoint explicitly
     * excludes (see SubscriptionAutomationService's docblock and the
     * checkpoint report's "scheduled upgrade" finding). Provided now so the
     * future Checkout/webhook-integrated checkpoint that actually applies
     * plan changes has the correct snapshot call shape ready to use,
     * without this checkpoint guessing at that checkpoint's own lifecycle
     * method name.
     */
    public function snapshotForUpgrade(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'upgrade_applied', 'subscription.plan_change_applied', $effectiveFrom);
    }

    /** @see self::snapshotForUpgrade() — same "not called yet" caveat. */
    public function snapshotForDowngrade(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'downgrade_applied', 'subscription.plan_change_applied', $effectiveFrom);
    }

    /**
     * Not called anywhere yet — no Enterprise amendment workflow exists in
     * this codebase. Provided so a future checkpoint building one has the
     * correct call shape rather than inventing a new snapshot method.
     */
    public function snapshotForEnterpriseAmendment(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'enterprise_amendment', 'subscription.enterprise_amended', $effectiveFrom);
    }

    /**
     * Organisation URL Branding, customer self-service phase —
     * `App\Console\Commands\RefreshEntitlementSnapshotsForCapabilityRollout`'s
     * only write path. A DELIBERATELY distinct `source_transition`/
     * `lifecycle_reason` from every real commercial-event snapshot above —
     * this is NOT a plan change, activation, or amendment; it exists
     * purely so an already-active subscription's snapshot can pick up a
     * brand-new entitlement key without a real commercial event having
     * occurred. `createOrReuse()`'s existing idempotency
     * (subscription_id, source_transition, effective_from) applies
     * identically here — running the same rollout twice with the same
     * $effectiveFrom reuses the row rather than duplicating it.
     *
     * `buildEntitlementsPayload()` (unchanged) rebuilds the FULL current
     * live plan-entitlement set, not just the new key — see that
     * command's own docblock for why this is the explicitly approved,
     * deterministic behaviour here (any other live plan-config drift
     * since the subscription's original snapshot is picked up too).
     */
    public function snapshotForEntitlementRollout(Subscription $subscription, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return $this->createOrReuse($subscription, 'entitlement_rollout', 'subscription.entitlement_rollout', $effectiveFrom);
    }

    /**
     * Friday Pack Entitlement Snapshot Capability Rollout — the SAFE,
     * surgical counterpart to `snapshotForEntitlementRollout()` above.
     * That method rebuilds a subscription's ENTIRE entitlement payload
     * from today's live plan defaults — an explicit, approved trade-off
     * for the `custom_branded_subdomain` rollout it was built for, but one
     * that can silently overwrite an unrelated entitlement key that has
     * drifted between the subscription's original snapshot and today's
     * plan configuration (e.g. a plan default an admin has since changed
     * in Pricing Management) — exactly what this rollout's own brief
     * prohibits.
     *
     * This method instead takes the subscription's CURRENT snapshot
     * verbatim and replaces only the ONE named capability's entry —
     * every other key in `entitlements_json` is carried over byte-for-byte
     * from the existing snapshot, untouched. This is what makes "existing
     * snapshot + newly introduced capability only = rolled-forward
     * snapshot" true in code, not just in the brief's own prose.
     *
     * Still produces a brand-new, immutable successor row (never mutates
     * the existing snapshot — `SubscriptionEntitlementSnapshot` enforces
     * this at the model level regardless) via the same `createOrReuse()`
     * idempotency boundary every other snapshot method here uses. Uses a
     * capability-specific `source_transition`/`lifecycle_reason`
     * (`subscription.capability_rollout.{capability}` /
     * `capability_rollout:{capability}`) so a rollout of one capability is
     * independently idempotent from a rollout of any other, and is never
     * confused with `snapshotForEntitlementRollout()`'s own
     * `entitlement_rollout` source_transition in any report or integrity
     * check. Callers must not exceed the 60-character `source_transition`
     * column width — `RefreshEntitlementSnapshotsForCapabilityRollout`
     * enforces this before ever reaching this method.
     *
     * @param  array<string, array{value_type: string, value: mixed, is_unlimited: bool, unit: ?string, source: string}>  $existingEntitlementsJson
     * @param  array{value_type: string, value: mixed, is_unlimited: bool, unit: ?string, source: string}  $newEntry
     */
    public function snapshotForCapabilityRollout(
        Subscription $subscription,
        string $capability,
        array $existingEntitlementsJson,
        array $newEntry,
        CarbonImmutable $effectiveFrom,
    ): SubscriptionEntitlementSnapshot {
        $sourceTransition = "subscription.capability_rollout.{$capability}";
        $lifecycleReason = "capability_rollout:{$capability}";

        if (strlen($sourceTransition) > 60 || strlen($lifecycleReason) > 60) {
            throw new \InvalidArgumentException("Capability key \"{$capability}\" is too long to fit this snapshot's source_transition/lifecycle_reason column width.");
        }

        $mergedEntitlementsJson = $existingEntitlementsJson;
        $mergedEntitlementsJson[$capability] = $newEntry;

        return DB::transaction(function () use ($subscription, $lifecycleReason, $sourceTransition, $mergedEntitlementsJson, $effectiveFrom) {
            $existing = SubscriptionEntitlementSnapshot::query()
                ->where('subscription_id', $subscription->id)
                ->where('source_transition', $sourceTransition)
                ->where('effective_from', $effectiveFrom)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return SubscriptionEntitlementSnapshot::create([
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'pricing_plan_id' => $subscription->pricing_plan_id,
                'plan_code_snapshot' => $subscription->plan_code_snapshot,
                'entitlements_json' => $mergedEntitlementsJson,
                'effective_from' => $effectiveFrom,
                'lifecycle_reason' => $lifecycleReason,
                'source_transition' => $sourceTransition,
            ]);
        });
    }

    private function createOrReuse(Subscription $subscription, string $lifecycleReason, string $sourceTransition, CarbonImmutable $effectiveFrom): SubscriptionEntitlementSnapshot
    {
        return DB::transaction(function () use ($subscription, $lifecycleReason, $sourceTransition, $effectiveFrom) {
            $existing = SubscriptionEntitlementSnapshot::query()
                ->where('subscription_id', $subscription->id)
                ->where('source_transition', $sourceTransition)
                ->where('effective_from', $effectiveFrom)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return SubscriptionEntitlementSnapshot::create([
                'subscription_id' => $subscription->id,
                'organization_id' => $subscription->organization_id,
                'pricing_plan_id' => $subscription->pricing_plan_id,
                'plan_code_snapshot' => $subscription->plan_code_snapshot,
                'entitlements_json' => $this->buildEntitlementsPayload($subscription, $lifecycleReason),
                'effective_from' => $effectiveFrom,
                'lifecycle_reason' => $lifecycleReason,
                'source_transition' => $sourceTransition,
            ]);
        });
    }

    /**
     * @return array<string, array{value_type: string, value: mixed, is_unlimited: bool, unit: ?string, source: string}>
     */
    /**
     * Exposed (not just used internally by createOrReuse()) so
     * `RefreshEntitlementSnapshotsForCapabilityRollout --dry-run` can
     * compute exactly what a real refresh WOULD write, without writing
     * anything — the same deterministic payload-building logic, just not
     * persisted.
     */
    public function buildEntitlementsPayload(Subscription $subscription, string $lifecycleReason): array
    {
        if ($lifecycleReason === 'trial_start') {
            // Unchanged — the dedicated trial profile stays hardcoded;
            // per-plan trial configuration is explicitly out of scope for
            // Phase G1 (see Phase G0 §11).
            $values = PlanEntitlements::trialProfile();
        } else {
            $planCode = $subscription->plan_code_snapshot;
            // Phase G1 — database-backed plan defaults, replacing the
            // direct PlanEntitlements calls. See PlanEntitlementRepository.
            $values = $planCode !== null ? $this->planEntitlements->forPlanCode($planCode) : [];
        }

        return collect($values)
            ->map(fn (EntitlementValue $value) => [
                'value_type' => $value->valueType,
                'value' => $value->value,
                'is_unlimited' => $value->isUnlimited,
                'unit' => $value->unit,
                'source' => $value->source,
            ])
            ->all();
    }
}
