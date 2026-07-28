<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown whenever PlanPriceMappingService refuses to guess or silently
 * repair a commercially significant mismatch — an invalid/archived plan, an
 * unsupported currency/interval, a mapping belonging to a different plan, a
 * livemode mismatch, or a provider response missing a required identifier.
 */
class PlanPriceMappingException extends RuntimeException
{
}
