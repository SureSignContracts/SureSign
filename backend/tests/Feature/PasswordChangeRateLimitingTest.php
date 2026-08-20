<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the new `password-change` named limiter (5/15min per user, 20/15min
 * per IP) applied to PUT /auth/password — the confirmed Medium finding that
 * this endpoint previously had no dedicated rate limit beyond the general
 * 120/min API throttle, letting a stolen-but-not-fully-compromised token be
 * used to brute-force current_password with little friction.
 */
class PasswordChangeRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeUser(string $email, string $password = 'CorrectPassw0rd!'): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    private function loginAndGetToken(string $email, string $password): string
    {
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertStatus(200);

        return $response->json('token');
    }

    private function requestAs(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_password_change_attempts_are_allowed_up_to_the_threshold(): void
    {
        $this->makeUser('limiter1@example.com');
        $token = $this->loginAndGetToken('limiter1@example.com', 'CorrectPassw0rd!');

        for ($i = 0; $i < 5; $i++) {
            $this->requestAs($token)->putJson('/api/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassw0rd12345!',
                'password_confirmation' => 'NewPassw0rd12345!',
            ])->assertStatus(422); // current_password validation failure, not a 429
        }
    }

    public function test_the_next_attempt_returns_429(): void
    {
        $this->makeUser('limiter2@example.com');
        $token = $this->loginAndGetToken('limiter2@example.com', 'CorrectPassw0rd!');

        for ($i = 0; $i < 5; $i++) {
            $this->requestAs($token)->putJson('/api/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassw0rd12345!',
                'password_confirmation' => 'NewPassw0rd12345!',
            ])->assertStatus(422);
        }

        $this->requestAs($token)->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPassw0rd12345!',
            'password_confirmation' => 'NewPassw0rd12345!',
        ])
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many attempts. Please try again later.'])
            ->assertHeader('Retry-After');
    }

    public function test_different_users_have_separate_buckets(): void
    {
        $this->makeUser('limiter3@example.com');
        $this->makeUser('limiter4@example.com');
        $tokenA = $this->loginAndGetToken('limiter3@example.com', 'CorrectPassw0rd!');
        $tokenB = $this->loginAndGetToken('limiter4@example.com', 'CorrectPassw0rd!');

        for ($i = 0; $i < 5; $i++) {
            $this->requestAs($tokenA)->putJson('/api/auth/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassw0rd12345!',
                'password_confirmation' => 'NewPassw0rd12345!',
            ])->assertStatus(422);
        }
        $this->requestAs($tokenA)->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPassw0rd12345!',
            'password_confirmation' => 'NewPassw0rd12345!',
        ])->assertStatus(429);

        // User B's bucket is untouched by user A's exhausted one.
        $this->requestAs($tokenB)->putJson('/api/auth/password', [
            'current_password' => 'CorrectPassw0rd!',
            'password' => 'NewPassw0rd12345!',
            'password_confirmation' => 'NewPassw0rd12345!',
        ])->assertStatus(200);
    }

    public function test_successful_change_remains_functional_below_the_threshold(): void
    {
        $this->makeUser('limiter5@example.com');
        $token = $this->loginAndGetToken('limiter5@example.com', 'CorrectPassw0rd!');

        // A couple of failed attempts, still under the threshold...
        $this->requestAs($token)->putJson('/api/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewPassw0rd12345!',
            'password_confirmation' => 'NewPassw0rd12345!',
        ])->assertStatus(422);

        // ...then a legitimate, correct change still succeeds.
        $this->requestAs($token)->putJson('/api/auth/password', [
            'current_password' => 'CorrectPassw0rd!',
            'password' => 'NewPassw0rd12345!',
            'password_confirmation' => 'NewPassw0rd12345!',
        ])->assertStatus(200);
    }
}
