<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase A — Project Organization Role foundation.
 *
 * Covers only projects.organization_role: nullable, canonical-value
 * validation, create/update semantics (including the omit-vs-null-clear
 * contract mirrored from the existing `currency` field), Super Admin
 * create-on-behalf, and that this value has no effect on Organization
 * identity, SureSign user roles, or authorization. See
 * App\Support\Projects\ProjectOrganizationRole.
 */
class ProjectOrganizationRoleTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $org = Organization::create(['name' => 'Concrete Specialist Ltd', 'slug' => 'concrete-specialist', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($user);
        return $user;
    }

    // ── Create: valid canonical values ──────────────────────────────────────

    public function test_project_can_be_created_with_main_contractor_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Riverside Apartments', 'organization_role' => 'main_contractor']);

        $response->assertStatus(201);
        $this->assertEquals('main_contractor', $response->json('organization_role'));
    }

    public function test_project_can_be_created_with_subcontractor_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Steel Package', 'organization_role' => 'subcontractor']);

        $response->assertStatus(201);
        $this->assertEquals('subcontractor', $response->json('organization_role'));
    }

    public function test_project_can_be_created_with_employer_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'New Development', 'organization_role' => 'employer']);

        $response->assertStatus(201);
        $this->assertEquals('employer', $response->json('organization_role'));
    }

    public function test_project_can_be_created_with_consultant_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'QS Appointment', 'organization_role' => 'consultant']);

        $response->assertStatus(201);
        $this->assertEquals('consultant', $response->json('organization_role'));
    }

    public function test_project_can_be_created_with_other_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Unusual Arrangement', 'organization_role' => 'other']);

        $response->assertStatus(201);
        $this->assertEquals('other', $response->json('organization_role'));
    }

    // ── Create: absent / null ────────────────────────────────────────────────

    public function test_project_can_be_created_with_role_absent(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'No Role Field At All']);

        $response->assertStatus(201);
        $this->assertNull($response->json('organization_role'));
    }

    public function test_project_can_be_created_with_role_explicitly_null(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Explicit Null Role', 'organization_role' => null]);

        $response->assertStatus(201);
        $this->assertNull($response->json('organization_role'));
    }

    // ── Invalid values ───────────────────────────────────────────────────────

    public function test_invalid_role_is_rejected_on_create(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Bad Role', 'organization_role' => 'client']);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_role');
    }

    public function test_arbitrary_string_role_is_rejected_on_create(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Bad Role', 'organization_role' => 'banana']);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_role');
    }

    public function test_general_contractor_synonym_is_rejected(): void
    {
        // Canonical value is main_contractor — a near-miss synonym must not
        // silently be accepted as a second spelling of the same thing.
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Bad Role', 'organization_role' => 'general_contractor']);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_role');
    }

    // ── Update semantics ─────────────────────────────────────────────────────

    public function test_role_can_be_updated_from_subcontractor_to_main_contractor(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id,
            'name' => 'Changing Perspective', 'organization_role' => 'subcontractor',
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['organization_role' => 'main_contractor']);

        $response->assertStatus(200);
        $this->assertEquals('main_contractor', $project->fresh()->organization_role);
    }

    public function test_role_can_be_cleared_to_null_via_explicit_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id,
            'name' => 'Clearing Role', 'organization_role' => 'consultant',
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['organization_role' => null]);

        $response->assertStatus(200);
        $this->assertNull($project->fresh()->organization_role);
    }

    public function test_omitted_role_on_update_leaves_existing_value_untouched(): void
    {
        // Mirrors the existing `currency` precedent in ProjectController::update() —
        // omitting the key entirely must never reset it.
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id,
            'name' => 'Untouched Role', 'organization_role' => 'employer',
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['name' => 'Renamed Only']);

        $response->assertStatus(200);
        $this->assertEquals('employer', $project->fresh()->organization_role);
    }

    public function test_null_role_stays_null_when_omitted_on_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id,
            'name' => 'Already Null', 'organization_role' => null,
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['name' => 'Still Null']);

        $response->assertStatus(200);
        $this->assertNull($project->fresh()->organization_role);
    }

    public function test_invalid_role_is_rejected_on_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Bad Update']);

        $response = $this->putJson("/api/projects/{$project->id}", ['organization_role' => 'developer']);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_role');
    }

    // ── Legacy Projects ──────────────────────────────────────────────────────

    public function test_legacy_project_created_without_migration_awareness_loads_with_null_role(): void
    {
        $user = $this->actingUser();
        // Simulates a pre-existing Project row — no organization_role passed,
        // never fabricated.
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Legacy Project']);

        $response = $this->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $this->assertNull($response->json('organization_role'));
    }

    // ── The boss use case ────────────────────────────────────────────────────

    public function test_same_organization_can_hold_projects_with_different_roles(): void
    {
        $user = $this->actingUser();

        $projectA = $this->postJson('/api/projects', ['name' => 'Project A', 'organization_role' => 'main_contractor'])->json();
        $projectB = $this->postJson('/api/projects', ['name' => 'Project B', 'organization_role' => 'subcontractor'])->json();

        $this->assertEquals('main_contractor', $projectA['organization_role']);
        $this->assertEquals('subcontractor', $projectB['organization_role']);
        $this->assertEquals($projectA['organization_id'], $projectB['organization_id']);

        // Organization row itself is completely unaffected.
        $org = Organization::find($user->organization_id);
        $this->assertArrayNotHasKey('organization_role', $org->getAttributes());
        $this->assertArrayNotHasKey('type', $org->getAttributes());

        // The user's SureSign role is unaffected.
        $this->assertTrue($user->fresh()->hasRole('Client'));
        $this->assertFalse($user->fresh()->hasRole('Admin'));
    }

    // ── Permission separation ────────────────────────────────────────────────

    public function test_client_role_user_can_set_any_valid_organization_role(): void
    {
        // organization_role must never gate on / be gated by the SureSign
        // user role — a plain Client-role user can set it freely.
        $this->actingUser();

        foreach (['main_contractor', 'subcontractor', 'employer', 'consultant', 'other'] as $role) {
            $response = $this->postJson('/api/projects', ['name' => "Project {$role}", 'organization_role' => $role]);
            $response->assertStatus(201);
        }
    }

    // ── Super Admin create-on-behalf ────────────────────────────────────────

    public function test_super_admin_create_on_behalf_supports_organization_role(): void
    {
        $targetOrg = Organization::create(['name' => 'Customer Org', 'slug' => 'customer-org', 'timezone' => 'Europe/London']);

        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/companies/{$targetOrg->id}/projects", [
            'name' => 'Admin-Created Project',
            'organization_role' => 'main_contractor',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('main_contractor', $response->json('organization_role'));
        $this->assertEquals($targetOrg->id, $response->json('organization_id'));
    }

    public function test_super_admin_create_on_behalf_rejects_invalid_role(): void
    {
        $targetOrg = Organization::create(['name' => 'Customer Org 2', 'slug' => 'customer-org-2', 'timezone' => 'Europe/London']);

        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/admin/companies/{$targetOrg->id}/projects", [
            'name' => 'Admin-Created Project',
            'organization_role' => 'client',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('organization_role');
    }

    // ── Existing create-without-the-field behaviour is unchanged ────────────

    public function test_existing_project_create_flow_without_the_field_is_unaffected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Ordinary Project', 'type' => 'new_build', 'contract_type' => 'JCT',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('new_build', $response->json('type'));
        $this->assertEquals('JCT', $response->json('contract_type'));
        $this->assertNull($response->json('organization_role'));
    }
}
