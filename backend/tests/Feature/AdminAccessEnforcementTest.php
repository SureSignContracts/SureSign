<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Support\Admin\AdminAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Super Admin Configurable Admin Access — `permission:admin.module.*`
 * backend enforcement coverage (restricted -> 403, permitted -> 200).
 * Kept as its own file rather than merged back into AdminAccessTest.php,
 * since it was built and held separately for the Two-Stage Admin Access
 * rollout (Stage 1 shipped 2026-08-26 as commit dede8a3; Stage 2 — this
 * file's own middleware — went live the same day once the production
 * backfill was confirmed complete, granting every pre-existing legacy
 * Admin the full baseline before enforcement became active). See
 * internal-docs/super-admin/admin-access.md's Production rollout section
 * for the full history.
 */
class AdminAccessEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(string $email = 'sa@example.com'): User
    {
        $user = User::factory()->create(['organization_id' => null, 'email' => $email]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function makeAdmin(string $email = 'admin@example.com'): User
    {
        $user = User::factory()->create(['organization_id' => null, 'email' => $email]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        return $user;
    }

    private function makeClient(): User
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return $user;
    }

    public function test_permitted_admin_can_access_a_granted_module(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions(['admin.module.pricing']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/pricing/settings')->assertStatus(200);
    }

    public function test_restricted_admin_is_blocked_from_an_ungranted_module(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions(['admin.module.pricing']); // projects NOT granted
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/projects')->assertStatus(403);
    }

    public function test_another_configurable_module_is_independently_enforced(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions(['admin.module.projects']); // dashboard NOT granted
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->getJson('/api/admin/projects')->assertStatus(200);
    }

    /**
     * Explicit backend-authority test for the five modules the Final
     * Deployment / Cold-Start Hardening phase specifically called out:
     * restricted -> 403, permitted -> the existing successful behaviour,
     * for each independently.
     */
    public function test_backend_authority_companies_projects_pricing_ai_credits_google_integration(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions([]); // none granted
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/organizations')->assertStatus(403);
        $this->getJson('/api/admin/projects')->assertStatus(403);
        $this->getJson('/api/admin/pricing/settings')->assertStatus(403);
        $this->getJson('/api/admin/ai-credits/summary')->assertStatus(403);
        $this->getJson('/api/admin/google/diagnostics')->assertStatus(403);

        $admin->givePermissionTo([
            'admin.module.companies',
            'admin.module.projects',
            'admin.module.pricing',
            'admin.module.ai_credits',
            'admin.module.google_integration',
        ]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/admin/organizations')->assertStatus(200);
        $this->getJson('/api/admin/projects')->assertStatus(200);
        $this->getJson('/api/admin/pricing/settings')->assertStatus(200);
        $this->getJson('/api/admin/ai-credits/summary')->assertStatus(200);
        $this->getJson('/api/admin/google/diagnostics')->assertStatus(200);
    }

    /**
     * Stage 2 counterpart of AdminAccessTest.php's Stage-1
     * test_clear_all_state_survives_backfill_rerun_and_a_non_transition_role_event
     * — same permission-state sequence, but now also asserting the
     * enforcement consequence: a Clear-All'd Admin is blocked from every
     * configurable module's API.
     */
    public function test_clear_all_blocks_module_apis_once_enforcement_is_active(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        AdminAccessService::grantFullAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => []])
            ->assertStatus(200);

        Artisan::call('admin:permissions:backfill');
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);

        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/admin/pricing/settings')->assertStatus(403);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    public function test_frontend_sidebar_permission_key_hides_configurable_modules(): void
    {
        // Backend-only proxy for the frontend assertion — the /auth/me
        // `permissions` array AdminSidebar.tsx's isVisible() reads from is
        // exactly the catalogue-scoped set; this proves it's empty for a
        // Clear-All'd Admin, which is what the sidebar's permissionKey
        // gate hides against.
        $admin = $this->makeAdmin();
        $admin->syncPermissions([]);
        Sanctum::actingAs($admin);

        $me = $this->getJson('/api/auth/me')->assertStatus(200);
        $this->assertEmpty(array_intersect($me->json('permissions') ?? [], AdminAccess::keys()));
    }
}
