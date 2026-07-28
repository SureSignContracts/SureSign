<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a `BillingCheckoutSession` transition is unsafe to apply —
 * currently, only the mutually-exclusive-terminal-state guard in
 * CheckoutSessionLifecycleService (a `completed` session can never become
 * `expired` and vice versa). Distinct from CheckoutValidationException
 * (CheckoutSessionService's own creation-time validation) — this is a
 * post-creation, webhook-driven lifecycle conflict.
 */
class CheckoutSessionLifecycleConflictException extends RuntimeException
{
}
