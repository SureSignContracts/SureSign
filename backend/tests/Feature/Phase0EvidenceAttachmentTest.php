<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 0 — Evidence Attachment Foundation for Snag, RFI, and QA Reports.
 *
 * Covers the shared `RecordAttachmentService` via all three controllers:
 * upload, list (scoped to the exact record), delete (with nested-resource
 * ownership validation), tenant isolation, and that pre-existing
 * `attachable_type` consumers (Contract) are unaffected.
 */
class Phase0EvidenceAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return [$org, $user];
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    private function makeSnag(Project $project, User $user): Snag
    {
        return Snag::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'snag_number' => 1, 'title' => 'Cracked tile', 'status' => 'open', 'priority' => 'medium',
        ]);
    }

    private function makeRfi(Project $project, User $user): Rfi
    {
        return Rfi::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'rfi_number' => 1, 'subject' => 'Clarify beam detail', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);
    }

    private function makeQaReport(Project $project, User $user): QaReport
    {
        return QaReport::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'report_number' => 1, 'title' => 'Slab pour inspection', 'status' => 'draft',
        ]);
    }

    /**
     * GD isn't available in this environment (`UploadedFile::fake()->image()`
     * throws), and FileSecurityService::assertSafe() enforces real magic
     * bytes — a bare `fake()->create()` file has neither. Mirrors the exact
     * pattern already used by Batch2ClientPermissionsTest for the same
     * reason: create the fake, then overwrite its content with a real
     * signature before it's uploaded.
     */
    private function fakePng(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    private function fakePdf(string $name = 'evidence.pdf'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'application/pdf');
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));
        return $file;
    }

    // ── Snag ──────────────────────────────────────────────────────────────

    public function test_authorized_user_can_upload_evidence_to_a_snag(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('snag');
        $project = $this->makeProject($org, $user);
        $snag = $this->makeSnag($project, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng()]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', [
            'attachable_type' => Snag::class, 'attachable_id' => $snag->id,
            'project_id' => $project->id, 'organization_id' => $org->id,
        ]);
    }

    public function test_multiple_evidence_files_can_exist_on_one_snag(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('snag-multi');
        $project = $this->makeProject($org, $user);
        $snag = $this->makeSnag($project, $user);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng('a.png')])->assertStatus(201);
        $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePdf('b.pdf')])->assertStatus(201);

        $response = $this->getJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments");
        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_another_snag_cannot_access_the_first_snags_evidence(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('snag-cross');
        $project = $this->makeProject($org, $user);
        $snagA = $this->makeSnag($project, $user);
        $snagB = Snag::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id,
            'snag_number' => 2, 'title' => 'Different defect', 'status' => 'open', 'priority' => 'low',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/snagging/{$snagA->id}/attachments", ['file' => $this->fakePng()])->assertStatus(201);

        $response = $this->getJson("/api/projects/{$project->id}/snagging/{$snagB->id}/attachments");
        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    public function test_another_project_cannot_access_the_snags_evidence(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndUser('snag-proj-a');
        $projectA = $this->makeProject($orgA, $userA);
        $snag = $this->makeSnag($projectA, $userA);
        Sanctum::actingAs($userA);
        $upload = $this->postJson("/api/projects/{$projectA->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng()])->json();

        [$orgB, $userB] = $this->makeOrgAndUser('snag-proj-b');
        $projectB = $this->makeProject($orgB, $userB);
        $snagB = $this->makeSnag($projectB, $userB);
        Sanctum::actingAs($userB);

        // Cross-tenant nested mismatch: userB's own project/snag, but the
        // attachment id actually belongs to org A's snag entirely.
        $response = $this->deleteJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments/{$upload['id']}");
        $response->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]); // not deleted
    }

    public function test_unauthorized_user_cannot_upload_to_another_organisations_snag(): void
    {
        Storage::fake('local');
        [$org, $owner] = $this->makeOrgAndUser('snag-unauth');
        $project = $this->makeProject($org, $owner);
        $snag = $this->makeSnag($project, $owner);

        [, $intruder] = $this->makeOrgAndUser('snag-intruder');
        Sanctum::actingAs($intruder);

        $response = $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng()]);
        $response->assertStatus(403);
    }

    public function test_evidence_attachment_can_be_deleted(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('snag-delete');
        $project = $this->makeProject($org, $user);
        $snag = $this->makeSnag($project, $user);
        Sanctum::actingAs($user);

        $upload = $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng()])->json();

        $response = $this->deleteJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments/{$upload['id']}");
        $response->assertStatus(204);
        $this->assertDatabaseMissing('file_uploads', ['id' => $upload['id']]);
    }

    public function test_deleting_one_attachment_does_not_affect_a_sibling_attachment(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('snag-sibling');
        $project = $this->makeProject($org, $user);
        $snag = $this->makeSnag($project, $user);
        Sanctum::actingAs($user);

        $first = $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng('a.png')])->json();
        $second = $this->postJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments", ['file' => $this->fakePng('b.png')])->json();

        $this->deleteJson("/api/projects/{$project->id}/snagging/{$snag->id}/attachments/{$first['id']}")->assertStatus(204);

        $this->assertDatabaseMissing('file_uploads', ['id' => $first['id']]);
        $this->assertDatabaseHas('file_uploads', ['id' => $second['id']]);
    }

    // ── RFI ───────────────────────────────────────────────────────────────

    public function test_authorized_user_can_upload_evidence_to_an_rfi(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('rfi');
        $project = $this->makeProject($org, $user);
        $rfi = $this->makeRfi($project, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/rfis/{$rfi->id}/attachments", ['file' => $this->fakePdf('sketch.pdf')]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => Rfi::class, 'attachable_id' => $rfi->id]);
    }

    public function test_another_rfi_cannot_access_the_first_rfis_evidence(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('rfi-cross');
        $project = $this->makeProject($org, $user);
        $rfiA = $this->makeRfi($project, $user);
        $rfiB = Rfi::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id,
            'rfi_number' => 2, 'subject' => 'Different query', 'status' => 'open', 'raised_date' => now()->toDateString(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/rfis/{$rfiA->id}/attachments", ['file' => $this->fakePdf('a.pdf')])->assertStatus(201);

        $response = $this->getJson("/api/rfis/{$rfiB->id}/attachments");
        $this->assertCount(0, $response->json());
    }

    public function test_unauthorized_user_cannot_delete_an_rfi_attachment(): void
    {
        Storage::fake('local');
        [$org, $owner] = $this->makeOrgAndUser('rfi-unauth');
        $project = $this->makeProject($org, $owner);
        $rfi = $this->makeRfi($project, $owner);
        Sanctum::actingAs($owner);
        $upload = $this->postJson("/api/rfis/{$rfi->id}/attachments", ['file' => $this->fakePdf('a.pdf')])->json();

        [, $intruder] = $this->makeOrgAndUser('rfi-intruder');
        Sanctum::actingAs($intruder);

        $response = $this->deleteJson("/api/rfis/{$rfi->id}/attachments/{$upload['id']}");
        $response->assertStatus(403);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }

    // ── QA Report ─────────────────────────────────────────────────────────

    public function test_authorized_user_can_upload_evidence_to_a_qa_report(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('qa');
        $project = $this->makeProject($org, $user);
        $qa = $this->makeQaReport($project, $user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/projects/{$project->id}/qa-reports/{$qa->id}/attachments", ['file' => $this->fakePng('inspection.png')]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('file_uploads', ['attachable_type' => QaReport::class, 'attachable_id' => $qa->id]);
    }

    public function test_multiple_evidence_files_can_exist_on_one_qa_report(): void
    {
        Storage::fake('local');
        [$org, $user] = $this->makeOrgAndUser('qa-multi');
        $project = $this->makeProject($org, $user);
        $qa = $this->makeQaReport($project, $user);
        Sanctum::actingAs($user);

        $this->postJson("/api/projects/{$project->id}/qa-reports/{$qa->id}/attachments", ['file' => $this->fakePng('a.png')])->assertStatus(201);
        $this->postJson("/api/projects/{$project->id}/qa-reports/{$qa->id}/attachments", ['file' => $this->fakePdf('cert.pdf')])->assertStatus(201);

        $response = $this->getJson("/api/projects/{$project->id}/qa-reports/{$qa->id}/attachments");
        $this->assertCount(2, $response->json());
    }

    public function test_another_project_cannot_delete_a_qa_reports_evidence(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndUser('qa-proj-a');
        $projectA = $this->makeProject($orgA, $userA);
        $qaA = $this->makeQaReport($projectA, $userA);
        Sanctum::actingAs($userA);
        $upload = $this->postJson("/api/projects/{$projectA->id}/qa-reports/{$qaA->id}/attachments", ['file' => $this->fakePng()])->json();

        [$orgB, $userB] = $this->makeOrgAndUser('qa-proj-b');
        $projectB = $this->makeProject($orgB, $userB);
        $qaB = $this->makeQaReport($projectB, $userB);
        Sanctum::actingAs($userB);

        $response = $this->deleteJson("/api/projects/{$projectB->id}/qa-reports/{$qaB->id}/attachments/{$upload['id']}");
        $response->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }

    // ── Existing attachable_type consumers unaffected ────────────────────

    public function test_existing_contract_attachable_relation_is_unaffected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('contract-unaffected');
        $project = $this->makeProject($org, $user);
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Main Contract', 'type' => 'main_contract', 'status' => 'active', 'retention_percentage' => 5,
        ]);

        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'attachable_type' => \App\Models\Contract::class, 'attachable_id' => $contract->id,
            'original_name' => 'contract.pdf', 'stored_name' => 'x.pdf', 'file_path' => 'x.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'disk' => 'local',
        ]);

        $this->assertInstanceOf(\App\Models\Contract::class, $upload->attachable);
        $this->assertTrue($contract->fileUploads()->exists());
    }
}
