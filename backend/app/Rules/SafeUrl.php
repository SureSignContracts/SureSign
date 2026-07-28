<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Accepts only a same-site relative path ("/...") or an absolute https:// URL.
 * Rejects javascript:, data:, and any other scheme — pricing CTA/link fields
 * are rendered directly as marketing-site link targets.
 *
 * Deliberately always requires https:// for an absolute URL, in every
 * environment — a local-dev-only relaxation belongs to the ONE caller that
 * actually needs it (CheckoutSessionService's own success/cancel URLs,
 * config-derived and never user input), not this shared rule, which
 * Pricing Management's CTA/link fields also depend on for a strict
 * production-equivalent guarantee even under local testing.
 */
class SafeUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a valid URL.');
            return;
        }

        if (str_starts_with($value, '/')) {
            return;
        }

        if (preg_match('#^https://[^\s]+$#i', $value)) {
            return;
        }

        $fail('The :attribute must be a relative path or a valid https:// URL.');
    }
}
