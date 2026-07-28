<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown for checkout-specific validation failures that are not
 * subscription-lifecycle concerns — an unsellable plan, a missing/inactive
 * provider price mapping, an unsupported currency/interval, or an unsafe
 * redirect URL. A commercially-conflicting-subscription rejection is NOT
 * wrapped in this exception — SubscriptionLifecycleConflictException from
 * SubscriptionLifecycleService::createDraftSubscription() propagates
 * as-is, since that service remains the sole authoritative source of that
 * rule (see CheckoutSessionService's class docblock).
 */
class CheckoutValidationException extends RuntimeException
{
}
