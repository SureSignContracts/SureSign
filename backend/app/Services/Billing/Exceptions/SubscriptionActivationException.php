<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when activation-specific requirements aren't met — a missing
 * provider subscription identity, missing/incoherent period dates, or a
 * mismatched provider subscription ID on a subscription that already has
 * one recorded. Never thrown for a plain invalid-status transition (that's
 * InvalidSubscriptionTransitionException) or a livemode/provider mismatch
 * (that's SubscriptionLifecycleConflictException).
 */
class SubscriptionActivationException extends RuntimeException
{
}
