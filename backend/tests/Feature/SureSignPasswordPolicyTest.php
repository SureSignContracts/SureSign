<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Rules\DiffersFromCurrentPassword;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Unified Password Security Hardening — the authoritative policy itself
 * (SureSignPasswordPolicy::rules()/Password::defaults()), independent of
 * any specific controller. Controller-specific behaviour (current-password
 * check, token revocation, notifications) is covered by
 * TokenRevocationTest/PasswordSecurityNotificationTest/existing auth
 * tests — this file is about the RULES, not any one endpoint.
 */
class SureSignPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function validate(string $password, array $extraRules = []): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(
            ['password' => $password],
            ['password' => array_merge(SureSignPasswordPolicy::rules(), $extraRules)],
        );
    }

    public function test_11_characters_rejected(): void
    {
        $this->assertTrue($this->validate(str_repeat('a', 11))->fails());
    }

    public function test_12_character_compliant_passphrase_accepted(): void
    {
        // 12 chars exactly, with uppercase/lowercase/number/special — the
        // minimum length boundary AND full composition satisfied together.
        $this->assertFalse($this->validate('Correct1Ho1!')->fails());
    }

    public function test_64_character_compliant_password_accepted(): void
    {
        // A 4-character compliant unit (upper/lower/number/special)
        // repeated to exactly the 64-character ceiling.
        $this->assertFalse($this->validate(str_repeat('Aa1!', 16))->fails());
    }

    public function test_password_exceeding_64_characters_rejected(): void
    {
        $this->assertTrue($this->validate(str_repeat('a', 65))->fails());
    }

    public function test_utf8_value_exceeding_bcrypt_72_byte_boundary_rejected(): void
    {
        // 'é' is 2 bytes in UTF-8 — 40 of them is 80 bytes (over the 72-byte
        // boundary) while only 40 characters (comfortably under the
        // 64-character ceiling), so this specifically exercises the BYTE
        // rule, not the character-count rule.
        $value = str_repeat('é', 40);
        $this->assertSame(40, mb_strlen($value));
        $this->assertGreaterThan(72, strlen($value));

        $this->assertTrue($this->validate($value)->fails());
    }

    public function test_valid_unicode_password_within_byte_boundary_accepted(): void
    {
        // 4 compliant ASCII chars (upper/lower/number/special) + 16 'é'
        // characters — 20 chars total, 36 bytes, well within both the
        // 64-character and 72-byte boundaries, and fully composition-compliant.
        $value = 'Aa1!' . str_repeat('é', 16);
        $this->assertLessThanOrEqual(72, strlen($value));

        $this->assertFalse($this->validate($value)->fails());
    }

    /**
     * The real point of PasswordByteSafe: two passwords sharing an
     * IDENTICAL first-72-byte prefix, differing only after it, must both
     * be rejected outright — never silently accepted as though bcrypt
     * "protects" the full value while actually only hashing the shared
     * prefix (which would let either variant authenticate as the other).
     * Never stores real credentials — throwaway multi-byte strings only.
     */
    public function test_two_passwords_sharing_a_72_byte_prefix_but_differing_after_it_are_both_rejected(): void
    {
        // 4 compliant ASCII chars (upper/lower/number/special) + 34 × 'é'
        // (2 bytes each) = exactly 72 bytes, 38 characters — valid on its
        // own (at the byte boundary, under the 64-char ceiling, fully
        // composition-compliant).
        $sharedPrefix = 'Aa1!' . str_repeat('é', 34);
        $this->assertSame(72, strlen($sharedPrefix));
        $this->assertFalse($this->validate($sharedPrefix)->fails());

        // Two DIFFERENT continuations of that identical 72-byte prefix —
        // if bcrypt's boundary were silently ignored, both could hash
        // identically to the 72-byte prefix and authenticate as each
        // other. Both must be rejected instead.
        $this->assertTrue($this->validate($sharedPrefix . 'a')->fails());
        $this->assertTrue($this->validate($sharedPrefix . 'b')->fails());
    }

    public function test_spaces_accepted_within_an_otherwise_compliant_password(): void
    {
        // Spaces remain a permitted character within a password that is
        // otherwise fully composition-compliant — distinct from a space
        // being the ONLY non-alphanumeric character (see
        // test_whitespace_alone_does_not_satisfy_the_special_character_requirement
        // below), which must not count as satisfying "special character".
        $this->assertFalse($this->validate('This passphrase Has1!')->fails());
    }

    /**
     * Password Composition Restoration (August 24, 2026) — SureSign's
     * product policy was intentionally changed on this date to require
     * one uppercase letter, one lowercase letter, one number, and one
     * special character, on top of the pre-existing length/uncompromised
     * policy. This is a deliberate reversal of the earlier "composition
     * not mandatory" decision (see SureSignPasswordPolicy's own docblock)
     * — not a correction of a defect in that earlier policy.
     *
     * Each test below otherwise satisfies every OTHER requirement (the
     * minimum length, and every other composition class) except the one
     * under test, so a passing assertion actually proves that specific rule —
     * not merely "this password happens to fail somehow".
     */
    public function test_missing_uppercase_is_rejected(): void
    {
        // 17 chars, has lowercase/number/special — missing uppercase only.
        $this->assertTrue($this->validate('alllowercase1234!')->fails());
    }

    public function test_missing_lowercase_is_rejected(): void
    {
        // 17 chars, has uppercase/number/special — missing lowercase only.
        $this->assertTrue($this->validate('ALLUPPERCASE1234!')->fails());
    }

    public function test_missing_number_is_rejected(): void
    {
        // 17 chars, has uppercase/lowercase/special — missing a number only.
        $this->assertTrue($this->validate('NoNumbersHerePls!')->fails());
    }

    public function test_missing_special_character_is_rejected(): void
    {
        // 18 chars, has uppercase/lowercase/number — missing a special character only.
        $this->assertTrue($this->validate('NoSymbolsHere12345')->fails());
    }

    public function test_fully_compliant_password_is_accepted(): void
    {
        // 16 chars, has uppercase/lowercase/number/special — every rule satisfied.
        $this->assertFalse($this->validate('Correct123Horse!')->fails());
    }

    /**
     * Laravel's own Password::symbols() matches `\p{Z}|\p{S}|\p{P}` —
     * `\p{Z}` is Unicode "Separator", which includes plain whitespace, so
     * a password containing nothing but a space would satisfy it.
     * SureSign's product definition of "special character" (enforced via
     * the dedicated PasswordHasSpecialCharacter rule, not ->symbols())
     * deliberately excludes whitespace — this proves that exclusion
     * actually holds, not merely that *a* symbol rule exists.
     */
    public function test_whitespace_alone_does_not_satisfy_the_special_character_requirement(): void
    {
        // 20 chars, has uppercase/lowercase/number — the only non-alphanumeric
        // character is a trailing space, which must not count as "special".
        $this->assertTrue($this->validate('Uppercaselower12345 ')->fails());
    }

    public function test_compromised_password_rejected(): void
    {
        // TestCase's global fake UncompromisedVerifier treats any value
        // containing this literal marker as breached — see
        // fakeUncompromisedPasswordVerifier()'s own docblock.
        $this->assertTrue($this->validate('longenoughpassphraseCOMPROMISED-TEST-MARKER')->fails());
    }

    /**
     * Exercises the REAL Illuminate\Validation\NotPwnedVerifier directly
     * (not TestCase's fake) with Http::fake() simulating a connection
     * failure — proves Laravel's own verified fail-open behaviour: a
     * provider outage never blocks a legitimate password change. No real
     * network call is made.
     */
    public function test_hibp_provider_failure_follows_verified_fail_open_behavior(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('simulated HIBP outage');
        });

        $verifier = new \Illuminate\Validation\NotPwnedVerifier(app(\Illuminate\Http\Client\Factory::class));
        $result = $verifier->verify(['value' => 'anyLongEnoughPassphraseHere', 'threshold' => 0]);

        $this->assertTrue($result, 'A provider outage must resolve to "not compromised" (fail open), never block the change.');
    }

    public function test_confirmation_mismatch_rejected(): void
    {
        $validator = Validator::make(
            ['password' => 'correcthorsebattery', 'password_confirmation' => 'somethingelseentirely'],
            ['password' => array_merge(['confirmed'], SureSignPasswordPolicy::rules())],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_differs_from_current_password_rule_rejects_identical_value(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id, 'password' => Hash::make('theExistingPassphrase')]);

        $validator = Validator::make(
            ['password' => 'theExistingPassphrase'],
            ['password' => [new DiffersFromCurrentPassword($user)]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_differs_from_current_password_rule_accepts_a_genuinely_new_value(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id, 'password' => Hash::make('theExistingPassphrase')]);

        $validator = Validator::make(
            ['password' => 'aCompletelyDifferentPassphrase'],
            ['password' => [new DiffersFromCurrentPassword($user)]],
        );

        $this->assertFalse($validator->fails());
    }
}
