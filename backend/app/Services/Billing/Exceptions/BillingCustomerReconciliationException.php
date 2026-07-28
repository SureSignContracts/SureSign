<?php

namespace App\Services\Billing\Exceptions;

use RuntimeException;

/**
 * Thrown when a local BillingCustomer mapping cannot be safely resolved
 * against the provider — a missing/deleted provider customer with related
 * financial history, a livemode mismatch, or conflicting provider metadata.
 * Deliberately never auto-repaired: the caller (Super Admin, via a future
 * reconciliation UI) must take a deliberate action.
 */
class BillingCustomerReconciliationException extends RuntimeException
{
}
