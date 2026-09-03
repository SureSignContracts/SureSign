<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\FridayPackPhotoSelection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack, R1B — Site Photographs & Evidence Selection.
 * Mirrors FridayPackTest/FridayPackPdfTest/ToolboxTalkTest's exact
 * conventions. See FridayPackPhotoDiscoveryService/
 * FridayPackPhotoSelectionService/FridayPackPhotoProtectionGuard
 * docblocks for the architectural decisions this proves.
 */
class FridayPackPhotoSelectionTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}"]);

        return [$org, $editor, $project];
    }

    private function makeSiteDiary(Project $project, User $editor, string $date): SiteDiary
    {
        return SiteDiary::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'diary_date' => $date, 'status' => 'submitted',
        ]);
    }

    private function makeToolboxTalk(Project $project, User $editor, string $date): ToolboxTalk
    {
        return ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Talk', 'talk_date' => $date, 'attendee_count' => 3,
        ]);
    }

    /** Creates a real FileUpload row AND writes real bytes at its path on
     * the faked disk — the physical-file-existence check needs a genuine
     * Storage::exists() hit, not just a DB row. */
    private function makeFileUpload(Project $project, $attachable, string $mimeType = 'image/png', ?string $path = null): FileUpload
    {
        $path ??= 'projects/' . $project->id . '/evidence/' . uniqid() . '.bin';
        Storage::disk('local')->put($path, 'fake-bytes');

        return FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'uploaded_by' => $attachable->created_by ?? $attachable->creator?->id ?? User::factory()->create()->id,
            'attachable_type' => $attachable::class, 'attachable_id' => $attachable->id,
            'original_name' => 'evidence.bin', 'stored_name' => basename($path), 'file_path' => $path,
            'mime_type' => $mimeType, 'file_size' => 9, 'disk' => 'local',
        ]);
    }

    private function fakePng(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    private function generateDraft(Project $project, User $editor): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
    }

    /**
     * R1F.2 — Content Readiness + Weekly Declarations gave
     * `submitForReview()` a genuine content-completeness gate; a bare
     * `generateDraft()` pack is now correctly `missing` in most
     * sections. Fills every required text field and declares every
     * conditional section `confirmed_none`/`not_applicable` so a test
     * whose real subject is photo-selection lifecycle behaviour (not
     * readiness itself — see FridayPackReadinessTest for that) can still
     * reach `ready_for_review`.
     */
    private function makeReady(FridayPack $pack, User $editor): void
    {
        $pack->update([
            'weekly_summary' => 'Works progressed as planned.',
            'look_ahead' => 'Continue next week.',
            'site_issues_summary' => 'No issues.',
        ]);
        $declarations = app(\App\Services\FridayPack\FridayPackSectionDeclarationService::class);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $declarations->declare($pack->fresh(), $editor, $section, '', \App\Models\FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE, null);
        }
        $declarations->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', \App\Models\FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE, null);
        $declarations->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', \App\Models\FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE, null);
    }

    // ── 1. SiteDiary attachment infrastructure (real endpoint) ─────────────

    public function test_site_diary_accepts_attachments_through_existing_infrastructure(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sd1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/attachments", ['file' => $this->fakePng()])
            ->assertStatus(201);
        $this->getJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/attachments")
            ->assertStatus(200)->assertJsonCount(1);
    }

    // ── 2-6. Discovery ───────────────────────────────────────────────────

    public function test_site_report_image_appears_as_candidate(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('disc1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")->assertStatus(200);

        $this->assertSame(1, $response->json('candidate_count'));
        $this->assertSame($upload->id, $response->json('candidates.0.file_upload_id'));
        $this->assertSame('site_report', $response->json('candidates.0.source_type'));
    }

    public function test_non_image_site_report_attachment_is_excluded_from_candidates(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('disc2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $diary, 'application/pdf');
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")
            ->assertStatus(200)->assertJsonPath('candidate_count', 0);
    }

    public function test_toolbox_talk_image_appears_as_candidate(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('disc3');
        $talk = $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $talk, 'image/jpeg');
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")->assertStatus(200);
        $this->assertSame(1, $response->json('candidate_count'));
        $this->assertSame($upload->id, $response->json('candidates.0.file_upload_id'));
        $this->assertSame('toolbox_talk', $response->json('candidates.0.source_type'));
    }

    public function test_toolbox_talk_pdf_attachment_is_excluded_from_candidates(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('disc4');
        $talk = $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $talk, 'application/pdf');
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")
            ->assertStatus(200)->assertJsonPath('candidate_count', 0);
    }

    public function test_candidate_uses_source_event_date_not_upload_date(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('disc5');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $diary);
        FileUpload::query()->update(['created_at' => '2020-01-01 00:00:00']); // deliberately stale upload timestamp
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates")->assertStatus(200);
        $this->assertSame(self::FRIDAY, $response->json('candidates.0.source_date'));
    }

    // ── 7. No auto-selection ─────────────────────────────────────────────

    public function test_no_candidate_is_automatically_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('auto1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        $this->assertSame(0, $pack->photoSelections()->count());
        $this->assertSame(0, $pack->snapshot_json['sections']['site_photographs']['count']);
    }

    // ── 8-9. Select / duplicate ──────────────────────────────────────────

    public function test_select_photo_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sel1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", [
            'file_upload_id' => $upload->id, 'caption' => 'Zinc roof progressing', 'location' => '3rd Floor',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('file_upload_id', $upload->id)
            ->assertJsonPath('caption', 'Zinc roof progressing')
            ->assertJsonPath('location', '3rd Floor')
            ->assertJsonPath('source_type', 'site_report');

        $this->assertSame(1, FridayPackPhotoSelection::where('friday_pack_id', $pack->id)->count());
    }

    public function test_duplicate_selection_of_same_file_upload_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sel2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(409);
    }

    // ── 10-12. Tenant / source validation ────────────────────────────────

    public function test_cross_organisation_file_upload_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ten1');
        [$foreignOrg, $foreignEditor, $foreignProject] = $this->makeOrgProjectAndEditor('ten1-foreign');
        $foreignDiary = $this->makeSiteDiary($foreignProject, $foreignEditor, self::FRIDAY);
        $foreignUpload = $this->makeFileUpload($foreignProject, $foreignDiary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $foreignUpload->id])
            ->assertStatus(422);
    }

    public function test_same_organisation_wrong_project_file_upload_is_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('ten2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B ten2']);
        $diaryB = $this->makeSiteDiary($projectB, $editor, self::FRIDAY);
        $uploadB = $this->makeFileUpload($projectB, $diaryB);
        $packA = $this->generateDraft($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$projectA->id}/friday-packs/{$packA->id}/photo-selections", ['file_upload_id' => $uploadB->id])
            ->assertStatus(422);
    }

    public function test_unapproved_attachable_source_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ten3');
        // A FileUpload attached to something other than SiteDiary/ToolboxTalk
        // (e.g. a Contract) is never an approved Friday Pack photo source.
        $contract = \App\Models\Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'type' => 'main_contract', 'title' => 'Main Contract', 'created_by' => $editor->id,
        ]);
        $upload = $this->makeFileUpload($project, $contract);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])
            ->assertStatus(422);
    }

    // ── 13-15. Stable source_type, no raw internals ─────────────────────

    public function test_response_never_exposes_raw_fqcn_or_storage_path(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sec1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $selectResponse = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id]);
        $selectResponse->assertStatus(201)->assertJsonPath('source_type', 'site_report');

        $candidatesResponse = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-candidates");
        $listResponse = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections");

        foreach ([$selectResponse->getContent(), $candidatesResponse->getContent(), $listResponse->getContent()] as $body) {
            $this->assertStringNotContainsString('App\\Models\\SiteDiary', $body);
            $this->assertStringNotContainsString('file_path', $body);
            $this->assertStringNotContainsString('"disk"', $body);
        }
    }

    // ── 16-19. Caption/location, order, non-mutation of source ──────────

    public function test_caption_and_location_persist_and_do_not_mutate_source(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('meta1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $selection = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->json();

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/{$selection['id']}", [
            'caption' => 'Updated caption', 'location' => 'Updated location',
        ])->assertStatus(200)->assertJsonPath('caption', 'Updated caption')->assertJsonPath('location', 'Updated location');

        $this->assertSame('Updated caption', FridayPackPhotoSelection::find($selection['id'])->caption);
        $this->assertSame('evidence.bin', $upload->fresh()->original_name, 'Editing a Friday Pack caption must never rename/mutate the underlying FileUpload.');
        $this->assertNull($diary->fresh()->works_carried_out, 'Editing a Friday Pack caption must never mutate the underlying SiteDiary.');
    }

    public function test_ordering_persists_via_reorder_endpoint(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('order1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $u1 = $this->makeFileUpload($project, $diary);
        $u2 = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $s1 = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $u1->id])->json('id');
        $s2 = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $u2->id])->json('id');

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/reorder", [
            'ordered_selection_ids' => [$s2, $s1],
        ])->assertStatus(200);

        $this->assertSame(1, FridayPackPhotoSelection::find($s2)->sort_order);
        $this->assertSame(2, FridayPackPhotoSelection::find($s1)->sort_order);
    }

    public function test_reorder_rejects_a_list_that_does_not_match_current_selections(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('order2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $u1 = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $s1 = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $u1->id])->json('id');

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/reorder", [
            'ordered_selection_ids' => [$s1, 999999],
        ])->assertStatus(409);
    }

    // ── 20-21. Snapshot integration ──────────────────────────────────────

    public function test_snapshot_contains_only_explicitly_selected_photos_with_frozen_metadata(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('snap1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $u1 = $this->makeFileUpload($project, $diary);
        $u2 = $this->makeFileUpload($project, $diary); // never selected
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", [
            'file_upload_id' => $u1->id, 'caption' => 'Roof detail', 'location' => 'North elevation',
        ])->assertStatus(201);

        $section = $pack->fresh()->snapshot_json['sections']['site_photographs'];
        $this->assertSame(1, $section['count']);
        $this->assertSame($u1->id, $section['items'][0]['file_upload_id']);
        $this->assertSame('Roof detail', $section['items'][0]['caption']);
        $this->assertSame('North elevation', $section['items'][0]['location']);
        $this->assertSame('site_report', $section['items'][0]['source_type']);
        $this->assertSame(self::FRIDAY, $section['items'][0]['source_date']);
        $this->assertSame(1, $section['items'][0]['order']);
        $this->assertArrayNotHasKey('file_path', $section['items'][0]);
        foreach ($section['items'] as $item) {
            $this->assertNotSame($u2->id, $item['file_upload_id']);
        }
    }

    // ── 22-23. Regeneration ──────────────────────────────────────────────

    public function test_regeneration_preserves_selections_caption_location_and_order(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('regen1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", [
            'file_upload_id' => $upload->id, 'caption' => 'Kept caption',
        ])->assertStatus(201);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate")->assertStatus(200);

        $section = $pack->fresh()->snapshot_json['sections']['site_photographs'];
        $this->assertSame(1, $section['count']);
        $this->assertSame('Kept caption', $section['items'][0]['caption']);
        $this->assertSame(1, FridayPackPhotoSelection::where('friday_pack_id', $pack->id)->count(), 'Regeneration must never delete/recreate selection rows.');
    }

    public function test_newly_discovered_photo_after_regeneration_remains_unselected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('regen2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        // New evidence appears after the pack was first generated.
        $this->makeFileUpload($project, $diary);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate")->assertStatus(200);

        $this->assertSame(1, $pack->fresh()->snapshot_json['sections']['site_photographs']['count'], 'A newly discovered photo must never be auto-selected by regeneration.');
    }

    // ── 24-26. Deletion protection ────────────────────────────────────────

    public function test_selected_file_upload_cannot_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/attachments/{$upload->id}")
            ->assertStatus(409);
        $this->assertNotNull(FileUpload::find($upload->id));
    }

    public function test_source_can_be_deleted_only_after_deselecting(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $selectionId = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->json('id');

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/{$selectionId}")->assertStatus(204);
        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/attachments/{$upload->id}")->assertStatus(204);
        $this->assertNull(FileUpload::find($upload->id));
    }

    public function test_restrictive_foreign_key_prevents_direct_deletion_bypass(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del3');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        app(\App\Services\FridayPack\FridayPackPhotoSelectionService::class)->select($pack, $project, $editor, $upload->id, null, null);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $upload->delete();
    }

    // ── 27. Source RECORD deletion protection (Post-Deploy Photo Hardening, P1) ──
    // Distinct from 24-26 above, which prove the ATTACHMENT itself cannot be
    // deleted while selected. These prove the parent SiteDiary/ToolboxTalk
    // RECORD (DELETE /site-diaries/{id}, DELETE /toolbox-talks/{id}) is
    // ALSO blocked — previously it was not, since FileUpload's polymorphic
    // `attachable` relation carries no real foreign key to protect it.

    public function test_site_diary_with_no_selected_photo_can_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $diary); // an ordinary, unselected attachment

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}")->assertStatus(204);
        $this->assertNull(SiteDiary::find($diary->id));
    }

    public function test_site_diary_deletion_is_blocked_while_one_of_its_photos_is_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        $response = $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}");
        $response->assertStatus(409);
        $response->assertJsonMissingPath('site_diary');
        $response->assertJsonMissingPath('SiteDiary');

        $this->assertNotNull(SiteDiary::find($diary->id));
        $this->assertNotNull(FileUpload::find($upload->id));
        $this->assertDatabaseHas('friday_pack_photo_selections', ['file_upload_id' => $upload->id]);
    }

    public function test_site_diary_deletion_succeeds_once_the_photo_is_deselected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec3');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $selectionId = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->json('id');

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/{$selectionId}")->assertStatus(204);
        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}")->assertStatus(204);
        $this->assertNull(SiteDiary::find($diary->id));
    }

    public function test_toolbox_talk_with_no_selected_photo_can_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec4');
        $talk = $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $talk);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")->assertStatus(204);
        $this->assertNull(ToolboxTalk::find($talk->id));
    }

    public function test_toolbox_talk_deletion_is_blocked_while_one_of_its_photos_is_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec5');
        $talk = $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $talk);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        $this->deleteJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")->assertStatus(409);

        $this->assertNotNull(ToolboxTalk::find($talk->id));
        $this->assertNotNull(FileUpload::find($upload->id));
        $this->assertDatabaseHas('friday_pack_photo_selections', ['file_upload_id' => $upload->id]);
    }

    public function test_toolbox_talk_deletion_succeeds_once_the_photo_is_deselected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec6');
        $talk = $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $talk);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $selectionId = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->json('id');

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections/{$selectionId}")->assertStatus(204);
        $this->deleteJson("/api/projects/{$project->id}/toolbox-talks/{$talk->id}")->assertStatus(204);
        $this->assertNull(ToolboxTalk::find($talk->id));
    }

    /**
     * The guard must hold regardless of the referencing FridayPack's own
     * lifecycle status — an Approved (or Sent) pack's frozen snapshot
     * still names this exact source record, so its provenance must not
     * be allowed to disappear just because the pack has moved past Draft.
     */
    public function test_site_diary_deletion_remains_blocked_once_the_friday_pack_is_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rec7');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        $this->makeReady($pack->fresh(), $editor);
        $lifecycle = app(FridayPackLifecycleService::class);
        $lifecycle->submitForReview($pack->fresh(), $editor);
        $lifecycle->markReviewed($pack->fresh(), $editor);
        $lifecycle->approve($pack->fresh(), $editor);

        $this->deleteJson("/api/projects/{$project->id}/site-diaries/{$diary->id}")->assertStatus(409);
        $this->assertNotNull(SiteDiary::find($diary->id));
    }

    // ── 27-28. Physical file integrity ───────────────────────────────────

    public function test_selection_of_a_file_upload_with_no_physical_file_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('phys1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        Storage::disk('local')->delete($upload->file_path); // simulate a missing physical file
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])
            ->assertStatus(422);
    }

    public function test_missing_physical_evidence_blocks_submit_for_review(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('phys2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);

        // The file disappears from disk AFTER selection (e.g. a disk-level
        // issue outside FileUpload's own deletion path) — never discovered
        // until this preflight, never deferred to PDF generation time.
        Storage::disk('local')->delete($upload->file_path);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")
            ->assertStatus(409);
        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_submit_for_review_succeeds_when_all_selected_evidence_is_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('phys3');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])->assertStatus(201);
        $this->makeReady($pack->fresh(), $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")->assertStatus(200);
    }

    public function test_submit_for_review_succeeds_with_zero_selected_photos(): void
    {
        // R1F.2 — zero SELECTED photos is still fine, exactly as R1B
        // established, as long as Site Photographs is otherwise resolved
        // (here, via an explicit confirmed_none declaration through
        // makeReady()) — this test now proves photo selection specifically
        // is never itself a hidden extra requirement, not that readiness
        // as a whole is skipped.
        [, $editor, $project] = $this->makeOrgProjectAndEditor('phys4');
        $pack = $this->generateDraft($project, $editor);
        $this->makeReady($pack, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review")
            ->assertStatus(200, 'Zero selected photos alone must never block submission once Site Photographs is otherwise resolved.');
    }

    // ── 29-31. Lifecycle gating ────────────────────────────────────────────

    public function test_ready_for_review_photo_mutation_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);
        $this->makeReady($pack, $editor);

        Sanctum::actingAs($editor);
        app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])
            ->assertStatus(409);
    }

    public function test_approved_photo_mutation_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc2');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);
        $this->makeReady($pack, $editor);
        $lifecycle = app(FridayPackLifecycleService::class);
        $pack = $lifecycle->submitForReview($pack->fresh(), $editor);
        $pack = $lifecycle->markReviewed($pack, $editor);
        $pack = $lifecycle->approve($pack, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])
            ->assertStatus(409);
    }

    public function test_sent_photo_mutation_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc3');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $upload = $this->makeFileUpload($project, $diary);
        $pack = $this->generateDraft($project, $editor);
        // Directly promoted to 'sent' — the real Send flow additionally
        // requires an approved schema-1 PDF (out of scope here); this
        // proves the photo-selection guard reads status alone, exactly
        // like every other Friday Pack lifecycle guard.
        $pack->forceFill(['status' => 'sent'])->save();

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/photo-selections", ['file_upload_id' => $upload->id])
            ->assertStatus(409);
    }

    // ── 32-33. Scheduler / schema ─────────────────────────────────────────

    public function test_scheduler_generated_draft_has_zero_selected_photos_and_stays_schema_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sched1');
        $diary = $this->makeSiteDiary($project, $editor, self::FRIDAY);
        $this->makeFileUpload($project, $diary);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertNotNull($pack);
        $this->assertSame(0, $pack->photoSelections()->count());
        $this->assertSame(0, $pack->snapshot_json['sections']['site_photographs']['count']);
        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }
}
