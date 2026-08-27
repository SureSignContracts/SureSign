<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Super Admin Application Monitoring — GET /api/admin/application-monitoring
 * requires Super Admin OR Admin with admin.module.application_monitoring
 * (Full Parity Access Expansion, 2026-08-26 — Application Monitoring was
 * permanently Super-Admin-only before this; see AdminAccessTest.php's own
 * coverage for the general catalogue behaviour, this file stays focused on
 * this one endpoint's role/permission boundary).
 */
class ApplicationMonitoringAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid(), 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    public function test_super_admin_can_access_monitoring_endpoint(): void
    {
        Sanctum::actingAs($this->makeUser('Super Admin'));

        $this->getJson('/api/admin/application-monitoring')->assertStatus(200);
    }

    public function test_admin_without_the_permission_cannot_access_monitoring_endpoint(): void
    {
        $admin = $this->makeUser('Admin');
        // makeUser()'s own assignRole('Admin') auto-grants the full
        // baseline via GrantBaselineAdminAccessOnRoleAttached — strip it
        // to exercise the genuinely-restricted case.
        $admin->syncPermissions([]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/application-monitoring')->assertStatus(403);
    }

    public function test_admin_with_the_permission_can_access_monitoring_endpoint(): void
    {
        $admin = $this->makeUser('Admin');
        $admin->syncPermissions(['admin.module.application_monitoring']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/application-monitoring')->assertStatus(200);
    }

    public function test_client_cannot_access_monitoring_endpoint(): void
    {
        Sanctum::actingAs($this->makeUser('Client'));

        $this->getJson('/api/admin/application-monitoring')->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_monitoring_endpoint(): void
    {
        $this->getJson('/api/admin/application-monitoring')->assertStatus(401);
    }

    public function test_response_never_includes_sensitive_fields(): void
    {
        Sanctum::actingAs($this->makeUser('Super Admin'));

        $response = $this->getJson('/api/admin/application-monitoring')->assertStatus(200);

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('password', strtolower($json));
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString('session_id', strtolower($json));
    }
}
