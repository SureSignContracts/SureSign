<?php

namespace App\Support\Billing;

/**
 * billing_checkout_sessions.status allow-list.
 */
class CheckoutSessionStatus
{
    public const CREATED = 'created';
    public const OPEN = 'open';
    public const COMPLETED = 'completed';
    public const EXPIRED = 'expired';
    public const CANCELLED = 'cancelled';

    public const ALL = [
        self::CREATED,
        self::OPEN,
        self::COMPLETED,
        self::EXPIRED,
        self::CANCELLED,
    ];
}
