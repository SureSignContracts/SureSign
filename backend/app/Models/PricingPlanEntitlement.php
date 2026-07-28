<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase G1 — one row per (pricing plan, `Feature::*` key). See the
 * creating migration's docblock for the exact three-state representation
 * (`is_applicable`/`is_unlimited`/`value`) and why `value_type`/`unit`
 * are deliberately not columns here (both are fixed functions of
 * `feature_key` via `App\Support\Entitlements\Feature`).
 *
 * Never queried directly by `FeatureGate`/`EntitlementSnapshotService`/
 * `SnapshotIntegrityClassifier` — all three go through
 * `App\Services\Entitlements\PlanEntitlementRepository`, which is also
 * the only place this model's rows are translated into an
 * `App\Support\Entitlements\EntitlementValue`.
 */
class PricingPlanEntitlement extends Model
{
    protected $fillable = [
        'pricing_plan_id',
        'feature_key',
        'is_applicable',
        'is_unlimited',
        'value',
    ];

    protected $casts = [
        'is_applicable' => 'boolean',
        'is_unlimited' => 'boolean',
        'value' => 'json',
    ];

    public function pricingPlan()
    {
        return $this->belongsTo(PricingPlan::class);
    }
}
