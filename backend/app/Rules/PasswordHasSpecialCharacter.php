<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Password Composition Restoration (August 24, 2026) — SureSign's product
 * policy was intentionally changed on this date to require one special
 * character in addition to the existing length + uncompromised-password
 * policy (see SureSignPasswordPolicy's own docblock for the full history:
 * this is a deliberate reversal of the earlier "length only" decision, not
 * a bug fix).
 *
 * Deliberately NOT Laravel's own `Password::symbols()` — that rule matches
 * `\p{Z}|\p{S}|\p{P}` (Unicode Separator, Symbol, or Punctuation), and
 * `\p{Z}` includes plain whitespace, so a password containing nothing but a
 * space would satisfy it. SureSign's product definition of "special
 * character" is ordinary punctuation/symbol usage — never whitespace alone.
 * This rule is exactly Laravel's own symbol check with the `\p{Z}`
 * (separator) branch deliberately removed, not a from-scratch character
 * class invented independently of the framework's own building blocks.
 *
 * This is the ONE backend definition of "special character" — the shared
 * frontend checker (`PasswordStrengthChecker.tsx`) mirrors this exact
 * intent (letters and digits and whitespace excluded, everything else
 * counts) so the two never silently disagree on what satisfies the
 * requirement.
 */
class PasswordHasSpecialCharacter implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }

        if (!preg_match('/\p{S}|\p{P}/u', $value)) {
            $fail('The :attribute field must contain at least one special character.');
        }
    }
}
