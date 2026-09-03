<?php

namespace Tests\Feature;

use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\SiteDiaryWorkforceEntry;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1C — Weekly Summary + Workforce. Mirrors
 * FridayPackTest/FridayPackPhotoSelectionTest's exact conventions. See
 * FridayPackWeeklySummarySourceService/FridayPackWorkforceService
 * docblocks for the architectural decisions this proves.
 */
class FridayPackR1CTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';
    private const TUESDAY = '2026-08-18';
    private const SATURDAY = '2026-08-22';

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}"]);

        return [$org, $editor, $project];
    }

    private function makeSiteDiary(Project $project, User $editor, string $date, array $overrides = []): SiteDiary
    {
        return SiteDiary::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'diary_date' => $date, 'status' => 'submitted',
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    // ── Weekly Summary — source material ─────────────────────────────────

    public function test_weekly_summary_sources_only_includes_mon_fri_with_non_empty_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['works_carried_out' => 'Foundations poured.']);
        $this->makeSiteDiary($project, $editor, self::TUESDAY, ['works_carried_out' => null]);
        $this->makeSiteDiary($project, $editor, self::SATURDAY, ['works_carried_out' => 'Weekend concrete pour.']);
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/weekly-summary-sources");

        $response->assertOk();
        $this->assertCount(1, $response->json('sources'));
        $this->assertSame('Foundations poured.', $response->json('sources.0.works_carried_out'));
        $this->assertSame(self::MONDAY, $response->json('sources.0.date'));
        $this->assertSame(1, $response->json('source_site_report_count'));
    }

    public function test_weekly_summary_source_endpoint_never_synthesizes_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws2');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['works_carried_out' => 'Exact raw text.']);
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/weekly-summary-sources");

        $this->assertSame('Exact raw text.', $response->json('sources.0.works_carried_out'));
    }

    public function test_weekly_summary_saves_while_draft(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws3');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", [
            'weekly_summary' => 'Confirmed narrative for the week.',
        ]);

        $response->assertOk();
        $this->assertSame('Confirmed narrative for the week.', $pack->fresh()->weekly_summary);
    }

    public function test_regeneration_preserves_confirmed_weekly_summary(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws4');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => 'My confirmed text']);

        app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame('My confirmed text', $pack->fresh()->weekly_summary);
    }

    public function test_snapshot_contains_confirmed_weekly_summary_and_source_count(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws5');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['works_carried_out' => 'Works A']);
        $this->makeSiteDiary($project, $editor, self::TUESDAY, ['works_carried_out' => 'Works B']);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => 'Final confirmed text']);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $section = $pack->snapshot_json['sections']['weekly_summary'];
        $this->assertSame('Final confirmed text', $section['text']);
        $this->assertSame(2, $section['source_site_report_count']);
    }

    public function test_scheduler_does_not_invent_weekly_summary_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws6');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['works_carried_out' => 'Works A']);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertNull($pack->weekly_summary);
        $this->assertNull($pack->snapshot_json['sections']['weekly_summary']['text']);
    }

    public function test_weekly_summary_immutable_once_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws7');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => 'Original']);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['weekly_summary' => 'Changed after approval']);
    }

    // ── Workforce — entries CRUD ──────────────────────────────────────────

    public function test_create_workforce_entry(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf1');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/workforce-entries", [
            'trade_or_role' => 'Labourer', 'operative_count' => 4,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('site_diary_workforce_entries', [
            'site_diary_id' => $diary->id, 'trade_or_role' => 'Labourer', 'operative_count' => 4,
        ]);
    }

    public function test_workforce_entry_role_is_trimmed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf2');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/workforce-entries", [
            'trade_or_role' => '  Carpenter  ', 'operative_count' => 2,
        ])->assertCreated();

        $this->assertDatabaseHas('site_diary_workforce_entries', ['trade_or_role' => 'Carpenter']);
    }

    public function test_duplicate_role_in_same_site_diary_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf3');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 2,
        ]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/workforce-entries", [
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);

        $response->assertStatus(422);
    }

    public function test_duplicate_role_case_insensitive_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf4');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 2,
        ]);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/workforce-entries", [
            'trade_or_role' => 'labourer', 'operative_count' => 3,
        ]);

        // Application-level check (case-insensitive via LOWER()) — proven
        // separately at the real MySQL level during R1C schema validation
        // (utf8mb4_0900_ai_ci is case-insensitive; see the creating
        // migration's own docblock).
        $response->assertStatus(422);
    }

    public function test_operative_count_minimum_enforced(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf5');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/site-diaries/{$diary->id}/workforce-entries", [
            'trade_or_role' => 'Labourer', 'operative_count' => 0,
        ]);

        $response->assertStatus(422);
    }

    public function test_cross_project_workforce_entry_injection_rejected(): void
    {
        [, $editorA, $projectA] = $this->makeOrgProjectAndEditor('wf6a');
        [, , $projectB] = $this->makeOrgProjectAndEditor('wf6b');
        $diaryA = $this->makeSiteDiary($projectA, $editorA, self::MONDAY);
        $entryOnOtherDiary = SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $this->makeSiteDiary($projectB, $editorA, self::MONDAY)->id,
            'project_id' => $projectB->id, 'organization_id' => $projectB->organization_id,
            'trade_or_role' => 'Electrician', 'operative_count' => 1,
        ]);

        Sanctum::actingAs($editorA);
        $response = $this->putJson(
            "/api/projects/{$projectA->id}/site-diaries/{$diaryA->id}/workforce-entries/{$entryOnOtherDiary->id}",
            ['trade_or_role' => 'Hacked', 'operative_count' => 99],
        );

        $response->assertStatus(404);
    }

    // ── Workforce aggregation service ─────────────────────────────────────

    public function test_existing_workers_on_site_works_with_zero_breakdown_rows(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 6]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertSame(6, $mondayDay['daily_total']);
        $this->assertSame('recorded', $mondayDay['status']);
        $this->assertFalse($mondayDay['has_breakdown']);
    }

    public function test_no_data_day_is_null_not_zero(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa2');
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertNull($mondayDay['daily_total']);
        $this->assertSame('no_data', $mondayDay['status']);
    }

    public function test_breakdown_total_and_matching_status(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa3');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Carpenter', 'operative_count' => 2,
        ]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertSame(5, $mondayDay['breakdown_total']);
        $this->assertSame(5, $mondayDay['daily_total']);
    }

    public function test_mismatching_breakdown_is_not_blocked_but_reported_honestly(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa4');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 8]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertSame(8, $mondayDay['daily_total']);
        $this->assertSame(3, $mondayDay['breakdown_total']);
    }

    public function test_breakdown_never_silently_overwrites_workers_on_site(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa5');
        $diary = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 8]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);

        $this->assertSame(8, $diary->fresh()->workers_on_site);
    }

    public function test_mon_fri_aggregation_only_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa6');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $this->makeSiteDiary($project, $editor, self::SATURDAY, ['workers_on_site' => 99]);
        $pack = $this->generatePack($project, $editor);

        $days = collect($pack->snapshot_json['sections']['workforce']['days'])->pluck('date')->all();
        $this->assertCount(5, $days);
        $this->assertNotContains(self::SATURDAY, $days);
        $this->assertSame(5, $pack->snapshot_json['sections']['workforce']['person_days_total']);
    }

    public function test_correct_trade_row_person_days_totals(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa7');
        $mon = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 3]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $mon->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);
        $tue = $this->makeSiteDiary($project, $editor, self::TUESDAY, ['workers_on_site' => 2]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $tue->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'labourer', 'operative_count' => 2,
        ]);
        $pack = $this->generatePack($project, $editor);

        $rows = collect($pack->snapshot_json['sections']['workforce']['rows']);
        $labourerRow = $rows->firstWhere('trade_or_role', 'Labourer');
        $this->assertNotNull($labourerRow);
        $this->assertSame(5, $labourerRow['person_days_total']);
        $this->assertSame(3, $labourerRow['counts']['monday']);
        $this->assertSame(2, $labourerRow['counts']['tuesday']);
        $this->assertNull($labourerRow['counts']['wednesday']);
    }

    public function test_no_fabricated_role_rows_without_breakdown(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa8');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['workforce']['rows']);
        $this->assertFalse($pack->snapshot_json['sections']['workforce']['has_trade_breakdown']);
    }

    public function test_same_date_conflicting_totals_not_silently_summed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa9');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 7]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertSame('conflicting_site_reports', $mondayDay['status']);
        $this->assertNull($mondayDay['daily_total']);
        $this->assertSame(2, $mondayDay['source_count']);
        $this->assertTrue($pack->snapshot_json['sections']['workforce']['has_conflicts']);
    }

    public function test_same_date_identical_totals_not_treated_as_conflict(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa10');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertSame('recorded', $mondayDay['status']);
        $this->assertSame(5, $mondayDay['daily_total']);
    }

    public function test_same_date_conflicting_breakdowns_not_silently_summed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa11');
        $diary1 = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $diary2 = $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary1->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Labourer', 'operative_count' => 3,
        ]);
        SiteDiaryWorkforceEntry::create([
            'site_diary_id' => $diary2->id, 'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'trade_or_role' => 'Carpenter', 'operative_count' => 2,
        ]);
        $pack = $this->generatePack($project, $editor);

        $mondayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::MONDAY);
        $this->assertFalse($mondayDay['has_breakdown']);
        $this->assertNull($mondayDay['breakdown_total']);
        $this->assertTrue($pack->snapshot_json['sections']['workforce']['has_conflicts']);
        // No fabricated trade matrix from the ambiguous day.
        $this->assertSame([], $pack->snapshot_json['sections']['workforce']['rows']);
    }

    public function test_regeneration_refreshes_workforce_data(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa12');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $pack = $this->generatePack($project, $editor);
        $this->assertSame(5, $pack->snapshot_json['sections']['workforce']['person_days_total']);

        $this->makeSiteDiary($project, $editor, self::TUESDAY, ['workers_on_site' => 4]);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame(9, $pack->snapshot_json['sections']['workforce']['person_days_total']);
    }

    public function test_scheduler_captures_existing_workforce_source_data_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa13');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame(5, $pack->snapshot_json['sections']['workforce']['person_days_total']);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "...and_pdf_still_blocked") — see project-context.md's R1G.1-ACT
     * entry.
     */
    public function test_schema_remains_version_2_and_pdf_now_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wa14');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version']);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf");
        $response->assertStatus(201);
    }

    public function test_tenant_isolation_on_workforce_entries(): void
    {
        // Cross-organisation: authorize() itself rejects before any
        // project-mismatch check is reached.
        [, , $projectA] = $this->makeOrgProjectAndEditor('wa15a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('wa15b');
        $diaryA = $this->makeSiteDiary($projectA, $editorB, self::MONDAY);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/site-diaries/{$diaryA->id}/workforce-entries")->assertStatus(403);

        // Same-organisation, different Project — authorize() passes (same
        // org), but authorizeProjectSiteDiary's own project-match check
        // must still reject it, mirroring MeetingMinutesController's
        // established pattern.
        [$orgC, $editorC, $projectC1] = $this->makeOrgProjectAndEditor('wa15c1');
        $projectC2 = Project::create(['organization_id' => $orgC->id, 'created_by' => $editorC->id, 'name' => 'Project C2']);
        $diaryC1 = $this->makeSiteDiary($projectC1, $editorC, self::MONDAY);

        Sanctum::actingAs($editorC);
        $this->getJson("/api/projects/{$projectC2->id}/site-diaries/{$diaryC1->id}/workforce-entries")->assertStatus(404);
    }
}
