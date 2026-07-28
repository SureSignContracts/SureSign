<?php

namespace Tests\Unit\Entitlements;

use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Tests\TestCase;

class PlanEntitlementsTest extends TestCase
{
    public function test_essential_includes_branding_but_not_advanced_reporting(): void
    {
        $defaults = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);

        $this->assertTrue($defaults[Feature::CUSTOM_BRANDING]->asBoolean());
        $this->assertFalse($defaults[Feature::ADVANCED_REPORTING]->asBoolean());
    }

    public function test_professional_includes_advanced_reporting_and_priority_support(): void
    {
        $defaults = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);

        $this->assertTrue($defaults[Feature::ADVANCED_REPORTING]->asBoolean());
        $this->assertTrue($defaults[Feature::PRIORITY_SUPPORT]->asBoolean());
    }

    public function test_professional_allows_more_projects_than_essential(): void
    {
        $essential = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);
        $professional = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);

        $this->assertGreaterThan($essential[Feature::MAX_ACTIVE_PROJECTS]->value, $professional[Feature::MAX_ACTIVE_PROJECTS]->value);
    }

    public function test_enterprise_baseline_is_not_automatically_unlimited(): void
    {
        // Entitlement Specification v1 §18 — Enterprise must never default
        // to unlimited "for convenience"; it must be a real, finite
        // baseline pending a negotiated override.
        $defaults = PlanEntitlements::forPlanCode(PlanEntitlements::ENTERPRISE);

        $this->assertFalse($defaults[Feature::MAX_ACTIVE_PROJECTS]->isUnlimited);
        $this->assertIsInt($defaults[Feature::MAX_ACTIVE_PROJECTS]->value);
    }

    public function test_unbuilt_features_are_not_applicable_on_every_plan(): void
    {
        foreach ([PlanEntitlements::ESSENTIAL, PlanEntitlements::PROFESSIONAL, PlanEntitlements::ENTERPRISE] as $planCode) {
            $defaults = PlanEntitlements::forPlanCode($planCode);

            $this->assertNull($defaults[Feature::ACCOUNTING_EXPORTS]->value);
            $this->assertNull($defaults[Feature::API_ACCESS]->value);
        }
    }

    public function test_dormant_keys_never_appear_in_any_plans_entitlement_set(): void
    {
        foreach ([PlanEntitlements::ESSENTIAL, PlanEntitlements::PROFESSIONAL, PlanEntitlements::ENTERPRISE] as $planCode) {
            $defaults = PlanEntitlements::forPlanCode($planCode);

            $this->assertArrayNotHasKey(Feature::MAX_USERS, $defaults);
            $this->assertArrayNotHasKey(Feature::MAX_ORGANISATIONS, $defaults);
        }
    }

    public function test_unknown_plan_code_resolves_to_an_empty_set(): void
    {
        $this->assertSame([], PlanEntitlements::forPlanCode('not_a_real_plan'));
        $this->assertFalse(PlanEntitlements::isKnownPlanCode('not_a_real_plan'));
    }

    // ─── Trial profile (Section 17) ────────────────────────────────────────

    public function test_trial_profile_is_dedicated_not_reused_from_a_standard_plan(): void
    {
        $trial = PlanEntitlements::trialProfile();
        $essential = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);
        $professional = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);

        // Demonstrates Professional-level reporting during the trial —
        // differs from both standard plans' own defaults, proving it's a
        // genuinely distinct profile rather than either plan's defaults.
        $this->assertNotEquals($essential[Feature::ADVANCED_REPORTING]->value, $trial[Feature::ADVANCED_REPORTING]->value);
        $this->assertEquals($professional[Feature::ADVANCED_REPORTING]->value, $trial[Feature::ADVANCED_REPORTING]->value);
    }

    public function test_trial_ai_allowance_is_capped_more_tightly_than_any_standard_plan(): void
    {
        $trial = PlanEntitlements::trialProfile();
        $essential = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);
        $professional = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);
        $enterprise = PlanEntitlements::forPlanCode(PlanEntitlements::ENTERPRISE);

        $trialAi = $trial[Feature::AI_ANALYSES_PER_MONTH]->value;

        $this->assertLessThan($essential[Feature::AI_ANALYSES_PER_MONTH]->value, $trialAi);
        $this->assertLessThan($professional[Feature::AI_ANALYSES_PER_MONTH]->value, $trialAi);
        $this->assertLessThan($enterprise[Feature::AI_ANALYSES_PER_MONTH]->value, $trialAi);
    }
}
