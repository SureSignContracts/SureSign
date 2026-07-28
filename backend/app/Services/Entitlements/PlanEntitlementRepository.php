<?php

namespace App\Services\Entitlements;

use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Support\Entitlements\EntitlementSource;
use App\Support\Entitlements\EntitlementValue;
use App\Support\Entitlements\EntitlementValueType;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase G1 — the database-backed replacement for
 * `PlanEntitlements::forPlanCode()`/`isKnownPlanCode()`. The single place
 * `FeatureGate`, `EntitlementSnapshotService`, and
 * `SnapshotIntegrityClassifier` now resolve a PLAN's default entitlements
 * from — none of them query `pricing_plan_entitlements` directly, and
 * none of them need to know whether a given plan's defaults came from the
 * database or the temporary hardcoded fallback below.
 *
 * `PlanEntitlements` itself is NOT retired by this class — it remains:
 *   (a) the seed source the creating migration transcribed exactly, and
 *   (b) the Stage 8 temporary compatibility fallback for any plan with no
 *       configured `pricing_plan_entitlements` rows yet (a fresh
 *       environment before that migration's seed step found the plan, or
 *       a genuine gap worth surfacing). This fallback is meant to be
 *       temporary — see internal-docs/super-admin/subscription-billing.md's
 *       Phase G1 section for the removal plan — and every use of it logs
 *       a warning specifically so it can never silently mask a real
 *       configuration gap.
 * `PlanEntitlements::trialProfile()` is untouched and still called
 * directly by `FeatureGate`/`EntitlementSnapshotService` — trial defaults
 * are explicitly out of scope for this phase (Phase G0 §11).
 */
class PlanEntitlementRepository
{
    /**
     * @var array<int, array<string, EntitlementValue>>
     */
    private array $resolvedByPlanId = [];

    /**
     * @return array<string, EntitlementValue>
     */
    public function forPlanCode(string $planCode): array
    {
        $plan = PricingPlan::query()->where('code', $planCode)->first();

        if ($plan === null) {
            // No `pricing_plans` row for this code at all (a fresh/partial
            // environment, or a plan renamed/removed) — same safety net as
            // "plan exists but has no configured rows" below: a known
            // hardcoded code never silently resolves to [] just because
            // the plan row itself is missing.
            if (PlanEntitlements::isKnownPlanCode($planCode)) {
                Log::warning('PlanEntitlementRepository: no pricing_plans row exists for this plan code — using the temporary hardcoded PlanEntitlements fallback.', [
                    'plan_code' => $planCode,
                ]);

                return PlanEntitlements::forPlanCode($planCode);
            }

            return [];
        }

        return $this->forPlan($plan);
    }

    /**
     * @return array<string, EntitlementValue>
     */
    public function forPlan(PricingPlan $plan): array
    {
        // Request-scoped memoization only (Stage 12) — avoids re-querying
        // for the same plan across multiple FeatureGate calls within one
        // request/job. Never persisted beyond the current process.
        if (isset($this->resolvedByPlanId[$plan->id])) {
            return $this->resolvedByPlanId[$plan->id];
        }

        $rows = PricingPlanEntitlement::query()->where('pricing_plan_id', $plan->id)->get();

        if ($rows->isEmpty()) {
            return $this->resolvedByPlanId[$plan->id] = $this->fallback($plan);
        }

        $values = $rows->mapWithKeys(function (PricingPlanEntitlement $row) {
            return [$row->feature_key => $this->toEntitlementValue($row)];
        })->all();

        return $this->resolvedByPlanId[$plan->id] = $values;
    }

    /**
     * Whether this plan code currently resolves to a real, non-fallback
     * entitlement set — the database-backed equivalent of the old
     * `PlanEntitlements::isKnownPlanCode()`, used by
     * `SnapshotIntegrityClassifier` to decide recoverability. Deliberately
     * still reports `true` while the Stage 8 fallback covers a plan (a
     * subscription on that plan IS still recoverable today, just from the
     * temporary source) — this only reports `false` when nothing at all
     * — neither configured rows nor the hardcoded fallback — can resolve
     * this plan code.
     */
    public function isKnownPlanCode(string $planCode): bool
    {
        $plan = PricingPlan::query()->where('code', $planCode)->first();

        if ($plan === null) {
            return PlanEntitlements::isKnownPlanCode($planCode);
        }

        return PricingPlanEntitlement::query()->where('pricing_plan_id', $plan->id)->exists()
            || PlanEntitlements::isKnownPlanCode($planCode);
    }

