<?php

namespace App\Support\Billing;

/**
 * The four possible outcomes of comparing a subscription's current
 * plan/interval against a requested target — see PlanChangeClassifier.
 */
class PlanChangeClassification
{
    /** Same plan AND same interval — nothing to do. */
    public const NO_CHANGE = 'no_change';

    /** Different plan, target ranks higher (App\Models\PricingPlan::$order). */
    public const UPGRADE = 'upgrade';

    /** Different plan, target ranks lower. */
    public const DOWNGRADE = 'downgrade';

    /**
     * Same plan, different interval (e.g. monthly -> annual) — deliberately
     * NOT classified as an upgrade or downgrade. No approved commercial
     * policy exists in this codebase for whether switching interval alone
     * is a financial upgrade, a downgrade, or commercially neutral (see
     * Stripe Sandbox Plan-Change checkpoint, Stage 4) — never guessed at.
     */
    public const AMBIGUOUS_INTERVAL_CHANGE = 'ambiguous_interval_change';

    public const ALL = [
        self::NO_CHANGE,
        self::UPGRADE,
        self::DOWNGRADE,
        self::AMBIGUOUS_INTERVAL_CHANGE,
    ];
}
