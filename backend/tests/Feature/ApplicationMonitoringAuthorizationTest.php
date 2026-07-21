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
 * must be reachable only by Super Admin, matching the tighter
 * 'role:Super Admin' group in routes/api.php (not 'Super Admin|Admin').
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

    public function test_admin_cannot_access_monitoring_endpoint(): void
    {
        Sanctum::actingAs($this->makeUser('Admin'));

        $this->getJson('/api/admin/application-monitoring')->assertStatus(403);
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
