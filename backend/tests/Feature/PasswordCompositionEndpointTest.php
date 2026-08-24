<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\InvitationLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Password Composition Restoration (August 24, 2026) — proves the shared
 * SureSignPasswordPolicy composition rules (uppercase/lowercase/number/
 * special character) actually reach every active password-setting route,
 * not just SureSignPasswordPolicy::rules() in isolation (that exhaustive
 * matrix already lives in SureSignPasswordPolicyTest — this file only
 * proves each ROUTE consumes it, deliberately not re-testing every
 * composition case per endpoint).
 *
 * Every non-compliant value below is well above the 12-character minimum
 * (so length alone never explains the rejection) but is missing every
 * composition category at once — sufficient to prove the shared policy is
 * wired in, without duplicating SureSignPasswordPolicyTest's per-rule
 * isolation.
 */
class PasswordCompositionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const NON_COMPLIANT = 'alllowercasenocomposition';
    private const COMPLIANT = 'Correct123Horse!';

    private function makeClient(string $email, string $password = 'ExistingPassphrase1!'): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    private function makeSuperAdmin(string $email): User
    {
        $admin = User::factory()->create(['email' => $email, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    public function test_invitation_acceptance_rejects_non_compliant_composition(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $apiUrl = app(InvitationLinkService::class)->apiUrl($user);
        $query = [];
        parse_str((string) parse_url($apiUrl, PHP_URL_QUERY), $query);

        $response = $this->postJson('/api/public/invitations/' . $user->id . '?' . http_build_query($query), [
            'password' => self::NON_COMPLIANT,
            'password_confirmation' => self::NON_COMPLIANT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_change_password_rejects_non_compliant_composition(): void
    {
        $user = $this->makeClient('change-composition@example.com');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/password', [
            'current_password' => 'ExistingPassphrase1!',
            'password' => self::NON_COMPLIANT,
            'password_confirmation' => self::NON_COMPLIANT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertTrue(Hash::check('ExistingPassphrase1!', $user->fresh()->password));
    }

    public function test_reset_password_rejects_non_compliant_composition(): void
    {
        $user = $this->makeClient('reset-composition@example.com');
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $response = $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NON_COMPLIANT,
            'password_confirmation' => self::NON_COMPLIANT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertTrue(Hash::check('ExistingPassphrase1!', $user->fresh()->password));
    }

    public function test_forced_password_change_rejects_non_compliant_composition(): void
    {
        $user = $this->makeClient('forced-composition@example.com');
        $user->update(['must_change_password' => true]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/force-password-change', [
            'password' => self::NON_COMPLIANT,
            'password_confirmation' => self::NON_COMPLIANT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_admin_set_password_rejects_non_compliant_composition(): void
    {
        $user = $this->makeClient('admin-set-composition@example.com');
        $admin = $this->makeSuperAdmin('admin-composition@example.com');
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/users/{$user->id}/set-password", [
            'password' => self::NON_COMPLIANT,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertTrue(Hash::check('ExistingPassphrase1!', $user->fresh()->password));
    }

    /**
     * One positive control per flow-family: a fully compliant password must
     * still succeed. Guards against a future change accidentally making
     * the composition rule impossible to satisfy (e.g. a regex bug).
     * Invitation acceptance and Admin Set Password already have this
     * positive-path coverage in InvitationFlowTest/TokenRevocationTest —
     * only Change Password, Reset Password, and Forced Change are
     * exercised here to avoid duplicating existing coverage.
     */
    public function test_change_password_accepts_a_fully_compliant_composition(): void
    {
        $user = $this->makeClient('change-compliant@example.com');
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/password', [
            'current_password' => 'ExistingPassphrase1!',
            'password' => self::COMPLIANT,
            'password_confirmation' => self::COMPLIANT,
        ])->assertStatus(200);

        $this->assertTrue(Hash::check(self::COMPLIANT, $user->fresh()->password));
    }

    public function test_reset_password_accepts_a_fully_compliant_composition(): void
    {
        $user = $this->makeClient('reset-compliant@example.com');
        $token = \Illuminate\Support\Facades\Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::COMPLIANT,
            'password_confirmation' => self::COMPLIANT,
        ])->assertStatus(200);

        $this->assertTrue(Hash::check(self::COMPLIANT, $user->fresh()->password));
    }

    public function test_forced_password_change_accepts_a_fully_compliant_composition(): void
    {
        $user = $this->makeClient('forced-compliant@example.com');
        $user->update(['must_change_password' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/force-password-change', [
            'password' => self::COMPLIANT,
            'password_confirmation' => self::COMPLIANT,
        ])->assertStatus(200);

        $this->assertTrue(Hash::check(self::COMPLIANT, $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }
}
