<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Drawing;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drawing Phase 1A — Database / Model / Authorization / API Foundation.
 *
 * Covers only the backend Drawing Register foundation: project/tenant
 * scoping, mandatory-Document eligibility (same project, same tenant, not
 * soft-deleted), active-registration uniqueness + re-registration after
 * removal, metadata validation, immutable document_id on update, soft
 * delete leaving the underlying Document untouched, and search/filter/
 * pagination. No frontend exists yet — see Drawing Phase 1B.
 */
class DrawingRegisterFoundationTest extends TestCase
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

    private function makeDocument(Project $project, User $user, string $title = 'A101.pdf'): Document
    {
        return Document::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'title' => $title, 'type' => 'other', 'file_name' => $title,
        ]);
    }

    // ── List / pagination / tenant scoping ──────────────────────────────

    public function test_authorized_user_can_list_project_drawings(): void
    {
        [$org, $user] = $this->makeOrgAndUser('list');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        Drawing::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id,
            'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A101', $response->json('data.0.drawing_number'));
    }

    public function test_empty_project_returns_empty_paginated_result(): void
    {
        [$org, $user] = $this->makeOrgAndUser('empty');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
        $this->assertSame(0, $response->json('total'));
    }

    public function test_list_scoped_to_requested_project(): void
    {
        [$org, $user] = $this->makeOrgAndUser('scope');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        $docA = $this->makeDocument($projectA, $user);
        $docB = $this->makeDocument($projectB, $user);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $projectA->id, 'document_id' => $docA->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'A']);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $projectB->id, 'document_id' => $docB->id, 'created_by' => $user->id, 'drawing_number' => 'B101', 'title' => 'B']);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$projectA->id}/drawings");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('A101', $response->json('data.0.drawing_number'));
    }

    public function test_list_is_tenant_isolated(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('tenA');
        [$orgB, $userB] = $this->makeOrgAndUser('tenB');
        $projectB = $this->makeProject($orgB, $userB);

        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/projects/{$projectB->id}/drawings");

        $response->assertStatus(403);
    }

    // ── Register (store) ────────────────────────────────────────────────

    public function test_authorized_user_can_register_drawing(): void
    {
        [$org, $user] = $this->makeOrgAndUser('reg');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA',
            'discipline' => 'Architectural', 'status' => 'For Construction', 'location_reference' => 'Block A',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('drawings', [
            'project_id' => $project->id, 'document_id' => $document->id, 'drawing_number' => 'A101',
        ]);
        $this->assertSame($document->id, $response->json('document.id'));
    }

    public function test_drawing_requires_valid_same_project_document(): void
    {
        [$org, $user] = $this->makeOrgAndUser('nodoc');
        $project = $this->makeProject($org, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => 999999, 'drawing_number' => 'A101', 'title' => 'X',
        ]);

        $response->assertStatus(422);
    }

    public function test_cross_project_document_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        $docB = $this->makeDocument($projectB, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectA->id}/drawings", [
            'document_id' => $docB->id, 'drawing_number' => 'A101', 'title' => 'X',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('drawings', ['document_id' => $docB->id]);
    }

    public function test_cross_tenant_document_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('xtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('xtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        $docB = $this->makeDocument($projectB, $userB);

        Sanctum::actingAs($userA);
        // A Document from another organisation's project can never resolve
        // for project A's own {project} scope — same 422 as the
        // cross-project case, since the eligibility query is always scoped
        // to the target project_id first.
        $response = $this->postJson("/api/projects/{$projectA->id}/drawings", [
            'document_id' => $docB->id, 'drawing_number' => 'A101', 'title' => 'X',
        ]);

        $response->assertStatus(422);
    }

    public function test_soft_deleted_document_cannot_be_registered(): void
    {
        [$org, $user] = $this->makeOrgAndUser('softdoc');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $document->delete();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'drawing_number' => 'A101', 'title' => 'X',
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_active_document_registration_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('dupe');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'First']);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'drawing_number' => 'A102', 'title' => 'Second',
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Drawing::where('document_id', $document->id)->count());
    }

    public function test_drawing_number_is_required(): void
    {
        [$org, $user] = $this->makeOrgAndUser('nonum');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'title' => 'X',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('drawing_number');
    }

    public function test_title_is_required(): void
    {
        [$org, $user] = $this->makeOrgAndUser('notitle');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'drawing_number' => 'A101',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('title');
    }

    // ── Update ───────────────────────────────────────────────────────────

    public function test_metadata_update_succeeds(): void
    {
        [$org, $user] = $this->makeOrgAndUser('upd');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Old Title']);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}", [
            'title' => 'New Title', 'status' => 'For Review',
        ]);

        $response->assertOk();
        $this->assertSame('New Title', $response->json('title'));
        $this->assertSame('For Review', $response->json('status'));
    }

    public function test_document_id_cannot_be_swapped_through_update(): void
    {
        [$org, $user] = $this->makeOrgAndUser('swap');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $otherDocument = $this->makeDocument($project, $user, 'B201.pdf');
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}", [
            'document_id' => $otherDocument->id, 'title' => 'Title',
        ])->assertOk();

        $this->assertSame($document->id, $drawing->fresh()->document_id);
    }

    public function test_unauthorized_update_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('unauth');
        [, $otherUser] = $this->makeOrgAndUser('unauth-other');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);

        Sanctum::actingAs($otherUser);
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}", ['title' => 'Hacked']);

        $response->assertStatus(403);
        $this->assertSame('Title', $drawing->fresh()->title);
    }

    public function test_cross_tenant_drawing_update_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('xupdA');
        [$orgB, $userB] = $this->makeOrgAndUser('xupdB');
        $projectB = $this->makeProject($orgB, $userB);
        $docB = $this->makeDocument($projectB, $userB);
        $drawingB = Drawing::create(['organization_id' => $orgB->id, 'project_id' => $projectB->id, 'document_id' => $docB->id, 'created_by' => $userB->id, 'drawing_number' => 'B101', 'title' => 'Title']);

        Sanctum::actingAs($userA);
        $response = $this->putJson("/api/projects/{$projectB->id}/drawings/{$drawingB->id}", ['title' => 'Hacked']);

        $response->assertStatus(403);
    }

    // ── Delete / re-registration ─────────────────────────────────────────

    public function test_delete_soft_deletes_drawing(): void
    {
        [$org, $user] = $this->makeOrgAndUser('del');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);

        Sanctum::actingAs($user);
        $response = $this->deleteJson("/api/projects/{$project->id}/drawings/{$drawing->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('drawings', ['id' => $drawing->id]);
    }

    public function test_underlying_document_survives_drawing_delete(): void
    {
        [$org, $user] = $this->makeOrgAndUser('survive');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/projects/{$project->id}/drawings/{$drawing->id}")->assertStatus(204);

        $this->assertDatabaseHas('documents', ['id' => $document->id, 'deleted_at' => null]);
    }

    public function test_deleted_drawing_is_absent_from_normal_list(): void
    {
        [$org, $user] = $this->makeOrgAndUser('gone');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);
        $drawing->delete();

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_same_document_can_be_re_registered_after_drawing_removal(): void
    {
        [$org, $user] = $this->makeOrgAndUser('re-reg');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);
        $drawing->delete();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings", [
            'document_id' => $document->id, 'drawing_number' => 'A102', 'title' => 'Re-registered',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Drawing::where('document_id', $document->id)->count());
    }

    // ── Search / filter / pagination shape ──────────────────────────────

    public function test_search_matches_drawing_number_and_title(): void
    {
        [$org, $user] = $this->makeOrgAndUser('search');
        $project = $this->makeProject($org, $user);
        $docA = $this->makeDocument($project, $user, 'A101.pdf');
        $docB = $this->makeDocument($project, $user, 'S203.pdf');
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docA->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA']);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docB->id, 'created_by' => $user->id, 'drawing_number' => 'S203', 'title' => 'Foundation Layout']);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings?search=Foundation");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('S203', $response->json('data.0.drawing_number'));
    }

    public function test_discipline_filter_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('disc');
        $project = $this->makeProject($org, $user);
        $docA = $this->makeDocument($project, $user, 'A101.pdf');
        $docB = $this->makeDocument($project, $user, 'S203.pdf');
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docA->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'A', 'discipline' => 'Architectural']);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docB->id, 'created_by' => $user->id, 'drawing_number' => 'S203', 'title' => 'S', 'discipline' => 'Structural']);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings?discipline=Structural");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('S203', $response->json('data.0.drawing_number'));
    }

    public function test_status_filter_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('stat');
        $project = $this->makeProject($org, $user);
        $docA = $this->makeDocument($project, $user, 'A101.pdf');
        $docB = $this->makeDocument($project, $user, 'S203.pdf');
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docA->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'A', 'status' => 'Draft']);
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $docB->id, 'created_by' => $user->id, 'drawing_number' => 'S203', 'title' => 'S', 'status' => 'For Construction']);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings?status=".urlencode('For Construction'));

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('S203', $response->json('data.0.drawing_number'));
    }

    public function test_pagination_shape_and_count(): void
    {
        [$org, $user] = $this->makeOrgAndUser('page');
        $project = $this->makeProject($org, $user);
        for ($i = 1; $i <= 3; $i++) {
            $doc = $this->makeDocument($project, $user, "DOC{$i}.pdf");
            Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $doc->id, 'created_by' => $user->id, 'drawing_number' => "A10{$i}", 'title' => "Drawing {$i}"]);
        }

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings?per_page=2");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('total'));
        $this->assertSame(2, $response->json('per_page'));
    }

    // ── Eligible documents (Register Drawing selector, Part L) ──────────

    public function test_eligible_documents_excludes_actively_registered_documents(): void
    {
        [$org, $user] = $this->makeOrgAndUser('eligible');
        $project = $this->makeProject($org, $user);
        $registered = $this->makeDocument($project, $user, 'Registered.pdf');
        $available = $this->makeDocument($project, $user, 'Available.pdf');
        Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $registered->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/eligible-documents");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($available->id, $ids);
        $this->assertNotContains($registered->id, $ids);
    }

    public function test_eligible_documents_includes_document_again_after_drawing_removed(): void
    {
        [$org, $user] = $this->makeOrgAndUser('eligible2');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = Drawing::create(['organization_id' => $org->id, 'project_id' => $project->id, 'document_id' => $document->id, 'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Title']);
        $drawing->delete();

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/eligible-documents");

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($document->id, $ids);
    }

    public function test_eligible_documents_search_matches_title_and_reference_number(): void
    {
        [$org, $user] = $this->makeOrgAndUser('eligible3');
        $project = $this->makeProject($org, $user);
        $this->makeDocument($project, $user, 'Ground Floor Plan.pdf');
        $refDoc = Document::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'title' => 'Untitled.pdf', 'type' => 'other', 'reference_number' => 'A101',
        ]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/eligible-documents?search=A101");

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$refDoc->id], $ids);
    }

    public function test_eligible_documents_is_tenant_isolated(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('eligTenA');
        [$orgB, $userB] = $this->makeOrgAndUser('eligTenB');
        $projectB = $this->makeProject($orgB, $userB);

        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/projects/{$projectB->id}/drawings/eligible-documents");

        $response->assertStatus(403);
    }
}
