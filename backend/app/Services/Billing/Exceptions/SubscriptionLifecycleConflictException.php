<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown for a lifecycle conflict that is NOT simply "wrong status" —
 * a stale/out-of-order provider event, a provider or livemode identity
 * mismatch, a conflicting plan mapping, or a subscription whose state
 * requires deliberate reconciliation rather than an automatic transition.
 * Distinct from InvalidSubscriptionTransitionException, which is purely
 * about the status/status-map check.
 */
class SubscriptionLifecycleConflictException extends RuntimeException
{
}
