<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the token-revocation policy for every password-changing action:
 * admin-initiated (setPassword/forcePasswordReset), self-service
 * (updatePassword), and forced-password-change completion
 * (forcePasswordChange). Chosen policy (documented per-action below) is
 * "revoke every other session, keep the one performing the change" for the
 * two user-initiated flows, and "revoke everything, no auto-relogin" for
 * admin-initiated resets — see AuthController.php/UserController.php for
 * the in-code rationale.
 */
class TokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeUser(string $email, string $password = 'password'): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'password' => \Illuminate\Support\Facades\Hash::make($password),
            'is_active' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    private function makeAdmin(string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function loginAndGetToken(string $email, string $password = 'password'): string
    {
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertStatus(200);

        return $response->json('token');
    }

    /** See AccountStatusTest for why forgetGuards() is required here. */
    private function requestAs(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ── Admin-initiated password reset (setPassword) ────────────────────

    public function test_admin_set_password_revokes_existing_tokens(): void
    {
        $this->makeAdmin('admin1@example.com');
        $user = $this->makeUser('reset-me@example.com');
        $this->loginAndGetToken('reset-me@example.com');
        $this->assertSame(1, $user->tokens()->count());

        $adminToken = $this->loginAndGetToken('admin1@example.com');
        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd!'])
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_admin_set_password_sets_must_change_password_flag_by_default(): void
    {
        $this->makeAdmin('admin2@example.com');
        $user = $this->makeUser('reset-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin2@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd!'])
            ->assertStatus(200);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_old_password_fails_after_admin_sets_new_password(): void
    {
        $this->makeAdmin('admin3@example.com');
        $user = $this->makeUser('reset-me3@example.com', 'OldPassw0rd!');
        $adminToken = $this->loginAndGetToken('admin3@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd!'])
            ->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => 'reset-me3@example.com', 'password' => 'OldPassw0rd!'])
            ->assertStatus(401);
    }

    public function test_temporary_password_permits_login(): void
    {
        $this->makeAdmin('admin4@example.com');
        $user = $this->makeUser('reset-me4@example.com');
        $adminToken = $this->loginAndGetToken('admin4@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => 'reset-me4@example.com', 'password' => 'TempPassw0rd!'])
            ->assertStatus(200)
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_admin_force_password_reset_revokes_existing_tokens(): void
    {
        $this->makeAdmin('admin5@example.com');
        $user = $this->makeUser('force-reset-me@example.com');
        $this->loginAndGetToken('force-reset-me@example.com');
        $this->assertSame(1, $user->tokens()->count());

        $adminToken = $this->loginAndGetToken('admin5@example.com');
        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/force-password-reset")
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertTrue($user->fresh()->must_change_password);
    }

    // ── Temporary-login session restricted to recovery routes ──────────

    public function test_temporary_login_session_can_access_only_allowed_recovery_routes(): void
    {
        $this->makeAdmin('admin6@example.com');
        $user = $this->makeUser('temp-session@example.com');
        $adminToken = $this->loginAndGetToken('admin6@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('temp-session@example.com', 'TempPassw0rd!');

        $this->requestAs($tempToken)->getJson('/api/auth/me')->assertStatus(200);
        $this->requestAs($tempToken)->postJson('/api/auth/logout')->assertStatus(200);
    }

    public function test_normal_routes_return_403_password_change_required_before_forced_change(): void
    {
        $this->makeAdmin('admin7@example.com');
        $user = $this->makeUser('temp-session2@example.com');
        $adminToken = $this->loginAndGetToken('admin7@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('temp-session2@example.com', 'TempPassw0rd!');

        $this->requestAs($tempToken)->getJson('/api/dashboard')
            ->assertStatus(403)
            ->assertJson([
                'message' => 'You must change your password before continuing.',
                'code'    => 'password_change_required',
            ]);
    }

    // ── Forced password change completion ───────────────────────────────

    public function test_successful_forced_password_change_clears_the_flag(): void
    {
        $this->makeAdmin('admin8@example.com');
        $user = $this->makeUser('complete-forced@example.com');
        $adminToken = $this->loginAndGetToken('admin8@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('complete-forced@example.com', 'TempPassw0rd!');

        $this->requestAs($tempToken)
            ->putJson('/api/auth/force-password-change', [
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);

        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_normal_application_access_works_after_forced_password_change(): void
    {
        $this->makeAdmin('admin9@example.com');
        $user = $this->makeUser('complete-forced2@example.com');
        $adminToken = $this->loginAndGetToken('admin9@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('complete-forced2@example.com', 'TempPassw0rd!');

        $this->requestAs($tempToken)
            ->putJson('/api/auth/force-password-change', [
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);

        // Chosen policy: the session actively completing the forced change
        // stays authenticated (same token) — the frontend's
        // ForcePasswordChangeGate immediately calls GET /auth/me with this
        // same token and expects to land in the app, not be logged out.
        $this->requestAs($tempToken)->getJson('/api/dashboard')->assertStatus(200);
    }

    public function test_forced_password_change_revokes_other_tokens_but_preserves_current_session(): void
    {
        $this->makeAdmin('admin10@example.com');
        $user = $this->makeUser('complete-forced3@example.com');
        $adminToken = $this->loginAndGetToken('admin10@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd!'])
            ->assertStatus(200);

        // Two separate devices both log in with the temporary password.
        $sessionA = $this->loginAndGetToken('complete-forced3@example.com', 'TempPassw0rd!');
        $sessionB = $this->loginAndGetToken('complete-forced3@example.com', 'TempPassw0rd!');
        $this->assertSame(2, $user->fresh()->tokens()->count());

        // Session A completes the forced change.
        $this->requestAs($sessionA)
            ->putJson('/api/auth/force-password-change', [
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);

        // Session A (the one that made the change) still works...
        $this->requestAs($sessionA)->getJson('/api/auth/me')->assertStatus(200);
        // ...session B (a different device/stolen token) does not.
        $this->requestAs($sessionB)->getJson('/api/auth/me')->assertStatus(401);
        $this->assertSame(1, $user->fresh()->tokens()->count());
    }

    // ── Self-service password change (updatePassword) ───────────────────

    public function test_self_password_change_revokes_other_tokens_but_preserves_current_session(): void
    {
        $this->makeUser('self-change@example.com', 'OldPassw0rd!');
        $sessionA = $this->loginAndGetToken('self-change@example.com', 'OldPassw0rd!');
        $sessionB = $this->loginAndGetToken('self-change@example.com', 'OldPassw0rd!');

        $this->requestAs($sessionA)
            ->putJson('/api/auth/password', [
                'current_password' => 'OldPassw0rd!',
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);

        $this->requestAs($sessionA)->getJson('/api/auth/me')->assertStatus(200);
        $this->requestAs($sessionB)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_no_stolen_token_remains_valid_after_self_password_change(): void
    {
        $user = $this->makeUser('self-change2@example.com', 'OldPassw0rd!');
        // Simulates a token an attacker stole earlier, separate from the
        // legitimate device making the change.
        $stolenToken = $user->createToken('stolen')->plainTextToken;
        $legitToken = $this->loginAndGetToken('self-change2@example.com', 'OldPassw0rd!');

        $this->requestAs($legitToken)
            ->putJson('/api/auth/password', [
                'current_password' => 'OldPassw0rd!',
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);

        $this->requestAs($stolenToken)->getJson('/api/auth/me')->assertStatus(401);
    }
}
