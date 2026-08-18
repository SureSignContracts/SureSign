<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\FeatureAvailability;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SureSign Feature Availability, Phase C — backend module enforcement.
 *
 * Covers: shared/cross-cutting areas remaining ungated while a related
 * module is Maintenance (Step 12 A–E), the locked EOT ownership decision
 * (project.delay_eot owns EOT mutation, project.notices alone never blocks
 * it), representative role/tenant behaviour (Step 13), data-integrity
 * (Step 14), and zero AI-credit side effects (Step 15).
 *
 * Strategy: for a request that should be BLOCKED (Maintenance/Coming Soon,
 * non-bypass user), the middleware short-circuits before the controller
 * ever runs — so the request body doesn't need to be a fully valid,
 * successful payload; a 503 with the correct code, and an unchanged
 * underlying row count, are exactly what proves the block. For a request
 * that should PASS THROUGH (Active, or a Super Admin/Admin bypass), "not a
 * 503" is the relevant assertion — the normal controller may still reject
 * the same minimal payload with its own 422/403, which is expected and
 * itself proves the middleware let the request continue to real
 * authorization/validation logic unchanged (Step 11).
 */
class FeatureAvailabilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $name = 'Concrete Specialist Ltd'): Organization
    {
        return Organization::create(['name' => $name, 'slug' => str()->slug($name) . '-' . str()->random(6), 'timezone' => 'Europe/London']);
    }

    private function makeUser(Organization $org, string $role = 'Client'): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        return $user;
    }

    private function makeProject(Organization $org, User $user, array $attrs = []): Project
    {
        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Riverside Apartments',
        ], $attrs));
    }

    private function makeContract(Project $project, User $user, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'Riverside Main Contract',
        ], $attrs));
    }

    private function setMaintenance(string $featureKey): void
    {
        FeatureAvailability::create(['feature_key' => $featureKey, 'status' => 'maintenance']);
    }

    // ── A. project.programme Maintenance — shared reads unaffected ──────

    public function test_programme_maintenance_blocks_mutation_but_overview_and_calendar_reads_still_succeed(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $this->setMaintenance('project.programme');

        // Project Overview / Calendar reads remain fully functional.
        $this->actingAs($user)->getJson("/api/projects/{$project->id}/stats")->assertOk();
        $this->actingAs($user)->getJson("/api/projects/{$project->id}/dashboard-intelligence")->assertOk();
        $this->actingAs($user)->getJson("/api/projects/{$project->id}/calendar-events")->assertOk();

        // Programme milestone reads also remain available (Phase C policy: reads stay ungated).
        $this->actingAs($user)->getJson("/api/contracts/{$contract->id}/programme")->assertOk();

        // Programme mutation is blocked.
        $response = $this->actingAs($user)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Test Milestone']);
        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.programme']);
        $this->assertDatabaseCount('contract_programme_milestones', 0);
    }

    public function test_programme_active_regression_reaches_normal_controller_logic(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        // No override row — Active by default.

        $response = $this->actingAs($user)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Test Milestone']);

        $response->assertStatus(201);
        $this->assertDatabaseCount('contract_programme_milestones', 1);
    }

    // ── B/C. EOT ownership — the locked decision ────────────────────────

    public function test_delay_eot_maintenance_blocks_eot_mutation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.delay_eot');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/eot-requests", ['reason' => 'Weather delay']);

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.delay_eot']);
        $this->assertDatabaseCount('eot_requests', 0);
    }

    public function test_notices_maintenance_alone_does_not_block_eot_mutation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        // project.notices is Maintenance; project.delay_eot is Active (no row).
        $this->setMaintenance('project.notices');

        // The exact same endpoint the Notices page's own EOT tab calls.
        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/eot-requests", ['reason' => 'Weather delay']);

        // Not blocked by Feature Availability — proves the locked ownership
        // rule (project.delay_eot owns this route, never project.notices).
        $this->assertNotEquals(503, $response->getStatusCode());
    }

    public function test_notices_maintenance_blocks_its_own_mutation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.notices');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/pay-less-notices", ['amount_withheld' => '100.00']);

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.notices']);
        $this->assertDatabaseCount('pay_less_notices', 0);
    }

    public function test_notices_read_paths_still_function_while_delay_eot_is_maintenance(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.delay_eot');

        // Notices page's own reads (pay-less-notices list) remain available.
        $this->actingAs($user)->getJson("/api/projects/{$project->id}/pay-less-notices")->assertOk();
        // EOT read path (shared with Calendar/intelligence) also remains available.
        $this->actingAs($user)->getJson("/api/projects/{$project->id}/eot-requests")->assertOk();
    }

    // ── D. project.documents Maintenance — generic download/preview unaffected ──

    public function test_documents_maintenance_blocks_explorer_upload_but_not_generic_download(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.documents');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/files", []);
        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.documents']);

        // Generic download/preview are never gated — a non-existent file
        // upload id here correctly 404s (normal controller behaviour), not
        // 503 (Feature Availability behaviour) — proving the route was
        // never intercepted by the middleware at all.
        $downloadResponse = $this->actingAs($user)->getJson('/api/file-uploads/999999/download');
        $this->assertNotEquals(503, $downloadResponse->getStatusCode());
    }

    /**
     * Closeout correction — the `projects.documents` apiResource
     * (`App\Models\Document`, distinct from the `file_uploads` table used
     * by every other module's attachments). An ordinary org-matching
     * Client can reach store/update/destroy directly
     * (DocumentController::authorizeProject() has no additional role
     * restriction) even though no current frontend page happens to call
     * them — exactly the case Feature Availability exists to cover, so
     * "no frontend caller" was corrected from a reason to skip gating
     * into a reason gating was still required.
     */
    public function test_documents_maintenance_blocks_document_resource_mutation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.documents');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/documents", ['title' => 'Test Document']);

        $response->assertStatus(503);
        $response->assertJson(['code' => 'feature_maintenance', 'feature' => 'project.documents']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_documents_resource_mutation_active_regression_reaches_normal_controller_logic(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        // No override row — Active by default.

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/documents", ['title' => 'Test Document', 'type' => 'other']);

        $response->assertStatus(201);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_super_admin_bypasses_document_resource_mutation(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeUser($org, 'Super Admin');
        $project = $this->makeProject($org, $superAdmin);
        $this->setMaintenance('project.documents');

        $response = $this->actingAs($superAdmin)->postJson("/api/projects/{$project->id}/documents", ['title' => 'Test Document', 'type' => 'other']);

        $this->assertNotEquals(503, $response->getStatusCode());
        $response->assertStatus(201);
    }

    // ── E. organization.reports — no mutation exists; shared reads unaffected ──

    public function test_reports_maintenance_does_not_affect_dashboard_or_project_reads(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('organization.reports');

        $this->actingAs($user)->getJson("/api/projects/{$project->id}/stats")->assertOk();
        $this->actingAs($user)->getJson('/api/reports/summary')->assertOk();
    }

    // ── Role / tenant tests (Step 13) ───────────────────────────────────

    /**
     * Neither `ai.assistant` (no operational backend route exists to gate
     * at all — Step 8) nor `organization.reports` (no mutation route
     * exists — Step 7) has a real gated MUTATION route today, so there is
     * no genuine end-to-end Coming-Soon-blocks-a-mutation case to exercise
     * in Phase C. The Coming Soon code path itself (middleware returning
     * `feature_coming_soon`) is already covered against a throwaway route
     * in FeatureAvailabilityTest's middleware-foundation group (Phase A/B).
     * This test instead documents the finding precisely: none of the
     * routes gated in Phase C sit on a `coming_soon_supported` feature.
     * `organization.team` was later also enabled for Coming Soon — its one
     * nominal mutation (`POST /users/invite`) remains `role:Super Admin`
     * ONLY at the route layer regardless (see routes/api.php), so this
     * finding still holds for it too.
     */
    public function test_no_gated_v1_mutation_route_currently_supports_coming_soon(): void
    {
        $comingSoonCapable = array_filter(
            \App\Support\FeatureAvailability\FeatureAvailabilityRegistry::all(),
            fn (array $entry) => $entry['coming_soon_supported']
        );

        // organization.reports, organization.team, and ai.assistant — none
        // has a gated mutation route (confirmed in Steps 7/8, and for
        // organization.team, in the routes/api.php comment above its
        // Super-Admin-only invite route).
        $this->assertEqualsCanonicalizing(['organization.reports', 'organization.team', 'ai.assistant'], array_keys($comingSoonCapable));
    }

    public function test_admin_bypasses_a_gated_mutation_route(): void
    {
        $org = $this->makeOrg();
        $admin = $this->makeUser($org, 'Admin');
        $project = $this->makeProject($org, $admin);
        $contract = $this->makeContract($project, $admin);
        $this->setMaintenance('project.programme');

        $response = $this->actingAs($admin)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Admin Bypass Test']);

        $this->assertNotEquals(503, $response->getStatusCode());
        $response->assertStatus(201);
    }

    public function test_super_admin_bypasses_a_gated_mutation_route(): void
    {
        $org = $this->makeOrg();
        $superAdmin = $this->makeUser($org, 'Super Admin');
        $project = $this->makeProject($org, $superAdmin);
        $contract = $this->makeContract($project, $superAdmin);
        $this->setMaintenance('project.programme');

        $response = $this->actingAs($superAdmin)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Super Admin Bypass Test']);

        $this->assertNotEquals(503, $response->getStatusCode());
        $response->assertStatus(201);
    }

    public function test_unauthenticated_request_still_gets_401_not_503(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $this->setMaintenance('project.programme');

        $response = $this->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Test']);

        // auth:sanctum runs before feature.available in the middleware
        // stack — authentication remains authoritative, never masked by
        // a 503.
        $response->assertUnauthorized();
    }

    public function test_wrong_tenant_authorization_remains_authoritative_when_feature_is_active(): void
    {
        $orgA = $this->makeOrg('Org A Ltd');
        $orgB = $this->makeOrg('Org B Ltd');
        $ownerA = $this->makeUser($orgA, 'Client');
        $intruder = $this->makeUser($orgB, 'Client');
        $project = $this->makeProject($orgA, $ownerA);
        $contract = $this->makeContract($project, $ownerA);
        // project.programme left Active — proves tenant authorization,
        // not Feature Availability, is what rejects this request.

        $response = $this->actingAs($intruder)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Cross-tenant attempt']);

        $response->assertForbidden();
        $this->assertDatabaseCount('contract_programme_milestones', 0);
    }

    // ── Data integrity (Step 14) — representative examples ──────────────

    public function test_blocked_variation_mutation_does_not_create_a_record(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $this->setMaintenance('project.variations');

        $response = $this->actingAs($user)->postJson("/api/contracts/{$contract->id}/variations", ['title' => 'Extra groundworks']);

        $response->assertStatus(503);
        $this->assertDatabaseCount('variations', 0);
    }

    public function test_blocked_risk_mutation_does_not_create_a_record(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $this->setMaintenance('project.risks');

        $response = $this->actingAs($user)->postJson("/api/projects/{$project->id}/risks", ['title' => 'Ground contamination risk']);

        $response->assertStatus(503);
        $this->assertDatabaseCount('contract_risks', 0);
    }

    public function test_blocked_organization_team_invite_is_a_non_issue_role_gate_already_prevents_client(): void
    {
        // organization.team's only mutation (POST /users/invite) is already
        // role:Super Admin only at the route layer — a Client can never
        // reach it regardless of Feature Availability (see routes/api.php's
        // own comment). Confirms this remains true and unaffected by
        // Phase C — the 403 here comes from role middleware, not
        // Feature Availability, and no feature_availabilities row is
        // involved at all.
        $org = $this->makeOrg();
        $client = $this->makeUser($org, 'Client');

        $response = $this->actingAs($client)->postJson('/api/users/invite', ['email' => 'new@example.com', 'role' => 'Client']);

        $response->assertForbidden();
    }

    // ── AI credit non-interference (Step 15) ────────────────────────────

    public function test_gated_mutation_blocking_never_touches_ai_credit_tables(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org, 'Client');
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $this->setMaintenance('project.programme');

        $this->actingAs($user)->postJson("/api/contracts/{$contract->id}/programme", ['name' => 'Test'])->assertStatus(503);

        $this->assertDatabaseCount('ai_credit_ledger_entries', 0);
        $this->assertDatabaseCount('ai_credit_simulation_results', 0);
    }
}
