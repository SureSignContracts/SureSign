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
        AdminAccessService::grantDefaultAccess($admin);
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$admin->id}/permissions", ['permissions' => []])
            ->assertStatus(200);

        Artisan::call('admin:permissions:backfill');
        $this->putJson("/api/users/{$admin->id}", ['role' => 'Admin'])->assertStatus(200);

        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/admin/pricing/settings')->assertStatus(403);
        $this->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    // ── Full Parity Access Expansion (2026-08-26) ────────────────────────

    /**
     * Backend authority for the eight modules the Full Parity Access
     * Expansion brought into the catalogue — restricted -> 403, permitted
     * -> 200, for each independently (excluding Users, which has its own
     * dedicated coverage below given its Super Admin carve-out).
     */
    public function test_backend_authority_for_the_eight_expanded_modules(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions([]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/storage')->assertStatus(403);
        $this->getJson('/api/admin/support-tickets')->assertStatus(403);
        $this->getJson('/api/admin/platform-announcements')->assertStatus(403);
        $this->getJson('/api/admin/system-logs')->assertStatus(403);
        $this->getJson('/api/admin/audit-log')->assertStatus(403);
        $this->putJson('/api/admin/suresign-settings/ai', ['ai_enabled' => true])->assertStatus(403);
        $this->getJson('/api/admin/application-monitoring')->assertStatus(403);

        $admin->givePermissionTo([
            'admin.module.storage',
            'admin.module.support',
            'admin.module.announcements',
            'admin.module.system_logs',
            'admin.module.audit_log',
            'admin.module.ai_config',
            'admin.module.application_monitoring',
        ]);
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/admin/storage')->assertStatus(200);
        $this->getJson('/api/admin/support-tickets')->assertStatus(200);
        $this->getJson('/api/admin/platform-announcements')->assertStatus(200);
        $this->getJson('/api/admin/system-logs')->assertStatus(200);
        $this->getJson('/api/admin/audit-log')->assertStatus(200);
        $this->putJson('/api/admin/suresign-settings/ai', ['ai_enabled' => true])->assertStatus(200);
        $this->getJson('/api/admin/application-monitoring')->assertStatus(200);
    }

    /**
     * Users: restricted -> 403, permitted -> ordinary Admin/Client
     * management succeeds. Confirms the module gate itself works exactly
     * like any other before the Super Admin carve-out is exercised
     * separately below.
     */
    public function test_backend_authority_for_users_module_on_ordinary_targets(): void
    {
        $admin = $this->makeAdmin();
        $admin->syncPermissions([]); // makeAdmin()'s own assignRole() auto-grants the full baseline via the listener
        $target = $this->makeClient();
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$target->id}", ['name' => 'Renamed'])->assertStatus(403);

        $admin->givePermissionTo('admin.module.users');
        Sanctum::actingAs($admin->fresh());

        $this->putJson("/api/users/{$target->id}", ['name' => 'Renamed'])->assertStatus(200);
    }

    /**
     * The Users module's one deliberate carve-out: even fully granted
     * admin.module.users, an Admin can never act on an existing Super
     * Admin account (update/ban/remove/etc.), and can never create a new
     * one via invite or a role change. A genuine Super Admin actor is
     * completely unaffected by this check.
     */
    public function test_users_module_carve_out_blocks_admin_from_touching_or_creating_super_admins(): void
    {
        $admin = $this->makeAdmin();
        $admin->givePermissionTo('admin.module.users');
        $otherSuperAdmin = $this->makeSuperAdmin('other-sa@example.com');
        $client = $this->makeClient();
        Sanctum::actingAs($admin->fresh());

        // Cannot act on an existing Super Admin in any way.
        $this->putJson("/api/users/{$otherSuperAdmin->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->postJson("/api/users/{$otherSuperAdmin->id}/ban", ['reason' => 'test reason here'])->assertStatus(403);
        $this->deleteJson("/api/users/{$otherSuperAdmin->id}")->assertStatus(403);
        $this->postJson("/api/users/{$otherSuperAdmin->id}/force-password-reset")->assertStatus(403);
        $this->postJson("/api/users/{$otherSuperAdmin->id}/revoke-tokens")->assertStatus(403);

        // Cannot promote an existing Admin/Client to Super Admin.
        $this->putJson("/api/users/{$client->id}", ['role' => 'Super Admin'])->assertStatus(403);

        // Cannot create a new Super Admin via invite.
        $this->postJson('/api/users/invite', ['email' => 'newsa@example.com', 'role' => 'Super Admin'])->assertStatus(403);

        // Confirms the Super Admin himself is completely unaffected —
        // a genuine Super Admin acting on the same target succeeds.
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);
        $this->putJson("/api/users/{$otherSuperAdmin->id}", ['name' => 'Renamed By Real SA'])->assertStatus(200);
    }

    /**
     * Users Module Super Admin Exclusion (2026-08-27): an Admin with
     * admin.module.users can never see a Super Admin account at all — not
     * in the list, not by direct id fetch, not via the subscription
     * detail endpoint. A genuine Super Admin actor is unaffected on every
     * one of these.
     */
    public function test_admin_with_users_module_cannot_list_or_view_super_admin_accounts(): void
    {
        $admin = $this->makeAdmin();
        $admin->givePermissionTo('admin.module.users');
        $superAdminTarget = $this->makeSuperAdmin('hidden-sa@example.com');
        $client = $this->makeClient();
        Sanctum::actingAs($admin->fresh());

        // Not in the list.
        $index = $this->getJson('/api/users?per_page=100')->assertStatus(200);
        $emails = collect($index->json('data'))->pluck('email')->all();
        $this->assertNotContains('hidden-sa@example.com', $emails);
        $this->assertContains($client->email, $emails);

        // Not fetchable by direct id, using this codebase's normal
        // generic-403 semantics — never a distinct message confirming the
        // target is a Super Admin.
        $this->getJson("/api/users/{$superAdminTarget->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);
        $this->getJson("/api/users/{$superAdminTarget->id}/subscription")->assertStatus(403);

        // An ordinary Admin/Client target is still fully visible/fetchable.
        $this->getJson("/api/users/{$client->id}")->assertStatus(200);

        // A genuine Super Admin actor is unaffected.
        $superAdmin = $this->makeSuperAdmin();
        Sanctum::actingAs($superAdmin);
        $indexAsSuperAdmin = $this->getJson('/api/users?per_page=100')->assertStatus(200);
        $this->assertContains('hidden-sa@example.com', collect($indexAsSuperAdmin->json('data'))->pluck('email')->all());
        $this->getJson("/api/users/{$superAdminTarget->id}")->assertStatus(200);
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
