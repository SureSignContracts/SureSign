<?php

use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Friday Pack Plan Entitlement Enforcement phase — seeds the actual
 * `pricing_plan_entitlements` rows the new `Feature::FRIDAY_PACKS` key
 * needs to resolve correctly for the three existing SureSign plans.
 *
 * Why this is required, not optional: `PlanEntitlementRepository::forPlan()`
 * only returns a key that has a real row for that plan — a plan that
 * already has SOME entitlement rows (which Essential/Professional/
 * Enterprise all do, since the 2026_08_07_000001 migration seeded the
 * original key set) never falls into the "no rows at all" hardcoded
 * fallback just because ONE new key is missing; it simply omits that key
 * from the resolved array, and `FeatureGate` then fails safe to "not
 * entitled" for it. Left unseeded, this would have made Friday Packs
 * resolve as NOT entitled for every plan, including Professional and
 * Enterprise — a regression for currently-working customers, not merely
 * a no-op for Essential. This migration is what prevents that.
 *
 * Final commercial decision (Friday Pack Plan Entitlement Enforcement
 * phase): Essential = false, Professional = true, Enterprise = true.
 *
 * Mirrors 2026_08_07_000001_create_pricing_plan_entitlements_table.php's
 * own seeding shape exactly (`is_applicable = true`, `is_unlimited =
 * false`, `value` a JSON-encoded boolean) — deliberately idempotent (skips
 * a plan that doesn't exist yet in this environment, and skips a row that
 * already exists for that plan+key, so re-running this migration, or
 * running it after a manual Pricing Management edit, never overwrites an
 * operator's own subsequent configuration).
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            PlanEntitlements::ESSENTIAL => false,
            PlanEntitlements::PROFESSIONAL => true,
            PlanEntitlements::ENTERPRISE => true,
        ];

        foreach ($defaults as $planCode => $included) {
            $pricingPlanId = DB::table('pricing_plans')->where('code', $planCode)->value('id');

            if ($pricingPlanId === null) {
                continue;
            }

            $alreadyExists = DB::table('pricing_plan_entitlements')
                ->where('pricing_plan_id', $pricingPlanId)
                ->where('feature_key', Feature::FRIDAY_PACKS)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $now = DB::table('pricing_plans')->where('id', $pricingPlanId)->value('updated_at') ?? now();

            DB::table('pricing_plan_entitlements')->insert([
                'pricing_plan_id' => $pricingPlanId,
                'feature_key' => Feature::FRIDAY_PACKS,
                'is_applicable' => true,
                'is_unlimited' => false,
                'value' => json_encode($included),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('pricing_plan_entitlements')->where('feature_key', Feature::FRIDAY_PACKS)->delete();
    }
};
