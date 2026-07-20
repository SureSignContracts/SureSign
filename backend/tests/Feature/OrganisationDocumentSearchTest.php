<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\TradePackage;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Documents Phase 4 — GET /documents/portfolio.
 *
 * Covers: tenant isolation, search across both the `documents` and
 * `file_uploads` tables, filters, sorting, pagination, generated/uploaded/
 * AI-generated distinction, WorkspaceNavigationResolver-backed source
 * navigation via DocumentSourceMapper, and exclusion of Help Centre
 * (support ticket) attachments.
 */
class OrganisationDocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $label): Organization
    {
        static $n = 0;
        $n++;

        return Organization::create([
            'name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}", 'timezone' => 'Europe/London',
        ]);
    }

    private function makeProject(Organization $org, User $user, array $overrides = []): Project
    {
        static $n = 0;
        $n++;

        return Project::create(array_merge([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project {$n}", 'status' => 'active', 'currency' => 'GBP',
        ], $overrides));
    }

    private function makeContract(Project $project, User $user): \App\Models\Contract
    {
        return \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'title' => 'Main Contract', 'type' => 'main_contract',
            'status' => 'active', 'retention_percentage' => 5,
        ]);
    }

    private function makeDocument(Project $project, User $user, array $overrides = []): Document
    {
        static $n = 0;
        $n++;

        return Document::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $user->id,
            'title' => "Document {$n}", 'type' => 'other', 'file_name' => "document-{$n}.pdf",
            'mime_type' => 'application/pdf', 'file_size' => 1024,
        ], $overrides));
    }

    private function makeFileUpload(Project $project, User $user, array $overrides = []): FileUpload
    {
        static $n = 0;
        $n++;

        return FileUpload::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'uploaded_by' => $user->id,
            'original_name' => "upload-{$n}.pdf", 'stored_name' => "stored-{$n}.pdf",
            'file_path' => "projects/{$project->id}/uploads/stored-{$n}.pdf",
            'mime_type' => 'application/pdf', 'file_size' => 2048,
        ], $overrides));
    }

    // ── Tenant isolation ──────────────────────────────────────────────────

    public function test_client_only_sees_their_own_organisations_documents(): void
    {
        $orgA = $this->makeOrg('a');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $projectA = $this->makeProject($orgA, $userA);
        $this->makeDocument($projectA, $userA, ['title' => 'Alpha Document']);

        $orgB = $this->makeOrg('b');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        $projectB = $this->makeProject($orgB, $userB);
        $this->makeDocument($projectB, $userB, ['title' => 'Secret Beta Document']);

        Sanctum::actingAs($userA);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $titles = collect($response->json('documents.data'))->pluck('title')->all();
        $this->assertContains('Alpha Document', $titles);
        $this->assertNotContains('Secret Beta Document', $titles);
        $this->assertEquals(1, $response->json('summary.total_documents'));
    }

    // ── Cross-table search ────────────────────────────────────────────────

    public function test_search_covers_both_documents_and_file_uploads(): void
    {
        $org = $this->makeOrg('search');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeDocument($project, $user, ['title' => 'Station Variation Order']);
        $this->makeFileUpload($project, $user, ['original_name' => 'station-drawing.pdf']);
        $this->makeFileUpload($project, $user, ['original_name' => 'unrelated-file.pdf']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio?search=station')->assertStatus(200);

        $this->assertCount(2, $response->json('documents.data'));
        $sources = collect($response->json('documents.data'))->pluck('source')->all();
        $this->assertContains('document', $sources);
        $this->assertContains('file_upload', $sources);
    }

    // ── Generated vs uploaded vs AI-generated ──────────────────────────────

    public function test_generated_uploaded_and_ai_generated_are_distinguished(): void
    {
        $org = $this->makeOrg('origin');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $contract = $this->makeContract($project, $user);
        $variation = Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V1', 'title' => 'Groundworks variation', 'status' => 'draft',
        ]);

        $this->makeDocument($project, $user, ['title' => 'Manual upload', 'documentable_type' => null, 'documentable_id' => null]);
        $this->makeDocument($project, $user, [
            'title' => 'Variation Order', 'documentable_type' => Variation::class, 'documentable_id' => $variation->id,
        ]);
        $this->makeDocument($project, $user, ['title' => 'AI Extracted Doc', 'ai_generated' => true]);
        $this->makeFileUpload($project, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $summary = $response->json('summary');
        $this->assertEquals(4, $summary['total_documents']);
        $this->assertEquals(1, $summary['generated']);
        $this->assertEquals(1, $summary['ai_generated']);
        // uploaded = manual document + AI doc (still origin=uploaded, no documentable_type) + file upload = 3
        $this->assertEquals(3, $summary['uploaded']);
    }

    // ── Filters ────────────────────────────────────────────────────────────

    public function test_origin_and_project_filters_work(): void
    {
        $org = $this->makeOrg('filter');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $projectA = $this->makeProject($org, $user, ['name' => 'Filter Project A']);
        $projectB = $this->makeProject($org, $user, ['name' => 'Filter Project B']);

        $contract = $this->makeContract($projectA, $user);
        $variation = Variation::create([
            'project_id' => $projectA->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V1', 'title' => 'V', 'status' => 'draft',
        ]);
        $this->makeDocument($projectA, $user, ['documentable_type' => Variation::class, 'documentable_id' => $variation->id]);
        $this->makeDocument($projectB, $user);

        Sanctum::actingAs($user);

        $generated = $this->getJson('/api/documents/portfolio?origin=generated')->assertStatus(200);
        $this->assertCount(1, $generated->json('documents.data'));

        $byProject = $this->getJson("/api/documents/portfolio?project_id={$projectB->id}")->assertStatus(200);
        $this->assertCount(1, $byProject->json('documents.data'));
        $this->assertEquals($projectB->id, $byProject->json('documents.data.0.project_id'));
    }

    // ── Sorting ────────────────────────────────────────────────────────────

    public function test_filename_sort_is_deterministic(): void
    {
        $org = $this->makeOrg('sort');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeDocument($project, $user, ['file_name' => 'zebra.pdf']);
        $this->makeDocument($project, $user, ['file_name' => 'aardvark.pdf']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio?sort=filename')->assertStatus(200);

        $filenames = collect($response->json('documents.data'))->pluck('filename')->all();
        $this->assertEquals(['aardvark.pdf', 'zebra.pdf'], $filenames);
    }

    // ── Navigation (DocumentSourceMapper / WorkspaceNavigationResolver) ────

    public function test_variation_document_resolves_navigation_url(): void
    {
        $org = $this->makeOrg('nav');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $contract = $this->makeContract($project, $user);
        $variation = Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'variation_number' => 'V1', 'title' => 'V', 'status' => 'draft',
        ]);
        $this->makeDocument($project, $user, ['documentable_type' => Variation::class, 'documentable_id' => $variation->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $row = $response->json('documents.data.0');
        $this->assertEquals("/app/projects/{$project->id}/variations", $row['action_url']);
        $this->assertEquals("/documents/{$row['id']}/preview", $row['preview_url']);
        $this->assertEquals("/documents/{$row['id']}/download", $row['download_url']);
    }

    public function test_trade_package_file_upload_resolves_navigation_url(): void
    {
        $org = $this->makeOrg('tpnav');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $tradePackage = TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => 'Groundworks Package', 'slug' => 'groundworks-package', 'package_reference' => 'TP-01',
        ]);
        $this->makeFileUpload($project, $user, ['trade_package_id' => $tradePackage->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $row = $response->json('documents.data.0');
        $this->assertEquals("/app/projects/{$project->id}/subcontracts/{$tradePackage->id}?tab=overview", $row['action_url']);
        $this->assertEquals('Groundworks Package', $row['trade_package']);
    }

    public function test_contract_owned_document_has_no_navigation_url_but_remains_usable(): void
    {
        $org = $this->makeOrg('contractnav');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active', 'retention_percentage' => 5,
        ]);
        $this->makeDocument($project, $user, ['documentable_type' => \App\Models\Contract::class, 'documentable_id' => $contract->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $row = $response->json('documents.data.0');
        $this->assertNull($row['action_url']);
        $this->assertNotNull($row['preview_url']);
        $this->assertNotNull($row['download_url']);
    }

    // ── Support ticket attachments excluded ────────────────────────────────

    public function test_support_ticket_attachments_are_excluded(): void
    {
        $org = $this->makeOrg('support');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);

        $ticket = SupportTicket::create([
            'user_id' => $user->id, 'organization_id' => $org->id,
            'reference' => 'SUP-1', 'subject' => 'Issue', 'message' => 'Help',
            'category' => 'other', 'priority' => 'normal', 'status' => 'open',
        ]);
        $this->makeFileUpload($project, $user, ['attachable_type' => SupportTicket::class, 'attachable_id' => $ticket->id]);
        $this->makeFileUpload($project, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $this->assertCount(1, $response->json('documents.data'));
    }

    // ── Pagination ─────────────────────────────────────────────────────────

    public function test_pagination_metadata_is_correct(): void
    {
        $org = $this->makeOrg('page');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        for ($i = 0; $i < 5; $i++) {
            $this->makeDocument($project, $user);
        }

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio?per_page=2&page=2')->assertStatus(200);

        $pagination = $response->json('documents.pagination');
        $this->assertEquals(2, $pagination['current_page']);
        $this->assertEquals(5, $pagination['total']);
        $this->assertEquals(3, $pagination['last_page']);
        $this->assertCount(2, $response->json('documents.data'));
    }

    // ── Empty states ─────────────────────────────────────────────────────

    public function test_no_documents_is_handled(): void
    {
        $org = $this->makeOrg('empty');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio')->assertStatus(200);

        $this->assertEquals(0, $response->json('summary.total_documents'));
        $this->assertCount(0, $response->json('documents.data'));
    }

    public function test_no_filter_results_returns_empty_data_not_error(): void
    {
        $org = $this->makeOrg('nofilter');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = $this->makeProject($org, $user);
        $this->makeDocument($project, $user, ['title' => 'Only Document']);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/documents/portfolio?search=nonexistent')->assertStatus(200);

        $this->assertCount(0, $response->json('documents.data'));
        $this->assertEquals(1, $response->json('summary.total_documents'));
    }
}
