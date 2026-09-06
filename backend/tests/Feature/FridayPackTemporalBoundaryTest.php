<?php

namespace Tests\Feature;

use App\Models\ContractProgrammeMilestone;
use App\Models\Contract;
use App\Models\DeliveryDocument;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\SiteInduction;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Support\FridayPack\FridayPackSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1F.0 — Temporal Boundary Integrity Audit.
 * Dedicated evidence file for the cross-phase audit's own findings and
 * fixes — see project-context.md's R1F.0 entry for the full temporal-
 * source matrix. Existing per-module test files (HsInspectionTest,
 * SiteInductionTest, FridayPackR1CTest, FridayPackR1DTest,
 * FridayPackR1E1Test, IncidentTest, PlantEquipmentTest,
 * StatutoryInspectionTest) remain the primary regression coverage for
 * each module's own domain rules; this file exists specifically to
 * close temporal-boundary gaps the audit identified and to prove the two
 * real defects this phase fixed:
 *
 * 1. `HsInspection::$inspection_date`'s bare `'date'` cast (see
 *    HsInspectionTest's own new boundary tests for that proof — not
 *    duplicated here).
 * 2. `FridayPackComplianceDocumentService`'s RAMS/Permit
 *    `submitted_this_week`/`approved_this_week` flags comparing a raw
 *    UTC `submitted_at`/`approved_at` instant via `toDateString()`
 *    against LOCAL calendar period boundaries — a genuine production
 *    bug (reproduced directly below), not a SQLite-only artifact.
 */
class FridayPackTemporalBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';
    private const SATURDAY = '2026-08-22';

    private function makeOrgProjectAndEditor(string $suffix, string $timezone = 'Europe/London'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => $timezone]);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
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

    private function makeSiteInduction(Project $project, User $editor, string $date, array $overrides = []): SiteInduction
    {
        return SiteInduction::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'induction_date' => $date, 'inductee_count' => 1,
        ], $overrides));
    }

    private function makeToolboxTalk(Project $project, User $editor, string $date, array $overrides = []): ToolboxTalk
    {
        return ToolboxTalk::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Talk', 'talk_date' => $date, 'attendee_count' => 3,
        ], $overrides));
    }

    private function makeDeliveryDocument(Project $project, User $editor, array $overrides = []): DeliveryDocument
    {
        return DeliveryDocument::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'title' => 'A Document', 'category' => 'other', 'status' => 'required',
            'created_by' => $editor->id,
        ], $overrides));
    }

    private function makeContract(Project $project, User $editor, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'type' => 'main_contract', 'title' => 'A Contract',
        ], $overrides));
    }

    private function makeMilestone(Contract $contract, Project $project, array $overrides = []): ContractProgrammeMilestone
    {
        return ContractProgrammeMilestone::create(array_merge([
            'contract_id' => $contract->id, 'project_id' => $project->id,
            'name' => 'A Milestone', 'milestone_type' => 'key_date',
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    // ── Site Inductions — closing the Friday-touch gap ──────────────────

    public function test_site_induction_friday_touching_period_end_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si1');
        $this->makeSiteInduction($project, $editor, self::FRIDAY);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['site_inductions']['items']);
    }

    // ── SiteDiary-based sources — confirming Pattern A (bare 'date'
    // cast + whereBetween "{start} 00:00:00"/"{end} 23:59:59" boundary)
    // remains genuinely, internally consistent at both edges. Not
    // touched by this phase's fixes — verified, not modified. ────────

    public function test_weekly_summary_friday_touching_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wsf');
        $this->makeSiteDiary($project, $editor, self::FRIDAY, ['works_carried_out' => 'Friday works.']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(1, $pack->snapshot_json['sections']['weekly_summary']['source_site_report_count']);
    }

    public function test_workforce_friday_touching_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wff');
        $this->makeSiteDiary($project, $editor, self::FRIDAY, ['workers_on_site' => 4]);
        $pack = $this->generatePack($project, $editor);

        $fridayDay = collect($pack->snapshot_json['sections']['workforce']['days'])->firstWhere('date', self::FRIDAY);
        $this->assertNotNull($fridayDay);
    }

    public function test_materials_monday_friday_included_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('mat1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['materials_delivered' => 'Steel delivered.']);
        $this->makeSiteDiary($project, $editor, self::FRIDAY, ['materials_delivered' => 'Timber delivered.']);
        $this->makeSiteDiary($project, $editor, self::SATURDAY, ['materials_delivered' => 'Weekend delivery.']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(2, $pack->snapshot_json['sections']['materials_delivered']['items']);
    }

    public function test_site_issues_monday_friday_included_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sit1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['issues' => 'Access blocked.']);
        $this->makeSiteDiary($project, $editor, self::FRIDAY, ['issues' => 'Late delivery.']);
        $this->makeSiteDiary($project, $editor, self::SATURDAY, ['issues' => 'Weekend issue.']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['sections']['site_issues']['source_site_report_count']);
    }

    // ── Toolbox Talks — the one section that still directly drives a
    // schema-2 section AND Site Photograph discovery from the same
    // date field. ────────────────────────────────────────────────────

    public function test_toolbox_talk_monday_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tt1');
        $this->makeToolboxTalk($project, $editor, self::MONDAY);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(1, $pack->snapshot_json['sections']['toolbox_talks']['count']);
    }

    public function test_toolbox_talk_friday_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tt2');
        $this->makeToolboxTalk($project, $editor, self::FRIDAY);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(1, $pack->snapshot_json['sections']['toolbox_talks']['count']);
    }

    public function test_toolbox_talk_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tt3');
        $this->makeToolboxTalk($project, $editor, self::SATURDAY);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(0, $pack->snapshot_json['sections']['toolbox_talks']['count']);
    }

    // ── RAMS / Permits — the genuine UTC-vs-local production bug this
    // phase found and fixed. Mirrors IncidentTest's own established
    // UTC-crossing proof pattern exactly (same org default timezone,
    // same crossing instants) — Europe/London (BST, UTC+1) is used
    // deliberately: local time is always AHEAD of UTC, so an early-local
    // -Monday submission can read as UTC Sunday (false EXCLUSION, the
    // real bug), and an early-local-Saturday submission can read as UTC
    // Friday (false INCLUSION — the same underlying defect's mirror
    // direction; the fix must reject this too). ─────────────────────

    public function test_rams_submitted_local_monday_early_morning_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ramstz1');
        // UTC 2026-08-16 23:15 = local Monday 2026-08-17 00:15 (BST +1).
        // The OLD toDateString()-based check reads UTC '2026-08-16'
        // (Sunday) and would have wrongly excluded this.
        $this->makeDeliveryDocument($project, $editor, [
            'category' => 'rams', 'title' => 'Crossing Doc', 'submitted_at' => '2026-08-16 23:15:00',
        ]);
        $pack = $this->generatePack($project, $editor);

        $flags = collect($pack->snapshot_json['sections']['rams']['items'])->pluck('submitted_this_week', 'title');
        $this->assertTrue($flags['Crossing Doc']);
    }

    public function test_rams_submitted_local_saturday_just_after_midnight_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ramstz2');
        // UTC 2026-08-21 23:30 = local Saturday 2026-08-22 00:30 (BST +1).
        // The OLD toDateString()-based check reads UTC '2026-08-21'
        // (Friday) and would have wrongly INCLUDED this.
        $this->makeDeliveryDocument($project, $editor, [
            'category' => 'rams', 'title' => 'Just After Local Midnight', 'submitted_at' => '2026-08-21 23:30:00',
        ]);
        $pack = $this->generatePack($project, $editor);

        $flags = collect($pack->snapshot_json['sections']['rams']['items'])->pluck('submitted_this_week', 'title');
        $this->assertFalse($flags['Just After Local Midnight']);
    }

    public function test_permit_approved_local_monday_early_morning_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('permtz1');
        $this->makeDeliveryDocument($project, $editor, [
            'category' => 'permit', 'status' => 'approved', 'title' => 'Crossing Permit', 'approved_at' => '2026-08-16 23:15:00',
        ]);
        $pack = $this->generatePack($project, $editor);

        $flags = collect($pack->snapshot_json['sections']['permits_inspections']['permits'])->pluck('approved_this_week', 'title');
        $this->assertTrue($flags['Crossing Permit']);
    }

    public function test_expiry_date_comparison_remains_factual_date_only(): void
    {
        // expiry_date is a plain DATE column — must never gain timezone
        // conversion; only local-date factual comparison against
        // period_end, unchanged by this phase's fix.
        [, $editor, $project] = $this->makeOrgProjectAndEditor('expiry1');
        $this->makeDeliveryDocument($project, $editor, [
            'category' => 'rams', 'status' => 'approved', 'title' => 'Expires on period end', 'expiry_date' => self::FRIDAY,
        ]);
        $pack = $this->generatePack($project, $editor);

        // Not yet passed period_end (expires ON period_end, inclusive) — still current.
        $this->assertTrue($pack->snapshot_json['sections']['rams']['items'][0]['current']);
    }

    // ── Look Ahead — tight boundary confirming the immediate Saturday
    // after next week's Friday is excluded (existing R1D tests already
    // prove Monday/Friday inclusion and a full week-later exclusion;
    // this closes the tight-boundary gap explicitly). ─────────────────

    public function test_look_ahead_saturday_immediately_after_next_friday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la1');
        $contract = $this->makeContract($project, $editor);
        // Next week is 2026-08-24 (Mon) .. 2026-08-28 (Fri); the following
        // Saturday is 2026-08-29 — must be excluded.
        $this->makeMilestone($contract, $project, ['name' => 'Just after next week', 'forecast_date' => '2026-08-29']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['look_ahead']['milestones']);
    }

    // ── General ──────────────────────────────────────────────────────

    public function test_schema_version_remains_2_after_temporal_fixes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('gen1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    public function test_no_authentic_section_falls_back_to_default_stub(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('gen2');
        $pack = $this->generatePack($project, $editor);

        foreach (FridayPackSections::ALL as $key) {
            $this->assertArrayHasKey($key, $pack->snapshot_json['sections'], "Missing section: {$key}");
            // The generic default stub shape is exactly ['count' => 0, 'items' => []]
            // with nothing else — every real collector's shape has at
            // least one additional key (source_count, sources, days,
            // milestones, permits, etc.) or, for sign_off, is
            // deliberately empty by design (see FridayPackSnapshotService's
            // own docblock) — so this only flags a genuine accidental
            // fallback, not sign_off's intentional exception.
            $section = $pack->snapshot_json['sections'][$key];
            if ($key === FridayPackSections::SIGN_OFF) {
                continue;
            }
            if ($key === FridayPackSections::SITE_PHOTOGRAPHS) {
                // Has its own genuine, distinct match arm
                // (FridayPackPhotoSelectionPresenter::sectionFor()) — but
                // for a brand-new pack (no existing FridayPack id yet,
                // so nothing could have been selected against it) it
                // legitimately produces the SAME empty shape as the
                // generic stub, by design. Shape alone can't distinguish
                // the two here; excluded rather than producing a false
                // positive.
                continue;
            }
            $isBareDefaultStub = is_array($section) && array_keys($section) === ['count', 'items'] && $section['count'] === 0 && $section['items'] === [];
            $this->assertFalse($isBareDefaultStub, "Section '{$key}' fell back to the generic default stub — no real collector matched.");
        }
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "test_schema_2_pdf_still_blocked") — see project-context.md's
     * R1G.1-ACT entry.
     */
    public function test_schema_2_pdf_now_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('gen3');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }
}
