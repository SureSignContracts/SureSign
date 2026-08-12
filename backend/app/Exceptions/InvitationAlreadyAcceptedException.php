<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by InvitationService::accept() when the invited User has already
 * completed first-time account setup (email_verified_at already set by a
 * prior successful acceptance). Never carries the user's id/email in its
 * own message — InvitationController maps this to a fixed, customer-safe
 * "Invitation already accepted" response.
 */
class InvitationAlreadyAcceptedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This invitation has already been accepted.');
    }
}
