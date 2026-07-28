<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested status change is not in
 * App\Support\Billing\SubscriptionTransitions::MAP for the subscription's
 * current status — the one guard every SubscriptionLifecycleService
 * transition method passes through before touching anything.
 */
class InvalidSubscriptionTransitionException extends RuntimeException
{
}
