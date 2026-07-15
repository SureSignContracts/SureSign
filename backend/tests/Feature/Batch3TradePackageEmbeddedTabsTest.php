<?php

namespace Tests\Feature;

use App\Models\ContractRisk;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3: Trade Package embedded operational tabs (Programme, Delay & EOT,
 * Compliance). Programme/Delay Events/EOT/Loss & Expense are covered by
 * their own dedicated test files — this covers the Compliance tab's two
 * modules (Risks, Delivery Documents), which weren't separately named in
 * the task but are the Compliance tab's actual content.
 *
 * Found and fixed the same parent-mismatch pattern in RiskController
 * (update/destroy, indexByTradePackage/storeForTradePackage) and
 * DeliveryDocumentController (indexByTradePackage/storeForTradePackage/
 * availableDocuments).
 */
class Batch3TradePackageEmbeddedTabsTest extends TestCase
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

    private function makeTradePackage(Project $project, User $user): TradePackage
    {
        static $n = 0;
        $n++;

        return TradePackage::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => "groundworks-tp-{$n}",
            'status'          => 'active',
        ]);
    }

    private function makeRisk(Project $project, array $overrides = []): ContractRisk
    {
        return ContractRisk::create(array_merge([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'title'           => 'Ground contamination risk',
            'severity'        => 'medium',
            'category'        => 'other',
            'status'          => 'open',
        ], $overrides));
    }

    // ── Positive: Client has full CRUD in their own org ──────────────────

    public function test_client_can_manage_risks_at_trade_package_level(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/risks")->assertStatus(200);

        $store = $this->postJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/risks", [
            'title' => 'Late delivery risk', 'severity' => 'high',
        ]);
        $store->assertStatus(201);
        $riskId = $store->json('id');

        $this->putJson("/api/projects/{$project->id}/risks/{$riskId}", ['status' => 'resolved'])->assertStatus(200);
        $this->deleteJson("/api/projects/{$project->id}/risks/{$riskId}")->assertStatus(204);
    }

    public function test_client_can_manage_delivery_documents_at_trade_package_level(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($project, $a['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/delivery-documents")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/delivery-documents/available-documents")->assertStatus(200);

        $store = $this->postJson("/api/projects/{$project->id}/trade-packages/{$tp->id}/delivery-documents", [
            'title' => 'Method Statement — Groundworks', 'category' => 'method_statement',
        ]);
        $store->assertStatus(201);
        $this->assertDatabaseHas('delivery_documents', ['trade_package_id' => $tp->id, 'title' => 'Method Statement — Groundworks']);
    }

    // ── Negative: cross-tenant / mismatched parent ───────────────────────

    public function test_client_cannot_access_another_organisations_trade_package_risks_or_delivery_documents(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $tpB = $this->makeTradePackage($projectB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/risks")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/risks", ['title' => 'Injected'])->assertStatus(403);
        $this->getJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/delivery-documents")->assertStatus(403);
        $this->postJson("/api/projects/{$projectB->id}/trade-packages/{$tpB->id}/delivery-documents", ['title' => 'Injected'])->assertStatus(403);
    }

    public function test_client_cannot_use_a_mismatched_same_organisation_project_id_for_trade_package_risks(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $tp = $this->makeTradePackage($projectOne, $a['user']);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectTwo->id}/trade-packages/{$tp->id}/risks")->assertStatus(404);
        $this->getJson("/api/projects/{$projectTwo->id}/trade-packages/{$tp->id}/delivery-documents")->assertStatus(404);
    }

    public function test_client_cannot_edit_or_delete_another_organisations_risk_using_a_project_scoped_route(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        $riskB = $this->makeRisk($projectB);

        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$projectB->id}/risks/{$riskB->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}/risks/{$riskB->id}")->assertStatus(403);
    }

    public function test_client_cannot_address_a_risk_using_a_mismatched_same_organisation_project_id(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectOne = $this->makeProject($a['org'], $a['user']);
        $projectTwo = $this->makeProject($a['org'], $a['user']);
        $risk = $this->makeRisk($projectOne);

        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$projectTwo->id}/risks/{$risk->id}", ['title' => 'Hijacked'])->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectTwo->id}/risks/{$risk->id}")->assertStatus(404);
    }
}
