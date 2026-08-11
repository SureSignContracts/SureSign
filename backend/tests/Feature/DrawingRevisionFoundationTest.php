<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Drawing;
use App\Models\DrawingRevision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drawing Phase 4 — Revision Foundation.
 *
 * Covers DrawingRevision creation/authorization/tenant-isolation, current-
 * revision switching (transactional, previous revision preserved and never
 * auto-relabelled), Drawing::effectiveDocument()'s legacy-fallback
 * resolution, and the backfill command's idempotence. No frontend exists
 * yet for this phase — see the live browser walkthrough in the final
 * report for the UI verification.
 */
class DrawingRevisionFoundationTest extends TestCase
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

    private function makeDrawing(Project $project, User $user, Document $document): Drawing
    {
        return Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $document->id,
            'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA',
        ]);
    }

    // ── Creation / eligibility ───────────────────────────────────────────

    public function test_authorized_user_can_add_a_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('add');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'A101-P01.pdf');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01', 'status' => 'For Review',
        ]);

        $response->assertStatus(201);
        $this->assertSame($revisionDoc->id, $response->json('document.id'));
        $this->assertDatabaseHas('drawing_revisions', ['drawing_id' => $drawing->id, 'document_id' => $revisionDoc->id, 'revision_code' => 'P01']);
    }

    public function test_same_project_document_accepted(): void
    {
        [$org, $user] = $this->makeOrgAndUser('sameproj');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);

        $response->assertStatus(201);
    }

    public function test_cross_project_document_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        $document = $this->makeDocument($projectA, $user);
        $drawing = $this->makeDrawing($projectA, $user, $document);
        $otherProjectDoc = $this->makeDocument($projectB, $user, 'other.pdf');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$projectA->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $otherProjectDoc->id, 'revision_code' => 'P01',
        ]);

        $response->assertStatus(422);
    }

    public function test_cross_tenant_document_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('xtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('xtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        $document = $this->makeDocument($projectA, $userA);
        $drawing = $this->makeDrawing($projectA, $userA, $document);
        $otherOrgDoc = $this->makeDocument($projectB, $userB, 'other.pdf');

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/projects/{$projectA->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $otherOrgDoc->id, 'revision_code' => 'P01',
        ]);

        $response->assertStatus(422);
    }

    public function test_soft_deleted_document_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('softdoc');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'deleted.pdf');
        $revisionDoc->delete();

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);

        $response->assertStatus(422);
    }

    public function test_revision_code_is_free_form(): void
    {
        [$org, $user] = $this->makeOrgAndUser('freeform');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'IFC-01 (Rev. C/2)',
        ]);

        $response->assertStatus(201);
        $this->assertSame('IFC-01 (Rev. C/2)', $response->json('revision_code'));
    }

    public function test_document_already_used_by_another_revision_of_same_drawing_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('dupe');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ])->assertStatus(201);

        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P02',
        ]);

        $response->assertStatus(422);
    }

    public function test_document_used_by_another_drawing_entirely_is_still_eligible(): void
    {
        // Confirms the deliberately different eligibility rule vs fresh
        // Drawing registration (Part L) — a Document already tied to a
        // DIFFERENT Drawing is fine for a revision of THIS Drawing.
        [$org, $user] = $this->makeOrgAndUser('otherdrawing');
        $project = $this->makeProject($org, $user);
        $documentA = $this->makeDocument($project, $user, 'a.pdf');
        $documentB = $this->makeDocument($project, $user, 'b.pdf');
        $drawingA = $this->makeDrawing($project, $user, $documentA);
        Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $documentB->id,
            'created_by' => $user->id, 'drawing_number' => 'B101', 'title' => 'Other Drawing',
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawingA->id}/revisions", [
            'document_id' => $documentB->id, 'revision_code' => 'P01',
        ]);

        $response->assertStatus(201);
    }

    // ── Current revision / history preservation ─────────────────────────

    public function test_new_revision_becomes_current(): void
    {
        [$org, $user] = $this->makeOrgAndUser('current');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);

        $revisionId = $response->json('id');
        $this->assertSame($revisionId, $drawing->fresh()->current_revision_id);
    }

    public function test_previous_revision_remains_stored_and_status_untouched(): void
    {
        [$org, $user] = $this->makeOrgAndUser('preserve');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $docP01 = $this->makeDocument($project, $user, 'p01.pdf');
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $r1 = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docP01->id, 'revision_code' => 'P01', 'status' => 'For Construction',
        ])->json();

        $r2 = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->json();

        $this->assertSame($r2['id'], $drawing->fresh()->current_revision_id);
        // The previous revision must still exist, unmodified — never
        // auto-relabelled "Superseded" (Part I).
        $previous = DrawingRevision::find($r1['id']);
        $this->assertNotNull($previous);
        $this->assertSame('For Construction', $previous->status);
    }

    public function test_current_revision_resolves_correct_document(): void
    {
        [$org, $user] = $this->makeOrgAndUser('resolve');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}");
        $this->assertSame($revisionDoc->id, $response->json('document.id'));
    }

    public function test_legacy_drawing_with_no_revision_falls_back_to_document_id(): void
    {
        [$org, $user] = $this->makeOrgAndUser('legacy');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}");

        $response->assertOk();
        $this->assertSame($document->id, $response->json('document.id'));
        $this->assertNull($response->json('current_revision'));
    }

    // ── Historical revision access ───────────────────────────────────────

    public function test_historical_revision_show_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('history');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $docP01 = $this->makeDocument($project, $user, 'p01.pdf');
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $r1 = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docP01->id, 'revision_code' => 'P01',
        ])->json();
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ]);

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$r1['id']}");

        $response->assertOk();
        $this->assertSame($docP01->id, $response->json('revision.document.id'));
        $this->assertFalse($response->json('is_current'));
    }

    public function test_old_revision_remains_viewable_after_new_current_set(): void
    {
        [$org, $user] = $this->makeOrgAndUser('oldview');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $docP01 = $this->makeDocument($project, $user, 'p01.pdf');
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $r1 = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docP01->id, 'revision_code' => 'P01',
        ])->json();
        $r2 = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->json();

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$r1['id']}");
        $response->assertOk();
        $this->assertFalse($response->json('is_current'));

        $currentResponse = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$r2['id']}");
        $currentResponse->assertOk();
        $this->assertTrue($currentResponse->json('is_current'));
    }

    // ── Authorization / scoping ───────────────────────────────────────────

    public function test_unauthorized_access_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('unauth');
        [, $otherUser] = $this->makeOrgAndUser('unauth-other');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);

        Sanctum::actingAs($otherUser);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions");

        $response->assertStatus(403);
    }

    public function test_revision_list_is_tenant_and_drawing_scoped(): void
    {
        [$org, $user] = $this->makeOrgAndUser('scoped');
        $project = $this->makeProject($org, $user);
        $documentA = $this->makeDocument($project, $user, 'a.pdf');
        $documentB = $this->makeDocument($project, $user, 'b.pdf');
        $drawingA = $this->makeDrawing($project, $user, $documentA);
        $drawingB = Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $documentB->id,
            'created_by' => $user->id, 'drawing_number' => 'B101', 'title' => 'Other Drawing',
        ]);
        $revDocA = $this->makeDocument($project, $user, 'reva.pdf');
        $revDocB = $this->makeDocument($project, $user, 'revb.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawingA->id}/revisions", ['document_id' => $revDocA->id, 'revision_code' => 'P01']);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawingB->id}/revisions", ['document_id' => $revDocB->id, 'revision_code' => 'P01']);

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawingA->id}/revisions");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($revDocA->id, $response->json('data.0.document.id'));
    }

    // ── Document survival / immutability ─────────────────────────────────

    public function test_adding_revision_does_not_delete_previous_document(): void
    {
        [$org, $user] = $this->makeOrgAndUser('survive');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $docP01 = $this->makeDocument($project, $user, 'p01.pdf');
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", ['document_id' => $docP01->id, 'revision_code' => 'P01']);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", ['document_id' => $docC01->id, 'revision_code' => 'C01']);

        $this->assertDatabaseHas('documents', ['id' => $docP01->id, 'deleted_at' => null]);
    }

    public function test_revision_document_cannot_be_swapped_through_update(): void
    {
        [$org, $user] = $this->makeOrgAndUser('noswap');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $originalDoc = $this->makeDocument($project, $user, 'orig.pdf');
        $otherDoc = $this->makeDocument($project, $user, 'other.pdf');

        Sanctum::actingAs($user);
        $revision = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $originalDoc->id, 'revision_code' => 'P01',
        ])->json();

        $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision['id']}", [
            'document_id' => $otherDoc->id, 'revision_code' => 'P01-renamed',
        ])->assertOk();

        $fresh = DrawingRevision::find($revision['id']);
        $this->assertSame($originalDoc->id, $fresh->document_id);
        $this->assertSame('P01-renamed', $fresh->revision_code);
    }

    // ── Backfill command ──────────────────────────────────────────────────

    public function test_migrated_existing_drawing_gets_unrecorded_initial_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('backfill');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);

        $this->assertNull($drawing->current_revision_id);

        $this->artisan('drawings:backfill-initial-revisions')->assertSuccessful();

        $drawing->refresh();
        $this->assertNotNull($drawing->current_revision_id);
        $revision = DrawingRevision::find($drawing->current_revision_id);
        $this->assertSame($document->id, $revision->document_id);
        $this->assertNull($revision->revision_code);
        $this->assertNull($revision->status);
        $this->assertNull($revision->issued_date);
    }

    public function test_backfill_is_idempotent(): void
    {
        [$org, $user] = $this->makeOrgAndUser('idempotent');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);

        $this->artisan('drawings:backfill-initial-revisions')->assertSuccessful();
        $firstRevisionId = $drawing->fresh()->current_revision_id;

        $this->artisan('drawings:backfill-initial-revisions')->assertSuccessful();

        $this->assertSame($firstRevisionId, $drawing->fresh()->current_revision_id);
        $this->assertSame(1, DrawingRevision::where('drawing_id', $drawing->id)->count());
    }

    public function test_backfill_does_not_touch_drawings_with_real_revision_history(): void
    {
        [$org, $user] = $this->makeOrgAndUser('realhistory');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);
        $realRevisionId = $drawing->fresh()->current_revision_id;

        $this->artisan('drawings:backfill-initial-revisions')->assertSuccessful();

        $this->assertSame($realRevisionId, $drawing->fresh()->current_revision_id);
        $this->assertSame(1, DrawingRevision::where('drawing_id', $drawing->id)->count());
    }

    // ── Register summary ──────────────────────────────────────────────────

    public function test_drawing_register_returns_effective_document_via_current_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('registersummary');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionDoc = $this->makeDocument($project, $user, 'rev.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $revisionDoc->id, 'revision_code' => 'P01',
        ]);

        $response = $this->getJson("/api/projects/{$project->id}/drawings");

        $response->assertOk();
        $this->assertSame($revisionDoc->id, $response->json('data.0.document.id'));
        $this->assertSame('P01', $response->json('data.0.current_revision.revision_code'));
    }
}
