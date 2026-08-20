<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Unified Password Security Hardening — PHP's bcrypt implementation (this
 * app's confirmed, unchanged hashing driver — `config('hashing.driver')` is
 * `bcrypt`) only incorporates the FIRST 72 BYTES of its input. Two
 * passwords that are byte-identical for their first 72 bytes and differ
 * only after that point hash identically and would silently both
 * "work" — a genuine correctness/security gap for any password containing
 * multi-byte UTF-8 characters, where 64 *characters* can already exceed 72
 * *bytes* well before the character count does.
 *
 * `SureSignPasswordPolicy::MAX_LENGTH` (64 characters, enforced separately
 * via a plain `max:` string rule) and this byte check are deliberately two
 * separate rules — a character-count limit and a byte-count limit are not
 * the same constraint, and conflating them would either wrongly reject a
 * 64-character ASCII password (well under 72 bytes) or wrongly accept a
 * password whose character count is fine but whose byte count silently
 * exceeds what bcrypt actually protects.
 *
 * `strlen()` (not `mb_strlen()`) is deliberately used here — it counts raw
 * bytes regardless of encoding, which is exactly bcrypt's own unit of
 * measurement. Never truncates the input silently; a password exceeding
 * the boundary is rejected outright with a generic message, never hashed
 * and stored as a shorter, different value than what the user believes
 * they set.
 */
class PasswordByteSafe implements ValidationRule
{
    /** PHP's bcrypt (the `password_hash`/`Hash::make` driver this app uses) only reads the first 72 bytes of its input. */
    public const MAX_BYTES = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }

        if (strlen($value) > self::MAX_BYTES) {
            // Deliberately generic — never explains bcrypt's internal byte
            // boundary to the end user (see this class's own docblock: "a
            // validation message may simply say the password is too long").
            $fail('The :attribute is too long.');
        }
    }
}
