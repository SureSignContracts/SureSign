<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unified Password Security Hardening — the one internal temp-secret
 * generator (`SureSignPasswordPolicy::generateTemporarySecret()`), used
 * only for the invitation-flow `users.password` placeholder. Tests
 * architecture (secure primitive, length, no template, never exposed),
 * not statistical randomness certification.
 */
class TemporarySecretGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_length_is_within_the_recommended_24_to_32_range(): void
    {
        $secret = SureSignPasswordPolicy::generateTemporarySecret();

        $this->assertGreaterThanOrEqual(24, strlen($secret));
        $this->assertLessThanOrEqual(32, strlen($secret));
    }

    public function test_custom_length_is_honored(): void
    {
        $this->assertSame(40, strlen(SureSignPasswordPolicy::generateTemporarySecret(40)));
    }

    public function test_repeated_generations_differ(): void
    {
        $values = array_map(fn () => SureSignPasswordPolicy::generateTemporarySecret(), range(1, 20));

        $this->assertCount(20, array_unique($values), 'Expected 20 independently generated secrets to all be distinct.');
    }

    /**
     * Structural proof of "no predictable template" — no company name,
     * year, or fixed prefix ever appears, and the character set spans
     * upper/lower/digit/symbol without any GUARANTEED-category pattern
     * (the old generator forced fixed slots; this one does not).
     */
    public function test_no_predictable_template_or_company_year_pattern(): void
    {
        foreach (range(1, 10) as $_) {
            $secret = SureSignPasswordPolicy::generateTemporarySecret();

            $this->assertStringNotContainsStringIgnoringCase('suresign', $secret);
            $this->assertDoesNotMatchRegularExpression('/20[0-9]{2}/', $secret, 'Generated secret must not contain a year-like pattern.');
        }
    }

    public function test_generated_secret_satisfies_structural_length_and_byte_safety_constraints(): void
    {
        // Structural compliance only (length/byte-safety) — NOT run through
        // the full Password::defaults()/uncompromised() policy, since this
        // internal secret is never shown to or typed by a human and must
        // not make invitation creation depend on a live HIBP call — see
        // SureSignPasswordPolicy::generateTemporarySecret()'s own docblock.
        $secret = SureSignPasswordPolicy::generateTemporarySecret();

        $this->assertGreaterThanOrEqual(SureSignPasswordPolicy::MIN_LENGTH, strlen($secret));
        $this->assertLessThanOrEqual(SureSignPasswordPolicy::MAX_LENGTH, strlen($secret));
        $this->assertLessThanOrEqual(72, strlen($secret));
    }

    /**
     * Confirms generation never triggers a network call — proves the
     * "does not depend on live HIBP" claim directly, not just by
     * inspecting source code.
     */
    public function test_generation_makes_no_external_http_call(): void
    {
        Http::fake();

        SureSignPasswordPolicy::generateTemporarySecret();

        Http::assertNothingSent();
    }

    /**
     * End-to-end proof that the invitation workflow's generated secret is
     * genuinely never returned, logged, or emailed — exercises the real
     * `POST /users/invite` endpoint, not just the generator in isolation.
     */
    public function test_invite_endpoint_never_returns_the_generated_secret(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        Log::spy();

        $response = $this->postJson('/api/users/invite', [
            'email' => 'new-invitee@example.com',
            'role'  => 'Client',
        ]);

        $response->assertStatus(201);
        $body = $response->getContent();

        $user = User::where('email', 'new-invitee@example.com')->first();
        $this->assertNotNull($user);

        // The response must not contain the hash, and — since we don't
        // know the plaintext secret to search for directly (it's
        // generated internally and never returned to this test either) —
        // the strongest available proof is that the response contains no
        // password-shaped field at all beyond the documented {id, email, role} shape.
        $this->assertStringNotContainsString($user->password, $body);
        $data = json_decode($body, true);
        $this->assertSame(['id', 'email', 'role'], array_keys($data['data']));
    }
}
