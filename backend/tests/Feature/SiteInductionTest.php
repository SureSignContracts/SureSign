<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteInduction;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Mirrors
 * FridayPackR1CTest/FridayPackR1E1Test's exact conventions. See
 * SiteInduction model / FridayPackSiteInductionSourceService docblocks
 * for the architectural decisions this proves — ONE ROW = ONE INDUCTION
 * SESSION, never one individual attendee.
 */
class SiteInductionTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';
    private const SATURDAY = '2026-08-22';

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
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}", 'code' => strtoupper($suffix),
        ]);

        return [$org, $editor, $project];
    }

    private function makeInduction(Project $project, User $editor, array $overrides = []): SiteInduction
    {
        return SiteInduction::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'induction_date' => self::MONDAY, 'inductee_count' => 1,
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    // ── Domain ───────────────────────────────────────────────────────────

    public function test_one_row_represents_one_session(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        $induction = $this->makeInduction($project, $editor, ['session_title' => 'Morning Site Induction', 'inductee_count' => 6]);

        $this->assertSame('Morning Site Induction', $induction->session_title);
        $this->assertSame(6, $induction->inductee_count);
    }

    public function test_two_sessions_same_date_allowed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d2');
        $this->makeInduction($project, $editor, ['session_title' => 'Morning', 'induction_date' => self::MONDAY]);
        $this->makeInduction($project, $editor, ['session_title' => 'Afternoon', 'induction_date' => self::MONDAY]);

        $this->assertSame(2, SiteInduction::where('project_id', $project->id)->count());
    }

    public function test_inductee_count_min_1_and_zero_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d3');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-inductions", [
            'induction_date' => self::MONDAY, 'inductee_count' => 0,
        ]);

        $response->assertStatus(422);
    }

    public function test_session_title_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d4');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-inductions", [
            'induction_date' => self::MONDAY, 'inductee_count' => 3,
        ]);

        $response->assertCreated();
    }

    public function test_company_or_trade_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d5');
        $induction = $this->makeInduction($project, $editor);

        $this->assertNull($induction->company_or_trade);
    }

    public function test_no_individual_attendee_field_exists(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d6');
        $induction = $this->makeInduction($project, $editor);

        $this->assertArrayNotHasKey('attendee_name', $induction->toArray());
        $this->assertArrayNotHasKey('worker_id', $induction->toArray());
    }

    public function test_soft_delete_behavior(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d7');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/site-inductions/{$induction->id}")->assertNoContent();

        $this->assertSoftDeleted('site_inductions', ['id' => $induction->id]);
        $this->assertDatabaseHas('site_inductions', ['id' => $induction->id]);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_cross_org_access_rejected(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('t1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('t1b');
        $inductionA = $this->makeInduction($projectA, $editorB);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/site-inductions/{$inductionA->id}")->assertStatus(403);
    }

    public function test_wrong_project_parent_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $inductionA = $this->makeInduction($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectB->id}/site-inductions/{$inductionA->id}")->assertStatus(404);
    }

    public function test_injected_organization_id_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t3');
        [$otherOrg] = $this->makeOrgProjectAndEditor('t3other');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-inductions", [
            'induction_date' => self::MONDAY, 'inductee_count' => 2, 'organization_id' => $otherOrg->id,
        ]);

        $response->assertCreated();
        $this->assertSame($project->organization_id, $response->json('organization_id'));
    }

    public function test_attachment_belongs_to_same_project_record(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t4');
        $inductionA = $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);
        $inductionB = $this->makeInduction($project, $editor, ['induction_date' => '2026-08-19']);

        Sanctum::actingAs($editor);
        $upload = $this->postJson(
            "/api/projects/{$project->id}/site-inductions/{$inductionA->id}/attachments",
            ['file' => $this->fakeImage()]
        )->json();

        $this->assertSame($inductionA->id, $upload['id'] ? FileUpload::find($upload['id'])->attachable_id : null);
        $this->assertNotSame($inductionB->id, FileUpload::find($upload['id'])->attachable_id);
    }

    public function test_cross_record_attachment_access_protected(): void
    {
        [, $editorA, $projectA] = $this->makeOrgProjectAndEditor('t5a');
        [, , $projectB] = $this->makeOrgProjectAndEditor('t5b');
        $inductionB = $this->makeInduction($projectB, $editorA);

        Sanctum::actingAs($editorA);
        $this->getJson("/api/projects/{$projectA->id}/site-inductions/{$inductionB->id}/attachments")->assertStatus(403);
    }

    // ── Attachments ──────────────────────────────────────────────────────

    private function fakeImage(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    public function test_evidence_upload_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson(
            "/api/projects/{$project->id}/site-inductions/{$induction->id}/attachments",
            ['file' => $this->fakeImage()]
        );

        $response->assertCreated();
        $this->assertDatabaseHas('file_uploads', [
            'attachable_type' => SiteInduction::class, 'attachable_id' => $induction->id,
        ]);
    }

    public function test_raw_storage_path_not_exposed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson(
            "/api/projects/{$project->id}/site-inductions/{$induction->id}/attachments",
            ['file' => $this->fakeImage()]
        );

        $response->assertJsonMissingPath('disk');
        $response->assertJsonMissingPath('file_path');
    }

    public function test_attachment_deletion_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $upload = $this->postJson(
            "/api/projects/{$project->id}/site-inductions/{$induction->id}/attachments",
            ['file' => $this->fakeImage()]
        )->json();

        $this->deleteJson("/api/projects/{$project->id}/site-inductions/{$induction->id}/attachments/{$upload['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('file_uploads', ['id' => $upload['id']]);
    }

    // ── Friday Pack ──────────────────────────────────────────────────────

    public function test_mon_fri_sessions_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['site_inductions']['items']);
    }

    public function test_saturday_session_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp2');
        $this->makeInduction($project, $editor, ['induction_date' => self::SATURDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['site_inductions']['items']);
    }

    public function test_multiple_same_day_sessions_all_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp3');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'session_title' => 'Morning']);
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'session_title' => 'Afternoon']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(2, $pack->snapshot_json['sections']['site_inductions']['items']);
    }

    public function test_total_inducted_summed_correctly(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp4');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'inductee_count' => 6]);
        $this->makeInduction($project, $editor, ['induction_date' => '2026-08-19', 'inductee_count' => 3]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(9, $pack->snapshot_json['sections']['site_inductions']['total_inducted']);
    }

    public function test_source_count_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp5');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['sections']['site_inductions']['source_count']);
    }

    public function test_total_is_attendance_sum_not_unique_person_semantics(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp6');
        // Two sessions, same day, deliberately overlapping conceptual
        // attendance — the total is a plain sum, never deduplicated.
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'inductee_count' => 5]);
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'inductee_count' => 5]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(10, $pack->snapshot_json['sections']['site_inductions']['total_inducted']);
    }

    public function test_no_fabricated_confirmed_none_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp7');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['site_inductions'];
        $this->assertSame([], $section['items']);
        $this->assertSame(0, $section['total_inducted']);
        $this->assertSame(0, $section['source_count']);
        $this->assertStringNotContainsString('no inductions occurred', strtolower(json_encode($section)));
    }

    public function test_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp8');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame([], $pack->snapshot_json['sections']['site_inductions']['items']);

        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['site_inductions']['items']);
    }

    public function test_draft_regeneration_refreshes_induction_data(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp9');
        $pack = $this->generatePack($project, $editor);
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['site_inductions']['items']);
    }

    public function test_approved_sent_snapshot_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp10');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    public function test_scheduled_pack_captures_existing_sessions_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp11');
        $this->makeInduction($project, $editor, ['induction_date' => self::MONDAY, 'inductee_count' => 4]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame(4, $pack->snapshot_json['sections']['site_inductions']['total_inducted']);
    }

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp12');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "test_schema_2_pdf_still_blocked") — see project-context.md's
     * R1G.1-ACT entry.
     */
    public function test_schema_2_pdf_now_succeeds(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('fp13');
        // This scenario crosses into the premium Friday Pack PDF workflow —
        // Site Inductions itself remains uncommercial-gated; only this
        // specific test's organisation needs Friday Pack entitlement.
        $this->giveFridayPackEntitlement($org);
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }

    // ── Activity / Feature ───────────────────────────────────────────────

    public function test_create_activity_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af1');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/site-inductions", [
            'induction_date' => self::MONDAY, 'inductee_count' => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'site_induction_added']);
    }

    public function test_update_activity_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af2');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/site-inductions/{$induction->id}", ['inductee_count' => 5])
            ->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'site_induction_updated']);
    }

    public function test_delete_activity_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af3');
        $induction = $this->makeInduction($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/site-inductions/{$induction->id}")->assertNoContent();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'site_induction_deleted']);
    }

    public function test_feature_key_gating_works(): void
    {
        $this->assertTrue(\App\Support\FeatureAvailability\FeatureAvailabilityRegistry::isValid('project.site_inductions'));
    }
}
