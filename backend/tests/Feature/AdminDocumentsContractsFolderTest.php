<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression for a real bug: a file uploaded from the bare "Contracts"
 * folder (not yet inside a named subfolder) gets tagged
 * `module_key='contracts', folder_key='contracts'` by
 * ProjectDocumentsExplorer's resolveUploadContext() (see its own comment).
 * The "Contracts" module-level count includes it (module_key matches), but
 * before this fix AdminController::explorerModuleFiles's subfolder queries
 * only matched the exact string 'contracts/main_contract', so the file was
 * invisible in the Super Admin folder browser (and in its own subfolder's
 * count) even though the parent module correctly reported 1 file.
 * DocumentController::projectModuleFiles (the project-level/Client explorer)
 * already had this exact fallback; AdminController did not — this is what
 * caused the two views to disagree.
 */
class AdminDocumentsContractsFolderTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        return $user;
    }

    private function makeProject(): Project
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-1', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => 'Test Project', 'status' => 'active',
        ]);
    }

    private function makeLegacyTaggedUpload(Project $project): FileUpload
    {
        // Mirrors exactly what ProjectDocumentsExplorer's upload modal sends
        // when the user uploads from "Contracts" itself, not a subfolder.
        return FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'uploaded_by' => $project->created_by,
            'original_name' => 'contract.pdf', 'stored_name' => 'stored.pdf',
            'file_path' => "projects/{$project->id}/contracts/stored.pdf",
            'mime_type' => 'application/pdf', 'file_size' => 1024,
            'folder_path' => 'contracts', 'module_key' => 'contracts', 'folder_key' => 'contracts',
            'source_type' => 'uploaded', 'disk' => 'local',
        ]);
    }

    public function test_module_level_count_includes_the_ambiguously_tagged_file(): void
    {
        $project = $this->makeProject();
        $this->makeLegacyTaggedUpload($project);

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson("/api/admin/documents/explorer/project/{$project->id}");

        $response->assertOk();
        $contracts = collect($response->json('folders'))->firstWhere('key', 'contracts');
        $this->assertSame(1, $contracts['files_count']);
    }

    public function test_main_contract_subfolder_count_includes_the_ambiguously_tagged_file(): void
    {
        $project = $this->makeProject();
        $this->makeLegacyTaggedUpload($project);

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson("/api/admin/documents/explorer/project/{$project->id}/module/contracts");

        $response->assertOk();
        $this->assertSame('folders', $response->json('type'));
        $mainContract = collect($response->json('folders'))->firstWhere('key', 'contracts/main_contract');
        $this->assertSame(1, $mainContract['files_count'], 'The ambiguously-tagged file must count toward Main Contract, not disappear.');
    }

    public function test_main_contract_file_listing_returns_the_ambiguously_tagged_file(): void
    {
        $project = $this->makeProject();
        $upload = $this->makeLegacyTaggedUpload($project);

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson("/api/admin/documents/explorer/project/{$project->id}/module/contracts/main_contract");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($upload->id), 'The file must actually be listed when opening Main Contract, not just counted.');
    }

    public function test_properly_tagged_main_contract_file_still_works(): void
    {
        $project = $this->makeProject();
        FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'uploaded_by' => $project->created_by,
            'original_name' => 'contract.pdf', 'stored_name' => 'stored2.pdf',
            'file_path' => "projects/{$project->id}/contracts/stored2.pdf",
            'mime_type' => 'application/pdf', 'file_size' => 1024,
            'folder_path' => 'contracts', 'module_key' => 'contracts', 'folder_key' => 'contracts/main_contract',
            'source_type' => 'uploaded', 'disk' => 'local',
        ]);

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson("/api/admin/documents/explorer/project/{$project->id}/module/contracts");

        $response->assertOk();
        $mainContract = collect($response->json('folders'))->firstWhere('key', 'contracts/main_contract');
        $this->assertSame(1, $mainContract['files_count']);
    }

    public function test_other_subfolders_are_not_affected_by_the_fallback(): void
    {
        $project = $this->makeProject();
        $this->makeLegacyTaggedUpload($project); // tagged 'contracts', should only ever count toward main_contract

        Sanctum::actingAs($this->makeSuperAdmin());
        $response = $this->getJson("/api/admin/documents/explorer/project/{$project->id}/module/contracts");

        $response->assertOk();
        $consultant = collect($response->json('folders'))->firstWhere('key', 'contracts/consultant_agreement');
        $supplier   = collect($response->json('folders'))->firstWhere('key', 'contracts/supplier_agreement');
        $subcontract = collect($response->json('folders'))->firstWhere('key', 'contracts/subcontract');
        $this->assertSame(0, $consultant['files_count']);
        $this->assertSame(0, $supplier['files_count']);
        $this->assertSame(0, $subcontract['files_count']);
    }
}
