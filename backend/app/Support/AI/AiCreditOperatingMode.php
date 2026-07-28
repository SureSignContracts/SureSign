<?php

namespace App\Support\AI;

use App\Models\SuresignSetting;

/**
 * The single authoritative AI Credit operating mode — one of three explicit
 * states, replacing the earlier (never-released) boolean
 * ai_credit_enforcement_enabled outright rather than layering a second
 * independent flag on top of it:
 *
 *  - DISABLED — the AI Credit accounting lifecycle does not run at all.
 *    No reservation, simulation, settlement, release, or enforcement
 *    evaluation is attempted. AI workflows themselves are completely
 *    unaffected — customers are never blocked. Every analysis row records
 *    credit_reservation_amount = null and shadow_enforcement_result = null
 *    (deliberately distinct from 'unresolved' — see
 *    App\Services\AI\AiCreditWorkflowLifecycle::reserveFor()'s docblock).
 *  - SHADOW — the default. The full accounting lifecycle runs (reserve,
 *    settle, release, ledger balances, candidate-policy simulation, and the
 *    customer usage meter all continue exactly as today), but an
 *    insufficient balance never blocks an AI workflow.
 *  - ENFORCED — the same accounting lifecycle runs as SHADOW, but an
 *    organisation whose available balance is insufficient is blocked from
 *    running AI analysis before the provider is ever called — see
 *    AiCreditWorkflowLifecycle::shouldBlock().
 *
 * current() is the ONLY place this setting is read from — every call site
 * (AiCreditWorkflowLifecycle, AiCreditUsageService, both AI jobs, the admin
 * endpoints) goes through here rather than reading
 * SuresignSetting::instance()->ai_credit_operating_mode directly, so an
 * unrecognised/corrupt stored value has one place to fail safe (to SHADOW,
 * never to ENFORCED — a bad value must never silently start blocking
 * customers).
 */
final class AiCreditOperatingMode
{
    public const DISABLED = 'disabled';
    public const SHADOW = 'shadow';
    public const ENFORCED = 'enforced';

    public const ALL = [self::DISABLED, self::SHADOW, self::ENFORCED];

    public const DEFAULT = self::SHADOW;

    public static function current(): string
    {
        $mode = SuresignSetting::instance()->ai_credit_operating_mode;

        return in_array($mode, self::ALL, true) ? $mode : self::DEFAULT;
    }

    public static function isDisabled(): bool
    {
        return self::current() === self::DISABLED;
    }

    public static function isEnforced(): bool
    {
        return self::current() === self::ENFORCED;
    }
}
