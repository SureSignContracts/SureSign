<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a signature-verified event's own `livemode` disagrees with
 * the application's currently-configured billing mode — e.g. a test-mode
 * event delivered to a live-configured deployment. The event is never
 * persisted into the active ledger when this occurs; this is a
 * deployment/dashboard-configuration issue (the wrong Stripe webhook
 * endpoint URL registered against the wrong mode), not something a retry
 * can fix.
 */
class WebhookModeMismatchException extends RuntimeException
{
}
