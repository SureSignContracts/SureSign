<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the EnsureAccountIsActive middleware and the token-revocation
 * rules for deactivation/ban/reactivation/unban — the confirmed High
 * finding from the pre-production assessment: a deactivated or banned
 * user's previously-issued Sanctum token kept working indefinitely because
 * nothing re-checked is_active/banned_at per request.
 *
 * Uses real login + real bearer tokens (not Sanctum::actingAs, which fakes
 * the guard directly and never touches personal_access_tokens) — that's
 * required to actually prove a token was deleted and stops authenticating.
 */
class AccountStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeUser(string $email, string $role = 'Client', bool $active = true): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'is_active' => $active,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeAdmin(string $email): User
    {
        // Admin/Super Admin are platform-wide, not tied to a single org.
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function loginAndGetToken(string $email, string $password = 'password'): string
    {
        // Auth::guard()'s resolved-user cache persists for the lifetime of the
        // test's container, not per simulated "request" — the immediately
        // preceding request's forgetGuards() call (see requestAs()) handles
        // that for every OTHER call in this file; login itself is always the
        // first request for a given token, so it needs its own reset too,
        // otherwise a prior test step's cached guard user leaks into the
        // credential check here.
        $this->app['auth']->forgetGuards();
        $response = $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        $response->assertStatus(200);

        return $response->json('token');
    }

    /**
     * Issues a request authenticated as the given bearer token. Forces the
     * auth guard to forget its previously-resolved user first — Laravel's
     * test HTTP calls reuse the same booted container across sequential
     * calls within one test method (unlike real separate requests), and the
     * Sanctum guard otherwise keeps returning whichever user it resolved on
     * the *first* call regardless of which token is sent afterward. This is
     * a test-harness quirk (confirmed via a minimal repro with no
     * application code involved at all), not an application bug.
     */
    private function requestAs(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    // ── Active user baseline ────────────────────────────────────────────

    public function test_active_user_with_valid_token_can_access_authenticated_route(): void
    {
        $this->makeUser('active@example.com');
        $token = $this->loginAndGetToken('active@example.com');

        $this->requestAs($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    // ── Login-time account-unavailable (Error Messaging & Recovery UX,
    //    Batch 1) — a deactivated/banned account must never reach the point
    //    of a token being issued, and must return the exact same
    //    message/code as the mid-session EnsureAccountIsActive check below,
    //    not different untagged wording. ───────────────────────────────────

    public function test_login_rejects_a_deactivated_account_with_account_unavailable_code(): void
    {
        $this->makeUser('deactivated-login@example.com', 'Client', active: false);

        $this->postJson('/api/auth/login', [
            'email' => 'deactivated-login@example.com',
            'password' => 'password',
        ])->assertStatus(403)->assertJson([
            'message' => 'Your account is not currently permitted to access the platform.',
            'code'    => 'account_unavailable',
        ]);
    }

    public function test_login_rejects_a_banned_account_with_account_unavailable_code(): void
    {
        $user = $this->makeUser('banned-login@example.com');
        $user->update(['banned_at' => now(), 'banned_reason' => 'test']);

        $this->postJson('/api/auth/login', [
            'email' => 'banned-login@example.com',
            'password' => 'password',
        ])->assertStatus(403)->assertJson([
            'message' => 'Your account is not currently permitted to access the platform.',
            'code'    => 'account_unavailable',
        ]);
    }

    public function test_login_does_not_issue_a_token_for_a_deactivated_account(): void
    {
        $user = $this->makeUser('no-token-login@example.com', 'Client', active: false);

        $this->postJson('/api/auth/login', [
            'email' => 'no-token-login@example.com',
            'password' => 'password',
        ]);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    // ── Deactivation ─────────────────────────────────────────────────────

    public function test_deactivating_a_user_revokes_all_existing_tokens(): void
    {
        $this->makeAdmin('admin1@example.com');
        $user = $this->makeUser('deactivate-me@example.com');
        $this->loginAndGetToken('deactivate-me@example.com');
        $this->loginAndGetToken('deactivate-me@example.com'); // a second session/device

        $this->assertSame(2, $user->tokens()->count());

        $adminToken = $this->loginAndGetToken('admin1@example.com');
        $this->requestAs($adminToken)
            ->putJson("/api/users/{$user->id}", ['is_active' => false])
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_old_bearer_token_is_rejected_after_deactivation(): void
    {
        $this->makeAdmin('admin2@example.com');
        $user = $this->makeUser('deactivate-me2@example.com');
        $staleToken = $this->loginAndGetToken('deactivate-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin2@example.com');

        $this->requestAs($adminToken)
            ->putJson("/api/users/{$user->id}", ['is_active' => false])
            ->assertStatus(200);

        // Deactivation itself deletes every token for this user (see
        // UserController::update) — Sanctum's own guard now rejects it
        // outright (401) since the row is simply gone.
        $this->requestAs($staleToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_reactivation_does_not_restore_the_old_token(): void
    {
        $this->makeAdmin('admin3@example.com');
        $user = $this->makeUser('reactivate-me@example.com');
        $staleToken = $this->loginAndGetToken('reactivate-me@example.com');
        $adminToken = $this->loginAndGetToken('admin3@example.com');

        $this->requestAs($adminToken)->putJson("/api/users/{$user->id}", ['is_active' => false])->assertStatus(200);
        $this->requestAs($adminToken)->putJson("/api/users/{$user->id}", ['is_active' => true])->assertStatus(200);

        $this->requestAs($staleToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_fresh_login_after_reactivation_succeeds(): void
    {
        $this->makeAdmin('admin4@example.com');
        $user = $this->makeUser('reactivate-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin4@example.com');

        $this->requestAs($adminToken)->putJson("/api/users/{$user->id}", ['is_active' => false])->assertStatus(200);
        $this->requestAs($adminToken)->putJson("/api/users/{$user->id}", ['is_active' => true])->assertStatus(200);

        $freshToken = $this->loginAndGetToken('reactivate-me2@example.com');
        $this->requestAs($freshToken)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_editing_unrelated_fields_does_not_revoke_tokens(): void
    {
        $this->makeAdmin('admin5@example.com');
        $user = $this->makeUser('rename-me@example.com');
        $token = $this->loginAndGetToken('rename-me@example.com');
        $adminToken = $this->loginAndGetToken('admin5@example.com');

        $this->requestAs($adminToken)
            ->putJson("/api/users/{$user->id}", ['name' => 'New Name'])
            ->assertStatus(200);

        $this->requestAs($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    // ── Ban (regression — already worked before this task, per the audit) ──

    public function test_ban_action_revokes_all_tokens(): void
    {
        $this->makeAdmin('admin6@example.com');
        $user = $this->makeUser('ban-me@example.com');
        $this->loginAndGetToken('ban-me@example.com');
        $this->assertSame(1, $user->tokens()->count());
        $adminToken = $this->loginAndGetToken('admin6@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/ban", ['reason' => 'Policy violation'])
            ->assertStatus(200);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_old_token_stops_working_after_ban(): void
    {
        $this->makeAdmin('admin7@example.com');
        $user = $this->makeUser('ban-me2@example.com');
        $staleToken = $this->loginAndGetToken('ban-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin7@example.com');

        $this->requestAs($adminToken)
            ->postJson("/api/users/{$user->id}/ban", ['reason' => 'Policy violation'])
            ->assertStatus(200);

        $this->requestAs($staleToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_unban_does_not_restore_old_tokens(): void
    {
        $this->makeAdmin('admin8@example.com');
        $user = $this->makeUser('unban-me@example.com');
        $staleToken = $this->loginAndGetToken('unban-me@example.com');
        $adminToken = $this->loginAndGetToken('admin8@example.com');

        $this->requestAs($adminToken)->postJson("/api/users/{$user->id}/ban", ['reason' => 'x'])->assertStatus(200);
        $this->requestAs($adminToken)->postJson("/api/users/{$user->id}/unban")->assertStatus(200);

        $this->requestAs($staleToken)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_fresh_login_after_unban_succeeds(): void
    {
        $this->makeAdmin('admin9@example.com');
        $user = $this->makeUser('unban-me2@example.com');
        $adminToken = $this->loginAndGetToken('admin9@example.com');

        $this->requestAs($adminToken)->postJson("/api/users/{$user->id}/ban", ['reason' => 'x'])->assertStatus(200);
        $this->requestAs($adminToken)->postJson("/api/users/{$user->id}/unban")->assertStatus(200);

        $freshToken = $this->loginAndGetToken('unban-me2@example.com');
        $this->requestAs($freshToken)->getJson('/api/auth/me')->assertStatus(200);
    }

    // ── Per-request status middleware: manually-created / stale tokens ─────
    //
    // These are the important ones: token revocation alone doesn't protect
    // against a token that was already stale/manually-issued *before* this
    // middleware existed (or created directly, bypassing login entirely).

    public function test_inactive_user_with_a_manually_created_token_is_still_blocked(): void
    {
        $user = $this->makeUser('manual-inactive@example.com', 'Client', active: false);
        $token = $user->createToken('manual')->plainTextToken;

        $response = $this->requestAs($token)->getJson('/api/auth/me');

        $response->assertStatus(403)->assertJson([
            'message' => 'Your account is not currently permitted to access the platform.',
            'code'    => 'account_unavailable',
        ]);
    }

    public function test_banned_user_with_a_manually_created_token_is_still_blocked(): void
    {
        $user = $this->makeUser('manual-banned@example.com');
        $user->update(['banned_at' => now(), 'banned_reason' => 'test']);
        $token = $user->createToken('manual')->plainTextToken;

        $this->requestAs($token)->getJson('/api/auth/me')
            ->assertStatus(403)
            ->assertJson(['code' => 'account_unavailable']);
    }

    public function test_status_middleware_cannot_be_bypassed_by_a_valid_looking_manually_created_token(): void
    {
        $user = $this->makeUser('manual-bypass@example.com', 'Client', active: false);
        // A syntactically valid Sanctum token, freshly created directly against
        // the (already inactive) user — proves the middleware checks live
        // account state, not merely "did this token exist at issuance."
        $token = $user->createToken('manual')->plainTextToken;

        $this->requestAs($token)->getJson('/api/dashboard')->assertStatus(403);
        $this->requestAs($token)->getJson('/api/projects')->assertStatus(401); // token deleted by the first request
    }

    public function test_active_unbanned_user_is_unaffected_by_status_middleware(): void
    {
        $this->makeUser('unaffected@example.com');
        $token = $this->loginAndGetToken('unaffected@example.com');

        $this->requestAs($token)->getJson('/api/auth/me')->assertStatus(200);
        $this->requestAs($token)->getJson('/api/dashboard')->assertStatus(200);
    }

    // ── Super Admin safeguards (regression — must remain intact) ───────────

    // Note: `isLastActiveSuperAdmin()`'s "Cannot deactivate/ban the last
    // Super Admin" message (UserController.php:125,217) sits behind a
    // same-user self-action check that fires first — and since the route
    // itself requires the actor to hold 'Super Admin' (routes/api.php's
    // `role:Super Admin` gate), the actor always counts toward the "active
    // Super Admins" total, so the count can never be exactly 1 while acting
    // on a *different* target. That message is therefore only reachable via
    // a pre-existing stale-session edge case this task's new account-status
    // middleware now closes off entirely (an inactive Super Admin can no
    // longer reach the endpoint at all). The tests below cover the actually
    // reachable, equivalent lockout protection: a lone Super Admin cannot
    // deactivate/ban *themselves* — pre-existing behaviour, unrelated to
    // this task's changes, confirmed still intact.
    public function test_super_admin_cannot_deactivate_their_own_account(): void
    {
        $superAdmin = $this->makeAdmin('only-super-admin@example.com');
        $token = $this->loginAndGetToken('only-super-admin@example.com');

        $this->requestAs($token)
            ->putJson("/api/users/{$superAdmin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJson(['message' => 'You cannot deactivate your own account.']);

        $this->assertTrue($superAdmin->fresh()->is_active);
    }

    public function test_super_admin_cannot_ban_their_own_account(): void
    {
        $superAdmin = $this->makeAdmin('only-super-admin2@example.com');
        $token = $this->loginAndGetToken('only-super-admin2@example.com');

        $this->requestAs($token)
            ->postJson("/api/users/{$superAdmin->id}/ban", ['reason' => 'x'])
            ->assertStatus(422)
            ->assertJson(['message' => 'You cannot ban your own account.']);

        $this->assertNull($superAdmin->fresh()->banned_at);
    }
}
