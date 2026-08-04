<?php

namespace App\Services\Consultancy\Exceptions;

/**
 * A paid Consultancy payment whose reservation is in a state automatic
 * conversion cannot safely resolve (independently cancelled with the time
 * no longer free, a genuine mismatch, or any other inconsistency automatic
 * logic must not guess through). The payment moves to 'manual_review' —
 * never discarded, never silently converted. See
 * App\Services\Consultancy\ConsultancyPaymentConversionService.
 */
class ConsultancyManualReviewRequiredException extends \RuntimeException
{
}
