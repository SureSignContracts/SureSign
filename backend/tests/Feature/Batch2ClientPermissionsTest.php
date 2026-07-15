<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 2: Contracts, Contract AI, Trade Packages, Subcontract AI.
 *
 * These modules were already backend-safe (audit Phase 1/2) — standard
 * org-membership authorize() checks, no Client-specific restriction, and
 * Contract::isDeletable() already enforces a workflow-state delete lock
 * equally for every role. The only change needed was the frontend
 * useProjectPermissions() blanket readOnly gate on the Contracts and Trade
 * Package workspace pages. These tests lock in Client's positive access,
 * cross-tenant negative access, and the pre-existing workflow-state guard.
 */
class Batch2ClientPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project for {$org->name}",
            'status'          => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Main Contract',
            'type'            => 'main_contract',
            'status'          => 'draft',
        ], $overrides));
    }

    private function makeTradePackage(Project $project, User $user, array $overrides = []): TradePackage
    {
        static $n = 0;
        $n++;

        return TradePackage::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => "groundworks-{$n}",
            'status'          => 'active',
        ], $overrides));
    }

    // ── Contracts ─────────────────────────────────────────────────────────

    public function test_client_can_create_and_edit_a_contract_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));

        $store = $this->postJson("/api/projects/{$project->id}/contracts", [
            'title' => 'JCT Main Contract',
            'type'  => 'main_contract',
            'contract_file' => $file,
        ]);
        $store->assertStatus(201);
        $contractId = $store->json('id');

        $update = $this->putJson("/api/contracts/{$contractId}", ['title' => 'JCT Main Contract v2']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('contracts', ['id' => $contractId, 'title' => 'JCT Main Contract v2']);
    }

    public function test_client_can_archive_and_restore_their_own_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/contracts/{$contract->id}/archive")->assertStatus(200);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id]);
        $this->assertNotNull($contract->fresh()->archived_at);

        $this->postJson("/api/contracts/{$contract->id}/restore")->assertStatus(200);
        $this->assertNull($contract->fresh()->archived_at);
    }

    public function test_client_can_delete_a_deletable_draft_contract_but_not_an_active_one_with_linked_records(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);

        $draft = $this->makeContract($project, $a['user'], ['status' => 'draft']);
        $active = $this->makeContract($project, $a['user'], ['status' => 'active', 'title' => 'Active Contract']);

        Sanctum::actingAs($a['user']);

        // Workflow-state lock applies identically to every role — not a Client restriction.
        $blocked = $this->deleteJson("/api/contracts/{$active->id}");
        $blocked->assertStatus(422);
        $this->assertDatabaseHas('contracts', ['id' => $active->id]);

        $allowed = $this->deleteJson("/api/contracts/{$draft->id}");
        $allowed->assertStatus(200);
        $this->assertSoftDeleted('contracts', ['id' => $draft->id]);
    }

    public function test_client_cannot_access_or_mutate_another_organisations_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/contracts/{$contractB->id}")->assertStatus(403);
        $this->putJson("/api/contracts/{$contractB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->postJson("/api/contracts/{$contractB->id}/archive")->assertStatus(403);
        $this->deleteJson("/api/contracts/{$contractB->id}")->assertStatus(403);
    }

    // ── Contract AI ───────────────────────────────────────────────────────

    public function test_client_can_view_and_confirm_their_own_contracts_ai_analysis(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);

        $analysis = ContractAiAnalysis::create([
            'contract_id'     => $contract->id,
            'organization_id' => $a['org']->id,
            'project_id'      => $project->id,
            'status'          => 'completed',
            'created_by'      => $a['user']->id,
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/contracts/{$contract->id}/ai-analyses")->assertStatus(200);
        $this->getJson("/api/ai/analyses/{$analysis->id}")->assertStatus(200);

        $confirm = $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => ['key_terms' => []]]);
        $confirm->assertStatus(200);
        $this->assertDatabaseHas('contract_ai_analyses', ['id' => $analysis->id, 'status' => 'confirmed']);
    }

    public function test_client_cannot_access_another_organisations_contract_ai_analysis(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($projectB, $b['user']);

        $analysis = ContractAiAnalysis::create([
            'contract_id'     => $contractB->id,
            'organization_id' => $b['org']->id,
            'project_id'      => $projectB->id,
            'status'          => 'completed',
            'created_by'      => $b['user']->id,
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/contracts/{$contractB->id}/ai-analysis")->assertStatus(403);
        $this->getJson("/api/ai/analyses/{$analysis->id}")->assertStatus(403);
        $this->postJson("/api/ai/analyses/{$analysis->id}/confirm", ['confirmed_data' => []])->assertStatus(403);
        $this->postJson("/api/ai/analyses/{$analysis->id}/cancel")->assertStatus(403);
    }

    // ── Trade Packages ────────────────────────────────────────────────────

    public function test_client_can_view_and_edit_their_own_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/workspace")->assertStatus(200);

        $update = $this->putJson("/api/projects/{$project->id}/trade-packages/{$tp->id}", ['name' => 'Groundworks Revised']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('trade_packages', ['id' => $tp->id, 'name' => 'Groundworks Revised']);
    }

    public function test_client_can_generate_trade_package_folders_for_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/subcontracts/generate-trade-packages", [
            'trade_packages' => [['name' => 'Electrical']],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('trade_packages', ['project_id' => $project->id, 'name' => 'Electrical']);
    }

    public function test_client_can_upload_a_subcontract_file_to_their_own_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $file = UploadedFile::fake()->create('subcontract.pdf', 10, 'application/pdf');
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));

        $response = $this->postJson("/api/trade-packages/{$tp->id}/upload", ['file' => $file]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', ['trade_package_id' => $tp->id, 'organization_id' => $a['org']->id]);
    }

    public function test_client_cannot_access_mutate_or_upload_to_another_organisations_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tpB = $this->makeTradePackage($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/workspace")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}", ['name' => 'Hijacked'])->assertStatus(403);

        $file = UploadedFile::fake()->create('sneaky.pdf', 10, 'application/pdf');
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));
        $this->postJson("/api/trade-packages/{$tpB->id}/upload", ['file' => $file])->assertStatus(403);
    }

    public function test_client_cannot_use_a_valid_trade_package_id_under_a_mismatched_project_id(): void
    {
        // IDOR check: a trade package that legitimately belongs to Org A's
        // project must not become reachable through a different (even if
        // also Org A-owned) project ID in the URL — the child's real parent
        // relationship must be re-validated, not just the org.
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson("/api/projects/{$projectTwo->id}/trade-packages/{$tp->id}/workspace");
        $response->assertStatus(404);
    }

    // ── Subcontract AI ────────────────────────────────────────────────────

    public function test_client_can_view_and_confirm_their_own_trade_packages_ai_analysis(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tp->id,
            'organization_id'  => $a['org']->id,
            'project_id'       => $project->id,
            'status'           => 'completed',
            'created_by'       => $a['user']->id,
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/trade-packages/{$tp->id}/ai-analyses")->assertStatus(200);
        $this->getJson("/api/trade-package-ai-analyses/{$analysis->id}")->assertStatus(200);

        $confirm = $this->postJson("/api/trade-package-ai-analyses/{$analysis->id}/confirm", ['confirmed_data' => ['general' => []]]);
        $confirm->assertStatus(200);
        $this->assertDatabaseHas('trade_package_ai_analyses', ['id' => $analysis->id, 'status' => 'confirmed']);
    }

    public function test_client_cannot_access_another_organisations_trade_package_ai_analysis(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tpB = $this->makeTradePackage($projectB, $b['user']);

        $analysis = TradePackageAiAnalysis::create([
            'trade_package_id' => $tpB->id,
            'organization_id'  => $b['org']->id,
            'project_id'       => $projectB->id,
            'status'           => 'completed',
            'created_by'       => $b['user']->id,
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/trade-packages/{$tpB->id}/ai-analysis")->assertStatus(403);
        $this->getJson("/api/trade-package-ai-analyses/{$analysis->id}")->assertStatus(403);
        $this->postJson("/api/trade-package-ai-analyses/{$analysis->id}/confirm", ['confirmed_data' => []])->assertStatus(403);
    }
}
