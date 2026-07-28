<?php

namespace App\Support\Billing;

use App\Models\PricingPlan;

/**
 * Pure, stateless: decides whether a requested plan/interval change is an
 * upgrade, a downgrade, a no-op, or an ambiguous interval-only change —
 * the classification `SubscriptionPlanChangeService::requestUpgrade()`/
 * `requestDowngrade()` need before either can be called (that service
 * takes the caller's word for which one applies; something has to decide
 * first). Ranks plans by `PricingPlan::$order` — the same field already
 * used to sequence Essential/Professional/Enterprise everywhere else in
 * Pricing/Billing (plan listings, comparison tables) — never display
 * order coincidentally, always the approved commercial hierarchy.
 */
class PlanChangeClassifier
{
    public static function classify(
        PricingPlan $currentPlan,
        string $currentInterval,
        PricingPlan $targetPlan,
        string $targetInterval,
    ): string {
        if ($currentPlan->id === $targetPlan->id) {
            return $currentInterval === $targetInterval
                ? PlanChangeClassification::NO_CHANGE
                : PlanChangeClassification::AMBIGUOUS_INTERVAL_CHANGE;
        }

        return $targetPlan->order > $currentPlan->order
            ? PlanChangeClassification::UPGRADE
            : PlanChangeClassification::DOWNGRADE;
    }
}
