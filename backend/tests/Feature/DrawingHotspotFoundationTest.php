<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingRevision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drawing Phase 5 (foundation) + Phase 6A (authoring CRUD).
 *
 * Covers normalized-coordinate validation, exact revision ownership
 * (never merely "same project"), tenant isolation, the two critical
 * revision-isolation properties (a new revision never inherits an older
 * revision's hotspots, and an older revision's hotspots are never touched
 * when a newer revision becomes current), and — added in Phase 6A —
 * label/reposition update, delete, and the current-revision-only authoring
 * restriction (store/update/destroy all reject a historical revision; index
 * never does). See the live browser walkthrough in the final report for the
 * overlay rendering/geometry/authoring verification.
 */
class DrawingHotspotFoundationTest extends TestCase
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

    /**
     * Mirrors the real DrawingRevisionController::store() invariant — every
     * revision that's created immediately becomes the drawing's current
     * revision — so tests built directly against Eloquent (rather than the
     * revision API) still reflect a realistic current_revision_id state for
     * the Phase 6A current-revision-only authoring restriction.
     */
    private function makeRevision(Drawing $drawing, User $user, Document $document, string $code = 'P01'): DrawingRevision
    {
        $revision = DrawingRevision::create([
            'drawing_id' => $drawing->id, 'document_id' => $document->id,
            'revision_code' => $code, 'created_by' => $user->id,
        ]);
        $drawing->update(['current_revision_id' => $revision->id]);

        return $revision;
    }

    // ── Listing / authorization ──────────────────────────────────────────

    public function test_authorized_revision_hotspot_list_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('list');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_list_scoped_to_exact_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('scoped');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionA = $this->makeRevision($drawing, $user, $document, 'P01');
        $docB = $this->makeDocument($project, $user, 'b.pdf');
        $revisionB = $this->makeRevision($drawing, $user, $docB, 'C01');
        DrawingHotspot::create(['drawing_revision_id' => $revisionA->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
        DrawingHotspot::create(['drawing_revision_id' => $revisionB->id, 'page_number' => 1, 'x' => 0.7, 'y' => 0.65, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionA->id}/hotspots");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertEqualsWithDelta(0.25, $response->json('data.0.x'), 0.0001);
    }

    public function test_cross_drawing_revision_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xdrawing');
        $project = $this->makeProject($org, $user);
        $documentA = $this->makeDocument($project, $user, 'a.pdf');
        $documentB = $this->makeDocument($project, $user, 'b.pdf');
        $drawingA = $this->makeDrawing($project, $user, $documentA);
        $drawingB = Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $documentB->id,
            'created_by' => $user->id, 'drawing_number' => 'B101', 'title' => 'Other Drawing',
        ]);
        // A revision that genuinely belongs to Drawing B...
        $revisionOfB = $this->makeRevision($drawingB, $user, $documentB);

        Sanctum::actingAs($user);
        // ...requested through Drawing A's own URL must be rejected, even
        // though both drawings are in the same project (Part I).
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawingA->id}/revisions/{$revisionOfB->id}/hotspots");

        $response->assertStatus(404);
    }

    public function test_cross_project_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xproj');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        $documentB = $this->makeDocument($projectB, $user);
        $drawingB = $this->makeDrawing($projectB, $user, $documentB);
        $revisionB = $this->makeRevision($drawingB, $user, $documentB);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$projectA->id}/drawings/{$drawingB->id}/revisions/{$revisionB->id}/hotspots");

        // The Drawing itself doesn't belong to project A — 404 either way
        // implicit model binding resolves it, never a cross-project leak.
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_cross_tenant_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('xtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('xtenB');
        $projectB = $this->makeProject($orgB, $userB);
        $documentB = $this->makeDocument($projectB, $userB);
        $drawingB = $this->makeDrawing($projectB, $userB, $documentB);
        $revisionB = $this->makeRevision($drawingB, $userB, $documentB);

        Sanctum::actingAs($userA);
        $response = $this->getJson("/api/projects/{$projectB->id}/drawings/{$drawingB->id}/revisions/{$revisionB->id}/hotspots");

        $response->assertStatus(403);
    }

    // ── Coordinate validation ─────────────────────────────────────────────

    public function test_x_below_zero_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xneg');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 1, 'x' => -0.1, 'y' => 0.5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('x');
    }

    public function test_x_above_one_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xover');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 1, 'x' => 1.1, 'y' => 0.5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('x');
    }

    public function test_y_below_zero_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('yneg');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 1, 'x' => 0.5, 'y' => -0.01,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('y');
    }

    public function test_y_above_one_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('yover');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 1, 'x' => 0.5, 'y' => 1.5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('y');
    }

    public function test_page_number_below_one_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('pagezero');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 0, 'x' => 0.5, 'y' => 0.5,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('page_number');
    }

    public function test_valid_normalized_hotspot_stores_correctly(): void
    {
        [$org, $user] = $this->makeOrgAndUser('valid');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 2, 'x' => 0.43720000, 'y' => 0.68140000, 'label' => 'North stair core',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('drawing_hotspots', [
            'drawing_revision_id' => $revision->id, 'page_number' => 2, 'label' => 'North stair core',
        ]);
        $this->assertEqualsWithDelta(0.4372, $response->json('x'), 0.0001);
        $this->assertEqualsWithDelta(0.6814, $response->json('y'), 0.0001);
    }

    public function test_boundary_values_zero_and_one_accepted(): void
    {
        [$org, $user] = $this->makeOrgAndUser('boundary');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots", [
            'page_number' => 1, 'x' => 0, 'y' => 1,
        ]);

        $response->assertStatus(201);
    }

    // ── Revision isolation (the core reason Phase 4 preceded Phase 5) ────

    public function test_historical_revision_retains_its_hotspots_after_new_current(): void
    {
        [$org, $user] = $this->makeOrgAndUser('historical');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        DrawingHotspot::create(['drawing_revision_id' => $revisionP01->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        // C01 becomes current via the real Phase 4 API.
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionP01->id}/hotspots");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_new_revision_does_not_inherit_hotspots(): void
    {
        [$org, $user] = $this->makeOrgAndUser('noinherit');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        DrawingHotspot::create(['drawing_revision_id' => $revisionP01->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $newRevision = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->json();

        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$newRevision['id']}/hotspots");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // ── Legacy Drawing without a real revision ────────────────────────────

    public function test_legacy_drawing_without_revision_cannot_own_hotspot(): void
    {
        [$org, $user] = $this->makeOrgAndUser('legacy');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        // No revision created — current_revision_id stays null.
        $this->assertNull($drawing->current_revision_id);

        Sanctum::actingAs($user);
        // There is no route that accepts a Drawing with no revision at all
        // for hotspot creation — every hotspot route requires a real,
        // resolvable {revision} segment. Requesting a nonexistent revision
        // id against this Drawing must 404, never fall back to
        // document_id/effectiveDocument().
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/999999/hotspots", [
            'page_number' => 1, 'x' => 0.5, 'y' => 0.5,
        ]);

        $response->assertStatus(404);
    }

    public function test_hotspot_list_does_not_expose_unrelated_records(): void
    {
        [$org, $user] = $this->makeOrgAndUser('unrelated');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'label' => 'Secret', 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots");

        $response->assertOk();
        $keys = array_keys($response->json('data.0'));
        sort($keys);
        $this->assertSame(['created_at', 'drawing_revision_id', 'id', 'label', 'page_number', 'x', 'y'], $keys);
    }

    // ── Phase 6A: label edit / reposition / delete ────────────────────────

    public function test_label_can_be_updated(): void
    {
        [$org, $user] = $this->makeOrgAndUser('labeledit');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'label' => 'Old label', 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}", [
            'label' => 'North stair core',
        ]);

        $response->assertOk();
        $this->assertSame('North stair core', $response->json('label'));
        $this->assertDatabaseHas('drawing_hotspots', ['id' => $hotspot->id, 'label' => 'North stair core']);
    }

    public function test_reposition_updates_x_and_y_together(): void
    {
        [$org, $user] = $this->makeOrgAndUser('reposition');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}", [
            'x' => 0.6, 'y' => 0.7,
        ]);

        $response->assertOk();
        $this->assertEqualsWithDelta(0.6, $response->json('x'), 0.0001);
        $this->assertEqualsWithDelta(0.7, $response->json('y'), 0.0001);
        // page_number is never accepted by update() — reposition stays on
        // the same page a hotspot was created on (Part H).
        $this->assertSame(1, $response->json('page_number'));
    }

    public function test_reposition_rejects_x_without_y(): void
    {
        [$org, $user] = $this->makeOrgAndUser('halfmove');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}", [
            'x' => 0.6,
        ]);

        $response->assertStatus(422);
    }

    public function test_update_rejects_page_number_change(): void
    {
        [$org, $user] = $this->makeOrgAndUser('pagechange');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}", [
            'page_number' => 2,
        ])->assertOk();

        // page_number is not a validated field on update() — a client
        // attempting to send it is silently ignored, never applied.
        $this->assertDatabaseHas('drawing_hotspots', ['id' => $hotspot->id, 'page_number' => 1]);
    }

    public function test_delete_removes_hotspot(): void
    {
        [$org, $user] = $this->makeOrgAndUser('delete');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revision = $this->makeRevision($drawing, $user, $document);
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        Sanctum::actingAs($user);
        $response = $this->deleteJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('drawing_hotspots', ['id' => $hotspot->id]);
    }

    public function test_hotspot_from_different_revision_rejected_for_update(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xrevupdate');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionA = $this->makeRevision($drawing, $user, $document, 'P01');
        $docB = $this->makeDocument($project, $user, 'b.pdf');
        Sanctum::actingAs($user);
        $revisionB = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docB->id, 'revision_code' => 'C01',
        ])->json();
        $hotspotOnA = DrawingHotspot::create(['drawing_revision_id' => $revisionA->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);

        // Hotspot belongs to revision A but is requested through revision B's URL.
        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionB['id']}/hotspots/{$hotspotOnA->id}", [
            'label' => 'Should not apply',
        ]);

        $response->assertStatus(404);
    }

    // ── Phase 6A: current-revision-only authoring restriction ─────────────

    public function test_store_rejected_on_historical_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histstore');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        // P01 is now historical — authoring against it must be refused.
        $response = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionP01->id}/hotspots", [
            'page_number' => 1, 'x' => 0.5, 'y' => 0.5,
        ]);

        $response->assertStatus(422);
    }

    public function test_update_rejected_on_historical_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histupdate');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revisionP01->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        $response = $this->putJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionP01->id}/hotspots/{$hotspot->id}", [
            'label' => 'Should not apply',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('drawing_hotspots', ['id' => $hotspot->id, 'label' => null]);
    }

    public function test_destroy_rejected_on_historical_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histdestroy');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        $hotspot = DrawingHotspot::create(['drawing_revision_id' => $revisionP01->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        $response = $this->deleteJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionP01->id}/hotspots/{$hotspot->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('drawing_hotspots', ['id' => $hotspot->id]);
    }

    public function test_historical_revision_index_still_readable(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histread');
        $project = $this->makeProject($org, $user);
        $document = $this->makeDocument($project, $user);
        $drawing = $this->makeDrawing($project, $user, $document);
        $revisionP01 = $this->makeRevision($drawing, $user, $document, 'P01');
        DrawingHotspot::create(['drawing_revision_id' => $revisionP01->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        // Reading a historical revision's hotspots is never restricted —
        // only store/update/destroy are.
        $response = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revisionP01->id}/hotspots");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
