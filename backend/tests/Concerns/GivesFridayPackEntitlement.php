<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Carbon\CarbonImmutable;

/**
 * Friday Pack Plan Entitlement Enforcement, Deployment B fixture
 * adaptation. Gives a test organisation the minimum REAL state needed to
 * pass `feature.entitled:friday_packs` — a real `PricingPlan` +
 * `PricingPlanEntitlement` row + `Subscription` (status ACTIVE) + its
 * `SubscriptionEntitlementSnapshot` — mirroring exactly what
 * `SubscriptionLifecycleService::activate()` really produces and what
 * `App\Services\Entitlements\FeatureGate`/`SubscriptionAccessPolicy`
 * really resolve. Deliberately NOT a shortcut: no middleware is
 * disabled, no controller exception is added, `FeatureGate` is never
 * mocked, and `SubscriptionAccessMode::NONE` is never treated as
 * entitled anywhere. A test using this trait passes for the exact same
 * reason a real Professional/Enterprise customer would.
 *
 * Scope: this is prerequisite fixture state ONLY, for suites whose
 * subject under test is something other than the entitlement gate
 * itself (PDF rendering, lifecycle, readiness, photo selection,
 * deliveries, H&S → Friday Pack integration, temporal boundaries).
 * `FridayPackEntitlementTest` deliberately does NOT use this trait for
 * its Essential/NONE/denied scenarios — those need an organisation that
 * is NOT entitled, which this trait never produces.
 */
trait GivesFridayPackEntitlement
{
    private static int $fridayPackEntitlementPlanCounter = 0;

    /**
     * Grants $organization an ACTIVE subscription on a fresh plan with
     * Friday Packs entitled (Professional by default — the lowest tier
     * that actually includes Friday Packs), with a real activation
     * snapshot. Safe to call once per organisation per test.
     */
    private function giveFridayPackEntitlement(Organization $organization, string $planCode = 'professional'): Subscription
    {
        // `pricing_plans.code` carries a real UNIQUE constraint — a test
        // that creates more than one organisation (e.g. a tenant-isolation
        // scenario with an "own" and a "foreign" org, both going through
        // this helper) would otherwise collide on a shared literal code.
        // The generated code is unique per call; `$planCode`'s *value*
        // (what Friday Packs entitlement resolves to) is what matters,
        // not the literal string stored in `code` — FeatureGate resolves
        // from the snapshot's own `entitlements_json` below, never by
        // matching on this string elsewhere.
        $n = ++self::$fridayPackEntitlementPlanCounter;
        $uniqueCode = $planCode . '-fpe-fixture-' . $n;

        $plan = PricingPlan::create([
            'code' => $uniqueCode,
            'slug' => $uniqueCode,
            'name' => ucfirst($planCode),
            'order' => $n,
            'currency' => 'GBP',
            'status' => 'active',
        ]);

        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::FRIDAY_PACKS,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => true,
        ]);

        $subscription = Subscription::create([
            'organization_id' => $organization->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-FPE-FIXTURE-' . random_int(1, 99999999),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => $uniqueCode,
            'starts_at' => now()->subDay(),
        ]);

        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'pricing_plan_id' => null,
            'plan_code_snapshot' => $uniqueCode,
            'entitlements_json' => [
                Feature::FRIDAY_PACKS => [
                    'value_type' => 'boolean', 'value' => true, 'is_unlimited' => false, 'unit' => null, 'source' => 'plan_default',
                ],
            ],
            'effective_from' => CarbonImmutable::now()->subHour(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        return $subscription;
    }
}
