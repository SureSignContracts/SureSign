<?php

namespace Tests\Feature\Pricing;

use App\Models\PricingPlan;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression guard for the Starter -> Essential canonicalisation (Stripe
 * Sandbox Activation checkpoint) — the seeded commercial plan set must
 * always be exactly Essential/Professional/Enterprise, never a fourth
 * "Starter" plan.
 */
class PricingSeederCanonicalPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_produces_exactly_the_three_canonical_plan_codes(): void
    {
        (new PricingSeeder())->run();

        $codes = PricingPlan::orderBy('order')->pluck('code')->all();

        $this->assertSame(['essential', 'professional', 'enterprise'], $codes);
        $this->assertNotContains('starter', $codes);
    }

    public function test_enterprise_has_no_fixed_self_serve_price(): void
    {
        (new PricingSeeder())->run();

        $enterprise = PricingPlan::where('code', 'enterprise')->firstOrFail();

        $this->assertNull($enterprise->monthly_price);
        $this->assertNull($enterprise->annual_price);
    }

    public function test_essential_and_professional_have_complete_monthly_and_annual_pricing(): void
    {
        (new PricingSeeder())->run();

        foreach (['essential', 'professional'] as $code) {
            $plan = PricingPlan::where('code', $code)->firstOrFail();
            $this->assertNotNull($plan->monthly_price, "{$code} is missing a monthly price");
            $this->assertNotNull($plan->annual_price, "{$code} is missing an annual price");
            $this->assertSame('GBP', $plan->currency);
        }
    }
}
