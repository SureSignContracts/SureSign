<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown by `SubscriptionPlanChangeService` for a sub-path this checkpoint
 * deliberately defers rather than guesses at — currently only "provider
 * plan changes for a `trialing` subscription" (Part 9 Q8: "the current
 * repository does not have an explicit approved rule for this; deferred").
 * Distinct from `SubscriptionLifecycleConflictException` so a caller can
 * tell "genuinely not allowed" apart from "not built yet."
 */
class PlanChangeNotSupportedException extends RuntimeException
{
}
