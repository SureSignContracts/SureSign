<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Document;
use App\Models\Drawing;
use App\Models\DrawingHotspot;
use App\Models\DrawingHotspotLink;
use App\Models\DrawingRevision;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Drawing Phase 6B — Hotspot <-> construction record linking. Covers the
 * polymorphic allowlist (never an arbitrary client class string),
 * per-record ownership validation, duplicate/cross-project/cross-tenant
 * rejection, current-revision-only linking, unlink/hotspot-delete
 * preserving the linked record, and the reverse record-side lookup.
 */
class DrawingHotspotLinkTest extends TestCase
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

    private function makeDrawingWithCurrentRevision(Project $project, User $user, Document $document): array
    {
        $drawing = Drawing::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'document_id' => $document->id,
            'created_by' => $user->id, 'drawing_number' => 'A101', 'title' => 'Ground Floor GA',
        ]);
        $revision = DrawingRevision::create([
            'drawing_id' => $drawing->id, 'document_id' => $document->id,
            'revision_code' => 'P01', 'created_by' => $user->id,
        ]);
        $drawing->update(['current_revision_id' => $revision->id]);

        return [$drawing, $revision];
    }

    private function makeHotspot(DrawingRevision $revision, User $user): DrawingHotspot
    {
        return DrawingHotspot::create(['drawing_revision_id' => $revision->id, 'page_number' => 1, 'x' => 0.25, 'y' => 0.3, 'created_by' => $user->id]);
    }

    private function makeSnag(Project $project, User $user): Snag
    {
        return Snag::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'snag_number' => 1, 'title' => 'Handrail incomplete', 'status' => 'open',
        ]);
    }

    private function makeRfi(Project $project, User $user): Rfi
    {
        return Rfi::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'rfi_number' => 1, 'subject' => 'Stair balustrade detail', 'status' => 'open',
        ]);
    }

    private function makeQaReport(Project $project, User $user): QaReport
    {
        return QaReport::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $user->id,
            'report_number' => 1, 'title' => 'Foundation pour inspection', 'status' => 'draft',
        ]);
    }

    private function makeVariation(Project $project, User $user): Variation
    {
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'Main Contract',
        ]);

        return Variation::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'created_by' => $user->id,
            'variation_number' => 1, 'title' => 'Additional fire doors', 'status' => 'draft',
        ]);
    }

    private function linksUrl(Project $project, Drawing $drawing, DrawingRevision $revision, DrawingHotspot $hotspot): string
    {
        return "/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}/links";
    }

    // ── Valid links for each of the four supported types ──────────────────

    public function test_snag_link_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('snaglink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id]);

        $response->assertStatus(201);
        $this->assertSame('snag', $response->json('type'));
        $this->assertStringContainsString('Handrail incomplete', $response->json('label'));
        $this->assertDatabaseHas('drawing_hotspot_links', [
            'drawing_hotspot_id' => $hotspot->id, 'linkable_type' => Snag::class, 'linkable_id' => $snag->id,
        ]);
    }

    public function test_rfi_link_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('rfilink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $rfi = $this->makeRfi($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'rfi', 'record_id' => $rfi->id]);

        $response->assertStatus(201);
        $this->assertSame('rfi', $response->json('type'));
    }

    public function test_qa_report_link_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('qalink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $qa = $this->makeQaReport($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'qa_report', 'record_id' => $qa->id]);

        $response->assertStatus(201);
        $this->assertSame('qa_report', $response->json('type'));
    }

    public function test_variation_link_works(): void
    {
        [$org, $user] = $this->makeOrgAndUser('varlink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $variation = $this->makeVariation($project, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'variation', 'record_id' => $variation->id]);

        $response->assertStatus(201);
        $this->assertSame('variation', $response->json('type'));
    }

    // ── Allowlist / validation ─────────────────────────────────────────────

    public function test_unsupported_type_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('badtype');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'contract', 'record_id' => 1]);

        $response->assertStatus(422);
    }

    public function test_arbitrary_php_class_string_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('classstring');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);

        Sanctum::actingAs($user);
        // A client attempting to smuggle a raw model class through `type`
        // must be rejected exactly like any other unrecognised string —
        // the allowlist has no fallback that ever evaluates this as a class.
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), [
            'type' => 'App\\Models\\User', 'record_id' => $user->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('drawing_hotspot_links', ['linkable_type' => 'App\\Models\\User']);
    }

    // ── Ownership ────────────────────────────────────────────────────────

    public function test_cross_project_record_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('xprojlink');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $user, $this->makeDocument($projectA, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snagInOtherProject = $this->makeSnag($projectB, $user);

        Sanctum::actingAs($user);
        $response = $this->postJson($this->linksUrl($projectA, $drawing, $revision, $hotspot), [
            'type' => 'snag', 'record_id' => $snagInOtherProject->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('record_id');
    }

    public function test_cross_tenant_record_rejected(): void
    {
        [$orgA, $userA] = $this->makeOrgAndUser('xtenA');
        [$orgB, $userB] = $this->makeOrgAndUser('xtenB');
        $projectA = $this->makeProject($orgA, $userA);
        $projectB = $this->makeProject($orgB, $userB);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $userA, $this->makeDocument($projectA, $userA));
        $hotspot = $this->makeHotspot($revision, $userA);
        $snagOtherTenant = $this->makeSnag($projectB, $userB);

        Sanctum::actingAs($userA);
        $response = $this->postJson($this->linksUrl($projectA, $drawing, $revision, $hotspot), [
            'type' => 'snag', 'record_id' => $snagOtherTenant->id,
        ]);

        $response->assertStatus(422);
    }

    // ── Duplicates ───────────────────────────────────────────────────────

    public function test_duplicate_link_rejected(): void
    {
        [$org, $user] = $this->makeOrgAndUser('duplink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id])->assertStatus(201);
        $response = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id]);

        $response->assertStatus(422);
        $this->assertSame(1, DrawingHotspotLink::count());
    }

    // ── Current-revision-only linking ──────────────────────────────────────

    public function test_link_rejected_on_historical_revision(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histlink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revisionP01] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revisionP01, $user);
        $snag = $this->makeSnag($project, $user);
        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');

        Sanctum::actingAs($user);
        $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->assertStatus(201);

        $response = $this->postJson($this->linksUrl($project, $drawing, $revisionP01, $hotspot), ['type' => 'snag', 'record_id' => $snag->id]);

        $response->assertStatus(422);
    }

    // ── Unlink / delete preserve the record ─────────────────────────────────

    public function test_unlink_preserves_record(): void
    {
        [$org, $user] = $this->makeOrgAndUser('unlink');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $link = $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id])->json();

        $response = $this->deleteJson($this->linksUrl($project, $drawing, $revision, $hotspot)."/{$link['id']}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('drawing_hotspot_links', ['id' => $link['id']]);
        $this->assertDatabaseHas('snags', ['id' => $snag->id]);
    }

    public function test_hotspot_delete_preserves_linked_record_and_removes_link(): void
    {
        [$org, $user] = $this->makeOrgAndUser('hsdel');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id])->assertStatus(201);

        $response = $this->deleteJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$revision->id}/hotspots/{$hotspot->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('drawing_hotspots', ['id' => $hotspot->id]);
        $this->assertDatabaseMissing('drawing_hotspot_links', ['drawing_hotspot_id' => $hotspot->id]);
        $this->assertDatabaseHas('snags', ['id' => $snag->id]);
    }

    // ── Historical revision preserves its links ─────────────────────────────

    public function test_historical_revision_links_persist_and_new_revision_does_not_inherit(): void
    {
        [$org, $user] = $this->makeOrgAndUser('histpersist');
        $project = $this->makeProject($org, $user);
        [$drawing, $revisionP01] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revisionP01, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $this->postJson($this->linksUrl($project, $drawing, $revisionP01, $hotspot), ['type' => 'snag', 'record_id' => $snag->id])->assertStatus(201);

        $docC01 = $this->makeDocument($project, $user, 'c01.pdf');
        $newRevision = $this->postJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions", [
            'document_id' => $docC01->id, 'revision_code' => 'C01',
        ])->json();

        // P01's hotspot + link are untouched.
        $links = $this->getJson($this->linksUrl($project, $drawing, $revisionP01, $hotspot))->json('data');
        $this->assertCount(1, $links);

        // C01 has no hotspots at all — nothing to inherit.
        $hotspotsOnNew = $this->getJson("/api/projects/{$project->id}/drawings/{$drawing->id}/revisions/{$newRevision['id']}/hotspots")->json('data');
        $this->assertCount(0, $hotspotsOnNew);
    }

    // ── Reverse record-side lookup ──────────────────────────────────────────

    public function test_record_reverse_lookup_returns_correct_drawing_location(): void
    {
        [$org, $user] = $this->makeOrgAndUser('reverse');
        $project = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($project, $user, $this->makeDocument($project, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snag = $this->makeSnag($project, $user);

        Sanctum::actingAs($user);
        $this->postJson($this->linksUrl($project, $drawing, $revision, $hotspot), ['type' => 'snag', 'record_id' => $snag->id])->assertStatus(201);

        $response = $this->getJson("/api/projects/{$project->id}/drawing-locations?type=snag&record_id={$snag->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($drawing->id, $data[0]['drawing_id']);
        $this->assertSame($revision->id, $data[0]['revision_id']);
        $this->assertSame(1, $data[0]['page_number']);
    }

    public function test_record_reverse_lookup_excludes_other_project_links(): void
    {
        [$org, $user] = $this->makeOrgAndUser('reverseother');
        $projectA = $this->makeProject($org, $user);
        $projectB = $this->makeProject($org, $user);
        [$drawing, $revision] = $this->makeDrawingWithCurrentRevision($projectA, $user, $this->makeDocument($projectA, $user));
        $hotspot = $this->makeHotspot($revision, $user);
        $snagInB = $this->makeSnag($projectB, $user);

        Sanctum::actingAs($user);
        // Querying via project B (the Snag's real project) with no links yet.
        $response = $this->getJson("/api/projects/{$projectB->id}/drawing-locations?type=snag&record_id={$snagInB->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }
}
