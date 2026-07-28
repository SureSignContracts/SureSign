<?php

namespace App\Support\Billing;

/**
 * Stripe Test Mode Integration checkpoint, Part 10 — the local plan-change
 * request state machine, owned entirely by
 * App\Services\Billing\SubscriptionPlanChangeService (never mutated
 * directly by a controller or the automation service).
 *
 * ```text
 * REQUESTED → SENT → CONFIRMED → APPLIED   (the success path)
 *     │          │
 *     ├──────────┴──→ FAILED               (terminal — retryable or not, see failure_code)
 *     ├──────────────→ CANCELLED           (terminal — operator/automation cancelled before effect)
 *     └──────────────→ SUPERSEDED          (terminal — replaced by a newer request)
 * ```
 *
 * `SENT` means "an outbound Stripe API call for this row has been made and
 * Stripe accepted it" — NOT that the local commercial plan has changed
 * (Part 4/Non-negotiable Principle 11: a successful outbound response
 * alone must never activate/confirm local state). `CONFIRMED` means a
 * verified webhook reported the target Price — only then does
 * `SubscriptionLifecycleService` apply the local plan change and only
 * then does `APPLIED` get set (after the new immutable entitlement
 * snapshot is created).
 */
class PlanChangeState
{
    public const REQUESTED = 'requested';
    public const SENT = 'sent';
    public const CONFIRMED = 'confirmed';
    public const APPLIED = 'applied';
    public const CANCELLED = 'cancelled';
    public const SUPERSEDED = 'superseded';
    public const FAILED = 'failed';

    public const ALL = [
        self::REQUESTED,
        self::SENT,
        self::CONFIRMED,
        self::APPLIED,
        self::CANCELLED,
        self::SUPERSEDED,
        self::FAILED,
    ];

    /** Non-terminal — a subscription may have at most one row in one of these states. */
    public const PENDING = [self::REQUESTED, self::SENT, self::CONFIRMED];

    public const TERMINAL = [self::APPLIED, self::CANCELLED, self::SUPERSEDED, self::FAILED];

    public static function isPending(string $state): bool
    {
        return in_array($state, self::PENDING, true);
    }

    public static function isTerminal(string $state): bool
    {
        return in_array($state, self::TERMINAL, true);
    }
}
