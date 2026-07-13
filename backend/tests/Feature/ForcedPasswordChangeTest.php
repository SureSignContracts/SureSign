<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the EnsurePasswordIsCurrent middleware's route allowlist in
 * isolation — separate from TokenRevocationTest, which covers the token
 * policy around the same flow.
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeUserRequiringPasswordChange(string $email): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create([
            'organization_id' => $org->id,
            'email' => $email,
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    private function loginAndGetToken(string $email, string $password = 'password'): string
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

    public function test_auth_me_remains_reachable_when_password_change_required(): void
    {
        $this->makeUserRequiringPasswordChange('needs-change@example.com');
        $token = $this->loginAndGetToken('needs-change@example.com');

        $this->requestAs($token)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_logout_remains_reachable_when_password_change_required(): void
    {
        $this->makeUserRequiringPasswordChange('needs-change2@example.com');
        $token = $this->loginAndGetToken('needs-change2@example.com');

        $this->requestAs($token)->postJson('/api/auth/logout')->assertStatus(200);
    }

    public function test_force_password_change_endpoint_remains_reachable(): void
    {
        $this->makeUserRequiringPasswordChange('needs-change3@example.com');
        $token = $this->loginAndGetToken('needs-change3@example.com');

        $this->requestAs($token)
            ->putJson('/api/auth/force-password-change', [
                'password' => 'BrandNewPassw0rd!',
                'password_confirmation' => 'BrandNewPassw0rd!',
            ])
            ->assertStatus(200);
    }

    public function test_normal_endpoints_are_blocked_when_password_change_required(): void
    {
        $this->makeUserRequiringPasswordChange('needs-change4@example.com');
        $token = $this->loginAndGetToken('needs-change4@example.com');

        foreach (['/api/dashboard', '/api/projects', '/api/settings'] as $path) {
            $this->requestAs($token)->getJson($path)
                ->assertStatus(403)
                ->assertJson([
                    'message' => 'You must change your password before continuing.',
                    'code'    => 'password_change_required',
                ]);
        }
    }

    public function test_different_users_do_not_affect_one_another(): void
    {
        $this->makeUserRequiringPasswordChange('needs-change5@example.com');
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $normalUser = User::factory()->create([
            'organization_id' => $org->id,
            'email' => 'normal-user@example.com',
            'is_active' => true,
            'must_change_password' => false,
        ]);
        $normalUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $restrictedToken = $this->loginAndGetToken('needs-change5@example.com');
        $normalToken = $this->loginAndGetToken('normal-user@example.com');

        $this->requestAs($restrictedToken)->getJson('/api/dashboard')->assertStatus(403);
        $this->requestAs($normalToken)->getJson('/api/dashboard')->assertStatus(200);
    }
}
