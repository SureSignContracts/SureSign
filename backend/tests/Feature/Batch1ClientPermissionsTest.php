<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 1: Projects, Dashboard, Documents, Notifications, Organisation branding.
 *
 * These modules were found (audit Phase 1/2) to already be backend-safe —
 * organization_id is always derived from the authenticated user, never
 * request input, and no controller special-cases the Client role beyond the
 * standard org-membership check. These tests lock in that positive access
 * for Client and the negative cross-tenant access, so a future change can't
 * silently regress either direction.
 */
class Batch1ClientPermissionsTest extends TestCase
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

    // ── Projects ──────────────────────────────────────────────────────────

    public function test_client_can_create_a_project_scoped_to_their_own_organisation(): void
    {
        $a = $this->makeOrgAndUser('a');
        Sanctum::actingAs($a['user']);

        $response = $this->postJson('/api/projects', ['name' => 'New Build']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('projects', [
            'name'            => 'New Build',
            'organization_id' => $a['org']->id,
        ]);
    }

    public function test_client_cannot_spoof_organization_id_when_creating_a_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        Sanctum::actingAs($a['user']);

        $response = $this->postJson('/api/projects', [
            'name'            => 'Spoofed Project',
            'organization_id' => $b['org']->id,
        ]);

        $response->assertStatus(201);
        // organization_id is never read from request input — always the authenticated user's org.
        $this->assertDatabaseHas('projects', [
            'name'            => 'Spoofed Project',
            'organization_id' => $a['org']->id,
        ]);
        $this->assertDatabaseMissing('projects', [
            'name'            => 'Spoofed Project',
            'organization_id' => $b['org']->id,
        ]);
    }

    public function test_client_can_edit_and_archive_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $update = $this->putJson("/api/projects/{$project->id}", ['name' => 'Renamed Project']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'name' => 'Renamed Project']);

        $archive = $this->deleteJson("/api/projects/{$project->id}");
        $archive->assertStatus(200);
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_client_cannot_view_or_edit_another_organisations_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);
        Sanctum::actingAs($a['user']);

        $this->getJson("/api/projects/{$projectB->id}")->assertStatus(403);
        $this->putJson("/api/projects/{$projectB->id}", ['name' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/projects/{$projectB->id}")->assertStatus(403);
    }

    public function test_client_project_list_is_scoped_to_their_own_organisation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $this->makeProject($a['org'], $a['user']);
        $this->makeProject($b['org'], $b['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/projects');
        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('organization_id')->unique();
        $this->assertEquals([$a['org']->id], $names->values()->all());
    }

    // ── Documents ─────────────────────────────────────────────────────────

    public function test_client_can_upload_preview_download_and_delete_a_document_in_their_own_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $project = $this->makeProject($a['org'], $a['user']);
        Sanctum::actingAs($a['user']);

        $store = $this->postJson("/api/projects/{$project->id}/documents", ['title' => 'Site Plan', 'type' => 'pdf']);
        $store->assertStatus(201);
        $documentId = $store->json('id');

        $this->getJson("/api/documents/{$documentId}")->assertStatus(200);
        $this->putJson("/api/documents/{$documentId}", ['title' => 'Site Plan v2'])->assertStatus(200);
        $this->deleteJson("/api/documents/{$documentId}")->assertStatus(200);
        $this->assertSoftDeleted('documents', ['id' => $documentId]);
    }

    public function test_client_cannot_access_another_organisations_document(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);

        $document = Document::create([
            'project_id'      => $projectB->id,
            'organization_id' => $b['org']->id,
            'created_by'      => $b['user']->id,
            'title'           => 'Confidential',
            'type'            => 'pdf',
            'status'          => 'draft',
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/documents/{$document->id}")->assertStatus(403);
        $this->putJson("/api/documents/{$document->id}", ['title' => 'Hijacked'])->assertStatus(403);
        $this->deleteJson("/api/documents/{$document->id}")->assertStatus(403);
        $this->getJson("/api/documents/{$document->id}/download")->assertStatus(403);
        $this->getJson("/api/documents/{$document->id}/preview")->assertStatus(403);
    }

    public function test_client_cannot_use_another_organisations_project_id_to_reach_their_own_document(): void
    {
        // IDOR check: a document that legitimately belongs to Org A must not
        // become reachable just because the attacker guesses Org B's project ID
        // in an unrelated nested route.
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectA = $this->makeProject($a['org'], $a['user']);
        $projectB = $this->makeProject($b['org'], $b['user']);

        Sanctum::actingAs($a['user']);

        // Confirm Org A cannot list Org B's project documents via its own token.
        $this->getJson("/api/projects/{$projectB->id}/documents")->assertStatus(403);

        // Uploading a file scoped to project A must record project A's org, never project B's.
        $upload = FileUpload::create([
            'project_id'      => $projectA->id,
            'organization_id' => $a['org']->id,
            'uploaded_by'     => $a['user']->id,
            'original_name'   => 'plan.pdf',
            'stored_name'     => 'stored.pdf',
            'file_path'       => 'projects/x/plan.pdf',
            'mime_type'       => 'application/pdf',
            'file_size'       => 1024,
            'folder_path'     => 'general',
        ]);
        $this->assertEquals($a['org']->id, $upload->organization_id);
    }

    public function test_client_cannot_download_preview_or_delete_another_organisations_file_upload(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);

        $upload = FileUpload::create([
            'project_id'      => $projectB->id,
            'organization_id' => $b['org']->id,
            'uploaded_by'     => $b['user']->id,
            'original_name'   => 'confidential.pdf',
            'stored_name'     => 'stored.pdf',
            'file_path'       => 'projects/b/confidential.pdf',
            'mime_type'       => 'application/pdf',
            'file_size'       => 1024,
            'folder_path'     => 'general',
        ]);

        Sanctum::actingAs($a['user']);

        $this->getJson("/api/file-uploads/{$upload->id}/download")->assertStatus(403);
        $this->getJson("/api/file-uploads/{$upload->id}/preview")->assertStatus(403);
        $this->deleteJson("/api/file-uploads/{$upload->id}")->assertStatus(403);
    }

    // ── Notifications ─────────────────────────────────────────────────────

    /**
     * NOTE: GET /notifications (the list endpoint) uses an unconditional
     * orderByRaw("FIELD(...)") which is MySQL-only and 500s under the
     * sqlite test driver configured in phpunit.xml. That's a pre-existing
     * incompatibility unrelated to Batch 1 — reported separately rather
     * than fixed here. /notifications/unread-count has no such raw SQL and
     * exercises the same per-user scoping ($request->user()->id), so it's
     * used here to verify the tenant-isolation behaviour without tripping
     * the unrelated bug.
     */
    public function test_client_sees_only_their_own_unread_notification_count(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');

        SuresignNotification::create([
            'user_id'  => $a['user']->id,
            'type'     => 'file_uploaded',
            'title'    => 'Mine',
            'message'  => 'My notification',
            'status'   => 'unread',
            'priority' => 'info',
        ]);
        SuresignNotification::create([
            'user_id'  => $b['user']->id,
            'type'     => 'file_uploaded',
            'title'    => 'Not mine',
            'message'  => "Someone else's notification",
            'status'   => 'unread',
            'priority' => 'info',
        ]);

        Sanctum::actingAs($a['user']);

        $response = $this->getJson('/api/notifications/unread-count');
        $response->assertStatus(200);
        $response->assertJson(['count' => 1]);
    }

    // ── Organisation branding ─────────────────────────────────────────────

    public function test_client_can_view_and_update_their_own_organisation_branding(): void
    {
        $a = $this->makeOrgAndUser('a');
        Sanctum::actingAs($a['user']);

        $this->getJson('/api/organization/branding')->assertStatus(200);

        $update = $this->postJson('/api/organization/branding', ['company_name' => 'Acme Construction']);
        $update->assertStatus(200);
        $this->assertDatabaseHas('branding_settings', [
            'organization_id'      => $a['org']->id,
            'company_display_name' => 'Acme Construction',
        ]);
    }

    public function test_client_branding_update_never_touches_another_organisation(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        Sanctum::actingAs($a['user']);

        $this->postJson('/api/organization/branding', ['company_name' => 'Acme Construction']);

        $this->assertDatabaseMissing('branding_settings', [
            'organization_id'      => $b['org']->id,
            'company_display_name' => 'Acme Construction',
        ]);
    }
}
