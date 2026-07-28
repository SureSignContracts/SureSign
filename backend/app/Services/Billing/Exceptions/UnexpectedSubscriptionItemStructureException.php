<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown by BillingProviderInterface::updateSubscriptionPrice() when the
 * provider subscription does not have exactly one recurring item — Part 11's
 * explicit requirement to validate this invariant rather than silently
 * updating whichever item happens to be first. SureSign never does
 * per-seat/multi-item billing (see the confirmed no-seat-billing decision
 * in CLAUDE.md), so a subscription with zero or more than one item is a
 * genuine, unexpected structural problem — never guessed at.
 */
class UnexpectedSubscriptionItemStructureException extends RuntimeException
{
}