    /**
     * Stage 9 — called once, from `PricingManagementService::createPlan()`,
     * so a brand-new plan never silently resolves to zero entitlements
     * (the gap Phase G0 found: `PlanEntitlements::forPlanCode()` returns
     * `[]` for any plan code it doesn't hardcode). A conservative,
     * explicit, most-restrictive baseline — every non-dormant `Feature`
     * key gets a real row (feature flags off, usage allowances at 0) —
     * not a guess at commercial intent, which is unknown at creation
     * time and belongs to the future Pricing Management entitlement
     * editor (Phase G2). A no-op if the plan already has any configured
     * rows (never overwrites existing configuration).
     */
    public function initializeDefaultsForPlan(PricingPlan $plan): void
    {
        if (PricingPlanEntitlement::query()->where('pricing_plan_id', $plan->id)->exists()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (Feature::ALL as $featureKey) {
            if (Feature::isDormant($featureKey)) {
                continue; // reserved keys never get rows — Entitlement Specification v1 §8
            }

            $rows[] = [
                'pricing_plan_id' => $plan->id,
                'feature_key' => $featureKey,
                'is_applicable' => true,
                'is_unlimited' => false,
                'value' => json_encode(Feature::isFeatureFlag($featureKey) ? false : 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (!empty($rows)) {
            DB::table('pricing_plan_entitlements')->insert($rows);
        }
    }

    /**
     * The same exact-fidelity transcription the creating migration runs
     * (Stage 3) — exposed here too so `PricingSeeder` (which runs AFTER
     * migrations, so it's the only place a genuinely fresh install ever
     * has real `pricing_plans` rows for "essential"/"professional"/
     * "enterprise" to attach rows to) can seed the real hardcoded values
     * for a known plan, not the conservative placeholder
     * `initializeDefaultsForPlan()` uses for a brand-new, commercially
     * undefined plan. A no-op if this plan already has any configured
     * rows — never overwrites.
     */
    public function seedExactDefaultsForKnownPlan(PricingPlan $plan): void
    {
        if (!PlanEntitlements::isKnownPlanCode($plan->code)) {
            return;
        }

        if (PricingPlanEntitlement::query()->where('pricing_plan_id', $plan->id)->exists()) {
            return;
        }

        $now = now();
        $rows = [];

        foreach (PlanEntitlements::forPlanCode($plan->code) as $featureKey => $entitlementValue) {
            $rows[] = [
                'pricing_plan_id' => $plan->id,
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

    private function toEntitlementValue(PricingPlanEntitlement $row): EntitlementValue
    {
        if (!$row->is_applicable) {
            return EntitlementValue::notApplicable($row->feature_key);
        }

        if ($row->is_unlimited) {
            return EntitlementValue::unlimited($row->feature_key, EntitlementSource::PLAN_DEFAULT, Feature::unit($row->feature_key));
        }

        return EntitlementValue::make(
            $row->feature_key,
            Feature::valueType($row->feature_key),
            $this->coerceToDeclaredType($row->feature_key, $row->value),
            false,
            EntitlementSource::PLAN_DEFAULT,
            Feature::unit($row->feature_key),
        );
    }

    /**
     * `value` round-trips through the `json` column cast — a JSON scalar
     * decodes to the right native PHP type in the ordinary case, but a
     * whole-number PHP float (e.g. `200.0` for a `decimal` entitlement
     * like `storage_gb`) can lose its float-ness on the way through
     * `json_encode()` (which serializes a zero-fraction float as `200`,
     * not `200.0`, without the rarely-used `JSON_PRESERVE_ZERO_FRACTION`
     * flag) and decode back as an `int`. Coercing explicitly here — based
     * on `Feature::valueType()`, never guessed — guarantees exact type
     * fidelity (Stage 3's "seeded values must match exactly") regardless
     * of how the value was originally stored.
     */
    private function coerceToDeclaredType(string $featureKey, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (Feature::valueType($featureKey)) {
            EntitlementValueType::DECIMAL => (float) $value,
            EntitlementValueType::INTEGER => (int) $value,
            EntitlementValueType::BOOLEAN => (bool) $value,
            default => $value,
        };
    }

    /**
     * Stage 8 — temporary migration safety net. Never silent: always logs
     * a warning so a genuine configuration gap (as opposed to an
     * environment where the seed migration simply hasn't run against
     * this plan yet) is always visible to an operator.
     *
     * @return array<string, EntitlementValue>
     */
    private function fallback(PricingPlan $plan): array
    {
        if (!PlanEntitlements::isKnownPlanCode($plan->code)) {
            Log::warning('PlanEntitlementRepository: plan has no configured entitlement rows and no hardcoded fallback exists — resolving empty.', [
                'pricing_plan_id' => $plan->id,
                'plan_code' => $plan->code,
            ]);

            return [];
        }

        Log::warning('PlanEntitlementRepository: plan has no configured entitlement rows — using the temporary hardcoded PlanEntitlements fallback. This should not persist once Phase G1\'s seed migration has run against this plan.', [
            'pricing_plan_id' => $plan->id,
            'plan_code' => $plan->code,
        ]);

        return PlanEntitlements::forPlanCode($plan->code);
    }
}
