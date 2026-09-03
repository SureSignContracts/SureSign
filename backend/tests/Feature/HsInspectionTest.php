<?php

namespace Tests\Feature;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\HsInspection;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. Mirrors
 * SiteInductionTest/IncidentTest's exact conventions. See HsInspection
 * model / FridayPackHsInspectionSourceService docblocks for the
 * architectural decisions this proves — ONE ROW = ONE H&S INSPECTION,
 * never a QaReport relabelled, outcome/status genuinely independent.
 */
class HsInspectionTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';
    private const SATURDAY = '2026-08-22';
    private const SUNDAY_BEFORE = '2026-08-16';

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

    private function makeInspection(Project $project, User $editor, array $overrides = []): HsInspection
    {
        return HsInspection::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General H&S Inspection',
            'inspected_by' => 'Jane Doe', 'outcome' => 'satisfactory',
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    private function fakeImage(string $name = 'evidence.png'): UploadedFile
    {
        $file = UploadedFile::fake()->create($name, 10, 'image/png');
        file_put_contents($file->getPathname(), "\x89PNG\r\n\x1a\n" . str_repeat('x', 200));
        return $file;
    }

    // ── Domain ───────────────────────────────────────────────────────────

    public function test_inspection_date_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_inspection_type_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d2');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
        ])->assertStatus(422);
    }

    public function test_inspected_by_free_text_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d3');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'outcome' => 'satisfactory',
        ]);
        $response->assertStatus(422);

        // Free text — an external consultant with no SureSign account is
        // fully representable.
        $response2 = $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'outcome' => 'satisfactory',
            'inspected_by' => 'External Consultant (ACME Safety Ltd)',
        ]);
        $response2->assertCreated();
    }

    public function test_outcome_satisfactory_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d4');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'satisfactory']);
        $this->assertSame('satisfactory', $inspection->outcome);
    }

    public function test_outcome_issues_found_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d5');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'issues_found']);
        $this->assertSame('issues_found', $inspection->outcome);
    }

    public function test_invalid_outcome_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d6');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'passed',
        ])->assertStatus(422);
    }

    public function test_status_open_accepted_and_defaulted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d7');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
        ]);
        $this->assertSame('open', $response->json('status'));
    }

    public function test_status_closed_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d8');
        $inspection = $this->makeInspection($project, $editor, ['status' => 'closed']);
        $this->assertSame('closed', $inspection->status);
    }

    public function test_invalid_status_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d9');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
            'status' => 'archived',
        ])->assertStatus(422);
    }

    public function test_issues_found_plus_closed_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d10');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'issues_found', 'status' => 'closed']);
        $this->assertSame('issues_found', $inspection->outcome);
        $this->assertSame('closed', $inspection->status);
    }

    public function test_satisfactory_plus_open_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d11');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'satisfactory', 'status' => 'open']);
        $this->assertSame('satisfactory', $inspection->outcome);
        $this->assertSame('open', $inspection->status);
    }

    public function test_findings_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d12');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->findings);
    }

    public function test_actions_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d13');
        $inspection = $this->makeInspection($project, $editor);
        $this->assertNull($inspection->actions);
    }

    public function test_soft_delete_behavior(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d14');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}")->assertNoContent();

        $this->assertSoftDeleted('hs_inspections', ['id' => $inspection->id]);
        $this->assertDatabaseHas('hs_inspections', ['id' => $inspection->id]);
    }

    // ── Semantics ────────────────────────────────────────────────────────

    public function test_outcome_does_not_determine_lifecycle_status(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sem1');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane',
            'outcome' => 'issues_found',
        ]);

        // outcome=issues_found must never auto-force status=open (or closed).
        $this->assertSame('open', $response->json('status'));
    }

    public function test_status_does_not_rewrite_outcome(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sem2');
        $inspection = $this->makeInspection($project, $editor, ['outcome' => 'issues_found']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}", ['status' => 'closed'])->assertOk();

        $this->assertSame('issues_found', $inspection->fresh()->outcome);
    }

    public function test_no_site_wide_compliance_conclusion_generated(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sem3');
        $this->makeInspection($project, $editor, ['outcome' => 'satisfactory']);
        $pack = $this->generatePack($project, $editor);

        $json = strtolower(json_encode($pack->snapshot_json['sections']['hs_inspections']));
        $this->assertStringNotContainsString('site compliant', $json);
        $this->assertStringNotContainsString('all h&s compliant', $json);
        $this->assertStringNotContainsString('no safety issues', $json);
    }

    public function test_qa_report_not_used_as_source(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sem4');
        \App\Models\QaReport::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'A QA Report', 'inspection_date' => self::MONDAY, 'status' => 'passed',
        ]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_cross_org_rejected(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('t1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('t1b');
        $inspectionA = $this->makeInspection($projectA, $editorB);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/hs-inspections/{$inspectionA->id}")->assertStatus(403);
    }

    public function test_wrong_project_parent_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $inspectionA = $this->makeInspection($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectB->id}/hs-inspections/{$inspectionA->id}")->assertStatus(404);
    }

    public function test_injected_organization_id_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t3');
        [$otherOrg] = $this->makeOrgProjectAndEditor('t3other');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
            'organization_id' => $otherOrg->id,
        ]);

        $response->assertCreated();
        $this->assertSame($project->organization_id, $response->json('organization_id'));
    }

    public function test_update_cannot_move_inspection_to_another_project(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t4a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $inspection = $this->makeInspection($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$projectA->id}/hs-inspections/{$inspection->id}", [
            'inspection_type' => 'Still here', 'project_id' => $projectB->id,
        ])->assertOk();

        $this->assertSame($projectA->id, $inspection->fresh()->project_id);
    }

    // ── Attachments ──────────────────────────────────────────────────────

    public function test_evidence_upload_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a1');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson(
            "/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments",
            ['file' => $this->fakeImage()]
        );

        $response->assertCreated();
        $this->assertDatabaseHas('file_uploads', [
            'attachable_type' => HsInspection::class, 'attachable_id' => $inspection->id,
        ]);
    }

    public function test_evidence_list_exposes_safe_metadata_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a2');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments", ['file' => $this->fakeImage()])->assertCreated();

        $response = $this->getJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments");
        $response->assertOk();
        $this->assertArrayHasKey('original_name', $response->json()[0]);
    }

    public function test_raw_disk_file_path_absent(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a3');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson(
            "/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments",
            ['file' => $this->fakeImage()]
        );

        $response->assertJsonMissingPath('disk');
        $response->assertJsonMissingPath('file_path');

        $listResponse = $this->getJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments");
        $this->assertArrayNotHasKey('disk', $listResponse->json()[0]);
        $this->assertArrayNotHasKey('file_path', $listResponse->json()[0]);
    }

    public function test_attachment_delete_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a4');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $upload = $this->postJson(
            "/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments",
            ['file' => $this->fakeImage()]
        )->json();

        $this->deleteJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments/{$upload['id']}")
            ->assertNoContent();

        $this->assertDatabaseMissing('file_uploads', ['id' => $upload['id']]);
    }

    public function test_cross_inspection_attachment_access_protected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a5');
        $inspectionA = $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);
        $inspectionB = $this->makeInspection($project, $editor, ['inspection_date' => '2026-08-19']);

        Sanctum::actingAs($editor);
        $upload = $this->postJson(
            "/api/projects/{$project->id}/hs-inspections/{$inspectionA->id}/attachments",
            ['file' => $this->fakeImage()]
        )->json();

        $this->assertSame($inspectionA->id, FileUpload::find($upload['id'])->attachable_id);
        $this->assertNotSame($inspectionB->id, FileUpload::find($upload['id'])->attachable_id);
    }

    public function test_soft_deleted_inspection_not_available_through_active_attachment_route(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('a6');
        $inspection = $this->makeInspection($project, $editor);
        $inspection->delete();

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}/attachments")->assertStatus(404);
    }

    // ── Activity ─────────────────────────────────────────────────────────

    public function test_create_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/hs-inspections", [
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
        ])->assertCreated();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'hs_inspection_added']);
    }

    public function test_update_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af2');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}", ['inspection_type' => 'Updated'])->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'hs_inspection_updated']);
    }

    public function test_status_change_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af3');
        $inspection = $this->makeInspection($project, $editor, ['status' => 'open']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}", ['status' => 'closed'])->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'hs_inspection_status_changed']);
    }

    public function test_delete_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af4');
        $inspection = $this->makeInspection($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/hs-inspections/{$inspection->id}")->assertNoContent();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'hs_inspection_deleted']);
    }

    // ── Friday Pack ──────────────────────────────────────────────────────

    public function test_mon_fri_inspection_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    // R1F.0 — Temporal Boundary Integrity Audit. Explicit Monday/Friday
    // touch + Sunday-before/Saturday-after exclusion coverage, closing
    // the gap that let the whereBetween()/bare-cast defect-coupling
    // (see FridayPackHsInspectionSourceService's own R1F.0 docblock) go
    // undetected until R1E.2E's unrelated StatutoryInspection work
    // happened to expose it.
    public function test_monday_touching_period_start_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1a');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_friday_touching_period_end_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1b');
        $this->makeInspection($project, $editor, ['inspection_date' => self::FRIDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_sunday_before_period_start_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1c');
        $this->makeInspection($project, $editor, ['inspection_date' => self::SUNDAY_BEFORE]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp2');
        $this->makeInspection($project, $editor, ['inspection_date' => self::SATURDAY]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_inspection_date_serializes_as_bare_y_m_d(): void
    {
        // Proves the R1F.0 cast fix directly — the stored value must be
        // a bare date, never a datetime-suffixed string, on any database
        // engine (verified separately against real MySQL).
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1d');
        $inspection = $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY]);

        $raw = \DB::table('hs_inspections')->where('id', $inspection->id)->value('inspection_date');
        $this->assertSame(self::MONDAY, $raw);
    }

    public function test_multiple_same_day_inspections_supported(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp3');
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'inspection_type' => 'General']);
        $this->makeInspection($project, $editor, ['inspection_date' => self::MONDAY, 'inspection_type' => 'Housekeeping']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(2, $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_source_count_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp4');
        $this->makeInspection($project, $editor);
        $this->makeInspection($project, $editor, ['inspection_date' => '2026-08-19']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['sections']['hs_inspections']['source_count']);
    }

    public function test_outcome_and_status_frozen_separately(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp5');
        $this->makeInspection($project, $editor, ['outcome' => 'issues_found', 'status' => 'closed']);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['hs_inspections']['items'][0];
        $this->assertSame('issues_found', $item['outcome']);
        $this->assertSame('closed', $item['status']);
    }

    public function test_no_fabricated_confirmed_none_or_compliance_language(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp6');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['hs_inspections'];
        $this->assertSame([], $section['items']);
        $this->assertSame(0, $section['source_count']);
        $json = strtolower(json_encode($section));
        $this->assertStringNotContainsString('no h&s inspections required', $json);
        $this->assertStringNotContainsString('no issues found', $json);
        $this->assertStringNotContainsString('site compliant', $json);
    }

    public function test_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp7');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame([], $pack->snapshot_json['sections']['hs_inspections']['items']);

        $this->makeInspection($project, $editor);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_draft_regeneration_refreshes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp8');
        $pack = $this->generatePack($project, $editor);
        $this->makeInspection($project, $editor);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['hs_inspections']['items']);
    }

    public function test_approved_sent_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp9');
        $this->makeInspection($project, $editor);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    public function test_scheduler_captures_real_records_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp10');
        $this->makeInspection($project, $editor, ['outcome' => 'issues_found']);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame('issues_found', $pack->snapshot_json['sections']['hs_inspections']['items'][0]['outcome']);
        $this->assertSame('open', $pack->snapshot_json['sections']['hs_inspections']['items'][0]['status']);
    }

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp11');
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
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp12');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }

    // ── Feature / Nav ────────────────────────────────────────────────────

    public function test_feature_gate_works(): void
    {
        $this->assertTrue(\App\Support\FeatureAvailability\FeatureAvailabilityRegistry::isValid('project.hs_inspections'));
    }

    public function test_health_and_safety_nav_lists_only_implemented_modules(): void
    {
        // R1E.2D added project.plant_equipment, R1E.2E added
        // project.statutory_inspections — updated here to match
        // (StatutoryInspectionTest is now the authoritative version of
        // this check; same expected-catch-up pattern as every prior
        // phase's own update to this assertion).
        $registry = \App\Support\FeatureAvailability\FeatureAvailabilityRegistry::ALL;
        $this->assertContains('project.site_inductions', $registry);
        $this->assertContains('project.incidents', $registry);
        $this->assertContains('project.hs_inspections', $registry);
        $this->assertContains('project.plant_equipment', $registry);
        $this->assertContains('project.statutory_inspections', $registry);
        $this->assertNotContains('project.health_safety', $registry);
    }
}
