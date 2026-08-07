<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the named rate limiters added in
 * App\Providers\AppServiceProvider::configureRateLimiters() and the routes
 * they're applied to in routes/api.php.
 *
 * CACHE_STORE=array in testing (phpunit.xml) is a single process-wide store,
 * so limiter state leaks across tests unless cleared — every test flushes it
 * in setUp() rather than relying on unique keys, since several tests
 * deliberately reuse the same email/IP to test bucket-sharing behaviour.
 */
class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeUser(string $email = 'user@example.com', string $password = 'password'): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);

        return User::factory()->create([
            'organization_id' => $org->id,
            'email'           => $email,
            'password'        => Hash::make($password),
        ]);
    }

    // ── Login ────────────────────────────────────────────────────────────

    public function test_valid_login_still_succeeds(): void
    {
        $this->makeUser('valid@example.com', 'correct-password');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'valid@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['token', 'user']);
    }

    public function test_invalid_login_attempts_are_allowed_up_to_the_threshold_then_429(): void
    {
        $this->makeUser('victim@example.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'victim@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'victim@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)
            ->assertJson(['message' => 'Too many attempts. Please try again later.'])
            ->assertHeader('Retry-After');
    }

    public function test_login_limiter_key_uses_normalised_email(): void
    {
        $this->makeUser('MixedCase@Example.com', 'correct-password');

        // 5 failed attempts using varying casing of the same email — all must
        // land in the same bucket if the key is correctly lowercased/trimmed.
        $variants = [
            'MixedCase@Example.com',
            'mixedcase@example.com',
            ' MixedCase@Example.com ',
            'MIXEDCASE@EXAMPLE.COM',
            'mixedCase@example.com',
        ];

        foreach ($variants as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
                ->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => 'mixedcase@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(429);
    }

    public function test_separate_email_ip_combinations_do_not_share_the_same_tight_bucket(): void
    {
        $this->makeUser('a@example.com', 'password-a');
        $this->makeUser('b@example.com', 'password-b');

        // Exhaust the bucket for a@example.com from one simulated IP.
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
                ->assertStatus(401);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        // A different email from the same IP must not be blocked by a@'s bucket.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/auth/login', ['email' => 'b@example.com', 'password' => 'password-b'])
            ->assertStatus(200);

        // The same email from a different IP must not be blocked either.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password-a'])
            ->assertStatus(200);
    }

    public function test_login_response_does_not_reveal_whether_the_email_exists(): void
    {
        $this->makeUser('exists@example.com', 'correct-password');

        $forExistingEmail = $this->postJson('/api/auth/login', [
            'email' => 'exists@example.com',
            'password' => 'wrong-password',
        ]);

        $forMissingEmail = $this->postJson('/api/auth/login', [
            'email' => 'does-not-exist@example.com',
            'password' => 'wrong-password',
        ]);

        $forExistingEmail->assertStatus(401)->assertJson(['message' => 'The email or password is incorrect.']);
        $forMissingEmail->assertStatus(401)->assertJson(['message' => 'The email or password is incorrect.']);
    }

    public function test_login_succeeds_again_after_limiter_window_expires(): void
    {
        $this->makeUser('timetravel@example.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'timetravel@example.com', 'password' => 'wrong'])
                ->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['email' => 'timetravel@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postJson('/api/auth/login', [
            'email' => 'timetravel@example.com',
            'password' => 'correct-password',
        ])->assertStatus(200);
    }

    // ── Forgot password ─────────────────────────────────────────────────

    public function test_forgot_password_requests_are_accepted_up_to_the_threshold(): void
    {
        $this->makeUser('forgot@example.com');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'forgot@example.com'])
                ->assertStatus(200);
        }
    }

    public function test_forgot_password_excess_request_returns_429(): void
    {
        $this->makeUser('forgot2@example.com');

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'forgot2@example.com'])
                ->assertStatus(200);
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'forgot2@example.com'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many attempts. Please try again later.']);
    }

    public function test_forgot_password_existing_and_non_existing_email_responses_are_indistinguishable(): void
    {
        $this->makeUser('realuser@example.com');

        $existing = $this->postJson('/api/auth/forgot-password', ['email' => 'realuser@example.com']);
        $missing = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        $existing->assertStatus(200);
        $missing->assertStatus(200);
        $this->assertSame($existing->json('message'), $missing->json('message'));
    }

    public function test_forgot_password_limiter_is_separate_from_login_limiter(): void
    {
        $this->makeUser('separate@example.com', 'correct-password');

        // Exhaust the login bucket for this email+IP.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'separate@example.com', 'password' => 'wrong'])
                ->assertStatus(401);
        }
        $this->postJson('/api/auth/login', ['email' => 'separate@example.com', 'password' => 'wrong'])
            ->assertStatus(429);

        // forgot-password for the same email+IP must be unaffected.
        $this->postJson('/api/auth/forgot-password', ['email' => 'separate@example.com'])
            ->assertStatus(200);
    }

    // ── Reset password ──────────────────────────────────────────────────

    public function test_repeated_invalid_reset_attempts_are_throttled(): void
    {
        $user = $this->makeUser('reset@example.com');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/reset-password', [
                'token' => 'not-a-real-token',
                'email' => 'reset@example.com',
                'password' => 'NewPassw0rd!',
                'password_confirmation' => 'NewPassw0rd!',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'reset@example.com',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(429);
    }

    public function test_legitimate_reset_remains_functional_below_the_threshold(): void
    {
        $user = $this->makeUser('legitreset@example.com');
        $token = PasswordBroker::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'legitreset@example.com',
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('NewPassw0rd!', $user->fresh()->password));
    }

    // ── Email verification resend ──────────────────────────────────────

    public function test_email_verification_resend_is_throttled(): void
    {
        $user = User::factory()->unverified()->create([
            'organization_id' => Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()])->id,
        ]);

        Sanctum::actingAs($user);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/email/verification-notification')->assertStatus(200);
        }

        $this->postJson('/api/auth/email/verification-notification')
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many attempts. Please try again later.']);
    }

    public function test_different_authenticated_users_do_not_share_the_same_resend_bucket(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-'.uniqid()]);
        $userA = User::factory()->unverified()->create(['organization_id' => $org->id]);
        $userB = User::factory()->unverified()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($userA);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/email/verification-notification')->assertStatus(200);
        }
        $this->postJson('/api/auth/email/verification-notification')->assertStatus(429);

        Sanctum::actingAs($userB);
        $this->postJson('/api/auth/email/verification-notification')->assertStatus(200);
    }

    // ── General API limiter ────────────────────────────────────────────

    public function test_authenticated_user_can_make_normal_requests_under_the_general_limit(): void
    {
        $user = $this->makeUser('normal-usage@example.com');
        Sanctum::actingAs($user);

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/auth/me')->assertStatus(200);
        }
    }

    public function test_general_api_limiter_returns_429_once_exceeded(): void
    {
        $user = $this->makeUser('heavy-usage@example.com');
        Sanctum::actingAs($user);

        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/auth/me')->assertStatus(200);
        }

        $this->getJson('/api/auth/me')->assertStatus(429);
    }

    public function test_different_authenticated_users_have_separate_general_limiter_buckets(): void
    {
        $userA = $this->makeUser('bucket-a@example.com');
        $userB = $this->makeUser('bucket-b@example.com');

        Sanctum::actingAs($userA);
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/auth/me')->assertStatus(200);
        }
        $this->getJson('/api/auth/me')->assertStatus(429);

        Sanctum::actingAs($userB);
        $this->getJson('/api/auth/me')->assertStatus(200);
    }

    // ── Proxy / client-IP resolution ────────────────────────────────────

    /**
     * A direct connection from a public (untrusted) address must NOT let the
     * client control its own rate-limit key via X-Forwarded-For — otherwise
     * an attacker bypasses the login limiter simply by sending a different
     * spoofed header on every request. bootstrap/app.php only trusts the
     * private Docker bridge ranges as proxies, so a request whose REMOTE_ADDR
     * is a public IP must resolve to that REMOTE_ADDR regardless of any
     * X-Forwarded-For header it carries.
     */
    public function test_spoofed_forwarded_for_header_from_an_untrusted_ip_cannot_bypass_the_login_limiter(): void
    {
        $this->makeUser('spoof-target@example.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.50',
                'HTTP_X_FORWARDED_FOR' => "9.9.9.{$i}",
            ])->postJson('/api/auth/login', [
                'email' => 'spoof-target@example.com',
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.50',
            'HTTP_X_FORWARDED_FOR' => '9.9.9.99',
        ])->postJson('/api/auth/login', [
            'email' => 'spoof-target@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    /**
     * The inverse case: a connection arriving from OUR trusted reverse proxy
     * (the private Docker bridge range nginx runs on) must have its
     * X-Forwarded-For header honoured, so that two different real clients
     * both going through nginx get separate buckets instead of colliding on
     * the single nginx container IP.
     */
    public function test_forwarded_for_header_from_a_trusted_proxy_ip_is_honoured(): void
    {
        $this->makeUser('behind-proxy@example.com', 'correct-password');

        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables([
                'REMOTE_ADDR' => '172.18.0.5', // simulated nginx container IP
                'HTTP_X_FORWARDED_FOR' => '198.51.100.1', // real client A
            ])->postJson('/api/auth/login', [
                'email' => 'behind-proxy@example.com',
                'password' => 'wrong',
            ])->assertStatus(401);
        }
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.1',
        ])->postJson('/api/auth/login', [
            'email' => 'behind-proxy@example.com',
            'password' => 'wrong',
        ])->assertStatus(429);

        // Real client B, through the same proxy, must not be blocked by A's bucket.
        $this->withServerVariables([
            'REMOTE_ADDR' => '172.18.0.5',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.2',
        ])->postJson('/api/auth/login', [
            'email' => 'behind-proxy@example.com',
            'password' => 'correct-password',
        ])->assertStatus(200);
    }
}
