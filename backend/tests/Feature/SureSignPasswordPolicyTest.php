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

    public function test_14_characters_rejected(): void
    {
        $this->assertTrue($this->validate(str_repeat('a', 14))->fails());
    }

    public function test_15_character_clean_passphrase_accepted(): void
    {
        $this->assertFalse($this->validate('correcthorsebattery')->fails());
    }

    public function test_64_ascii_characters_accepted(): void
    {
        $this->assertFalse($this->validate(str_repeat('a1', 32))->fails());
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
        // 20 'é' characters = 40 bytes — well within both the 64-character
        // and 72-byte boundaries.
        $value = str_repeat('é', 20);
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
        // 36 × 'é' (2 bytes each) = exactly 72 bytes, 36 characters —
        // valid on its own (at the boundary, under the 64-char ceiling).
        $sharedPrefix = str_repeat('é', 36);
        $this->assertSame(72, strlen($sharedPrefix));
        $this->assertFalse($this->validate($sharedPrefix)->fails());

        // Two DIFFERENT continuations of that identical 72-byte prefix —
        // if bcrypt's boundary were silently ignored, both could hash
        // identically to the 72-byte prefix and authenticate as each
        // other. Both must be rejected instead.
        $this->assertTrue($this->validate($sharedPrefix . 'a')->fails());
        $this->assertTrue($this->validate($sharedPrefix . 'b')->fails());
    }

    public function test_spaces_accepted(): void
    {
        $this->assertFalse($this->validate('this passphrase has spaces')->fails());
    }

    public function test_uppercase_not_mandatory(): void
    {
        $this->assertFalse($this->validate('alllowercasepassphrase')->fails());
    }

    public function test_lowercase_not_mandatory(): void
    {
        $this->assertFalse($this->validate('ALLUPPERCASEPASSPHRASE')->fails());
    }

    public function test_number_not_mandatory(): void
    {
        $this->assertFalse($this->validate('noNumbersInThisPassphrase')->fails());
    }

    public function test_symbol_not_mandatory(): void
    {
        $this->assertFalse($this->validate('noSymbolsInThisPassphraseEither')->fails());
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
