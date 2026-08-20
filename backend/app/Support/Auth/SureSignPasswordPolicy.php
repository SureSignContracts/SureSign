<?php

namespace App\Support\Auth;

use App\Rules\PasswordByteSafe;
use Illuminate\Validation\Rules\Password;

/**
 * Unified Password Security Hardening — the ONE authoritative password
 * policy for every SureSign workflow that writes a user-chosen password:
 * Settings → Change Password, the admin-forced must-change flow, Reset
 * Password, an admin explicitly setting another user's password, the
 * onboarding profile step's optional password, and invitation acceptance.
 * Before this class, `Password::min(8)->mixedCase()->numbers()->symbols()`
 * was independently duplicated across six controllers — this replaces all
 * six.
 *
 * Modern password-security guidance (and this app's own product decision):
 * length and uncompromised-status matter, arbitrary character-category
 * composition rules do not. A 15+ character passphrase with no uppercase,
 * number, or symbol is fully valid; a short password satisfying every
 * legacy composition rule is not.
 *
 * `Password::defaults()` (configured once, in
 * `AppServiceProvider::boot()` via `configureDefaults()` below) is Laravel
 * 13's own reusable-policy mechanism — `min(15)->uncompromised()`. This
 * class layers on top of it, rather than duplicating it, the two things
 * `Password::defaults()` cannot itself express: a maximum CHARACTER length
 * (a plain `max:` string rule) and a maximum BYTE length safe for this
 * app's bcrypt hashing driver (`PasswordByteSafe` — see that class's own
 * docblock for why these are two separate constraints, not one).
 */
class SureSignPasswordPolicy
{
    /** Below Password::defaults()'s own min(15) — kept here only as the single source both this class and its tests reference. */
    public const MIN_LENGTH = 15;

    /** Character-count ceiling — a plain `max:` string rule, independent of PasswordByteSafe's BYTE ceiling. */
    public const MAX_LENGTH = 64;

    /** Registers this app's `Password::defaults()` — call once, from `AppServiceProvider::boot()`. */
    public static function configureDefaults(): void
    {
        Password::defaults(fn () => Password::min(self::MIN_LENGTH)->uncompromised());
    }

    /**
     * The complete validation rule set for a manually chosen password —
     * every call site merges this into its own `['required'|'nullable',
     * 'confirmed', ...SureSignPasswordPolicy::rules()]` (whether the field
     * is required/nullable and whether confirmation applies varies
     * legitimately by workflow, so those two stay the caller's own
     * decision, not baked in here).
     *
     * @return array<int, mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            'max:' . self::MAX_LENGTH,
            new PasswordByteSafe(),
            Password::defaults(),
        ];
    }

    /**
     * Generates a uniformly random internal secret — used ONLY for the
     * temporary `users.password` placeholder an invited user's row needs
     * before they set their own real password (see
     * `UserController::inviteOneUser()`). This is NOT a user-facing
     * "Generate Password" feature (none exists in this app) and this
     * value is never returned, emailed, logged, or shown to anyone —
     * see that call site's own docblock.
     *
     * Deliberately does NOT run this value through `rules()`/
     * `Password::defaults()` — `uncompromised()` would make invitation
     * creation depend on a live HIBP network call to validate a secret no
     * human ever sees or types, which is pointless (a uniformly random
     * 28-character CSPRNG string cannot meaningfully appear in a leaked-
     * password corpus) and would wrongly couple an internal-only
     * operation to third-party network availability. Structural
     * compliance (length, no predictable template) is verified by test
     * instead.
     *
     * CSPRNG only (`random_int`, PHP's cryptographically secure source) —
     * never `shuffle()`/`mt_rand()`/`Math.random()`. No guaranteed
     * character-category scheme (the old generator forced uppercase/
     * lowercase/digit/symbol slots, a leftover from the composition rules
     * this whole phase removes) — pure independent per-character CSPRNG
     * selection is both simpler and stronger.
     */
    public static function generateTemporarySecret(int $length = 28): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
        $lastIndex = strlen($alphabet) - 1;

        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, $lastIndex)];
        }

        return $secret;
    }
}
