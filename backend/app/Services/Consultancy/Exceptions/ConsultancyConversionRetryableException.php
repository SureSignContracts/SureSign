<?php

namespace App\Services\Consultancy\Exceptions;

/**
 * A local Appointment-conversion failure after Stripe has ALREADY taken
 * payment — an expected distributed-systems recovery case, never a
 * "payment failed" outcome. The payment must move to 'conversion_pending'
 * (never 'failed') and remain retryable. See
 * App\Services\Consultancy\ConsultancyPaymentConversionService.
 */
class ConsultancyConversionRetryableException extends \RuntimeException
{
}
