<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\DeliveryDocument;
use App\Models\FridayPack;
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
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Services\FridayPack\FridayPackLifecycleService;
use App\Services\FridayPack\FridayPackReadinessService;
use App\Services\FridayPack\FridayPackSectionDeclarationService;
use App\Support\FridayPack\FridayPackNotReadyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1F.2 — Content Readiness + Weekly
 * Declarations. Implements the R1F.1 approved architecture
 * (project-context.md's R1F.1 entry is the canonical matrix this test
 * suite verifies against, never re-decided here).
 */
class FridayPackReadinessTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';

    private function makeOrgProjectAndEditor(string $suffix): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}"]);

        return [$org, $editor, $project];
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    private function regenerate(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    private function readiness(FridayPack $pack): array
    {
        return app(FridayPackReadinessService::class)->evaluate($pack->fresh());
    }

    private function declare(FridayPack $pack, User $actor, string $section, string $subsection, string $declaration, ?string $note = null): FridayPackSectionDeclaration
    {
        return app(FridayPackSectionDeclarationService::class)->declare($pack->fresh(), $actor, $section, $subsection, $declaration, $note);
    }

    private function clearDeclaration(FridayPack $pack, User $actor, string $section, string $subsection): void
    {
        app(FridayPackSectionDeclarationService::class)->clear($pack->fresh(), $actor, $section, $subsection);
    }

    /** Fills every CORE text field so only the section under test remains a blocker. */
    private function fillCoreText(FridayPack $pack): void
    {
        $pack->update(['weekly_summary' => 'All works progressed as planned.', 'look_ahead' => 'Continue foundations next week.']);
    }

    /** Declares/confirms every conditional section so the pack is genuinely fully ready. */
    private function makeFullyReadyPack(Project $project, User $editor): FridayPack
    {
        $pack = $this->generatePack($project, $editor);
        $this->fillCoreText($pack);
        $pack->update(['site_issues_summary' => 'No issues.']);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $this->declare($pack->fresh(), $editor, $section, '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        }
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        return $pack->fresh();
    }

    private function makeSiteDiary(Project $project, User $editor, string $date, array $overrides = []): SiteDiary
    {
        return SiteDiary::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'diary_date' => $date, 'status' => 'submitted',
        ], $overrides));
    }

    // ── GENERAL ──────────────────────────────────────────────────────────

    public function test_readiness_is_derived_not_persisted(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('friday_packs', 'readiness_status'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('friday_packs', 'completion_percentage'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('friday_packs', 'compliance_score'));
    }

    public function test_incomplete_draft_is_valid(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('draft', $pack->status);
        $this->assertFalse($this->readiness($pack)['ready']);
    }

    public function test_scheduler_creates_incomplete_draft_without_declarations(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('g2');
        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame('draft', $pack->status);
        $this->assertSame(0, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
        $this->assertFalse($this->readiness($pack)['ready']);
    }

    public function test_draft_to_ready_blocked_when_required_content_missing(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g3');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/submit-for-review");
        $response->assertStatus(409);
        $this->assertFalse($response->json('readiness.ready'));
        $this->assertNotEmpty($response->json('readiness.blockers'));
        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_blocker_payload_deterministic_no_internal_names(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g4');
        $pack = $this->generatePack($project, $editor);

        $blockers = $this->readiness($pack)['blockers'];
        foreach ($blockers as $blocker) {
            $this->assertArrayHasKey('section_key', $blocker);
            $this->assertArrayHasKey('label', $blocker);
            $this->assertArrayHasKey('reason', $blocker);
            $this->assertStringNotContainsString('App\\', $blocker['reason']);
            $this->assertStringNotContainsString('Model', $blocker['reason']);
        }
    }

    public function test_no_percentages_or_compliance_score_anywhere_in_payload(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g5');
        $pack = $this->generatePack($project, $editor);
        $json = strtolower(json_encode($this->readiness($pack)));

        $this->assertStringNotContainsString('percent', $json);
        $this->assertStringNotContainsString('score', $json);
        $this->assertStringNotContainsString('compliant', $json);
    }

    public function test_schema_1_skips_new_readiness_gate(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g6');
        $pack = FridayPack::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'week_ending' => self::FRIDAY, 'period_start' => self::MONDAY, 'period_end' => self::FRIDAY,
            'status' => 'draft', 'snapshot_json' => ['schema_version' => 1, 'sections' => []],
            'settings_snapshot_json' => ['enabled' => true, 'included_sections' => []],
            'generated_at' => now(), 'generated_by' => $editor->id,
        ]);

        $result = app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
        $this->assertSame('ready_for_review', $result->status);
    }

    public function test_historical_approved_sent_pack_unaffected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g7');
        $pack = $this->makeFullyReadyPack($project, $editor);
        app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);
        $pack = app(FridayPackLifecycleService::class)->markReviewed($pack->fresh(), $editor);
        $pack = app(FridayPackLifecycleService::class)->approve($pack->fresh(), $editor);

        $this->assertSame('approved', $pack->status);
        // Declaration mutation must now be rejected — see Draft-only tests below.
    }

    // ── DECLARATION STORAGE ──────────────────────────────────────────────

    public function test_top_level_declaration_stored_with_empty_subsection_key(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds1');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('', $d->subsection_key);
    }

    public function test_permits_child_declaration_stored_with_subsection_key_permits(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds2');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->assertSame('permits', $d->subsection_key);
    }

    public function test_inspections_child_declaration_stored_with_subsection_key_inspections(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds3');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->assertSame('inspections', $d->subsection_key);
    }

    public function test_duplicate_top_level_slot_rejected_at_db_level(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds4');
        $pack = $this->generatePack($project, $editor);
        FridayPackSectionDeclaration::create([
            'friday_pack_id' => $pack->id, 'section_key' => 'incidents', 'subsection_key' => '',
            'declaration' => 'confirmed_none', 'confirmed_by' => $editor->id, 'confirmed_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FridayPackSectionDeclaration::create([
            'friday_pack_id' => $pack->id, 'section_key' => 'incidents', 'subsection_key' => '',
            'declaration' => 'confirmed_none', 'confirmed_by' => $editor->id, 'confirmed_at' => now(),
        ]);
    }

    public function test_permits_and_inspections_slots_coexist(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds5');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack, $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->assertSame(2, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
    }

    public function test_declaration_actor_and_confirmed_at_recorded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds6');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame($editor->id, $d->confirmed_by);
        $this->assertNotNull($d->confirmed_at);
    }

    public function test_declaration_optional_note(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds7');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE, 'Checked with site manager.');
        $this->assertSame('Checked with site manager.', $d->note);
    }

    public function test_invalidated_at_nullable_and_defaults_null(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ds8');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertNull($d->invalidated_at);
    }

    // ── DECLARATION MUTATION ─────────────────────────────────────────────

    public function test_confirmed_none_saved_where_allowed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm1');
        $pack = $this->generatePack($project, $editor);
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/section-declarations", [
            'section_key' => 'incidents', 'declaration' => 'confirmed_none',
        ])->assertOk();
    }

    public function test_not_applicable_saved_where_allowed(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm2');
        $pack = $this->generatePack($project, $editor);
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/section-declarations", [
            'section_key' => 'rams', 'declaration' => 'not_applicable',
        ])->assertOk();
    }

    public function test_disallowed_confirmed_none_rejected_for_weekly_summary(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm3');
        $pack = $this->generatePack($project, $editor);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'weekly_summary', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    public function test_disallowed_not_applicable_rejected_for_incidents(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm4');
        $pack = $this->generatePack($project, $editor);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
    }

    public function test_invalid_section_key_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm5');
        $pack = $this->generatePack($project, $editor);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'not_a_real_section', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    public function test_invalid_subsection_key_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm6');
        $pack = $this->generatePack($project, $editor);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'permits_inspections', 'not_a_real_child', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    public function test_declaration_on_excluded_section_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm7');
        $pack = $this->generatePack($project, $editor);
        // Simulate a disabled section by stripping it from included_sections.
        $settings = $pack->settings_snapshot_json;
        $settings['included_sections'] = array_values(array_diff($settings['included_sections'], ['incidents']));
        $pack->update(['settings_snapshot_json' => $settings]);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    public function test_unauthorized_user_rejected(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('dm8a');
        [, $editorB, ] = $this->makeOrgProjectAndEditor('dm8b');
        $pack = $this->generatePack($project, User::find($project->created_by));

        Sanctum::actingAs($editorB);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/section-declarations", [
            'section_key' => 'incidents', 'declaration' => 'confirmed_none',
        ])->assertStatus(403);
    }

    public function test_cross_project_mutation_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('dm9a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $packA = $this->generatePack($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$projectB->id}/friday-packs/{$packA->id}/section-declarations", [
            'section_key' => 'incidents', 'declaration' => 'confirmed_none',
        ])->assertStatus(404);
    }

    public function test_non_draft_mutation_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm10');
        $pack = $this->makeFullyReadyPack($project, $editor);
        app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);

        $this->expectException(\RuntimeException::class);
        $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    public function test_declaration_clear_works(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm11');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);

        $this->clearDeclaration($pack, $editor, 'incidents', '');
        $this->assertSame(0, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
    }

    public function test_clear_and_declare_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm12');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'friday_pack_section_declared']);

        $this->clearDeclaration($pack, $editor, 'incidents', '');
        $this->assertDatabaseHas('project_activities', ['activity_type' => 'friday_pack_section_declaration_cleared']);
    }

    public function test_redeclaration_after_invalidation_reuses_slot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm13');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);

        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'Dropped tool', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $this->regenerate($project, $editor);
        $this->assertNotNull(FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->first()->invalidated_at);

        Incident::query()->delete();
        $this->regenerate($project, $editor);
        $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE, 'confirmed again');

        $this->assertSame(1, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
        $fresh = FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->first();
        $this->assertNull($fresh->invalidated_at);
        $this->assertSame('confirmed again', $fresh->note);
    }

    public function test_redeclaration_refreshes_confirmed_by_and_at(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dm14');
        $pack = $this->generatePack($project, $editor);
        $first = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        sleep(1);
        $second = $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);

        $this->assertTrue($second->confirmed_at->gt($first->confirmed_at));
    }

    // ── CONTRADICTION ────────────────────────────────────────────────────

    public function test_source_data_wins_over_declaration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'Dropped tool', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $pack = $this->regenerate($project, $editor);

        $sections = $this->readiness($pack)['sections'];
        $this->assertSame('complete', $sections['incidents']['state']);
    }

    public function test_regeneration_invalidates_confirmed_none_when_source_appears(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c2');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'Dropped tool', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $this->regenerate($project, $editor);

        $this->assertNotNull(FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->first()->invalidated_at);
    }

    public function test_regeneration_invalidates_not_applicable_when_source_appears(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'rams', '', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        DeliveryDocument::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'RAMS Doc', 'category' => 'rams', 'status' => 'required',
        ]);
        $this->regenerate($project, $editor);

        $this->assertNotNull(FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->first()->invalidated_at);
    }

    public function test_invalidation_does_not_delete_row(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c4');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'X', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $this->regenerate($project, $editor);

        $this->assertSame(1, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
    }

    public function test_invalidation_logged_with_real_regeneration_actor(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c5');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'X', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $this->regenerate($project, $editor);

        $this->assertDatabaseHas('project_activities', [
            'activity_type' => 'friday_pack_section_declaration_invalidated',
            'user_id' => $editor->id,
        ]);
    }

    public function test_still_empty_regenerated_section_preserves_declaration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c6');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->regenerate($project, $editor);

        $this->assertNull(FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->first()->invalidated_at);
    }

    public function test_invalidated_declaration_ignored_by_readiness(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c7');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $d->update(['invalidated_at' => now()]);

        $sections = $this->readiness($pack)['sections'];
        $this->assertSame('missing', $sections['incidents']['state']);
    }

    // ── REPORT INFORMATION ───────────────────────────────────────────────

    public function test_report_information_required_subset_satisfied_by_generation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ri1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['report_information']['state']);
    }

    public function test_report_information_honest_null_fields_do_not_block(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ri2');
        $pack = $this->generatePack($project, $editor);
        // Project has no principal contractor/scope of works/site address set — must not block.
        $this->assertSame('complete', $this->readiness($pack)['sections']['report_information']['state']);
        $blockerKeys = collect($this->readiness($pack)['blockers'])->pluck('section_key');
        $this->assertNotContains('report_information', $blockerKeys);
    }

    // ── WEEKLY SUMMARY ───────────────────────────────────────────────────

    public function test_weekly_summary_blank_blocks(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['weekly_summary']['state']);
    }

    public function test_weekly_summary_whitespace_only_blocks(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws2');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => "   \n  "]);
        $this->assertSame('missing', $this->readiness($pack)['sections']['weekly_summary']['state']);
    }

    public function test_weekly_summary_non_empty_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws3');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['weekly_summary' => 'Foundations complete.']);
        $this->assertSame('complete', $this->readiness($pack)['sections']['weekly_summary']['state']);
    }

    public function test_weekly_summary_declaration_shortcut_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ws4');
        $pack = $this->generatePack($project, $editor);
        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'weekly_summary', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
    }

    // ── WORKFORCE ────────────────────────────────────────────────────────

    public function test_workforce_real_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf1');
        $pack = $this->generatePack($project, $editor);
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['workers_on_site' => 5]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['workforce']['state']);
    }

    public function test_workforce_no_source_remains_missing(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf2');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['workforce']['state']);
    }

    public function test_workforce_confirmed_none_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'workforce', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('confirmed_none', $this->readiness($pack)['sections']['workforce']['state']);
    }

    public function test_workforce_not_applicable_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wf4');
        $pack = $this->generatePack($project, $editor);
        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'workforce', '', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
    }

    // ── SITE PHOTOGRAPHS ─────────────────────────────────────────────────

    public function test_site_photographs_missing_without_selection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sp1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['site_photographs']['state']);
    }

    public function test_site_photographs_confirmed_none_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sp2');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'site_photographs', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('confirmed_none', $this->readiness($pack)['sections']['site_photographs']['state']);
    }

    // ── RAMS ─────────────────────────────────────────────────────────────

    public function test_rams_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rams1');
        $pack = $this->generatePack($project, $editor);
        DeliveryDocument::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'RAMS Doc', 'category' => 'rams', 'status' => 'required',
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['rams']['state']);
    }

    public function test_rams_not_applicable_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rams2');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'rams', '', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->assertSame('not_applicable', $this->readiness($pack)['sections']['rams']['state']);
    }

    // ── TOOLBOX TALKS / SITE INDUCTIONS ──────────────────────────────────

    public function test_toolbox_talks_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tt1');
        $pack = $this->generatePack($project, $editor);
        ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Talk', 'talk_date' => self::MONDAY, 'attendee_count' => 3,
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['toolbox_talks']['state']);
    }

    public function test_toolbox_talks_confirmed_none_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tt2');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'toolbox_talks', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('confirmed_none', $this->readiness($pack)['sections']['toolbox_talks']['state']);
    }

    public function test_site_inductions_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si1');
        $pack = $this->generatePack($project, $editor);
        SiteInduction::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'induction_date' => self::MONDAY, 'inductee_count' => 1,
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['site_inductions']['state']);
    }

    // ── INCIDENTS ────────────────────────────────────────────────────────

    public function test_incident_rows_complete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inc1');
        $pack = $this->generatePack($project, $editor);
        Incident::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'type' => 'near_miss', 'title' => 'X', 'occurred_at' => self::MONDAY . ' 09:00:00',
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['incidents']['state']);
    }

    public function test_no_incident_rows_remains_missing(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inc2');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['incidents']['state']);
    }

    public function test_incident_confirmed_none_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inc3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('confirmed_none', $this->readiness($pack)['sections']['incidents']['state']);
    }

    public function test_incident_not_applicable_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('inc4');
        $pack = $this->generatePack($project, $editor);
        $this->expectException(\RuntimeException::class);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
    }

    // ── H&S INSPECTIONS ──────────────────────────────────────────────────

    public function test_hs_inspection_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('hs1');
        $pack = $this->generatePack($project, $editor);
        HsInspection::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => self::MONDAY, 'inspection_type' => 'General', 'inspected_by' => 'Jane', 'outcome' => 'satisfactory',
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['hs_inspections']['state']);
    }

    // ── PERMITS / STATUTORY INSPECTIONS (independent children) ──────────

    public function test_permit_source_completes_permits_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi1');
        $pack = $this->generatePack($project, $editor);
        DeliveryDocument::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Permit', 'category' => 'permit', 'status' => 'required',
        ]);
        $pack = $this->regenerate($project, $editor);

        $subsections = $this->readiness($pack)['sections']['permits_inspections']['subsections'];
        $this->assertSame('complete', $subsections['permits']['state']);
        $this->assertSame('missing', $subsections['inspections']['state']);
    }

    public function test_statutory_inspection_source_completes_inspections_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi2');
        $pack = $this->generatePack($project, $editor);
        StatutoryInspection::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'inspection_date' => self::MONDAY, 'inspection_type' => 'X', 'subject_description' => 'Y', 'outcome' => 'satisfactory',
        ]);
        $pack = $this->regenerate($project, $editor);

        $subsections = $this->readiness($pack)['sections']['permits_inspections']['subsections'];
        $this->assertSame('missing', $subsections['permits']['state']);
        $this->assertSame('complete', $subsections['inspections']['state']);
    }

    public function test_permit_and_inspection_child_declarations_independent(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        $subsections = $this->readiness($pack)['sections']['permits_inspections']['subsections'];
        $this->assertSame('not_applicable', $subsections['permits']['state']);
        $this->assertSame('missing', $subsections['inspections']['state']);
    }

    public function test_combined_parent_complete_only_when_both_children_resolved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pi4');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        // One child still missing -> a blocker for that child must exist.
        $blockers = collect($this->readiness($pack)['blockers'])->pluck('subsection_key');
        $this->assertContains('inspections', $blockers);
        $this->assertNotContains('permits', $blockers);

        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $blockers = collect($this->readiness($pack->fresh())['blockers'])->pluck('section_key');
        $this->assertNotContains('permits_inspections', $blockers);
    }

    // ── PLANT & EQUIPMENT ────────────────────────────────────────────────

    public function test_plant_deployment_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pl1');
        $pack = $this->generatePack($project, $editor);
        $item = PlantItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'name' => 'Crane', 'type' => 'Tower Crane',
        ]);
        PlantDeployment::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'plant_item_id' => $item->id,
            'created_by' => $editor->id, 'on_site_from' => self::MONDAY,
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['plant_equipment']['state']);
    }

    public function test_plant_item_without_deployment_does_not_satisfy_source(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pl2');
        $pack = $this->generatePack($project, $editor);
        PlantItem::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'name' => 'Crane', 'type' => 'Tower Crane',
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['plant_equipment']['state']);
    }

    // ── MATERIALS DELIVERED ──────────────────────────────────────────────

    public function test_materials_source_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('mat1');
        $pack = $this->generatePack($project, $editor);
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['materials_delivered' => 'Steel delivered.']);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('complete', $this->readiness($pack)['sections']['materials_delivered']['state']);
    }

    // ── SITE ISSUES ──────────────────────────────────────────────────────

    public function test_site_issues_summary_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('siss1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['site_issues']['state']);
    }

    public function test_site_issues_confirmed_summary_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('siss2');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['site_issues_summary' => 'No delays this week.']);
        $this->assertSame('complete', $this->readiness($pack)['sections']['site_issues']['state']);
    }

    public function test_site_issues_confirmed_none_alternative(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('siss3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'site_issues', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->assertSame('confirmed_none', $this->readiness($pack)['sections']['site_issues']['state']);
    }

    public function test_raw_site_issues_source_does_not_bypass_summary_rule(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('siss4');
        $pack = $this->generatePack($project, $editor);
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['issues' => 'Access blocked.']);
        $pack = $this->regenerate($project, $editor);
        // Raw diary "issues" text alone never satisfies the confirmed-summary rule.
        $this->assertSame('missing', $this->readiness($pack)['sections']['site_issues']['state']);
    }

    // ── LOOK AHEAD ───────────────────────────────────────────────────────

    public function test_look_ahead_blank_blocks(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la1');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['look_ahead']['state']);
    }

    public function test_look_ahead_confirmed_text_completes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la2');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['look_ahead' => 'Continue foundations next week.']);
        $this->assertSame('complete', $this->readiness($pack)['sections']['look_ahead']['state']);
    }

    public function test_look_ahead_milestones_alone_do_not_complete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la3');
        $pack = $this->generatePack($project, $editor);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'type' => 'main_contract', 'title' => 'A Contract',
        ]);
        ContractProgrammeMilestone::create([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'name' => 'M1', 'forecast_date' => '2026-08-24',
        ]);
        $pack = $this->regenerate($project, $editor);
        $this->assertSame('missing', $this->readiness($pack)['sections']['look_ahead']['state']);
    }

    // ── SIGN OFF ─────────────────────────────────────────────────────────

    public function test_sign_off_excluded_from_readiness(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so1');
        $pack = $this->generatePack($project, $editor);
        $this->assertArrayNotHasKey('sign_off', $this->readiness($pack)['sections']);
    }

    // ── DISABLED / CONFIG ────────────────────────────────────────────────

    public function test_excluded_section_omitted_from_readiness(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('dis1');
        $pack = $this->generatePack($project, $editor);
        $settings = $pack->settings_snapshot_json;
        $settings['included_sections'] = array_values(array_diff($settings['included_sections'], ['incidents']));
        $pack->update(['settings_snapshot_json' => $settings]);

        $this->assertArrayNotHasKey('incidents', $this->readiness($pack)['sections']);
        $blockerKeys = collect($this->readiness($pack)['blockers'])->pluck('section_key');
        $this->assertNotContains('incidents', $blockerKeys);
    }

    // ── LIFECYCLE ────────────────────────────────────────────────────────

    public function test_all_blockers_resolved_submit_for_review_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc1');
        $pack = $this->generatePack($project, $editor);
        $this->fillCoreText($pack);
        $pack->update(['site_issues_summary' => 'No issues.']);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $this->declare($pack->fresh(), $editor, $section, '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        }
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        $this->assertTrue($this->readiness($pack)['ready']);
        $result = app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);
        $this->assertSame('ready_for_review', $result->status);
    }

    public function test_readiness_evaluated_before_status_mutation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc2');
        $pack = $this->generatePack($project, $editor);

        try {
            app(FridayPackLifecycleService::class)->submitForReview($pack, $editor);
            $this->fail('Expected FridayPackNotReadyException.');
        } catch (FridayPackNotReadyException $e) {
            $this->assertNotEmpty($e->blockers());
        }

        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_return_to_draft_retains_declarations(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc3');
        $pack = $this->generatePack($project, $editor);
        $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $this->fillCoreText($pack);
        $pack->update(['site_issues_summary' => 'None.']);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $this->declare($pack->fresh(), $editor, $section, '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        }
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);
        app(FridayPackLifecycleService::class)->returnToDraft($pack->fresh(), $editor);

        $this->assertGreaterThan(0, FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->count());
        $this->assertSame('draft', $pack->fresh()->status);
    }

    public function test_declaration_editable_again_after_return_to_draft(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('lc4');
        $pack = $this->generatePack($project, $editor);
        $this->fillCoreText($pack);
        $pack->update(['site_issues_summary' => 'None.']);
        foreach (['workforce', 'site_photographs', 'rams', 'toolbox_talks', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment', 'materials_delivered'] as $section) {
            $this->declare($pack->fresh(), $editor, $section, '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        }
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'permits', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);
        $this->declare($pack->fresh(), $editor, 'permits_inspections', 'inspections', FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE);

        app(FridayPackLifecycleService::class)->submitForReview($pack->fresh(), $editor);
        app(FridayPackLifecycleService::class)->returnToDraft($pack->fresh(), $editor);

        $this->declare($pack->fresh(), $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE, 'updated');
        $this->assertSame('updated', FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)->where('section_key', 'incidents')->first()->note);
    }

    // ── API / UI CONTRACT ────────────────────────────────────────────────

    public function test_detail_payload_contains_readiness(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('api1');
        $pack = $this->generatePack($project, $editor);
        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}");
        $response->assertOk();
        $this->assertArrayHasKey('readiness', $response->json());
    }

    public function test_invalidated_declaration_not_represented_as_active_in_payload(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('api2');
        $pack = $this->generatePack($project, $editor);
        $d = $this->declare($pack, $editor, 'incidents', '', FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE);
        $d->update(['invalidated_at' => now()]);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}");
        $this->assertArrayNotHasKey('declaration', $response->json('readiness.sections.incidents'));
    }

    public function test_permits_inspections_expose_independent_subsection_status(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('api3');
        $pack = $this->generatePack($project, $editor);
        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}");
        $this->assertArrayHasKey('permits', $response->json('readiness.sections.permits_inspections.subsections'));
        $this->assertArrayHasKey('inspections', $response->json('readiness.sections.permits_inspections.subsections'));
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`. This test
     * previously proved PDF generation was refused outright; updated in
     * place now that condition has changed. What it proves NOW is the
     * more important, still-true architectural invariant this checkpoint
     * required verifying: a Draft PDF preview must remain generable even
     * for a pack that is NOT readiness-complete (no declarations made at
     * all here) — readiness/Draft→Ready content gating governs
     * submitForReview(), never pdf() — the two are deliberately separate
     * concepts, and activation must not accidentally couple them.
     */
    public function test_schema_2_pdf_generates_even_when_not_readiness_complete(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pdf1');
        $pack = $this->generatePack($project, $editor);
        Sanctum::actingAs($editor);

        // Confirm this pack is genuinely NOT readiness-complete first —
        // otherwise this test would prove nothing about the separation
        // between the two concepts.
        $readiness = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->json('readiness');
        $this->assertFalse($readiness['ready'], 'Expected a freshly generated pack, with no declarations made, to NOT be readiness-complete.');

        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }
}
