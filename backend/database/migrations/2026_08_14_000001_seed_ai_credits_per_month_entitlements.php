<?php

use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Support\Entitlements\Feature;
use Illuminate\Database\Migrations\Migration;

/**
 * Entitlement Specification v1 §4a / AI Credit Policy Part Ten (G4C.3E) —
 * seeds the provisional, not-founder-approved ai_credits_per_month values
 * for the three known plan codes. Existing plans already have entitlement
 * rows (PlanEntitlementRepository::initializeDefaultsForPlan() is a no-op
 * once any row exists for a plan), so the new registry key needs an
 * explicit data migration rather than relying on that generic default path.
 *
 * Essential=100, Professional=1000 (provisional product configuration, NOT
 * a per-credit price, NOT founder-approved — see the amendment's own
 * disclaimer). Enterprise is deliberately left unconfigured (0, the same
 * most-restrictive baseline PlanEntitlementRepository already uses for any
 * undefined value) — negotiated/custom, per the registry table.
 *
 * Idempotent: skipped entirely for a plan that already has a row for this
 * key (e.g. a re-run, or a future manual override already in place).
 */
return new class extends Migration
{
    public function up(): void
    {
        $allowances = [
            'essential' => 100,
            'professional' => 1000,
        ];

        foreach (PricingPlan::query()->get(['id', 'code']) as $plan) {
            if (PricingPlanEntitlement::query()
                ->where('pricing_plan_id', $plan->id)
                ->where('feature_key', Feature::AI_CREDITS_PER_MONTH)
                ->exists()
            ) {
                continue;
            }

            PricingPlanEntitlement::create([
                'pricing_plan_id' => $plan->id,
                'feature_key' => Feature::AI_CREDITS_PER_MONTH,
                'is_applicable' => true,
                'is_unlimited' => false,
                'value' => $allowances[$plan->code] ?? 0,
            ]);
        }
    }

    public function down(): void
    {
        PricingPlanEntitlement::query()->where('feature_key', Feature::AI_CREDITS_PER_MONTH)->delete();
    }
};
