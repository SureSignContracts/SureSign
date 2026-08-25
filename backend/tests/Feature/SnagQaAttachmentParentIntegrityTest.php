<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Snag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final Pre-Commit Attachment Integrity Verification (P3 follow-up) —
 * SnagQaProjectParentIntegrityTest proved show()/update()/destroy(); this
 * file proves the identical authorizeProjectSnag()/authorizeProjectQaReport()
 * call sites in attachments()/uploadAttachment()/deleteAttachment() are
 * genuinely wired up too, not merely structurally identical code. Same
 * fixture/faking convention as Phase0EvidenceAttachmentTest.
 */
class SnagQaAttachmentParentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndTwoProjects(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}"]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $projectA = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project A {$suffix}"]);
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project B {$suffix}"]);

        return [$org, $user, $projectA, $projectB];
    }

    /**
     * GD isn't available in this environment and FileSecurityService
     * enforces real magic bytes — mirrors Phase0EvidenceAttachmentTest's
     * exact pattern.
     */
    private function fakePng(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    // ── Snag attachments ─────────────────────────────────────────────────

    public function test_snag_attachment_listing_with_correct_parent_succeeds(): void
    {
        Storage::fake('local');
        [, $user, , $projectB] = $this->makeOrgAndTwoProjects('sa1');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201);

        $this->getJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments")
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_snag_attachment_listing_with_wrong_project_is_rejected(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('sa2');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201);

        $response = $this->getJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}/attachments");

        $response->assertStatus(404);
    }

    public function test_snag_attachment_upload_with_wrong_project_is_rejected_with_no_mutation(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('sa3');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}/attachments", ['file' => $this->fakePng()]);

        $response->assertStatus(404);
        $this->assertSame(0, FileUpload::where('attachable_type', Snag::class)->where('attachable_id', $snagB->id)->count());
    }

    public function test_snag_attachment_delete_with_wrong_project_is_rejected_with_no_mutation(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('sa4');
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $upload = $this->postJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201)->json();

        $response = $this->deleteJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}/attachments/{$upload['id']}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }

    public function test_admin_does_not_bypass_snag_attachment_parent_child_integrity(): void
    {
        Storage::fake('local');
        [, , $projectA, $projectB] = $this->makeOrgAndTwoProjects('sa5');
        $creator = User::factory()->create(['organization_id' => $projectB->organization_id, 'is_active' => true]);
        $snagB = Snag::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $creator->id, 'snag_number' => 1, 'title' => 'Belongs to B',
        ]);
        Sanctum::actingAs($creator);
        $upload = $this->postJson("/api/projects/{$projectB->id}/snagging/{$snagB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201)->json();

        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}/attachments")->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectA->id}/snagging/{$snagB->id}/attachments/{$upload['id']}")->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }

    // ── QA Report attachments ────────────────────────────────────────────

    public function test_qa_report_attachment_listing_with_correct_parent_succeeds(): void
    {
        Storage::fake('local');
        [, $user, , $projectB] = $this->makeOrgAndTwoProjects('qa1');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$projectB->id}/qa-reports/{$reportB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201);

        $this->getJson("/api/projects/{$projectB->id}/qa-reports/{$reportB->id}/attachments")
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_qa_report_attachment_listing_with_wrong_project_is_rejected(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('qa2');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}/attachments");

        $response->assertStatus(404);
    }

    public function test_qa_report_attachment_upload_with_wrong_project_is_rejected_with_no_mutation(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('qa3');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}/attachments", ['file' => $this->fakePng()]);

        $response->assertStatus(404);
        $this->assertSame(0, FileUpload::where('attachable_type', QaReport::class)->where('attachable_id', $reportB->id)->count());
    }

    public function test_qa_report_attachment_delete_with_wrong_project_is_rejected_with_no_mutation(): void
    {
        Storage::fake('local');
        [, $user, $projectA, $projectB] = $this->makeOrgAndTwoProjects('qa4');
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $user->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);

        Sanctum::actingAs($user);
        $upload = $this->postJson("/api/projects/{$projectB->id}/qa-reports/{$reportB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201)->json();

        $response = $this->deleteJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}/attachments/{$upload['id']}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }

    public function test_admin_does_not_bypass_qa_report_attachment_parent_child_integrity(): void
    {
        Storage::fake('local');
        [, , $projectA, $projectB] = $this->makeOrgAndTwoProjects('qa5');
        $creator = User::factory()->create(['organization_id' => $projectB->organization_id, 'is_active' => true]);
        $reportB = QaReport::create([
            'organization_id' => $projectB->organization_id, 'project_id' => $projectB->id,
            'created_by' => $creator->id, 'report_number' => 1, 'title' => 'Belongs to B',
        ]);
        Sanctum::actingAs($creator);
        $upload = $this->postJson("/api/projects/{$projectB->id}/qa-reports/{$reportB->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201)->json();

        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}/attachments")->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectA->id}/qa-reports/{$reportB->id}/attachments/{$upload['id']}")->assertStatus(404);
        $this->assertDatabaseHas('file_uploads', ['id' => $upload['id']]);
    }
}
