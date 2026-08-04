<?php

use App\Support\Entitlements\Feature;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Organisation URL Branding, customer self-service phase — seeds default
 * `pricing_plan_entitlements` rows for the two new Feature keys
 * (CUSTOM_BRANDED_SUBDOMAIN, CUSTOM_DOMAIN) on the three known plans.
 * `PlanEntitlementRepository::initializeDefaultsForPlan()` only ever
 * populates a plan that has NO existing rows at all — Essential/
 * Professional/Enterprise already have rows for the other eleven keys, so
 * this migration is what actually gives them a value for these two new
 * ones (`updateOrInsert`, so it's safe to re-run and never overwrites a
 * value a Super Admin may have already configured via the Pricing
 * Management entitlement editor between deploy and this migration
 * running).
 *
 * Initial mapping (approved 2026-08-27):
 *   - CUSTOM_BRANDED_SUBDOMAIN: Essential=false, Professional=true, Enterprise=true.
 *   - CUSTOM_DOMAIN: false for every plan — Super-Admin-managed only this
 *     phase (see Feature::CUSTOM_DOMAIN's own docblock); not yet sold as
 *     customer self-service.
 *
 * IMPORTANT — this migration alone does NOT grant the capability to any
 * ALREADY-ACTIVE subscription: FeatureGate resolves an active
 * subscription's entitlements from its immutable
 * SubscriptionEntitlementSnapshot, taken at activation and never
 * containing a key that didn't exist yet. Only a subscription's next real
 * commercial event (or the explicit, separate
 * `entitlements:refresh-capability-rollout` Artisan command) picks this
 * up for existing customers — see that command's own docblock and
 * internal-docs/super-admin/organisation-url-branding.md.
 */
return new class extends Migration
{
    private const MAPPING = [
        'essential' => [Feature::CUSTOM_BRANDED_SUBDOMAIN => false, Feature::CUSTOM_DOMAIN => false],
        'professional' => [Feature::CUSTOM_BRANDED_SUBDOMAIN => true, Feature::CUSTOM_DOMAIN => false],
        'enterprise' => [Feature::CUSTOM_BRANDED_SUBDOMAIN => true, Feature::CUSTOM_DOMAIN => false],
    ];

    public function up(): void
    {
        foreach (self::MAPPING as $planCode => $entitlements) {
            $planId = DB::table('pricing_plans')->where('code', $planCode)->value('id');

            if ($planId === null) {
                continue; // plan doesn't exist in this environment (e.g. a fresh/partial install) — nothing to seed
            }

            foreach ($entitlements as $featureKey => $value) {
                DB::table('pricing_plan_entitlements')->updateOrInsert(
                    ['pricing_plan_id' => $planId, 'feature_key' => $featureKey],
                    [
                        'feature_key' => $featureKey,
                        'is_applicable' => true,
                        'is_unlimited' => false,
                        'value' => $value ? 'true' : 'false',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::MAPPING as $planCode => $entitlements) {
            $planId = DB::table('pricing_plans')->where('code', $planCode)->value('id');
            if ($planId === null) {
                continue;
            }

            DB::table('pricing_plan_entitlements')
                ->where('pricing_plan_id', $planId)
                ->whereIn('feature_key', array_keys($entitlements))
                ->delete();
        }
    }
};
