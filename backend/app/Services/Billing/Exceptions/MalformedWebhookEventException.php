<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a signature-verified event's payload is missing a field
 * required to build a VerifiedWebhookEvent (id, type, created). Distinct
 * from InvalidWebhookSignatureException — the signature was valid, but
 * Stripe's own event shape didn't contain what was expected.
 */
class MalformedWebhookEventException extends RuntimeException
{
}
