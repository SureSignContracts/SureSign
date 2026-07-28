<?php

namespace Tests\Feature\Entitlements;

use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Services\Entitlements\PlanEntitlementRepository;
use App\Support\Entitlements\EntitlementSource;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G1 — the database-backed replacement for
 * `PlanEntitlements::forPlanCode()`/`isKnownPlanCode()`. Covers all three
 * row states (normal value, unlimited, not-applicable), the two levels of
 * temporary fallback (Stage 8), new-plan initialization (Stage 9), and
 * the unique-row constraint (Stage 11).
 */
class PlanEntitlementRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $code): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function repository(): PlanEntitlementRepository
    {
        return $this->app->make(PlanEntitlementRepository::class);
    }

    public function test_resolves_a_normal_configured_value_exactly(): void
    {
        $plan = $this->plan('essential');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::MAX_ACTIVE_PROJECTS,
            'is_applicable' => true, 'is_unlimited' => false, 'value' => 5,
        ]);

        $resolved = $this->repository()->forPlan($plan);

        $this->assertSame(5, $resolved[Feature::MAX_ACTIVE_PROJECTS]->value);
        $this->assertFalse($resolved[Feature::MAX_ACTIVE_PROJECTS]->isUnlimited);
        $this->assertSame('projects', $resolved[Feature::MAX_ACTIVE_PROJECTS]->unit);
        $this->assertSame(EntitlementSource::PLAN_DEFAULT, $resolved[Feature::MAX_ACTIVE_PROJECTS]->source);
    }

    public function test_resolves_an_unlimited_value(): void
    {
        $plan = $this->plan('essential');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::STORAGE_GB,
            'is_applicable' => true, 'is_unlimited' => true, 'value' => null,
        ]);

        $resolved = $this->repository()->forPlan($plan);

        $this->assertTrue($resolved[Feature::STORAGE_GB]->isUnlimited);
        $this->assertNull($resolved[Feature::STORAGE_GB]->value);
    }

    public function test_resolves_a_not_applicable_value(): void
    {
        $plan = $this->plan('essential');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::API_ACCESS,
            'is_applicable' => false, 'is_unlimited' => false, 'value' => null,
        ]);

        $resolved = $this->repository()->forPlan($plan);

        $this->assertFalse($resolved[Feature::API_ACCESS]->isUnlimited);
        $this->assertNull($resolved[Feature::API_ACCESS]->value);
        $this->assertSame(EntitlementSource::NONE, $resolved[Feature::API_ACCESS]->source);
    }

    public function test_falls_back_to_hardcoded_defaults_when_plan_has_no_configured_rows(): void
    {
        $plan = $this->plan('essential');

        $resolved = $this->repository()->forPlan($plan);
        $expected = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);

        $this->assertSame($expected[Feature::MAX_ACTIVE_PROJECTS]->value, $resolved[Feature::MAX_ACTIVE_PROJECTS]->value);
        $this->assertSame($expected[Feature::CUSTOM_BRANDING]->value, $resolved[Feature::CUSTOM_BRANDING]->value);
    }

    public function test_falls_back_to_hardcoded_defaults_when_no_pricing_plan_row_exists_at_all(): void
    {
        // No PricingPlan row created at all — matches the exact scenario
        // several pre-existing entitlement tests rely on (a Subscription's
        // plan_code_snapshot referencing 'professional' with no matching
        // pricing_plans row in that test's database).
        $resolved = $this->repository()->forPlanCode(PlanEntitlements::PROFESSIONAL);
        $expected = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);

        $this->assertSame($expected[Feature::AI_ANALYSES_PER_MONTH]->value, $resolved[Feature::AI_ANALYSES_PER_MONTH]->value);
    }

    public function test_unknown_plan_code_with_no_row_and_no_hardcoded_match_resolves_empty(): void
    {
        $this->assertSame([], $this->repository()->forPlanCode('not_a_real_plan'));
    }

    public function test_is_known_plan_code_true_for_configured_plan(): void
    {
        $plan = $this->plan('a-custom-plan');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::CUSTOM_BRANDING,
            'is_applicable' => true, 'is_unlimited' => false, 'value' => false,
        ]);

        $this->assertTrue($this->repository()->isKnownPlanCode('a-custom-plan'));
    }

    public function test_is_known_plan_code_false_for_genuinely_unknown_code(): void
    {
        $this->assertFalse($this->repository()->isKnownPlanCode('not_a_real_plan'));
    }

    public function test_initialize_defaults_for_plan_creates_a_safe_conservative_baseline(): void
    {
        $plan = $this->plan('a-brand-new-plan');

        $this->repository()->initializeDefaultsForPlan($plan);

        $rows = PricingPlanEntitlement::where('pricing_plan_id', $plan->id)->get()->keyBy('feature_key');

        // Every non-dormant key gets a row — never silently zero rows.
        foreach (Feature::ALL as $key) {
            if (Feature::isDormant($key)) {
                $this->assertFalse($rows->has($key), "Dormant key {$key} must never receive a row.");
                continue;
            }
            $this->assertTrue($rows->has($key), "Non-dormant key {$key} must receive a row.");
        }

        $this->assertSame(false, $rows[Feature::CUSTOM_BRANDING]->value);
        $this->assertSame(0, $rows[Feature::MAX_ACTIVE_PROJECTS]->value);
    }

    public function test_initialize_defaults_for_plan_is_a_no_op_when_rows_already_exist(): void
    {
        $plan = $this->plan('essential');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::MAX_ACTIVE_PROJECTS,
            'is_applicable' => true, 'is_unlimited' => false, 'value' => 5,
        ]);

        $this->repository()->initializeDefaultsForPlan($plan);

        // Still exactly one row — never overwritten with the conservative
        // baseline, never duplicated.
        $this->assertSame(1, PricingPlanEntitlement::where('pricing_plan_id', $plan->id)->count());
        $this->assertSame(5, PricingPlanEntitlement::where('pricing_plan_id', $plan->id)->first()->value);
    }

    public function test_seed_exact_defaults_for_known_plan_matches_hardcoded_values_exactly(): void
    {
        $plan = $this->plan('professional');

        $this->repository()->seedExactDefaultsForKnownPlan($plan);

        $resolved = $this->repository()->forPlan($plan);
        $expected = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);

        foreach ($expected as $key => $expectedValue) {
            $this->assertSame($expectedValue->value, $resolved[$key]->value, "Mismatch for {$key}");
            $this->assertSame($expectedValue->isUnlimited, $resolved[$key]->isUnlimited, "Unlimited mismatch for {$key}");
        }
    }

    public function test_seed_exact_defaults_does_nothing_for_an_unrecognised_plan_code(): void
    {
        $plan = $this->plan('a-custom-plan');

        $this->repository()->seedExactDefaultsForKnownPlan($plan);

        $this->assertSame(0, PricingPlanEntitlement::where('pricing_plan_id', $plan->id)->count());
    }

    public function test_duplicate_plan_feature_row_is_rejected_by_the_unique_constraint(): void
    {
        $plan = $this->plan('essential');
        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::CUSTOM_BRANDING,
            'is_applicable' => true, 'is_unlimited' => false, 'value' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => Feature::CUSTOM_BRANDING,
            'is_applicable' => true, 'is_unlimited' => false, 'value' => false,
        ]);
    }
}
