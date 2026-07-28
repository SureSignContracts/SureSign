<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when the webhook signing secret matching the application's
 * currently-configured mode (test or live) is empty/unconfigured — a
 * deployment misconfiguration, never a reason to try the other mode's
 * secret instead. The exception message never includes the secret value
 * itself (there is none to include) nor which secret env var is set.
 */
class WebhookSecretNotConfiguredException extends RuntimeException
{
}
