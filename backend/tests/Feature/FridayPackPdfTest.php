<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack V1C — PDF generation. Mirrors FridayPackTest's
 * exact conventions. See FridayPackPdfService/FridayPackPdfPresenter
 * docblocks for the architectural decisions this proves.
 */
class FridayPackPdfTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $editor, $project];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * R1A.1: FridayPackGenerationService::generate() now always produces a
     * schema-2 (realigned) snapshot, which FridayPackPdfService
     * deliberately refuses to render until a schema-2-aware template
     * exists (see FridayPackUnsupportedSchemaVersionException). This
     * file's tests prove PDF *mechanics* — invalidation, locking,
     * authorization, DomPDF rendering — that are orthogonal to snapshot
     * schema content, so they generate a pack normally and then downgrade
     * its already-persisted snapshot to the legacy schema-1 shape it can
     * safely render, exactly mirroring a genuine pre-realignment pack.
     * The pack is still in `draft` status at this point, so
     * FridayPackIntegrityGuard permits this — it is not a workaround for
     * a broken guard. See test_schema_2_pdf_generation_is_refused() below
     * for the dedicated proof that a real (non-downgraded) schema-2 pack
     * is correctly blocked.
     */
    private function generatePdfRenderablePack(Project $project, string $weekEnding, User $editor): FridayPack
    {
        $pack = app(FridayPackGenerationService::class)->generate($project, $weekEnding, $editor);
        $snapshot = $pack->snapshot_json;
        $snapshot['schema_version'] = 1;
        $pack->forceFill(['snapshot_json' => $snapshot])->save();

        return $pack->fresh();
    }

    // ── Document link + metadata (critical proof #4) ─────────────────────

    public function test_generated_pdf_produces_a_correctly_owned_and_linked_document(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('doc1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);

        $documentId = $response->json('document.id');
        $document = Document::find($documentId);

        $this->assertNotNull($document);
        $this->assertSame($project->id, $document->project_id);
        $this->assertSame($org->id, $document->organization_id);
        $this->assertSame($editor->id, $document->created_by);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(0, $document->file_size);
        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $this->assertSame(FridayPack::class, $document->documentable_type);
        $this->assertSame($pack->id, $document->documentable_id);
        $this->assertSame($document->id, $response->json('friday_pack.pdf_document_id'));
        $this->assertSame($document->id, $pack->fresh()->pdf_document_id);
    }

    public function test_document_type_and_category_are_set(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('doc2');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);

        $document = Document::where('documentable_id', $pack->id)->where('documentable_type', FridayPack::class)->first();
        $this->assertSame('friday_pack', $document->type);
        $this->assertSame('12_Friday_Packs', $document->category);
    }

    // ── Snapshot-only PDF (critical proof #1) ─────────────────────────────

    public function test_pdf_generation_uses_frozen_snapshot_not_live_data(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('snap1');
        ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Original Talk', 'talk_date' => self::FRIDAY, 'attendee_count' => 5,
        ]);
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        // Mutate LIVE source data — Friday Pack itself is NOT regenerated.
        ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'New Talk After Generation', 'talk_date' => self::FRIDAY, 'attendee_count' => 3,
        ]);

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);

        $document = Document::find($response->json('document.id'));
        $templateData = $document->template_data;
        $renderedSections = $templateData['pack']['sections'];
        $toolboxSection = collect($renderedSections)->firstWhere('key', 'toolbox_talks');

        $this->assertCount(1, $toolboxSection['data']['items'], 'PDF rendering input must reflect the frozen snapshot (1 talk), not live data (2 talks).');
        $this->assertSame('Original Talk', $toolboxSection['data']['items'][0]['title']);
    }

    // ── Commentary invalidation (critical proof #2) ───────────────────────

    public function test_editing_commentary_invalidates_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inv1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $originalDocumentId = $pack->fresh()->pdf_document_id;
        $this->assertNotNull($originalDocumentId);

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['executive_summary' => 'Updated summary.'])
            ->assertStatus(200);

        $this->assertNull($pack->fresh()->pdf_document_id, 'Editing Draft commentary must invalidate the current PDF pointer.');
        $this->assertNotNull(Document::find($originalDocumentId), 'The historical Document row must be preserved, never deleted.');
    }

    public function test_resubmitting_identical_commentary_does_not_invalidate_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inv2');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $pack->update(['executive_summary' => 'Same value']);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $documentId = $pack->fresh()->pdf_document_id;

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['executive_summary' => 'Same value'])
            ->assertStatus(200);

        $this->assertSame($documentId, $pack->fresh()->pdf_document_id, 'Resubmitting an unchanged value must not invalidate a valid current PDF.');
    }

    public function test_settings_update_does_not_invalidate_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inv3');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $documentId = $pack->fresh()->pdf_document_id;

        $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
            'enabled' => true, 'included_sections' => ['toolbox_talks'],
            'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
        ])->assertStatus(200);

        $this->assertSame($documentId, $pack->fresh()->pdf_document_id, 'A Settings change must never invalidate an already-generated pack\'s own PDF — settings_snapshot_json is frozen at generation time.');
    }

    // ── Snapshot regeneration invalidation (critical proof #3) ───────────

    public function test_regenerating_the_pack_invalidates_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inv4');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $originalDocumentId = $pack->fresh()->pdf_document_id;

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate")->assertStatus(200);

        $this->assertNull($pack->fresh()->pdf_document_id, 'Regenerating the Draft snapshot must invalidate the current PDF pointer.');
        $this->assertNotNull(Document::find($originalDocumentId), 'The historical Document row must be preserved.');

        // R1A.1: /regenerate always calls the CURRENT collector
        // (FridayPackGenerationService::applySnapshot()), so a
        // regenerated Draft is upgraded to schema 2 regardless of what
        // schema it was downgraded to for this test's own PDF-rendering
        // setup — see FridayPackSnapshotService's own docblock.
        // R1G.1-ACT (2026-09-03): a subsequent PDF generation attempt now
        // succeeds — SCHEMA_TWO_LIVE is true — producing a genuinely NEW
        // current PDF, never silently re-rendered through the
        // schema-1-only template and never reusing the invalidated
        // $originalDocumentId.
        $this->assertSame(2, $pack->fresh()->snapshot_json['schema_version'], 'Explicit regeneration must upgrade a legacy Draft to schema 2.');
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);
        $newDocumentId = $response->json('document.id');
        $this->assertNotSame($originalDocumentId, $newDocumentId, 'The invalidated Draft must produce a genuinely new Document, never reuse the old one.');
        $this->assertSame($newDocumentId, $pack->fresh()->pdf_document_id);
    }

    // ── Failure safety (critical proof #5) ────────────────────────────────

    public function test_failed_pdf_generation_leaves_pack_and_existing_current_pdf_untouched(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fail1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $originalSnapshot = $pack->snapshot_json;
        $originalCommentary = $pack->executive_summary;

        // Real failure (not a mock) via the same kill switch
        // DocumentGenerationService itself checks first — matches
        // PaymentApplicationCertificationPartialSuccessTest's established
        // convention for forcing a genuine generation failure.
        SuresignSetting::instance()->update(['feature_document_generation' => false]);

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(500);

        $fresh = $pack->fresh();
        $this->assertNull($fresh->pdf_document_id);
        $this->assertSame($originalSnapshot, $fresh->snapshot_json, 'Snapshot must survive a failed PDF generation untouched.');
        $this->assertSame($originalCommentary, $fresh->executive_summary);
        $this->assertSame('draft', $fresh->status);
    }

    public function test_failed_regeneration_preserves_existing_valid_current_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fail2');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
        $validDocumentId = $pack->fresh()->pdf_document_id;
        $this->assertNotNull($validDocumentId);

        SuresignSetting::instance()->update(['feature_document_generation' => false]);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(500);

        $this->assertSame($validDocumentId, $pack->fresh()->pdf_document_id, 'A failed regeneration attempt must never clear/replace an existing valid current PDF pointer.');
        $this->assertNotNull(Document::find($validDocumentId));
    }

    // ── Tenant / IDOR (critical proof #6) ─────────────────────────────────

    public function test_cross_organisation_pdf_generation_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t1');
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $foreignOrg = Organization::create(['name' => 'Foreign t1', 'slug' => 'foreign-t1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $foreignUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($foreignUser);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(403);
        $this->assertNull($pack->fresh()->pdf_document_id);
    }

    public function test_same_organisation_wrong_project_pdf_generation_is_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B t2']);
        Sanctum::actingAs($editor);
        $packB = $this->generatePdfRenderablePack($projectB, self::FRIDAY, $editor);

        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/pdf")->assertStatus(404);
        $this->assertNull($packB->fresh()->pdf_document_id);
    }

    public function test_admin_cannot_use_mismatched_parent_project_for_pdf_generation(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t3');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B t3']);
        Sanctum::actingAs($editor);
        $packB = $this->generatePdfRenderablePack($projectB, self::FRIDAY, $editor);

        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}/pdf")->assertStatus(404);
        $this->postJson("/api/projects/{$projectB->id}/friday-packs/{$packB->id}/pdf")->assertStatus(201);
    }

    public function test_document_download_is_governed_by_existing_document_authorization(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dl1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $documentId = $response->json('document.id');

        // The generic, existing Document download route — no new download
        // authorization system was introduced for Friday Pack PDFs.
        $this->getJson("/api/documents/{$documentId}/download")->assertStatus(200);

        $foreignOrg = Organization::create(['name' => 'Foreign dl1', 'slug' => 'foreign-dl1']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $foreignUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($foreignUser);
        $this->getJson("/api/documents/{$documentId}/download")->assertStatus(403);
    }

    // ── Real PDF smoke test (Phase 28) ────────────────────────────────────

    public function test_real_dompdf_render_produces_a_valid_non_empty_pdf_file(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('smoke1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $document = app(FridayPackPdfService::class)->generate($pack, $editor);

        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertGreaterThan(1000, $document->file_size, 'A real rendered PDF should be well over 1KB.');
        $this->assertTrue(Storage::disk('local')->exists($document->file_path));
        $rawContent = Storage::disk('local')->get($document->file_path);
        $this->assertStringStartsWith('%PDF-', $rawContent, 'File must be a genuine PDF, verified by its own magic header.');
    }

    // ── Draft status label (Phase 9) ──────────────────────────────────────

    public function test_template_data_carries_the_draft_status_label(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('draft1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $document = Document::find($response->json('document.id'));

        $this->assertSame('draft', $document->template_data['pack']['status']);
        $this->assertSame('DRAFT — NOT YET APPROVED', $document->template_data['pack']['status_label']);
    }

    // ── Feature Availability ──────────────────────────────────────────────

    public function test_maintenance_blocks_pdf_generation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fa1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);
        \App\Models\FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(503);
    }

    // ── R1A.1: schema version boundary ──────────────────────────────────────

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true` (V7 passed
     * final external visual QA — see project-context.md's R1G.1-ACT
     * entry). A real, non-downgraded pack — i.e. what
     * FridayPackGenerationService actually produces post-R1A — is schema
     * 2. PDF generation for it must now succeed via the REAL exposed route
     * (never only the presenter/service invoked directly), producing a
     * real stored Document and repointing pdf_document_id. This test
     * previously proved the opposite (a controlled 409 refusal, pending
     * visual QA) — updated in place now that condition has been satisfied.
     */
    public function test_schema_2_pdf_generation_succeeds_via_the_real_route(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2-1');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version'], 'A freshly generated pack must be schema 2 without any test downgrade.');

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);
        $document = $response->json('document');
        $this->assertNotNull($document);
        $this->assertSame($document['id'], $pack->fresh()->pdf_document_id, 'A successful schema-2 PDF generation must repoint pdf_document_id at the new Document.');
    }

    /**
     * R1G.1-ACT — a genuinely future/unsupported schema version must still
     * be refused exactly as before activation; SCHEMA_TWO_LIVE only ever
     * unblocks CURRENT_VERSION, never a hypothetical future one.
     */
    public function test_future_unsupported_schema_version_still_refused_via_the_real_route(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2-2');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $pack->forceFill(['snapshot_json' => array_merge($pack->snapshot_json, ['schema_version' => 3])])->save();

        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(409);
        $this->assertStringContainsString('temporarily unavailable', $response->json('message'));
        $this->assertNull($pack->fresh()->pdf_document_id, 'A refused unsupported-schema PDF attempt must never leave a pointer or partial Document behind.');
    }

    /** The legacy schema-1 path must still render successfully — this is
     * the actual behaviour proven throughout this file via
     * generatePdfRenderablePack(); this test makes the underlying
     * schema-1 assumption explicit in one place. */
    public function test_schema_1_legacy_pdf_generation_still_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v1-1');
        Sanctum::actingAs($editor);
        $pack = $this->generatePdfRenderablePack($project, self::FRIDAY, $editor);

        $this->assertSame(1, $pack->snapshot_json['schema_version']);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }
}
