<?php

namespace App\Support\Billing;

/**
 * billing_payments.status allow-list.
 */
class PaymentStatus
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';
    public const REFUNDED = 'refunded';
    public const PARTIALLY_REFUNDED = 'partially_refunded';
    public const DISPUTED = 'disputed';

    public const ALL = [
        self::PENDING,
        self::PROCESSING,
        self::SUCCEEDED,
        self::FAILED,
        self::CANCELLED,
        self::REFUNDED,
        self::PARTIALLY_REFUNDED,
        self::DISPUTED,
    ];
}
