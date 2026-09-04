<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Friday Pack Entitlement Snapshot Capability Rollout phase — proves the
 * GENERALISED `entitlements:refresh-capability-rollout {capability}`
 * command is a genuinely SURGICAL merge (existing snapshot + the one
 * named capability only), never a full re-snapshot that could silently
 * alter an unrelated, possibly-grandfathered entitlement value. See
 * `RefreshEntitlementSnapshotsForCapabilityRollout`'s own docblock for
 * the full architectural reasoning.
 */
class RefreshEntitlementSnapshotsForCapabilityRolloutTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $suffix): Organization
    {
        return Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}-" . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    /**
     * @param  array<string, bool>  $entitlements  feature_key => value
     */
    private function makePlan(string $code, array $entitlements): PricingPlan
    {
        $plan = PricingPlan::firstOrCreate(
            ['code' => $code],
            ['slug' => $code . '-' . random_int(1, 10000000), 'name' => ucfirst($code), 'status' => 'active'],
        );

        foreach ($entitlements as $key => $value) {
            PricingPlanEntitlement::updateOrCreate(
                ['pricing_plan_id' => $plan->id, 'feature_key' => $key],
                ['is_applicable' => true, 'is_unlimited' => false, 'value' => $value],
            );
        }

        return $plan;
    }

    private function makeSubscription(Organization $org, PricingPlan $plan, string $status = SubscriptionStatus::ACTIVE, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-ROLLOUT-' . random_int(1, 100000000),
            'status' => $status,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => $plan->code,
            'starts_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }

    /**
     * Directly creates a legacy-style snapshot with an explicit
     * entitlements_json payload — simulating a real subscription whose
     * snapshot predates the `friday_packs` key entirely (never contains
     * it), exactly the scenario this rollout exists to fix.
     */
    private function makeLegacySnapshot(Subscription $subscription, array $entitlementsJson, ?CarbonImmutable $effectiveFrom = null): SubscriptionEntitlementSnapshot
    {
        return SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'pricing_plan_id' => $subscription->pricing_plan_id,
            'plan_code_snapshot' => $subscription->plan_code_snapshot,
            'entitlements_json' => $entitlementsJson,
            'effective_from' => $effectiveFrom ?? CarbonImmutable::now()->subMonths(3),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);
    }

    private function unrelatedEntitlementEntry(bool $value = true): array
    {
        return [
            'value_type' => 'boolean',
            'value' => $value,
            'is_unlimited' => false,
            'unit' => null,
            'source' => 'plan_default',
        ];
    }

    // ── Core rollout behaviour ──────────────────────────────────────────

    public function test_essential_snapshot_without_friday_packs_remains_not_entitled(): void
    {
        $org = $this->makeOrg('e1');
        $plan = $this->makePlan('essential', [Feature::FRIDAY_PACKS => false, Feature::ADVANCED_REPORTING => false]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(false)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertFalse($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    public function test_professional_snapshot_without_friday_packs_gains_it(): void
    {
        $org = $this->makeOrg('p1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true, Feature::ADVANCED_REPORTING => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertTrue($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    public function test_enterprise_snapshot_without_friday_packs_gains_it(): void
    {
        $org = $this->makeOrg('en1');
        $plan = $this->makePlan('enterprise', [Feature::FRIDAY_PACKS => true, Feature::ADVANCED_REPORTING => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertTrue($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    // ── Idempotency / no-op cases ────────────────────────────────────────

    public function test_professional_snapshot_already_containing_friday_packs_true_is_idempotent(): void
    {
        $org = $this->makeOrg('p2');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::FRIDAY_PACKS => $this->unrelatedEntitlementEntry(true)]);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);
        $after = SubscriptionEntitlementSnapshot::count();

        $this->assertSame($before, $after, 'An already-correct snapshot must not produce a new row.');
        $output = Artisan::output();
        $this->assertStringContainsString('1', $output);
    }

    public function test_essential_snapshot_already_containing_correct_friday_packs_false_is_idempotent(): void
    {
        $org = $this->makeOrg('e2');
        $plan = $this->makePlan('essential', [Feature::FRIDAY_PACKS => false]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::FRIDAY_PACKS => $this->unrelatedEntitlementEntry(false)]);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);
        $after = SubscriptionEntitlementSnapshot::count();

        $this->assertSame($before, $after);
    }

    public function test_repeated_rollout_is_idempotent(): void
    {
        $org = $this->makeOrg('p3');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);
        $afterFirst = SubscriptionEntitlementSnapshot::count();

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);
        $afterSecond = SubscriptionEntitlementSnapshot::count();

        $this->assertSame($afterFirst, $afterSecond, 'A second run must not create duplicate/drifted snapshots.');
    }

    // ── Unrelated / grandfathered entitlement preservation ───────────────

    public function test_unrelated_grandfathered_entitlement_is_preserved_verbatim(): void
    {
        $org = $this->makeOrg('p4');
        // Today's live Professional plan default for advanced_reporting is
        // FALSE — but this subscription's existing snapshot recorded TRUE
        // (a grandfathered/drifted value from an earlier plan
        // configuration). The rollout must never touch it.
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true, Feature::ADVANCED_REPORTING => false]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertTrue($latest->entitlements_json[Feature::ADVANCED_REPORTING]['value'], 'Grandfathered/drifted unrelated entitlement must survive the rollout unchanged.');
        $this->assertTrue($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    public function test_enterprise_non_standard_unrelated_values_are_preserved(): void
    {
        $org = $this->makeOrg('en2');
        $plan = $this->makePlan('enterprise', [Feature::FRIDAY_PACKS => true, Feature::MAX_ACTIVE_PROJECTS => true]);
        $sub = $this->makeSubscription($org, $plan);
        // A non-standard, higher-than-normal custom value recorded for
        // this specific Enterprise customer's existing snapshot.
        $this->makeLegacySnapshot($sub, [
            Feature::STORAGE_GB => ['value_type' => 'integer', 'value' => 5000, 'is_unlimited' => false, 'unit' => 'GB', 'source' => 'negotiated_override'],
        ]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertSame(5000, $latest->entitlements_json[Feature::STORAGE_GB]['value']);
        $this->assertSame('negotiated_override', $latest->entitlements_json[Feature::STORAGE_GB]['source']);
        $this->assertTrue($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    // ── Dry run ───────────────────────────────────────────────────────────

    public function test_dry_run_reports_changes_but_mutates_nothing(): void
    {
        $org = $this->makeOrg('p5');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--dry-run' => true]);
        $after = SubscriptionEntitlementSnapshot::count();

        $this->assertSame($before, $after, 'Dry run must never write a snapshot.');
        $this->assertStringContainsString('Dry run only', Artisan::output());

        // The subscription must still be genuinely eligible/unresolved —
        // proving the dry run really would have refreshed it.
        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertArrayNotHasKey(Feature::FRIDAY_PACKS, $latest->entitlements_json);
    }

    // ── Invalid capability ───────────────────────────────────────────────

    public function test_invalid_capability_is_rejected_safely(): void
    {
        $exitCode = Artisan::call('entitlements:refresh-capability-rollout', ['capability' => 'not_a_real_feature_key', '--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('not a registered', Artisan::output());
        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }

    public function test_dormant_capability_is_rejected_safely(): void
    {
        $exitCode = Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::MAX_USERS, '--dry-run' => true]);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('dormant', Artisan::output());
    }

    // ── Already fully rolled out ─────────────────────────────────────────

    public function test_capability_already_fully_rolled_out_is_a_safe_no_op(): void
    {
        $org = $this->makeOrg('p6');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::FRIDAY_PACKS => $this->unrelatedEntitlementEntry(true)]);

        $before = SubscriptionEntitlementSnapshot::count();
        $exitCode = Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);
        $after = SubscriptionEntitlementSnapshot::count();

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $after);
    }

    // ── Subscription states ──────────────────────────────────────────────

    public function test_trial_subscription_is_skipped_not_touched(): void
    {
        $org = $this->makeOrg('t1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan, SubscriptionStatus::TRIALING);
        $this->makeLegacySnapshot($sub, [], null);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $this->assertSame($before, SubscriptionEntitlementSnapshot::count(), 'TRIAL must never be touched — the trial profile is deliberately out of scope.');
    }

    public function test_grace_past_due_subscription_is_refreshed(): void
    {
        $org = $this->makeOrg('g1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan, SubscriptionStatus::PAST_DUE);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $latest = $sub->fresh()->currentEntitlementSnapshot;
        $this->assertTrue($latest->entitlements_json[Feature::FRIDAY_PACKS]['value']);
    }

    public function test_restricted_subscription_is_skipped_not_touched(): void
    {
        $org = $this->makeOrg('r1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan, SubscriptionStatus::SUSPENDED);
        $this->makeLegacySnapshot($sub, []);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $this->assertSame($before, SubscriptionEntitlementSnapshot::count());
    }

    public function test_none_mode_subscription_is_skipped_not_touched(): void
    {
        $org = $this->makeOrg('n1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan, SubscriptionStatus::DRAFT);

        $before = SubscriptionEntitlementSnapshot::count();
        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $this->assertSame($before, SubscriptionEntitlementSnapshot::count());
    }

    public function test_subscription_with_no_existing_snapshot_is_not_manufactured_one(): void
    {
        $org = $this->makeOrg('ns1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan); // no snapshot created at all

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count(), 'Must never manufacture a snapshot for a subscription that has none.');
    }

    // ── FeatureGate resolves correctly after rollout ──────────────────────

    public function test_feature_gate_resolves_friday_packs_correctly_after_rollout(): void
    {
        $org = $this->makeOrg('fg1');
        $plan = $this->makePlan('professional', [Feature::FRIDAY_PACKS => true]);
        $sub = $this->makeSubscription($org, $plan);
        $this->makeLegacySnapshot($sub, [Feature::ADVANCED_REPORTING => $this->unrelatedEntitlementEntry(true)]);

        Artisan::call('entitlements:refresh-capability-rollout', ['capability' => Feature::FRIDAY_PACKS, '--confirm' => true]);

        /** @var \App\Services\Entitlements\FeatureGate $gate */
        $gate = $this->app->make(\App\Services\Entitlements\FeatureGate::class);
        $this->assertTrue($gate->allows($org->fresh(), Feature::FRIDAY_PACKS));
    }
}
