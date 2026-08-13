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
 * Phase B — Project Basics + Deferred Details Safety.
 *
 * Confirms the backend contract this phase relies on rather than changes:
 * Create Project was trimmed on the frontend to name/code/type/
 * organization_role only, but ProjectController::store()/update() were not
 * modified — every field the trimmed Create form no longer sends already
 * had a safe null/default, and every field moved into EditProjectModal
 * (description, status, contract_type, contract_value, currency,
 * start_date, end_date) was already accepted by update() before this
 * phase. These tests exist to prove that contract explicitly, end-to-end,
 * rather than leave it implicit.
 *
 * Deliberately out of scope, per the same phase: retention_cap_percentage
 * and client_id — neither was ever exposed in Create Project's UI, so
 * neither is a "removed" capability this phase needs to preserve. See the
 * Phase B final report for the full reasoning.
 */
class ProjectBasicsAndDeferredEditingTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $org = Organization::create(['name' => 'Basics Org', 'slug' => 'basics-org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($user);
        return $user;
    }

    // ── Creation with only the Project Basics fields ────────────────────────

    public function test_project_can_be_created_with_only_the_required_minimum(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Minimum Project']);

        $response->assertStatus(201);
        $this->assertEquals('Minimum Project', $response->json('name'));
    }

    public function test_project_can_be_created_with_name_and_code(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Coded Project', 'code' => 'PRJ-001']);

        $response->assertStatus(201);
        $this->assertEquals('PRJ-001', $response->json('code'));
    }

    public function test_project_can_be_created_with_works_type(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'New Build Project', 'type' => 'New Build']);

        $response->assertStatus(201);
        $this->assertEquals('New Build', $response->json('type'));
    }

    public function test_project_can_be_created_with_organization_role(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Role Project', 'organization_role' => 'main_contractor']);

        $response->assertStatus(201);
        $this->assertEquals('main_contractor', $response->json('organization_role'));
    }

    /**
     * The exact Project Basics shape the trimmed CreateProjectModal now
     * sends — proves all four fields survive together, not just in
     * isolation.
     */
    public function test_project_can_be_created_with_the_full_project_basics_shape(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Riverside Apartments', 'code' => 'RIV-01',
            'type' => 'New Build', 'organization_role' => 'main_contractor',
        ]);

        $response->assertStatus(201);
        $this->assertEquals('Riverside Apartments', $response->json('name'));
        $this->assertEquals('RIV-01', $response->json('code'));
        $this->assertEquals('New Build', $response->json('type'));
        $this->assertEquals('main_contractor', $response->json('organization_role'));
    }

    // ── Deferred fields default safely when Create no longer sends them ────

    public function test_deferred_fields_are_null_or_default_when_absent_from_create(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Deferred Everything']);

        $response->assertStatus(201);
        $this->assertNull($response->json('description'));
        $this->assertEquals('active', $response->json('status')); // safe existing default, not a Phase B change
        $this->assertNull($response->json('contract_type'));
        $this->assertNull($response->json('contract_value'));
        $this->assertNull($response->json('start_date'));
        $this->assertNull($response->json('end_date'));
        $this->assertNull($response->json('address'));
        $this->assertNull($response->json('client_id'));
    }

    // ── Every field deferred to Edit Project can actually be edited ────────

    public function test_description_can_be_set_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Desc Project']);

        $response = $this->putJson("/api/projects/{$project->id}", ['description' => 'A short overview.']);

        $response->assertStatus(200);
        $this->assertEquals('A short overview.', $project->fresh()->description);
    }

    public function test_status_can_be_changed_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Status Project']);

        $response = $this->putJson("/api/projects/{$project->id}", ['status' => 'on_hold']);

        $response->assertStatus(200);
        $this->assertEquals('on_hold', $project->fresh()->status);
    }

    public function test_invalid_status_is_rejected_on_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Bad Status']);

        $response = $this->putJson("/api/projects/{$project->id}", ['status' => 'archived']);

        $response->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_contract_type_can_be_set_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Contract Type Project']);

        $response = $this->putJson("/api/projects/{$project->id}", ['contract_type' => 'NEC4']);

        $response->assertStatus(200);
        $this->assertEquals('NEC4', $project->fresh()->contract_type);
    }

    public function test_contract_value_can_be_set_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Value Project']);

        $response = $this->putJson("/api/projects/{$project->id}", ['contract_value' => 5250000]);

        $response->assertStatus(200);
        $this->assertEquals(5250000, (float) $project->fresh()->contract_value);
    }

    public function test_currency_can_be_set_and_cleared_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Currency Project']);

        $set = $this->putJson("/api/projects/{$project->id}", ['currency' => 'usd']);
        $set->assertStatus(200);
        $this->assertEquals('USD', $project->fresh()->currency);

        $cleared = $this->putJson("/api/projects/{$project->id}", ['currency' => null]);
        $cleared->assertStatus(200);
        $this->assertNull($project->fresh()->currency);
    }

    public function test_start_and_end_dates_can_be_set_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Dates Project']);

        $response = $this->putJson("/api/projects/{$project->id}", [
            'start_date' => '2026-09-01', 'end_date' => '2027-03-01',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('2026-09-01', $project->fresh()->start_date->toDateString());
        $this->assertEquals('2027-03-01', $project->fresh()->end_date->toDateString());
    }

    public function test_updating_one_deferred_field_preserves_the_others(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Preserved Project',
            'description' => 'Original description', 'status' => 'on_hold', 'contract_type' => 'JCT',
            'contract_value' => 1000000, 'organization_role' => 'subcontractor',
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['contract_value' => 1250000]);

        $response->assertStatus(200);
        $fresh = $project->fresh();
        $this->assertEquals(1250000, (float) $fresh->contract_value);
        $this->assertEquals('Original description', $fresh->description);
        $this->assertEquals('on_hold', $fresh->status);
        $this->assertEquals('JCT', $fresh->contract_type);
        $this->assertEquals('subcontractor', $fresh->organization_role);
    }

    // ── retention_cap_percentage / client_id explicitly untouched ──────────

    public function test_retention_cap_percentage_remains_creatable_via_api_though_not_in_the_ui(): void
    {
        // Never exposed in Create Project's UI before or after Phase B — this
        // proves the underlying store() capability itself was not touched.
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Retention Cap Project', 'retention_cap_percentage' => 5]);

        $response->assertStatus(201);
        $this->assertEquals(5, (float) $response->json('retention_cap_percentage'));
    }

    public function test_client_id_behaviour_is_unchanged_by_phase_b(): void
    {
        // No UI exists for this in either modal, before or after — confirms
        // the update endpoint still simply ignores an unrecognised key
        // rather than erroring, matching pre-Phase-B behaviour.
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Client Id Project']);

        $response = $this->putJson("/api/projects/{$project->id}", ['name' => 'Client Id Project Renamed', 'client_id' => 999]);

        $response->assertStatus(200);
        $this->assertNull($project->fresh()->client_id);
    }

    // ── Boss scenario, end-to-end through the new minimal shape ─────────────

    public function test_boss_scenario_create_then_defer_then_edit(): void
    {
        $user = $this->actingUser();

        $projectA = $this->postJson('/api/projects', ['name' => 'Project A', 'organization_role' => 'main_contractor'])->json();
        $projectB = $this->postJson('/api/projects', ['name' => 'Project B', 'organization_role' => 'subcontractor'])->json();

        // Both created with the minimal Basics shape only.
        $this->assertEquals('main_contractor', $projectA['organization_role']);
        $this->assertEquals('subcontractor', $projectB['organization_role']);

        // Each can independently receive deferred details afterward.
        $this->putJson("/api/projects/{$projectA['id']}", ['contract_type' => 'JCT', 'contract_value' => 5000000])->assertStatus(200);
        $this->putJson("/api/projects/{$projectB['id']}", ['contract_type' => 'NEC4', 'contract_value' => 750000])->assertStatus(200);

        $freshA = Project::find($projectA['id']);
        $freshB = Project::find($projectB['id']);
        $this->assertEquals('JCT', $freshA->contract_type);
        $this->assertEquals('NEC4', $freshB->contract_type);
        $this->assertEquals('main_contractor', $freshA->organization_role);
        $this->assertEquals('subcontractor', $freshB->organization_role);

        // Organisation and user role remain exactly as before.
        $this->assertEquals($freshA->organization_id, $freshB->organization_id);
        $this->assertTrue($user->fresh()->hasRole('Client'));
    }
}
