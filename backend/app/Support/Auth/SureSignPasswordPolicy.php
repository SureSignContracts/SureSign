<?php

namespace App\Support\Auth;

use App\Rules\PasswordByteSafe;
use App\Rules\PasswordHasSpecialCharacter;
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
 * Policy history — read before assuming either direction is "the bug":
 *
 * 1. Unified Password Security Hardening (original phase) intentionally
 *    moved SureSign to a length + uncompromised-password policy, with no
 *    mandatory character-category composition — a deliberate product
 *    decision at the time, following the modern guidance that length and
 *    breach-checking matter more than forced composition. That was a
 *    real, considered choice, not an oversight.
 * 2. Password Composition Restoration (August 24, 2026) — SureSign's
 *    product policy was then intentionally CHANGED to additionally
 *    require one uppercase letter, one lowercase letter, one number, and
 *    one special character, on top of the existing length/max-length/
 *    byte-safety/uncompromised protections, all of which are unchanged.
 *    This is a conscious reversal of decision #1, made for SureSign's own
 *    product-security reasons — not a correction of a defect in decision
 *    #1's implementation, and not a claim that #1 was ever a bug.
 *
 * `Password::defaults()` (configured once, in `AppServiceProvider::boot()`
 * via `configureDefaults()` below) is Laravel 13's own reusable-policy
 * mechanism — `min(12)->mixedCase()->numbers()->uncompromised()`. This
 * class layers on top of it, rather than duplicating it, the things
 * `Password::defaults()` cannot itself express in the way SureSign's
 * product policy needs: a maximum CHARACTER length (a plain `max:` string
 * rule), a maximum BYTE length safe for this app's bcrypt hashing driver
 * (`PasswordByteSafe` — see that class's own docblock for why these are
 * two separate constraints, not one), and a special-character requirement
 * that excludes plain whitespace (`PasswordHasSpecialCharacter` —
 * deliberately not Laravel's own `->symbols()`, whose Unicode "Separator"
 * branch would let a bare space satisfy it; see that rule's own docblock).
 */
class SureSignPasswordPolicy
{
    /** Below Password::defaults()'s own min(12) — kept here only as the single source both this class and its tests reference. */
    public const MIN_LENGTH = 12;

    /** Character-count ceiling — a plain `max:` string rule, independent of PasswordByteSafe's BYTE ceiling. */
    public const MAX_LENGTH = 64;

    /** Registers this app's `Password::defaults()` — call once, from `AppServiceProvider::boot()`. */
    public static function configureDefaults(): void
    {
        Password::defaults(fn () => Password::min(self::MIN_LENGTH)->mixedCase()->numbers()->uncompromised());
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
            new PasswordHasSpecialCharacter(),
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
     * character-category scheme — this secret is never seen or typed by a
     * human and is immediately overwritten the moment the invited user
     * accepts (`InvitationService::accept()`), so it has no reason to
     * satisfy the human-facing composition policy either before or after
     * the August 24, 2026 restoration — pure independent per-character CSPRNG
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
