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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd12345!'])
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_admin_set_password_sets_must_change_password_flag_by_default(): void
    {
        $this->makeAdmin('admin2@example.com');
        $user = $this->makeUser('reset-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin2@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd12345!'])
            ->assertStatus(200);

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_old_password_fails_after_admin_sets_new_password(): void
    {
        $this->makeAdmin('admin3@example.com');
        $user = $this->makeUser('reset-me3@example.com', 'OldPassw0rd!');
        $adminToken = $this->loginAndGetToken('admin3@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'NewPassw0rd12345!'])
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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => 'reset-me4@example.com', 'password' => 'TempPassw0rd12345!'])
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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('temp-session@example.com', 'TempPassw0rd12345!');

        $this->requestAs($tempToken)->getJson('/api/auth/me')->assertStatus(200);
        $this->requestAs($tempToken)->postJson('/api/auth/logout')->assertStatus(200);
    }

    public function test_normal_routes_return_403_password_change_required_before_forced_change(): void
    {
        $this->makeAdmin('admin7@example.com');
        $user = $this->makeUser('temp-session2@example.com');
        $adminToken = $this->loginAndGetToken('admin7@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('temp-session2@example.com', 'TempPassw0rd12345!');

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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('complete-forced@example.com', 'TempPassw0rd12345!');

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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        $tempToken = $this->loginAndGetToken('complete-forced2@example.com', 'TempPassw0rd12345!');

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
            ->postJson("/api/users/{$user->id}/set-password", ['password' => 'TempPassw0rd12345!'])
            ->assertStatus(200);

        // Two separate devices both log in with the temporary password.
        $sessionA = $this->loginAndGetToken('complete-forced3@example.com', 'TempPassw0rd12345!');
        $sessionB = $this->loginAndGetToken('complete-forced3@example.com', 'TempPassw0rd12345!');
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

    // ── User Removal Token Revocation (UserController::destroy/bulkRemove) ─
    //
    // Removal alone (SoftDeletes) is not an authentication boundary: a
    // still-valid token merely fails to resolve a soft-deleted user, but the
    // exact same token becomes valid again the instant the row is restored
    // (e.g. a later re-invite of the same email). These tests prove removal
    // now durably revokes tokens up front, so a restore can never resurrect
    // a pre-removal session.

    public function test_single_remove_revokes_all_existing_tokens(): void
    {
        $this->makeAdmin('remove-admin1@example.com');
        $user = $this->makeUser('remove-me1@example.com');
        $this->loginAndGetToken('remove-me1@example.com');
        $this->loginAndGetToken('remove-me1@example.com');
        $this->assertSame(2, $user->tokens()->count());

        $adminToken = $this->loginAndGetToken('remove-admin1@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        $this->assertSame(0, User::withTrashed()->find($user->id)->tokens()->count());
        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_removed_users_old_token_returns_401(): void
    {
        $this->makeAdmin('remove-admin2@example.com');
        $user = $this->makeUser('remove-me2@example.com');
        $oldToken = $this->loginAndGetToken('remove-me2@example.com');

        $adminToken = $this->loginAndGetToken('remove-admin2@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        $this->requestAs($oldToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_restoring_a_removed_user_does_not_revive_their_old_token(): void
    {
        $this->makeAdmin('remove-admin3@example.com');
        $user = $this->makeUser('remove-me3@example.com');
        $oldToken = $this->loginAndGetToken('remove-me3@example.com');

        $adminToken = $this->loginAndGetToken('remove-admin3@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        // Sanity: unusable while soft-deleted (SoftDeletingScope).
        $this->requestAs($oldToken)->getJson('/api/auth/me')->assertStatus(401);

        User::withTrashed()->find($user->id)->restore();

        // The old token must still be dead — restore() alone must never
        // resurrect a pre-removal session.
        $this->requestAs($oldToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_reinviting_a_removed_user_does_not_resurrect_their_old_token(): void
    {
        $this->fakeBrevoForInvite();
        $this->makeAdmin('remove-admin4@example.com');
        $user = $this->makeUser('remove-me4@example.com');
        $oldToken = $this->loginAndGetToken('remove-me4@example.com');
        $originalOrgId = $user->organization_id;

        $adminToken = $this->loginAndGetToken('remove-admin4@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        // Re-invite the exact same email — this is UserController::
        // inviteOneUser()'s restore branch, the real production path a
        // holder of the old token would be hoping to ride back in on.
        $this->requestAs($adminToken)
            ->postJson('/api/users/invite', ['email' => 'remove-me4@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        $this->requestAs($oldToken)->getJson('/api/auth/me')->assertStatus(401);

        // Existing organisation-preservation invariant is untouched by this
        // fix — re-invite still restores the same organization_id.
        $this->assertSame($originalOrgId, $user->fresh()->organization_id);
    }

    public function test_a_freshly_issued_token_works_normally_after_legitimate_restoration(): void
    {
        $this->fakeBrevoForInvite();
        $this->makeAdmin('remove-admin5@example.com');
        $user = $this->makeUser('remove-me5@example.com');
        $this->loginAndGetToken('remove-me5@example.com');

        $adminToken = $this->loginAndGetToken('remove-admin5@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        $this->requestAs($adminToken)
            ->postJson('/api/users/invite', ['email' => 'remove-me5@example.com', 'role' => 'Client'])
            ->assertStatus(201);

        // The restored account can still authenticate normally and get a
        // brand-new, genuinely valid token — this fix only kills the OLD
        // pre-removal token, not the account's ability to be used again.
        $restored = $user->fresh();
        $restored->forceFill(['password' => \Illuminate\Support\Facades\Hash::make('BrandNewPassw0rd!')])->save();
        $newToken = $this->loginAndGetToken('remove-me5@example.com', 'BrandNewPassw0rd!');

        $this->requestAs($newToken)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_bulk_remove_revokes_tokens_for_every_successfully_removed_user(): void
    {
        $this->makeAdmin('remove-admin6@example.com');
        $alice = $this->makeUser('bulk-remove-alice@example.com');
        $bob = $this->makeUser('bulk-remove-bob@example.com');
        $aliceToken = $this->loginAndGetToken('bulk-remove-alice@example.com');
        $bobToken = $this->loginAndGetToken('bulk-remove-bob@example.com');

        $adminToken = $this->loginAndGetToken('remove-admin6@example.com');
        $this->requestAs($adminToken)
            ->postJson('/api/users/bulk-remove', ['ids' => [$alice->id, $bob->id]])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.removed');

        $this->requestAs($aliceToken)->getJson('/api/auth/me')->assertStatus(401);
        $this->requestAs($bobToken)->getJson('/api/auth/me')->assertStatus(401);
        $this->assertSame(0, User::withTrashed()->find($alice->id)->tokens()->count());
        $this->assertSame(0, User::withTrashed()->find($bob->id)->tokens()->count());
    }

    public function test_bulk_remove_leaves_a_last_super_admin_rejected_rows_token_untouched(): void
    {
        $superAdmin = $this->makeAdmin('remove-admin7@example.com');
        $otherUser = $this->makeUser('bulk-remove-other@example.com');
        $superAdminToken = $this->loginAndGetToken('remove-admin7@example.com');

        $response = $this->requestAs($superAdminToken)
            ->postJson('/api/users/bulk-remove', ['ids' => [$otherUser->id, $superAdmin->id]])
            ->assertStatus(200);

        $response->assertJsonCount(1, 'data.removed')->assertJsonCount(1, 'data.failed');

        // The last-Super-Admin row was rejected — it must remain active,
        // not soft-deleted, and its token must still be valid (the fix
        // must never revoke a token for a row the operation itself refused
        // to remove).
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'deleted_at' => null]);
        $this->requestAs($superAdminToken)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_normal_remove_leaves_organization_id_unchanged(): void
    {
        $this->makeAdmin('remove-admin8@example.com');
        $user = $this->makeUser('remove-me8@example.com');
        $originalOrgId = $user->organization_id;
        $this->assertNotNull($originalOrgId);

        $adminToken = $this->loginAndGetToken('remove-admin8@example.com');
        $this->requestAs($adminToken)
            ->deleteJson("/api/users/{$user->id}")
            ->assertStatus(200);

        $this->assertSame($originalOrgId, $user->fresh()->organization_id);
    }

    private function fakeBrevoForInvite(): void
    {
        \App\Models\SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.app',
            'support_email' => 'support@suresigncontracts.app',
            'admin_email' => 'admin@suresigncontracts.app',
        ]);
        \Illuminate\Support\Facades\Http::fake(['api.brevo.com/*' => \Illuminate\Support\Facades\Http::response(['messageId' => 'fake-message-id'], 201)]);
    }
}
