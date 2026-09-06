<?php

namespace Tests\Feature;

use App\Models\DeliveryDocument;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\FridayPackPhotoSelection;
use App\Models\FridayPackSectionDeclaration;
use App\Models\HsInspection;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\PlantDeployment;
use App\Models\PlantItem;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\SiteInduction;
use App\Models\StatutoryInspection;
use App\Models\SuresignSetting;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackPdfService;
use App\Services\FridayPack\FridayPackSectionDeclarationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1G.1 — Schema-2 PDF Implementation. Mirrors
 * FridayPackPdfTest/FridayPackReadinessTest's exact conventions. See
 * FridayPackSchemaTwoPdfPresenter/pdfs/friday-pack-v2.blade.php docblocks
 * for the architectural decisions this proves.
 */
class FridayPackSchemaTwoPdfTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeOrgProjectAndEditor(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}"]);

        return [$org, $editor, $project];
    }

    private function generatePack(Project $project, User $editor): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
    }

    private function declare(FridayPack $pack, User $actor, string $section, string $subsection, string $declaration): FridayPackSectionDeclaration
    {
        return app(FridayPackSectionDeclarationService::class)->declare($pack->fresh(), $actor, $section, $subsection, $declaration, null);
    }

    /** Declares every conditional section confirmed-none/not-applicable, and fills the required text fields — mirrors FridayPackReadinessTest::makeFullyReadyPack(). */
    private function makeFullyReadyPack(Project $project, User $editor): FridayPack
    {
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => 'All works progressed as planned.', 'look_ahead' => 'Continue foundations next week.', 'site_issues_summary' => 'No issues.']);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $this->declare($pack->fresh(), $editor, $section, '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        }
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        return $pack->fresh();
    }

    private function generatePdf(FridayPack $pack, User $editor): Document
    {
        return app(FridayPackPdfService::class)->generate($pack->fresh(), $editor);
    }

    private function pdfBytes(Document $document): string
    {
        return Storage::disk('local')->get($document->file_path);
    }

    /**
     * Renders the schema-2 pipeline (presenter + pdfs.friday-pack-v2 +
     * real Dompdf) DIRECTLY — bypassing FridayPackPdfService/
     * DocumentGenerationService entirely. This is deliberate: the live
     * service still refuses every schema-2 pack
     * (`FridayPackPdfService::SCHEMA_TWO_LIVE === false` — see that
     * class's own docblock for why: visual QA could not be completed in
     * this environment). These tests prove the presenter/template/Dompdf
     * pipeline itself is correct and ready, independent of whether the
     * live customer-facing gate has been flipped yet.
     *
     * @return array{bytes: string, temp_files: string[]}
     */
    private function renderSchemaTwoPdfDirectly(FridayPack $pack): array
    {
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        $bytes = $pdf->output();

        return ['bytes' => $bytes, 'temp_files' => $presented['temp_files']];
    }

    // ── PIPELINE ─────────────────────────────────────────────────────────

    public function test_schema_1_pdf_still_generates_via_legacy_template_unchanged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p1');
        $pack = $this->generatePack($project, $editor);
        // Downgrade the already-persisted snapshot to legacy shape, exactly
        // mirroring FridayPackPdfTest's own established technique.
        $pack->forceFill(['snapshot_json' => array_merge($pack->snapshot_json, ['schema_version' => 1, 'sections' => []])])->save();

        $document = $this->generatePdf($pack, $editor);
        $this->assertStringStartsWith('%PDF-', $this->pdfBytes($document));
    }

    public function test_schema_2_pdf_pipeline_renders_a_valid_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2');
        $pack = $this->makeFullyReadyPack($project, $editor);

        $rendered = $this->renderSchemaTwoPdfDirectly($pack);
        $this->assertStringStartsWith('%PDF-', $rendered['bytes']);
        $this->assertGreaterThan(1000, strlen($rendered['bytes']));

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($rendered['temp_files']);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`: V7 passed
     * final external visual QA (see project-context.md's R1G.1-ACT entry).
     * This test previously proved the opposite (the customer-facing gate
     * staying closed pending visual QA) — updated in place, per this
     * checkpoint's own explicit instruction, now that the real condition
     * that test was guarding has actually been satisfied. Goes through the
     * REAL `FridayPackPdfService::generate()` (not just the presenter/Blade
     * directly), proving schema-2 now produces a real, stored Document via
     * the full service path.
     */
    public function test_schema_2_live_service_now_generates_a_real_pdf(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2b');
        $pack = $this->makeFullyReadyPack($project, $editor);

        $document = $this->generatePdf($pack, $editor);

        $this->assertStringStartsWith('%PDF-', $this->pdfBytes($document));
        $this->assertSame($document->id, $pack->fresh()->pdf_document_id);
    }

    public function test_unsupported_future_schema_version_still_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p3');
        $pack = $this->generatePack($project, $editor);
        $pack->forceFill(['snapshot_json' => array_merge($pack->snapshot_json, ['schema_version' => 3])])->save();

        $this->expectException(\App\Support\FridayPack\FridayPackUnsupportedSchemaVersionException::class);
        $this->generatePdf($pack, $editor);
    }

    public function test_new_document_row_created_each_generation_and_previous_preserved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p4');
        $pack = $this->makeFullyReadyPack($project, $editor);
        // Document/pdf_document_id MECHANICS are schema-version-agnostic —
        // proven here against the schema-1 path (mirrors FridayPackPdfTest's
        // own established technique). R1G.1-ACT: schema-2 now has its OWN
        // dedicated proof of this same invariant — see
        // test_new_document_row_created_each_generation_via_the_real_schema_two_path()
        // below — this test is kept as-is rather than converted, since it
        // already exercises the mechanics correctly and there is no reason
        // to duplicate schema-1's own regression coverage away from it.
        $pack->forceFill(['snapshot_json' => array_merge($pack->snapshot_json, ['schema_version' => 1, 'sections' => []])])->save();

        $first = $this->generatePdf($pack, $editor);
        $second = $this->generatePdf($pack->fresh(), $editor);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotNull(Document::find($first->id), 'previous Document row must still exist');
        $this->assertSame($second->id, $pack->fresh()->pdf_document_id);
    }

    /**
     * R1G.1-ACT (2026-09-03) — the same Document-history invariant proven
     * above, now exercised through the REAL, now-live schema-2 path (not a
     * schema-1 downgrade), via the REAL exposed route rather than the
     * service directly — end-to-end proof for schema-2 specifically.
     */
    public function test_new_document_row_created_each_generation_via_the_real_schema_two_path(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p4b');
        $pack = $this->makeFullyReadyPack($project, $editor);
        Sanctum::actingAs($editor);

        $firstResponse = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $firstResponse->assertStatus(201);
        $firstDocumentId = $firstResponse->json('document.id');

        $secondResponse = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $secondResponse->assertStatus(201);
        $secondDocumentId = $secondResponse->json('document.id');

        $this->assertNotSame($firstDocumentId, $secondDocumentId, 'Each schema-2 generation must create a NEW Document, never overwrite the previous one.');
        $this->assertNotNull(Document::find($firstDocumentId), 'The previous schema-2 Document row must still exist after regeneration.');
        $this->assertSame($secondDocumentId, $pack->fresh()->pdf_document_id, 'pdf_document_id must repoint at the newest Document only.');
    }

    public function test_pdf_document_id_untouched_when_generation_disabled(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p5');
        $pack = $this->makeFullyReadyPack($project, $editor);
        $pack->forceFill(['snapshot_json' => array_merge($pack->snapshot_json, ['schema_version' => 1, 'sections' => []])])->save();

        SuresignSetting::instance()->update(['feature_document_generation' => false]);

        try {
            $this->generatePdf($pack, $editor);
            $this->fail('Expected abort(403) when document generation is disabled.');
        } catch (\Throwable) {
            // expected — abort_unless() inside DocumentGenerationService
        }

        $this->assertNull($pack->fresh()->pdf_document_id);
    }

    // ── SECTIONS / DECLARATIONS ──────────────────────────────────────────

    public function test_all_declared_sections_render_neutral_or_declaration_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s1');
        $pack = $this->makeFullyReadyPack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString('No workforce was recorded on site', $html);
        $this->assertStringContainsString('No incidents to report', $html);
        $this->assertStringContainsString('No permit requirement applied', $html);
        $this->assertStringContainsString('Statutory inspection was not applicable', $html);
        // No compliance scoring / AI labelling anywhere in the rendered
        // BODY content (CSS percentage widths in the <style> block are
        // unrelated and expected — this checks the document body only).
        $bodyOnly = strtolower(substr($html, strpos($html, '<body>')));
        $this->assertStringNotContainsString('compliance score', $bodyOnly);
        $this->assertStringNotContainsString('ai summary', $bodyOnly);
        $this->assertDoesNotMatchRegularExpression('/\d+\s*%/', $bodyOnly);
    }

    public function test_excluded_section_omitted_and_numbering_is_fixed_not_renumbered(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s2');
        $pack = $this->makeFullyReadyPack($project, $editor);

        $settings = $pack->settings_snapshot_json;
        $included = array_values(array_diff($settings['included_sections'], ['plant_equipment']));
        $pack->forceFill(['settings_snapshot_json' => ['enabled' => true, 'included_sections' => $included]])->save();

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringNotContainsString('Plant &amp; Equipment', $html);
        // R1G.1-VFIX2: numbering is FIXED, never renumbered to close a gap
        // — Materials Delivered keeps its own number "7" even though "6.
        // Plant & Equipment" is excluded (a real paper form's numbering
        // doesn't shift because one chapter doesn't apply this week).
        $this->assertMatchesRegularExpression('/7\.\s*Materials Delivered/', $html);
        $this->assertStringNotContainsString('6. Plant', $html);
    }

    public function test_source_present_suppresses_declaration_after_regeneration(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('s3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);

        Incident::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'occurred_at' => '2026-08-19 09:00:00', 'type' => 'near_miss', 'title' => 'Dropped tool',
            'injury_occurred' => false, 'status' => 'open',
        ]);

        $regenerated = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($regenerated->fresh())['data']])->render();

        $this->assertStringContainsString('Dropped tool', $html);
        $this->assertStringNotContainsString('No incidents to report', $html);
    }

    public function test_declaration_attribution_shown_and_omitted_when_confirmer_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s4');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);

        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());
        $incidentsSection = $this->findSection($presented['data'], 'incidents');
        $this->assertNotNull($incidentsSection['declaration']['attribution']);
        $this->assertStringContainsString('Confirmed by', $incidentsSection['declaration']['attribution']);

        $editor->delete();

        $presentedAfterDeletion = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());
        $incidentsSectionAfter = $this->findSection($presentedAfterDeletion['data'], 'incidents');
        $this->assertNull($incidentsSectionAfter['declaration']['attribution']);
        $this->assertStringContainsString('No incidents to report', $incidentsSectionAfter['declaration']['statement']);
    }

    /**
     * R1G.1-VFIX2 — five backend keys (rams/toolbox_talks/site_inductions/
     * incidents/hs_inspections) now live nested under the top-level
     * 'health_and_safety' entry's 'children' array, not as their own
     * top-level entries. This helper searches both levels so existing
     * tests don't need to know which shape a given key lives in.
     */
    private function findSection(array $presentedData, string $key): ?array
    {
        foreach ($presentedData['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
            if ($section['kind'] === 'health_and_safety') {
                foreach ($section['children'] as $child) {
                    if ($child['key'] === $key) {
                        return $child;
                    }
                }
            }
        }

        return null;
    }

    // ── SEMANTICS ────────────────────────────────────────────────────────

    public function test_person_days_terminology_never_labelled_unique_workers(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('sem1');
        SiteDiary::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $editor->id, 'diary_date' => '2026-08-17', 'status' => 'submitted', 'workers_on_site' => 6]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack)['data']])->render();
        $this->assertStringContainsString('Person-Days', $html);
        $this->assertStringNotContainsString('Total Workers', $html);
        $this->assertStringNotContainsString('Unique Workers', $html);
    }

    public function test_incident_description_never_rendered(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('sem2');
        Incident::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'occurred_at' => '2026-08-19 09:00:00', 'type' => 'incident', 'title' => 'Slip on wet floor',
            'description' => 'CONFIDENTIAL-MEDICAL-DETAIL-MUST-NEVER-APPEAR', 'injury_occurred' => null, 'status' => 'open',
        ]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack)['data']])->render();
        $this->assertStringContainsString('Slip on wet floor', $html);
        $this->assertStringNotContainsString('CONFIDENTIAL-MEDICAL-DETAIL', $html);
        $this->assertStringContainsString('Not confirmed', $html); // null injury_occurred
    }

    public function test_hs_inspection_never_says_site_compliant(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('sem3');
        HsInspection::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => '2026-08-18', 'inspection_type' => 'Weekly walkaround', 'inspected_by' => 'J. Smith',
            'outcome' => 'satisfactory', 'status' => 'closed',
        ]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = strtolower(view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack)['data']])->render());
        $this->assertStringNotContainsString('site compliant', $html);
        $this->assertStringNotContainsString('compliant', $html);
    }

    public function test_no_signature_claim_in_sign_off(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sem4');
        $pack = $this->generatePack($project, $editor);

        $html = strtolower(view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack)['data']])->render());
        $this->assertStringNotContainsString('e-signed', $html);
        $this->assertStringNotContainsString('electronically signed', $html);
        $this->assertStringNotContainsString('digitally signed', $html);
    }

    // ── PHOTOGRAPHS ──────────────────────────────────────────────────────

    private function makeSelectedPhoto(Project $project, User $editor, string $diaryDate, string $imageBytes, ?string $caption = null, ?string $location = null): FridayPackPhotoSelection
    {
        $diary = SiteDiary::create(['project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id, 'diary_date' => $diaryDate, 'status' => 'submitted']);
        $path = "projects/{$project->id}/site-diaries/{$diary->id}/" . uniqid() . '.jpg';
        Storage::disk('local')->put($path, $imageBytes);

        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'uploaded_by' => $editor->id,
            'attachable_type' => SiteDiary::class, 'attachable_id' => $diary->id,
            'original_name' => 'evidence.jpg', 'stored_name' => basename($path), 'file_path' => $path,
            'mime_type' => 'image/jpeg', 'file_size' => strlen($imageBytes), 'disk' => 'local',
        ]);

        $pack = FridayPack::where('project_id', $project->id)->whereDate('week_ending', self::FRIDAY)->firstOrFail();

        return FridayPackPhotoSelection::create([
            'friday_pack_id' => $pack->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'file_upload_id' => $upload->id, 'source_type' => 'site_report', 'source_id' => $diary->id, 'source_date' => $diaryDate,
            'original_file_name' => 'evidence.jpg', 'caption' => $caption, 'location' => $location, 'sort_order' => 1, 'selected_by' => $editor->id,
        ]);
    }

    /** A minimal, genuinely valid 2x2 JPEG (real magic bytes + real GD-decodable content), matching this repo's "fake upload magic bytes" testing convention. */
    private function realJpegBytes(): string
    {
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 50, 50));
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function realJpegBytesWithSize(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 100, 120, 140));
        ob_start();
        imagejpeg($image, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** @return array{presenter_output: array, temp_files: string[]} */
    private function presentWithPhotos(Project $project, User $editor, array $sizes): array
    {
        $this->generatePack($project, $editor);
        foreach ($sizes as $i => [$w, $h]) {
            $this->makeSelectedPhoto($project, $editor, self::MONDAY, $this->realJpegBytesWithSize($w, $h), "Photo {$i}");
        }
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        return ['presenter_output' => $presented, 'temp_files' => $presented['temp_files']];
    }

    private function photoRows(array $presented): array
    {
        $photoSection = collect($presented['data']['sections'])->firstWhere('key', 'site_photographs');

        return $photoSection['content']['rows'];
    }

    public function test_selected_photo_renders_with_caption_and_location_and_is_optimised(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('photo1');
        $this->generatePack($project, $editor);
        $this->makeSelectedPhoto($project, $editor, '2026-08-17', $this->realJpegBytes(), 'Trench excavation', 'Plot 4');

        $regenerated = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($regenerated->fresh());

        $photoSection = collect($presented['data']['sections'])->firstWhere('key', 'site_photographs');
        $this->assertSame('source', $photoSection['render_mode']);
        // R1G.1-VFIX3: content-adaptive layout groups photos into 'rows'
        // (single/pair) rather than a flat 'items' list — see
        // FridayPackSchemaTwoPdfPresenter::groupPhotosIntoRows().
        $item = $photoSection['content']['rows'][0]['photos'][0];
        $this->assertSame('Trench excavation — Plot 4', $item['caption_location']);
        $this->assertNotNull($item['image_uri']);
        $this->assertStringStartsWith('file://', $item['image_uri']);
        $this->assertFileExists(substr($item['image_uri'], 7));

        // Cleanup happens via FridayPackPdfService, not the presenter directly —
        // clean up this test's own temp file so it doesn't leak.
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    public function test_missing_selected_photo_file_renders_placeholder_not_crash(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('photo2');
        $this->generatePack($project, $editor);
        $selection = $this->makeSelectedPhoto($project, $editor, '2026-08-17', $this->realJpegBytes());

        // Delete the underlying file after selection — simulating a
        // vanished attachment on a still-Draft pack's defensive preview
        // path (never reachable for Ready/Approved — see
        // FridayPackPhotoSelectionService::assertAllSelectionsValid()).
        Storage::disk('local')->delete($selection->fileUpload->file_path);

        $regenerated = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        // Must not throw.
        $rendered = $this->renderSchemaTwoPdfDirectly($regenerated);
        $this->assertStringStartsWith('%PDF-', $rendered['bytes']);
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($rendered['temp_files']);
    }

    public function test_raw_storage_path_never_exposed_in_presented_data(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('photo3');
        $this->generatePack($project, $editor);
        $this->makeSelectedPhoto($project, $editor, '2026-08-17', $this->realJpegBytes());
        $regenerated = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($regenerated->fresh());
        $json = json_encode($presented['data']);
        $this->assertStringNotContainsString('projects/' . $project->id . '/site-diaries', $json);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    // ── BRANDING ─────────────────────────────────────────────────────────

    public function test_organisation_display_name_falls_back_without_logo(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('brand1');
        $pack = $this->generatePack($project, $editor);

        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack);
        $this->assertSame($project->organization->name, $presented['data']['organisation_display_name']);
    }

    // ── PAGE NUMBERS / REAL PDF PROOF ────────────────────────────────────

    public function test_real_multi_page_pdf_has_working_page_counter(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('page1');
        $this->generatePack($project, $editor);
        // Several selected photos to force genuine multi-page pagination.
        for ($i = 0; $i < 4; $i++) {
            $this->makeSelectedPhoto($project, $editor, '2026-08-17', $this->realJpegBytes(), "Photo {$i}");
        }
        $regenerated = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $rendered = $this->renderSchemaTwoPdfDirectly($regenerated->fresh());

        $bytes = $rendered['bytes'];
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(2000, strlen($bytes));
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($rendered['temp_files']);

        // Real Dompdf canvas introspection — the authoritative,
        // dependency-free way to prove genuine multi-page output (no PDF
        // text-parsing library exists or was added in this codebase).
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($regenerated->fresh());
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        $pdf->render();
        $pageCount = $pdf->getDomPDF()->getCanvas()->get_page_count();
        $this->assertGreaterThan(1, $pageCount, 'Expected the photo-heavy fixture to paginate across multiple pages.');

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    // ── R1G.1-VFIX2: presentation hierarchy structural contract ─────────

    public function test_presentation_hierarchy_matches_the_fixed_10_section_structure(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('hier1');
        $pack = $this->makeFullyReadyPack($project, $editor);
        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // Report Information is a header block, never a numbered section.
        $this->assertStringNotContainsString('1. Report Information', $html);
        $this->assertMatchesRegularExpression('/1\.\s*Weekly Summary/', $html);
        $this->assertMatchesRegularExpression('/2\.\s*Workforce on Site/', $html);
        $this->assertMatchesRegularExpression('/3\.\s*Site Photographs/', $html);
        $this->assertMatchesRegularExpression('/4\.\s*Health &amp; Safety/', $html);
        $this->assertMatchesRegularExpression('/4\.1\s*RAMS/', $html);
        $this->assertMatchesRegularExpression('/4\.2\s*Toolbox Talks/', $html);
        $this->assertMatchesRegularExpression('/4\.3\s*Site Inductions/', $html);
        $this->assertMatchesRegularExpression('/4\.4\s*Accidents/', $html);
        $this->assertMatchesRegularExpression('/4\.5\s*H&amp;S Inspections/', $html);
        $this->assertMatchesRegularExpression('/5\.\s*Permits/', $html);
        $this->assertMatchesRegularExpression('/6\.\s*Plant &amp; Equipment/', $html);
        $this->assertMatchesRegularExpression('/7\.\s*Materials Delivered/', $html);
        $this->assertMatchesRegularExpression('/8\.\s*Site Issues/', $html);
        $this->assertMatchesRegularExpression('/9\.\s*Look Ahead/', $html);
        $this->assertMatchesRegularExpression('/10\.\s*Sign Off/', $html);
    }

    public function test_backend_snapshot_and_readiness_keys_unchanged_by_presentation_restructure(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('hier2');
        $pack = $this->makeFullyReadyPack($project, $editor);

        // The FROZEN SNAPSHOT still uses the original flat 15 backend keys
        // — the presentation hierarchy is a pure display-layer grouping,
        // never reflected back into stored data.
        $sections = $pack->fresh()->snapshot_json['sections'];
        foreach (['rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'permits_inspections', 'plant_equipment'] as $key) {
            $this->assertArrayHasKey($key, $sections);
        }
        $this->assertArrayNotHasKey('health_and_safety', $sections);

        // Readiness/declaration keys are likewise untouched — 'incidents'
        // is still declared under its own real backend key, never
        // 'health_and_safety' or 'health_and_safety.incidents'.
        $declaration = app(\App\Services\FridayPack\FridayPackSectionDeclarationService::class)
            ->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE, null);
        $this->assertSame('incidents', $declaration->section_key);
    }

    public function test_dark_table_header_class_applied_to_schema_two_tables(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('hier3');
        DeliveryDocument::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id, 'category' => 'rams', 'title' => 'RAMS Doc', 'status' => 'approved']);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString('class="data-table"', $html);
        $this->assertStringContainsString('background: #33393f', $html); // dark-slate table header — never schema-1's blue
        $this->assertStringNotContainsString('#1f3864', $html); // schema-1's blue never leaks into schema-2
    }

    public function test_site_photographs_heading_never_orphaned_from_content(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('hier4');
        $this->generatePack($project, $editor);
        $this->makeSelectedPhoto($project, $editor, '2026-08-17', $this->realJpegBytes());
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // The heading and the photo grid are both inside the SAME
        // page-break-before wrapper — structurally impossible for Dompdf
        // to orphan the heading on a different page than its content.
        $this->assertMatchesRegularExpression(
            '/<div class="site-photographs-block">\s*<h2[^>]*>3\.\s*Site Photographs<\/h2>.*?photo-row/s',
            $html
        );
    }

    public function test_repeating_chrome_is_no_longer_html_and_never_leaks_the_raw_page_token(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('hier5');
        $pack = $this->generatePack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // R1G.1-VFIX6 — the repeating header/footer is no longer CSS
        // `position: fixed` HTML at all (found unreliable on continuation
        // pages — see DocumentGenerationService::generatePdf()'s
        // `$repeatingChrome` docblock). It is now painted entirely by
        // that service's canvas mechanism — see
        // FridayPackSchemaTwoPdfTest's own dedicated canvas-coordinate
        // tests below for the real proof.
        $this->assertStringNotContainsString('class="chrome-header"', $html);
        $this->assertStringNotContainsString('class="chrome-footer"', $html);
        $this->assertStringNotContainsString('Page {PAGE_NUM}', $html); // never leaks the raw token into HTML content
    }

    // ── R1G.1-VFIX3: margins, typography, workforce, page flow ──────────

    public function test_page_uses_15mm_left_right_margins(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-margin');
        $pack = $this->generatePack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // R1G.1-VFIX5: real content bounds come from `.report-content`'s
        // own padding, NOT `@page margin-left/right` — confirmed via real
        // PDF content-stream coordinate extraction that Dompdf does not
        // reliably honour `@page` left/right margins for ordinary body
        // content in this configuration (see this file's own docblock and
        // project-context.md's VFIX5 entry for the numeric proof).
        $this->assertStringContainsString('padding-left: 15mm', $html);
        $this->assertStringContainsString('padding-right: 15mm', $html);
        $this->assertStringContainsString('<div class="report-content">', $html);
        // R1G.1-VFIX6: the chrome header/footer are no longer HTML at all
        // (see test_repeating_chrome_is_no_longer_html_and_never_leaks_the_raw_page_token
        // and test_header_and_footer_stay_inside_the_15mm_safe_column for
        // where the "shares the same 15mm inset" proof now lives).
    }

    /**
     * R1G.1-VFIX5 — real content-bound proof via actual PDF content-stream
     * coordinates, not CSS-string assertions alone (per this checkpoint's
     * own explicit instruction). A4 width is 595.28pt; 15mm ≈ 42.5pt, so
     * ordinary content must render with a left edge in the low 40s and a
     * right edge below ~553pt — never at x≈0.
     */
    public function test_report_information_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-coords');
        $pack = $this->generatePack($project, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        $bytes = $pdf->output();

        [$minX, $maxRight] = $this->wideRectangleBounds($bytes);

        $this->assertNotNull($minX, 'Expected at least one wide content rectangle (e.g. the info-grid label cells) in the rendered PDF.');
        $this->assertGreaterThanOrEqual(40.0, $minX, 'Content left edge must respect the ~42.5pt (15mm) safe margin, not render near the physical page edge.');
        $this->assertLessThanOrEqual(555.0, $maxRight, 'Content right edge must respect the ~42.5pt (15mm) safe margin on the right.');

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    /**
     * Extracts every "x y w h re" rectangle-fill/stroke operator from the
     * PDF's own (decompressed) content streams and returns [minX, maxRight]
     * across every rectangle wider than 30pt (filters out small glyph/
     * decoration artefacts, keeping table cell/border-scale rectangles) —
     * excluding the one full-page background rect (width ~595, the
     * `body { background: #ffffff }` page fill, not a content boundary).
     * This is the SAME technique used to originally diagnose the VFIX5
     * margin defect — real coordinates from the actual rendered PDF, never
     * CSS-string inference.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function wideRectangleBounds(string $pdfBytes): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdfBytes, $m);
        $minX = null;
        $maxRight = null;
        foreach ($m[1] as $stream) {
            $stream = rtrim($stream, "\r\n");
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false || !preg_match('/\bBT\b.*\bET\b/s', $decoded)) {
                continue; // not a real content stream (font data, CMaps, etc.)
            }
            preg_match_all('/([\-\d.]+) ([\-\d.]+) ([\-\d.]+) ([\-\d.]+) re/', $decoded, $rects, PREG_SET_ORDER);
            foreach ($rects as $r) {
                $x = (float) $r[1];
                $w = (float) $r[3];
                if ($w <= 30 || $w >= 590) { // skip tiny artefacts and the full-page background fill
                    continue;
                }
                $right = $x + $w;
                if ($minX === null || $x < $minX) {
                    $minX = $x;
                }
                if ($maxRight === null || $right > $maxRight) {
                    $maxRight = $right;
                }
            }
        }

        return [$minX, $maxRight];
    }

    public function test_font_is_dejavu_sans_with_no_remote_dependency(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-font');
        $pack = $this->generatePack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString("font-family: 'DejaVu Sans'", $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('@font-face', $html);
        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
    }

    public function test_workforce_renders_as_a_single_table(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-wf1');
        SiteDiary::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $editor->id, 'diary_date' => self::MONDAY, 'status' => 'submitted', 'workers_on_site' => 8]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // Exactly one workforce <table> element — never a separate
        // weekday-totals band plus a second trade table (R1G.1-VFIX2's
        // own layout).
        $this->assertSame(1, substr_count($html, '<table class="data-table workforce-table">'));
        $this->assertStringContainsString('Daily Total', $html);
        $this->assertStringNotContainsString('Total Workers', $html);
        $this->assertStringNotContainsString('Unique Workers', $html);
    }

    public function test_workforce_conflict_state_never_fabricates_a_total(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-wf2');
        SiteDiary::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $editor->id, 'diary_date' => self::MONDAY, 'status' => 'submitted', 'workers_on_site' => 5]);
        SiteDiary::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $editor->id, 'diary_date' => self::MONDAY, 'status' => 'submitted', 'workers_on_site' => 9]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString('Conflicting reports', $html);
    }

    public function test_health_and_safety_forces_new_page(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-flow1');
        $pack = $this->generatePack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString('class="health-and-safety-block"', $html);
        $this->assertStringContainsString('.health-and-safety-block { page-break-before: always; padding-top: 40px; }', $html);
    }

    public function test_section_five_forces_new_page_after_health_and_safety(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-flow2');
        $pack = $this->generatePack($project, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertMatchesRegularExpression('/<h2 class="section-heading" style="page-break-before: always; padding-top: 40px;">5\./', $html);
    }

    public function test_section_five_new_page_moves_to_next_group_member_when_permits_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-flow3');
        $pack = $this->generatePack($project, $editor);
        $included = array_values(array_diff($pack->settings_snapshot_json['included_sections'], ['permits_inspections']));
        $pack->forceFill(['settings_snapshot_json' => ['enabled' => true, 'included_sections' => $included]])->save();

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringNotContainsString('5. Permits', $html);
        $this->assertMatchesRegularExpression('/<h2 class="section-heading" style="page-break-before: always; padding-top: 40px;">6\./', $html);
    }

    public function test_one_photo_renders_as_a_larger_lone_block(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-p1');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600]]); // landscape
        $rows = $this->photoRows($result['presenter_output']);

        // R1G.1-VFIX5: exactly one photo overall renders larger ('lone'),
        // never squeezed into a half-width evidence column.
        $this->assertCount(1, $rows);
        $this->assertSame('lone', $rows[0]['kind']);
        $this->assertSame('landscape', $rows[0]['photos'][0]['orientation']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_two_landscape_photos_pair_into_one_evidence_row(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-p2');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [2200, 1400]]);
        $rows = $this->photoRows($result['presenter_output']);

        // R1G.1-VFIX5: this is the exact case external QA flagged as
        // wasteful — two ordinary landscape photos must pair into ONE
        // two-column row (each sized to its own evidence column), never
        // consume two separate near-full-page rows.
        $this->assertCount(1, $rows);
        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertCount(2, $rows[0]['photos']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_two_portrait_photos_pair_into_one_row(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-p3');
        $result = $this->presentWithPhotos($project, $editor, [[1600, 2400], [1500, 2100]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertCount(1, $rows);
        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertCount(2, $rows[0]['photos']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_three_mixed_photos_flow_naturally(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-p4');
        // R1G.1-VFIX5: pairing is SEQUENTIAL (selection order), never
        // orientation-grouped — landscape, portrait, portrait pairs as
        // [landscape+portrait], then a trailing single portrait.
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [1600, 2400], [1500, 2100]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertCount(2, $rows);
        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertSame('single', $rows[1]['kind']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_four_mixed_photos_match_the_representative_fixture_expectation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-p4b');
        // The exact representative-fixture order (2 landscape, 2 portrait)
        // the R1G.1-VFIX5 checkpoint itself describes as the expected
        // result: landscape+landscape pair, then portrait+portrait pair —
        // never one lone landscape per page with wasted space.
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [2200, 1400], [1600, 2400], [1500, 2100]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertCount(2, $rows);
        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertSame('landscape', $rows[0]['photos'][0]['orientation']);
        $this->assertSame('landscape', $rows[0]['photos'][1]['orientation']);
        $this->assertSame('pair', $rows[1]['kind']);
        $this->assertSame('portrait', $rows[1]['photos'][0]['orientation']);
        $this->assertSame('portrait', $rows[1]['photos'][1]['orientation']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_five_photos_flow_across_multiple_rows_without_hardcoded_count(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-p5');
        $result = $this->presentWithPhotos($project, $editor, [
            [1600, 2400], [1500, 2100], [1600, 2200], [1550, 2300], [2400, 1600],
        ]);
        $rows = $this->photoRows($result['presenter_output']);

        // 4 pair sequentially into 2 rows, the trailing 5th is its own row.
        $totalPhotos = array_sum(array_map(fn ($r) => count($r['photos']), $rows));
        $this->assertSame(5, $totalPhotos);
        $this->assertCount(3, $rows);
        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertSame('pair', $rows[1]['kind']);
        $this->assertSame('single', $rows[2]['kind']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_odd_photo_count_leaves_a_trailing_single(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-p6');
        $result = $this->presentWithPhotos($project, $editor, [[1600, 2400], [1500, 2100], [1550, 2300]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertSame('pair', $rows[0]['kind']);
        $this->assertSame('single', $rows[1]['kind']);
        $this->assertFalse($rows[1]['photos'][0]['is_panoramic']); // trailing photo, never stretched full-width

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_panoramic_photo_spans_full_evidence_width(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-pano');
        // ratio 2400/1000 = 2.4, comfortably over the 2.0 panoramic threshold.
        $result = $this->presentWithPhotos($project, $editor, [[1600, 2400], [2400, 1000]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertCount(2, $rows);
        $this->assertSame('single', $rows[0]['kind']); // the portrait, left without a pairing partner
        $this->assertSame('panoramic', $rows[1]['kind']);
        $this->assertTrue($rows[1]['photos'][0]['is_panoramic']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_ordinary_16_by_9_photo_is_not_treated_as_panoramic(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-169');
        // 1920x1080 = 16:9 ≈ 1.78 ratio — a completely normal site photo,
        // must stay an ordinary evidence-column candidate, never full-width.
        $result = $this->presentWithPhotos($project, $editor, [[1600, 900], [1600, 900]]);
        $rows = $this->photoRows($result['presenter_output']);

        $this->assertCount(1, $rows);
        $this->assertSame('pair', $rows[0]['kind']);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    public function test_photo_img_tag_never_sets_both_fixed_width_and_height(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix3-p7');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [1600, 2400]]);
        $html = view('pdfs.friday-pack-v2', ['pack' => $result['presenter_output']['data']])->render();

        $this->assertStringNotContainsString('height="', $html); // no HTML height attribute anywhere on an <img>
        $this->assertStringContainsString('max-width: 100%; height: auto', str_replace(["\n", "  "], '', $this->cssBlock($html, 'photo-block-column img.photo-img')) ?: '');

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    private function cssBlock(string $html, string $selector): ?string
    {
        if (preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    // ── Post-Deploy Photo Hardening, P1 — WebP source, full real pipeline ──

    /**
     * The complete real chain for a genuine WebP source photo, through
     * the ACTUAL public surfaces a user goes through — never a shortcut
     * that fabricates a FridayPackPhotoSelection row directly (that's
     * what `makeSelectedPhoto()` above does for JPEG-only tests; this one
     * deliberately does not reuse it):
     *
     *   real WebP FileUpload (stored mime_type stays image/webp)
     *   → GET photo-candidates (FridayPackPhotoDiscoveryService — proves
     *     discovery accepts it, since it only ever filters on
     *     mime_type LIKE 'image/%', never a specific format)
     *   → POST photo-selections (FridayPackPhotoSelectionService — proves
     *     selection accepts it)
     *   → regenerated snapshot contains the frozen selection
     *   → FridayPackSchemaTwoPdfPresenter resolves a real (non-null)
     *     image_uri — proving imagecreatefromwebp() decoded it rather
     *     than falling back to the "Photo unavailable" placeholder
     *   → the REAL generated PDF's own raw bytes contain a genuine
     *     embedded JPEG (SOI marker `\xFF\xD8\xFF`) — the same
     *     dependency-free proof method this codebase established in
     *     R1G.1-VFIX2 for the original JPEG fix, applied here to prove a
     *     WebP INPUT still produces real embedded image content, never a
     *     placeholder and never raw WebP bytes (the PDF's own JPEG output
     *     format is deliberately unchanged).
     *
     * Requires a GD build with WebP encode support to construct the real
     * fixture — skips (never fakes a pass) on a GD build without it.
     */
    public function test_webp_source_photo_is_discovered_selected_and_rendered_as_real_jpeg_in_the_pdf(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('This environment\'s GD build has no WebP encode support (imagewebp) to construct a real fixture with.');
        }

        [, $editor, $project] = $this->makeOrgProjectAndEditor('webp-e2e');
        $pack = $this->generatePack($project, $editor);

        $diary = SiteDiary::create(['project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id, 'diary_date' => self::MONDAY, 'status' => 'submitted']);

        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 60, 130, 90));
        ob_start();
        imagewebp($image, null, 90);
        $webpBytes = ob_get_clean();
        imagedestroy($image);
        $this->assertStringStartsWith('RIFF', $webpBytes, 'Fixture must be a genuine WebP file, not a fallback format.');

        $path = "projects/{$project->id}/site-diaries/{$diary->id}/" . uniqid() . '.webp';
        Storage::disk('local')->put($path, $webpBytes);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'uploaded_by' => $editor->id,
            'attachable_type' => SiteDiary::class, 'attachable_id' => $diary->id,
            'original_name' => 'evidence.webp', 'stored_name' => basename($path), 'file_path' => $path,
            'mime_type' => 'image/webp', 'file_size' => strlen($webpBytes), 'disk' => 'local',
        ]);

        Sanctum::actingAs($editor);

        // 1. Real discovery — the candidates endpoint, not a direct query.
        $candidates = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")->json('candidates');
        $webpCandidate = collect($candidates)->firstWhere('file_upload_id', $upload->id);
        $this->assertNotNull($webpCandidate, 'Expected the WebP attachment to be discovered as a candidate — discovery only filters on mime_type LIKE image/%, never a specific format.');

        // 2. Real selection — the real endpoint.
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);
        $this->assertDatabaseHas('friday_pack_photo_selections', ['file_upload_id' => $upload->id, 'friday_pack_id' => $pack->id]);

        // 3. Frozen snapshot contains it.
        $pack = $pack->fresh();
        $this->assertSame(1, $pack->snapshot_json['sections']['site_photographs']['count']);

        // 4. Presenter resolves a real image, not a placeholder — proves
        // imagecreatefromwebp() genuinely decoded the source.
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack);
        $rows = $this->photoRows($presented);
        $this->assertNotEmpty($rows);
        $imageUri = collect($rows)->flatMap(fn ($row) => $row['photos'] ?? [$row])->pluck('image_uri')->filter()->first();
        $this->assertNotNull($imageUri, 'Expected a real image_uri — a WebP source falling back to null would mean GD still cannot decode WebP.');
        $this->assertStringStartsWith('file://', $imageUri);
        $this->assertStringEndsWith('.jpg', $imageUri, 'The optimised rendition must still be a JPEG file — the PDF output format is unchanged for a WebP source.');

        // 5. The REAL generated PDF's raw bytes contain a genuine embedded
        // JPEG (not a placeholder, not raw WebP).
        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $this->assertStringContainsString("\xFF\xD8\xFF", $bytes, 'Expected a real embedded JPEG (SOI marker) in the generated PDF — proves the WebP source was genuinely decoded and re-encoded, not rendered as a placeholder.');

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    // ── R1G.1-VFIX5: real PDF coordinate proof for every remaining
    //    content region the checkpoint named (Workforce, H&S, final-page
    //    Statutory Inspections, Sign Off, photo evidence, header/footer)
    //    — the SAME wideRectangleBounds() content-stream technique used
    //    above for Report Information, never a CSS-string assertion. ──

    public function test_workforce_table_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-wf-coords');
        SiteDiary::create(['project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $editor->id, 'diary_date' => self::MONDAY, 'status' => 'submitted', 'workers_on_site' => 8]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        [$minX, $maxRight] = $this->wideRectangleBounds($pdf->output());

        $this->assertNotNull($minX, 'Expected the workforce table border rectangles to be present.');
        $this->assertGreaterThanOrEqual(40.0, $minX);
        $this->assertLessThanOrEqual(555.0, $maxRight);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    public function test_health_and_safety_rams_table_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-hs-coords');
        DeliveryDocument::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id, 'category' => 'rams', 'title' => 'Excavation RAMS', 'status' => 'approved']);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        [$minX, $maxRight] = $this->wideRectangleBounds($pdf->output());

        $this->assertNotNull($minX, 'Expected the RAMS table border rectangles to be present.');
        $this->assertGreaterThanOrEqual(40.0, $minX);
        $this->assertLessThanOrEqual(555.0, $maxRight);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    public function test_final_page_statutory_inspections_table_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-final-coords');
        StatutoryInspection::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => '2026-08-18', 'inspection_type' => 'Scaffold inspection', 'subject_description' => 'North scaffold tower',
            'outcome' => 'pass', 'status' => 'completed',
        ]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        $html = view('pdfs.friday-pack-v2', ['pack' => $presented['data']])->render();
        $this->assertStringContainsString('Scaffold inspection', $html);

        [$minX, $maxRight] = $this->wideRectangleBounds($pdf->output());
        $this->assertNotNull($minX, 'Expected the final-page Statutory Inspections table border rectangles to be present.');
        $this->assertGreaterThanOrEqual(40.0, $minX);
        $this->assertLessThanOrEqual(555.0, $maxRight);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    public function test_sign_off_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-signoff-coords');
        $pack = $this->generatePack($project, $editor);
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $presented['data']])->setPaper('a4', 'portrait');
        [$minX, $maxRight] = $this->wideRectangleBounds($pdf->output());

        // Every pack (even a bare, freshly-generated one) always renders a
        // Sign Off section — this proves the whole-document safe margin
        // extends all the way to the final numbered section, not just the
        // first page's Report Information block.
        $this->assertNotNull($minX);
        $this->assertGreaterThanOrEqual(40.0, $minX);
        $this->assertLessThanOrEqual(555.0, $maxRight);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);
    }

    public function test_photo_evidence_region_actual_pdf_coordinates_respect_the_safe_margin(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-photo-coords');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [2200, 1400], [1600, 2400], [1500, 2100]]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdfs.friday-pack-v2', ['pack' => $result['presenter_output']['data']])->setPaper('a4', 'portrait');
        [$minX, $maxRight] = $this->wideRectangleBounds($pdf->output());

        // Photo evidence borders (see `.photo-img { border: 1px solid
        // #cccccc }`) are real drawn rectangles, so this proves the photo
        // region itself — not just surrounding text tables — sits inside
        // the same 15mm safe column, never bleeding to the physical edge.
        $this->assertNotNull($minX);
        $this->assertGreaterThanOrEqual(40.0, $minX);
        $this->assertLessThanOrEqual(555.0, $maxRight);

        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
    }

    /**
     * R1G.1-VFIX6 — builds a real multi-page schema-2 document through the
     * FULL `DocumentGenerationService::generatePdf()` pipeline (not just
     * `Pdf::loadView()`), since the repeating header/footer is now drawn
     * by that service's own canvas mechanism, not CSS. Bypasses the
     * still-gated `FridayPackPdfService` the same way
     * `renderSchemaTwoPdfDirectly()` does, but goes one level deeper
     * because the chrome under test lives in `DocumentGenerationService`
     * itself. Returns the real generated PDF bytes.
     */
    private function generateSchemaTwoPdfWithChrome(FridayPack $pack, User $editor, Project $project): string
    {
        $presented = app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh());
        $info = $presented['data']['report_information'];
        $identityLine = trim(($info['project_name'] ?? $project->name)
            . ($info['report_number'] ? " — Report {$info['report_number']}" : '')
            . " — Week Ending {$presented['data']['week_ending_label']}");

        $document = \App\Services\DocumentGenerationService::generatePdf(
            $project, $editor, 'pdfs.friday-pack-v2', ['pack' => $presented['data']],
            'Friday Pack Test', 'friday_pack', '12_Friday_Packs', 'FP-TEST',
            $pack, includePageNumbers: true, pageNumberYFromBottomPx: 20, pageNumberXFromRightPx: 135,
            repeatingChrome: [
                'header_left'    => $presented['data']['organisation_display_name'],
                'header_right'   => 'WEEKLY FRIDAY PACK',
                'header_subline' => $identityLine,
                'footer_left'    => $identityLine,
                'footer_center'  => 'Generated with SureSign',
            ],
        );
        $bytes = Storage::disk('local')->get($document->file_path);
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($presented['temp_files']);

        return $bytes;
    }

    /**
     * Extracts every `Tj`/`TJ` text-draw operator's Y coordinate (the
     * value right before `Td`) from a decompressed content stream, paired
     * with the literal drawn string (letters separated by the single
     * spaces Dompdf's own per-glyph positioning produces) — the same
     * decompression technique `wideRectangleBounds()` uses, applied to
     * text instead of rectangles. Real proof of WHERE text is painted, not
     * an assumption from CSS.
     *
     * @return array<int, array{y: float, text: string}>
     */
    private function extractTextDraws(string $pdfBytes): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdfBytes, $m);
        $draws = [];
        foreach ($m[1] as $stream) {
            $stream = rtrim($stream, "\r\n");
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false || !preg_match('/\bBT\b.*\bET\b/s', $decoded)) {
                continue;
            }
            preg_match_all('/([\-\d.]+) ([\-\d.]+) Td[^\[]*\[\s*\((.*?)\)\s*\]\s*TJ/s', $decoded, $texts, PREG_SET_ORDER);
            foreach ($texts as $t) {
                // Dompdf encodes this font's glyphs as 2-byte codes (a
                // leading \x00 byte per ASCII character, not a literal
                // space) — strip the null bytes, not spaces, to recover
                // the real string.
                $draws[] = ['y' => (float) $t[2], 'text' => str_replace("\x00", '', $t[3])];
            }
        }

        return $draws;
    }

    public function test_continuation_page_header_is_painted_inside_visible_page_coordinates(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix6-header-coords');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [2200, 1400], [1600, 2400], [1500, 2100]]);
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']); // presentWithPhotos already generated the pack; re-fetch for the full-pipeline render below
        $pack = FridayPack::where('project_id', $project->id)->whereDate('week_ending', self::FRIDAY)->firstOrFail();

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = $this->extractTextDraws($bytes);

        // The doc-type header text ("WEEKLY FRIDAY PACK") only ever
        // appears on a continuation page (page 1 deliberately excludes
        // the header — see DocumentGenerationService's own docblock) —
        // finding it at all proves it painted on page 2+, and its Y
        // coordinate must genuinely sit inside the A4 page's visible
        // bounds [0, 841.89], not off the top edge (exactly the drift
        // this checkpoint fixed).
        $docTypeDraw = collect($draws)->first(fn ($d) => str_contains($d['text'], 'WEEKLY FRIDAY PACK'));
        $this->assertNotNull($docTypeDraw, 'Expected the header doc-type text to be painted on a continuation page.');
        $this->assertGreaterThanOrEqual(0.0, $docTypeDraw['y']);
        $this->assertLessThanOrEqual(841.89, $docTypeDraw['y']);
    }

    public function test_footer_identity_is_painted_inside_visible_page_coordinates_on_every_page(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix6-footer-coords');
        $pack = $this->generatePack($project, $editor);

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = $this->extractTextDraws($bytes);

        $brandDraw = collect($draws)->first(fn ($d) => str_contains($d['text'], 'Generated with SureSign'));
        $this->assertNotNull($brandDraw, 'Expected the footer brand line to be painted.');
        $this->assertGreaterThanOrEqual(0.0, $brandDraw['y']);
        $this->assertLessThanOrEqual(841.89, $brandDraw['y']);
    }

    public function test_no_duplicate_footer_identity_lines(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix6-footer-dup');
        $pack = $this->generatePack($project, $editor);

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = $this->extractTextDraws($bytes);

        // Exactly ONE "Generated with SureSign" brand line PER PAGE — the
        // real defect this fixes was a SECOND, near-duplicate identity
        // line crowding the footer band on the same page (the old CSS
        // design's `.left` + `.brand` spans could both carry similar
        // project/date text); it was never about the footer legitimately
        // repeating once per physical page, which every repeating footer
        // must do. The expected count comes from the SAME generated
        // document's own "Page X of Y" text (Y is the same on every page)
        // — never a second, independently-rendered PDF, which is not
        // guaranteed to reflow identically pixel-for-pixel.
        $pageOfTotal = collect($draws)->first(fn ($d) => (bool) preg_match('/^Page \d+ of \d+$/', $d['text']));
        $this->assertNotNull($pageOfTotal, 'Expected at least one "Page X of Y" draw to locate the real page count from.');
        preg_match('/of (\d+)/', $pageOfTotal['text'], $m);
        $realPageCount = (int) $m[1];

        $brandDraws = collect($draws)->filter(fn ($d) => str_contains($d['text'], 'Generated with SureSign'));
        $this->assertCount($realPageCount, $brandDraws, 'Expected exactly one footer brand line per physical page — never two on the same page.');
    }

    public function test_header_and_footer_stay_inside_the_15mm_safe_column(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix6-safe-column');
        $result = $this->presentWithPhotos($project, $editor, [[2400, 1600], [1600, 2400]]);
        app(\App\Services\FridayPack\FridayPackPhotoPdfOptimisationService::class)->cleanup($result['temp_files']);
        $pack = FridayPack::where('project_id', $project->id)->whereDate('week_ending', self::FRIDAY)->firstOrFail();

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);

        preg_match_all('/stream\r?\n(.*?)endstream/s', $bytes, $m);
        $ruleXs = [];
        foreach ($m[1] as $stream) {
            $stream = rtrim($stream, "\r\n");
            $decoded = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($decoded === false) {
                continue;
            }
            // The chrome rule lines are drawn as "x1 y1 m x2 y2 l S" —
            // extract every horizontal-line pair sharing the header/footer's
            // own 1.125pt line width.
            preg_match_all('/([\-\d.]+) ([\-\d.]+) m ([\-\d.]+) ([\-\d.]+) l S/', $decoded, $lines, PREG_SET_ORDER);
            foreach ($lines as $l) {
                $ruleXs[] = (float) $l[1];
                $ruleXs[] = (float) $l[3];
            }
        }

        $this->assertNotEmpty($ruleXs, 'Expected at least one chrome rule line to be present.');
        foreach ($ruleXs as $x) {
            $this->assertGreaterThanOrEqual(40.0, $x, 'Chrome rule must respect the 15mm safe column on the left.');
            $this->assertLessThanOrEqual(555.0, $x, 'Chrome rule must respect the 15mm safe column on the right.');
        }
    }

    // ── R1G.1-VFIX5: statutory table narrow-column wrapping fix ─────────

    public function test_statutory_inspection_date_never_splits_character_by_character(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-date-wrap');
        StatutoryInspection::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => '2026-08-18', 'inspection_type' => 'Scaffold inspection', 'subject_description' => 'North scaffold tower',
            'outcome' => 'pass', 'status' => 'completed',
        ]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        // `word-break: normal; overflow-wrap: normal;` — never
        // `word-wrap: break-word`, which forces a break mid-token with no
        // natural space/hyphen boundary (the exact defect that split a
        // short date string character-by-character in a narrow column).
        $this->assertStringContainsString('word-break: normal', $html);
        $this->assertStringContainsString('overflow-wrap: normal', $html);
        $this->assertStringNotContainsString('word-wrap: break-word', $html);
        $this->assertStringContainsString('18/08/2026', $html); // whole, unsplit date string
    }

    public function test_plant_equipment_type_is_humanised_never_raw_enum(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix5-humanise');
        $plantItem = PlantItem::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id, 'name' => 'Scissor Lift 1', 'type' => 'access_equipment', 'status' => 'active']);
        PlantDeployment::create(['organization_id' => $org->id, 'project_id' => $project->id, 'plant_item_id' => $plantItem->id, 'created_by' => $editor->id, 'on_site_from' => self::MONDAY]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $html = view('pdfs.friday-pack-v2', ['pack' => app(\App\Support\FridayPack\FridayPackSchemaTwoPdfPresenter::class)->present($pack->fresh())['data']])->render();

        $this->assertStringContainsString('Access equipment', $html);
        $this->assertStringNotContainsString('access_equipment', $html);
    }

    // ── R1G.1-VFIX7: chrome-rule/subline spacing + statutory column widths ──

    /**
     * Extracts every stroked horizontal "m ... l S" line from a real
     * decompressed content stream — mirrors
     * test_header_and_footer_stay_inside_the_15mm_safe_column's own inline
     * regex, factored out so this checkpoint's own tests can reuse it
     * without duplicating it a third time.
     *
     * @return array<int, array{y: float, x1: float, x2: float}>
     */
    private function extractHorizontalLines(string $pdfBytes): array
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdfBytes, $m);
        $lines = [];
        foreach ($m[1] as $stream) {
            $stream = rtrim($stream, "\r\n");
            $decoded = @gzuncompress($stream) ?: @gzinflate($stream);
            if ($decoded === false) {
                continue;
            }
            preg_match_all('/([\-\d.]+) ([\-\d.]+) m ([\-\d.]+) ([\-\d.]+) l S/', $decoded, $matches, PREG_SET_ORDER);
            foreach ($matches as $l) {
                if (abs((float) $l[2] - (float) $l[4]) < 0.01) {
                    $lines[] = ['y' => (float) $l[2], 'x1' => (float) $l[1], 'x2' => (float) $l[3]];
                }
            }
        }

        return $lines;
    }

    /**
     * External QA's real finding: the header rule sat INSIDE the header
     * sub-line's own measured text span, visually striking through it. Real
     * DejaVu Sans 7pt font-metric proof (never assumed): the sub-line's own
     * measured descender bottom must sit ABOVE (i.e. at a strictly greater
     * native PDF y — this content stream is in PDF's own bottom-left,
     * y-increases-upward coordinate system) the rule's own y.
     */
    public function test_header_rule_never_intersects_the_header_subline_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix7-header-rule');
        // Force a genuine multi-page document without needing real photo
        // fixtures (this environment's GD build has no JPEG encode/decode
        // support — see this checkpoint's own project-context.md entry).
        for ($i = 0; $i < 8; $i++) {
            StatutoryInspection::create([
                'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
                'inspection_date' => '2026-08-18', 'inspection_type' => 'Statutory Thorough Examination',
                'subject_description' => "Tower Crane TC-{$i} lifting equipment", 'reference' => 'CERT-' . (1000 + $i),
                'outcome' => 'satisfactory', 'status' => 'closed',
            ]);
        }
        $pack = $this->makeFullyReadyPack($project, $editor);

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = $this->extractTextDraws($bytes);
        $lines = $this->extractHorizontalLines($bytes);

        $subline = collect($draws)->first(fn ($d) => str_contains($d['text'], 'Week Ending') && $d['y'] > 700.0);
        $this->assertNotNull($subline, 'Expected the header identity sub-line to be painted on a continuation page.');

        // DejaVu Sans 7pt real descender depth is ≈1.4pt below the drawn
        // baseline — the rule must sit at least that far below it again,
        // never inside the glyph's own ink.
        $sublineDescenderBottom = $subline['y'] - 1.4;

        $headerRule = collect($lines)->filter(fn ($l) => $l['y'] < $subline['y'] && $l['y'] > $subline['y'] - 20);
        $this->assertNotEmpty($headerRule, 'Expected the header rule to be found near the sub-line.');
        foreach ($headerRule as $rule) {
            $this->assertLessThan($sublineDescenderBottom, $rule['y'], 'Header rule must sit strictly below the sub-line\'s own measured text, never through it.');
        }
    }

    /**
     * External QA's second real finding: the footer's centred tertiary
     * "Generated with SureSign" line could visually collide with a long
     * left-hand identity line on the SAME row. Fixed by moving it to a
     * genuine second row — proves the two now sit on measurably different
     * rows (never the same y), and that the page number shares the
     * identity line's OWN row rather than a third, independent position.
     */
    public function test_footer_is_a_genuine_two_row_layout_with_no_shared_row_collisions(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('vfix7-footer-rows');
        $pack = $this->generatePack($project, $editor);

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = $this->extractTextDraws($bytes);

        $identityLine = collect($draws)->first(fn ($d) => str_contains($d['text'], 'Week Ending') && $d['y'] < 100.0);
        $pageNumber = collect($draws)->first(fn ($d) => (bool) preg_match('/^Page \d+ of \d+$/', $d['text']));
        $brandLine = collect($draws)->first(fn ($d) => str_contains($d['text'], 'Generated with SureSign'));

        $this->assertNotNull($identityLine, 'Expected the footer identity line.');
        $this->assertNotNull($pageNumber, 'Expected the footer page number.');
        $this->assertNotNull($brandLine, 'Expected the footer brand line.');

        // Identity + page number share ONE row (row 1).
        $this->assertEqualsWithDelta($identityLine['y'], $pageNumber['y'], 0.1, 'Identity and page number must share the same footer row.');

        // The brand line sits on a genuinely SEPARATE row, comfortably
        // clear of row 1's own 7pt line height (8.96pt) — never
        // overlapping it regardless of either string's length.
        $this->assertGreaterThan(8.96, abs($identityLine['y'] - $brandLine['y']), 'Footer brand line must sit on a separate row from the identity/page-number row, not overlap it.');
    }

    /**
     * Regression guard against the exact confirmed root cause of external
     * QA's statutory-table clipping report: the eight column widths must
     * sum to EXACTLY 100% — never over (Dompdf's fixed table layout
     * proportionally rescales an over-100% set down, silently compressing
     * every column, which is what produced the reported clipping).
     */
    public function test_statutory_table_column_widths_sum_to_exactly_one_hundred_percent(): void
    {
        $css = file_get_contents(resource_path('views/pdfs/friday-pack-v2.blade.php'));

        $widths = [
            'stat-col-date'           => 2, // Date + Next Due
            'stat-col-narrative-lg'   => 2, // Inspection + Subject
            'stat-col-narrative-sm'   => 2, // Plant + Reference
            'stat-col-outcome'        => 1,
            'stat-col-status'         => 1,
        ];

        $total = 0.0;
        foreach ($widths as $class => $occurrences) {
            $this->assertMatchesRegularExpression(
                '/table\.statutory-table th\.' . preg_quote($class, '/') . '\s*\{\s*width:\s*(\d+(?:\.\d+)?)%/',
                $css,
                "Expected a width rule for .{$class}."
            );
            preg_match('/table\.statutory-table th\.' . preg_quote($class, '/') . '\s*\{\s*width:\s*(\d+(?:\.\d+)?)%/', $css, $m);
            $total += ((float) $m[1]) * $occurrences;
        }

        $this->assertEqualsWithDelta(100.0, $total, 0.01, 'Statutory Inspections table column widths must sum to exactly 100% — never over, which Dompdf silently rescales (the confirmed root cause of the reported clipping).');
    }

    /**
     * Upgrades the pre-existing test_statutory_inspection_date_never_
     * splits_character_by_character (HTML-source presence only — external
     * QA correctly found that insufficient proof of real rendering) with
     * genuine PDF content-stream coordinate extraction: every atomic value
     * must appear as one COMPLETE, unsplit drawn string, never truncated.
     */
    public function test_statutory_inspection_atomic_values_render_complete_in_the_actual_pdf(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('vfix7-atomic-render');
        StatutoryInspection::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => '2026-08-18', 'inspection_type' => 'Scaffold inspection', 'subject_description' => 'North scaffold tower',
            'outcome' => 'satisfactory', 'status' => 'closed', 'next_due_date' => '2027-02-18',
        ]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $bytes = $this->generateSchemaTwoPdfWithChrome($pack, $editor, $project);
        $draws = collect($this->extractTextDraws($bytes))->pluck('text');

        $this->assertTrue($draws->contains('18/08/2026'), 'Expected the complete, unsplit inspection date to be painted.');
        $this->assertTrue($draws->contains('18/02/2027'), 'Expected the complete, unsplit next-due date to be painted.');
        $this->assertTrue($draws->contains('Satisfactory'), 'Expected the complete, unsplit outcome to be painted.');
        $this->assertTrue($draws->contains('Closed'), 'Expected the complete, unsplit status to be painted.');
        // Never a truncated fragment of any of the above.
        $this->assertFalse($draws->contains('18/08/202'), 'The inspection date must never render truncated.');
        $this->assertFalse($draws->contains('Satisfactor'), 'The outcome must never render truncated.');
    }
}
