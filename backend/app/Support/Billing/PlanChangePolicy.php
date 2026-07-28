<?php

namespace App\Support\Billing;

/**
 * Stripe Test Mode Integration checkpoint — the two approved policies.
 * `IMMEDIATE` is used only for upgrades (proration enabled, current
 * billing-cycle anchor preserved). `SCHEDULED_PERIOD_END` is used for
 * every downgrade and may also be used for an explicitly scheduled
 * upgrade — never inferred from timing alone, always the caller's
 * explicit choice (`SubscriptionPlanChangeService::requestUpgrade()`'s
 * `$scheduled` flag).
 */
class PlanChangePolicy
{
    public const IMMEDIATE = 'immediate';
    public const SCHEDULED = 'scheduled';

    public const ALL = [self::IMMEDIATE, self::SCHEDULED];

    public static function isValid(string $policy): bool
    {
        return in_array($policy, self::ALL, true);
    }
}
