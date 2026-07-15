<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final cleanup (Task 4): the project-level Documents Explorer
 * (/app/projects/{id}/documents) is a genuine, existing destination — it
 * simply had no WorkspaceNavigationResolver entry, so file_upload
 * notifications always had a null action_url. Now wired up; a
 * trade-package-scoped file still correctly routes to that package's
 * Documents workspace tab instead of the generic project page.
 */
class NotificationDocumentActionUrlTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndClient(string $label): array
    {
        static $n = 0;
        $n++;

        $org   = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user  = User::factory()->create(['organization_id' => $org->id]);
        $other = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $other->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'user', 'other');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    public function test_project_level_file_upload_notification_links_to_the_documents_page(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $file = UploadedFile::fake()->create('site-plan.pdf', 10, 'application/pdf');
        file_put_contents($file->getPathname(), "%PDF-1.4\n" . str_repeat('x', 200));

        $response = $this->postJson("/api/projects/{$project->id}/files", ['file' => $file]);
        $response->assertStatus(201);
        $uploadId = $response->json('id');

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'file_upload', 'source_id' => $uploadId,
            'action_url'  => "/app/projects/{$project->id}/documents",
        ]);
    }

    public function test_trade_package_scoped_file_deletion_notification_links_to_the_workspace_documents_tab(): void
    {
        $a       = $this->makeOrgAndClient('a');
        $project = $this->makeProject($a['org'], $a['user']);
        $tradePackage = TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $a['org']->id,
            'name' => 'Package A', 'slug' => 'package-a-' . uniqid(), 'status' => 'active',
        ]);

        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_package_id' => $tradePackage->id, 'uploaded_by' => $a['user']->id,
            'original_name' => 'sub.pdf', 'stored_name' => 'sub.pdf', 'file_path' => 'x/sub.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 10, 'source_type' => 'uploaded', 'disk' => 'local',
        ]);

        Sanctum::actingAs($a['user']);
        $this->deleteJson("/api/file-uploads/{$upload->id}")->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'source_type' => 'file_upload', 'source_id' => $upload->id,
            'action_url'  => "/app/projects/{$project->id}/subcontracts/{$tradePackage->id}?tab=documents",
        ]);
    }
}
