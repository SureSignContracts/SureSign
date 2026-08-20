<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * Unified Password Security Hardening — a manually chosen replacement
 * password must not equal the account's current one. Compares via
 * `Hash::check()` against the user's stored hash — never a raw string
 * comparison against the hash itself, and never against a second
 * plaintext value (there is nothing to compare a bcrypt hash to except
 * through the hasher's own verify function).
 *
 * Reused across every authenticated/administrative flow that changes an
 * EXISTING password with a known prior value: self-service Change
 * Password, the admin-forced `must_change_password` flow, and an admin
 * explicitly setting another user's password. Deliberately NOT applied to
 * invitation acceptance or the internal temporary-secret generator —
 * those set a password for the first time, with no real "current"
 * password to differ from (`users.password` before acceptance is an
 * internal compatibility placeholder never known to, or usable by, the
 * account holder).
 */
class DiffersFromCurrentPassword implements ValidationRule
{
    public function __construct(private readonly User $user)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            return;
        }

        if (Hash::check($value, $this->user->password)) {
            $fail('The new password must be different from your current password.');
        }
    }
}
