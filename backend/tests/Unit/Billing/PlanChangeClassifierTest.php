<?php

namespace Tests\Unit\Billing;

use App\Models\PricingPlan;
use App\Support\Billing\PlanChangeClassification;
use App\Support\Billing\PlanChangeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanChangeClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function plan(string $code, int $order): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'order' => $order]);
    }

    public function test_same_plan_same_interval_is_no_change(): void
    {
        $plan = $this->plan('essential', 1);

        $this->assertSame(
            PlanChangeClassification::NO_CHANGE,
            PlanChangeClassifier::classify($plan, 'monthly', $plan, 'monthly'),
        );
    }

    public function test_same_plan_different_interval_is_ambiguous(): void
    {
        $plan = $this->plan('essential', 1);

        $this->assertSame(
            PlanChangeClassification::AMBIGUOUS_INTERVAL_CHANGE,
            PlanChangeClassifier::classify($plan, 'monthly', $plan, 'annual'),
        );
    }

    public function test_higher_order_target_is_an_upgrade(): void
    {
        $essential = $this->plan('essential', 1);
        $professional = $this->plan('professional', 2);

        $this->assertSame(
            PlanChangeClassification::UPGRADE,
            PlanChangeClassifier::classify($essential, 'monthly', $professional, 'monthly'),
        );
    }

    public function test_lower_order_target_is_a_downgrade(): void
    {
        $essential = $this->plan('essential', 1);
        $professional = $this->plan('professional', 2);

        $this->assertSame(
            PlanChangeClassification::DOWNGRADE,
            PlanChangeClassifier::classify($professional, 'monthly', $essential, 'monthly'),
        );
    }

    public function test_upgrade_classification_is_independent_of_interval_change(): void
    {
        $essential = $this->plan('essential', 1);
        $professional = $this->plan('professional', 2);

        $this->assertSame(
            PlanChangeClassification::UPGRADE,
            PlanChangeClassifier::classify($essential, 'monthly', $professional, 'annual'),
        );
    }
}
