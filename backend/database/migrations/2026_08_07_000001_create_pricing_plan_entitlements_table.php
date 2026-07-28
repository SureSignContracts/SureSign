<?php

use App\Support\Entitlements\PlanEntitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G1 — the database-backed replacement for
 * App\Support\Entitlements\PlanEntitlements's hardcoded registry body.
 * One row per (pricing plan, Feature::* key) — Feature itself remains the
 * only entitlement catalogue (no new keys, no duplicated metadata):
 * `value_type` and `unit` are deliberately NOT columns here — both are
 * already fixed, deterministic functions of `feature_key` via
 * `Feature::valueType()`/`Feature::unit()`, so storing them again per row
 * would duplicate metadata Feature already owns (Phase G0 §8, explicit
 * instruction).
 *
 * Three states a row can represent, exactly matching
 * App\Support\Entitlements\EntitlementValue's own three constructors:
 *   - `is_applicable = true`,  `is_unlimited = false`, `value` set   → a normal resolved value (make()/notIncluded())
 *   - `is_applicable = true`,  `is_unlimited = true`,  `value = null` → unlimited()
 *   - `is_applicable = false`, `is_unlimited = false`, `value = null` → notApplicable() — e.g. accounting_exports/api_access
 *     today, since neither feature is built yet (Feature::REGISTRY's `sold => false`).
 *
 * Reserved/dormant keys (`max_users`, `max_organisations`) deliberately
 * get NO rows at all — Entitlement Specification v1 §8's explicit rule:
 * "giving them rows today would imply an active, resolved value where
 * none is commercially intended."
 *
 * Seeded in this same migration (Stage 3) by calling the existing
 * `PlanEntitlements`/`Feature` classes programmatically — never
 * hand-transcribed — so the seeded values are guaranteed byte-for-byte
 * identical to today's hardcoded defaults, for every `pricing_plans` row
 * that matches one of `PlanEntitlements::isKnownPlanCode()`'s three
 * existing codes. A plan that doesn't match (none exist yet, but see
 * Stage 9 for new plans going forward) simply receives no rows here —
 * `App\Services\Entitlements\PlanEntitlementRepository`'s temporary
 * fallback (Stage 8) covers that gap until this migration's own seed
 * data is confirmed complete in every environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_plan_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('pricing_plan_id')->constrained('pricing_plans')->cascadeOnDelete();

            // Validated in the app layer against Feature::ALL (dormant
            // keys excluded) — matching this codebase's existing
            // convention for other "enum-constrained in app layer"
            // string columns (e.g. pricing_plans.badge_color).
            $table->string('feature_key', 60);

            // Whether this entitlement applies to this plan at all —
            // false only for a feature that isn't sold/built yet
            // (EntitlementValue::notApplicable()). Distinct from a
            // feature flag that's simply switched off (that's
            // is_applicable=true, is_unlimited=false, value=false).
            $table->boolean('is_applicable')->default(true);

            $table->boolean('is_unlimited')->default(false);

            // JSON so a single column can hold whichever scalar type
            // Feature::valueType($feature_key) declares (boolean/integer/
            // decimal/string) — json_decode() already returns the correct
            // native PHP type for a JSON scalar, so no separate typed
            // columns are needed. Always null when is_unlimited=true or
            // is_applicable=false, matching EntitlementValue's own
            // invariant (enforced in PlanEntitlementRepository, not the
            // database — consistent with how this codebase already
            // enforces cross-field invariants at the app layer, e.g.
            // BillingCheckoutSession's completed/expired mutual exclusion).
            $table->json('value')->nullable();

            $table->timestamps();

            // Prevents duplicate plan-feature rows (Stage 11) — exactly
            // one row per plan per key.
            $table->unique(['pricing_plan_id', 'feature_key']);
        });

        $this->seedFromHardcodedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_plan_entitlements');
    }

    private function seedFromHardcodedDefaults(): void
    {
        $planCodes = [
            PlanEntitlements::ESSENTIAL,
            PlanEntitlements::PROFESSIONAL,
            PlanEntitlements::ENTERPRISE,
        ];

        foreach ($planCodes as $planCode) {
            $pricingPlanId = DB::table('pricing_plans')->where('code', $planCode)->value('id');

            // This plan doesn't exist in this environment's database yet
            // (e.g. a fresh install before PricingSeeder runs) — nothing
            // to attach rows to. PlanEntitlementRepository's temporary
            // fallback covers plan resolution until a later seed/migration
            // run finds the plan and this data can be backfilled.
            if ($pricingPlanId === null) {
                continue;
            }

            $now = DB::table('pricing_plans')->where('id', $pricingPlanId)->value('updated_at') ?? now();
            $rows = [];

            foreach (PlanEntitlements::forPlanCode($planCode) as $featureKey => $entitlementValue) {
                $rows[] = [
                    'pricing_plan_id' => $pricingPlanId,
                    'feature_key' => $featureKey,
                    'is_applicable' => $entitlementValue->isUnlimited || $entitlementValue->value !== null,
                    'is_unlimited' => $entitlementValue->isUnlimited,
                    'value' => $entitlementValue->value === null ? null : json_encode($entitlementValue->value),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('pricing_plan_entitlements')->insert($rows);
            }
        }
    }
};
