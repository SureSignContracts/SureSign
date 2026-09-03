<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DelayEvent;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1D — Report Information, Materials Delivered,
 * Site Issues, Look Ahead, Sign Off. Mirrors FridayPackR1CTest's exact
 * conventions. See FridayPackReportInformationService/
 * FridayPackMaterialsSourceService/FridayPackSiteIssuesSourceService/
 * FridayPackLookAheadSourceService docblocks for the architectural
 * decisions this proves.
 */
class FridayPackR1DTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';
    private const TUESDAY = '2026-08-18';
    private const SATURDAY = '2026-08-22';
    // Next week's window: 2026-08-24 (Mon) through 2026-08-28 (Fri).
    private const NEXT_MONDAY = '2026-08-24';
    private const NEXT_FRIDAY = '2026-08-28';
    private const WEEK_AFTER_NEXT_MONDAY = '2026-08-31';

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client', array $projectOverrides = []): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create(array_merge([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
            'code'            => strtoupper($suffix),
        ], $projectOverrides));

        return [$org, $editor, $project];
    }

    private function makeContract(Project $project, User $editor, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'type' => 'main_contract', 'title' => 'A Contract',
        ], $overrides));
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

    private function reportInfo(FridayPack $pack): array
    {
        return $pack->snapshot_json['sections']['report_information'];
    }

    // ── Report Information — Project / Site Address / Freeze ──────────────

    public function test_project_name_and_code_frozen_in_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ri1');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame($project->name, $this->reportInfo($pack)['project_name']);
        $this->assertSame($project->code, $this->reportInfo($pack)['project_code']);
    }

    public function test_site_address_frozen_in_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ri2', 'Client', [
            'address' => '1 Site Rd', 'city' => 'Colchester', 'state' => 'Essex', 'postcode' => 'CO1 1AA', 'country' => 'UK',
        ]);
        $pack = $this->generatePack($project, $editor);

        $address = $this->reportInfo($pack)['site_address'];
        $this->assertSame('1 Site Rd', $address['address']);
        $this->assertSame('Colchester', $address['city']);
        $this->assertSame('CO1 1AA', $address['postcode']);
    }

    public function test_project_edits_after_generation_do_not_alter_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ri3');
        $pack = $this->generatePack($project, $editor);
        $originalName = $this->reportInfo($pack)['project_name'];

        $project->update(['name' => 'Renamed After Generation']);

        $this->assertSame($originalName, $this->reportInfo($pack->fresh())['project_name']);
        $this->assertNotSame('Renamed After Generation', $this->reportInfo($pack->fresh())['project_name']);
    }

    // ── Principal Contractor ───────────────────────────────────────────────

    public function test_principal_contractor_from_single_eligible_main_contract(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc1');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'principal_contractor' => 'Acme Principal Co']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame('Acme Principal Co', $this->reportInfo($pack)['principal_contractor']);
    }

    public function test_principal_contractor_null_when_zero_eligible_contracts(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc2');
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['principal_contractor']);
    }

    public function test_principal_contractor_null_when_multiple_eligible_main_contracts(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc3');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'principal_contractor' => 'Contractor A']);
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'principal_contractor' => 'Contractor B']);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['principal_contractor']);
    }

    public function test_principal_contractor_null_when_eligible_contract_value_is_null(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc4');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'principal_contractor' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['principal_contractor']);
    }

    public function test_principal_contractor_ignores_terminated_and_subcontract_contracts(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc5');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'status' => 'terminated', 'principal_contractor' => 'Terminated Co']);
        $this->makeContract($project, $editor, ['type' => 'subcontract', 'principal_contractor' => 'Subcontract Co']);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['principal_contractor']);
    }

    public function test_principal_contractor_never_derived_from_organisation_or_party_name(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pc6');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'party_name' => 'Some Party', 'principal_contractor' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['principal_contractor']);
    }

    // ── Reporting Organisation / Sub-Contractor label ──────────────────────

    public function test_reporting_organisation_and_role_captured(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('ro1', 'Client', ['organization_role' => 'subcontractor']);
        $pack = $this->generatePack($project, $editor);

        $info = $this->reportInfo($pack);
        $this->assertSame($org->name, $info['reporting_organisation_name']);
        $this->assertSame('subcontractor', $info['organization_role']);
    }

    public function test_reporting_organisation_role_null_when_not_set(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('ro2');
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['organization_role']);
    }

    // ── Sub-Contract Order Number ───────────────────────────────────────────

    public function test_sub_contract_order_no_from_single_eligible_subcontract(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so1');
        $this->makeContract($project, $editor, ['type' => 'subcontract', 'reference_number' => 'SC-001']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame('SC-001', $this->reportInfo($pack)['sub_contract_order_no']);
    }

    public function test_sub_contract_order_no_null_when_zero_eligible(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so2');
        $this->makeContract($project, $editor, ['type' => 'main_contract', 'reference_number' => 'MC-001']);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['sub_contract_order_no']);
    }

    public function test_sub_contract_order_no_null_when_multiple_eligible(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so3');
        $this->makeContract($project, $editor, ['type' => 'subcontract', 'reference_number' => 'SC-001']);
        $this->makeContract($project, $editor, ['type' => 'subcontract', 'reference_number' => 'SC-002']);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['sub_contract_order_no']);
    }

    public function test_sub_contract_order_no_null_when_reference_empty(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so4');
        $this->makeContract($project, $editor, ['type' => 'subcontract', 'reference_number' => null]);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['sub_contract_order_no']);
    }

    public function test_sub_contract_order_no_never_uses_trade_package_reference(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so5');
        \App\Models\TradePackage::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'name' => 'Pkg', 'slug' => 'pkg-r1d-so5', 'package_reference' => 'PKG-999', 'status' => 'active',
        ]);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['sub_contract_order_no']);
    }

    // ── Scope of Works — R1D honest null ────────────────────────────────────

    public function test_scope_of_works_always_null_in_r1d(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sw1', 'Client', ['description' => 'A real project description']);
        $this->makeContract($project, $editor, ['notes' => 'Some contract notes']);
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($this->reportInfo($pack)['scope_of_works']);
    }

    // ── Week dates / Prepared By / Report Number ────────────────────────────

    public function test_week_dates_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('wd1');
        $pack = $this->generatePack($project, $editor);

        $info = $this->reportInfo($pack);
        $this->assertSame(self::MONDAY, $info['week_commencing']);
        $this->assertSame(self::FRIDAY, $info['week_ending']);
    }

    public function test_prepared_by_manual_generation_uses_actor_name(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pb1');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame($editor->name, $this->reportInfo($pack)['prepared_by']);
        $this->assertNotNull($this->reportInfo($pack)['prepared_at']);
    }

    public function test_prepared_by_scheduled_generation_is_suresign_automation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('pb2');
        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame('SureSign Automation', $this->reportInfo($pack)['prepared_by']);
    }

    public function test_report_number_allocated_once_at_creation(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rn1');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame('001', $pack->report_number);
        $this->assertSame('001', $this->reportInfo($pack)['report_number']);
    }

    public function test_report_number_preserved_across_regeneration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rn2');
        $pack = $this->generatePack($project, $editor);
        $originalNumber = $pack->report_number;

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame($originalNumber, $pack->report_number);
        $this->assertSame($originalNumber, $this->reportInfo($pack)['report_number']);
    }

    public function test_report_number_sequence_is_project_scoped_and_atomic(): void
    {
        [, $editor, $projectA] = $this->makeOrgProjectAndEditor('rn3a');
        [, , $projectB] = $this->makeOrgProjectAndEditor('rn3b');

        $service = app(DocumentNumberService::class);
        $this->assertSame('001', $service->allocateFridayPackSequence($projectA));
        $this->assertSame('002', $service->allocateFridayPackSequence($projectA));
        // A different project starts its own sequence at 001, not 003.
        $this->assertSame('001', $service->allocateFridayPackSequence($projectB));
    }

    public function test_report_number_uniqueness_enforced_at_db_level(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('rn4');
        $this->generatePack($project, $editor, self::FRIDAY);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FridayPack::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'week_ending' => '2026-08-28', 'period_start' => '2026-08-24', 'period_end' => '2026-08-28',
            'status' => 'draft', 'report_number' => '001', 'snapshot_json' => [], 'settings_snapshot_json' => [],
        ]);
    }

    // ── Date Issued / Distributed To ────────────────────────────────────────

    public function test_report_information_excludes_date_issued_and_distributed_to(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('di1');
        $pack = $this->generatePack($project, $editor);

        $info = $this->reportInfo($pack);
        $this->assertArrayNotHasKey('date_issued', $info);
        $this->assertArrayNotHasKey('distributed_to', $info);
    }

    public function test_sent_at_remains_null_until_a_real_send(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('di2');
        $pack = $this->generatePack($project, $editor);

        $this->assertNull($pack->sent_at);
    }

    // ── Materials Delivered ─────────────────────────────────────────────────

    public function test_materials_mon_fri_source_collection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('md1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['materials_delivered' => 'Bricks and mortar']);
        $this->makeSiteDiary($project, $editor, self::TUESDAY, ['materials_delivered' => null]);
        $pack = $this->generatePack($project, $editor);

        $items = $pack->snapshot_json['sections']['materials_delivered']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Bricks and mortar', $items[0]['materials_delivered']);
        $this->assertSame(1, $pack->snapshot_json['sections']['materials_delivered']['source_site_report_count']);
    }

    public function test_materials_saturday_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('md2');
        $this->makeSiteDiary($project, $editor, self::SATURDAY, ['materials_delivered' => 'Weekend delivery']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['materials_delivered']['items']);
    }

    public function test_materials_no_invented_fields(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('md3');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['materials_delivered' => '10no. steel beams']);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['materials_delivered']['items'][0];
        $this->assertSame(['date', 'day_name', 'materials_delivered'], array_keys($item));
    }

    public function test_materials_empty_when_none_recorded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('md4');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['materials_delivered']['items']);
        $this->assertSame(0, $pack->snapshot_json['sections']['materials_delivered']['source_site_report_count']);
    }

    public function test_materials_snapshot_does_not_retroactively_change(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('md5');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame([], $pack->snapshot_json['sections']['materials_delivered']['items']);

        $this->makeSiteDiary($project, $editor, self::MONDAY, ['materials_delivered' => 'Added after generation']);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['materials_delivered']['items']);
    }

    // ── Site Issues, Delays & Risks ──────────────────────────────────────────

    public function test_site_issues_source_collection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, ['issues' => 'Access blocked by delivery lorry']);
        $pack = $this->generatePack($project, $editor);

        $sources = $pack->snapshot_json['sections']['site_issues']['source_site_report_count'];
        $this->assertSame(1, $sources);
    }

    public function test_site_issues_week_scoped_delay_event_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si2');
        DelayEvent::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'event_number' => 1, 'title' => 'In-week delay', 'date_occurred' => self::MONDAY,
        ]);
        DelayEvent::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'event_number' => 2, 'title' => 'Out-of-week delay', 'date_occurred' => self::WEEK_AFTER_NEXT_MONDAY,
        ]);
        $pack = $this->generatePack($project, $editor);

        $refs = $pack->snapshot_json['sections']['site_issues']['delay_event_references'];
        $this->assertCount(1, $refs);
        $this->assertSame('In-week delay', $refs[0]['title']);
    }

    public function test_site_issues_never_dumps_contract_risk(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si3');
        ContractRisk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'title' => 'A persistent register risk', 'status' => 'open',
        ]);
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['site_issues'];
        $this->assertArrayNotHasKey('risks', $section);
        $this->assertArrayNotHasKey('contract_risks', $section);
        $this->assertStringNotContainsString('persistent register risk', json_encode($section));
    }

    public function test_site_issues_confirmed_summary_saves_while_draft(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si4');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", [
            'site_issues_summary' => 'Confirmed issues text',
        ]);

        $response->assertOk();
        $this->assertSame('Confirmed issues text', $pack->fresh()->site_issues_summary);
    }

    public function test_site_issues_regeneration_preserves_confirmed_summary(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si5');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['site_issues_summary' => 'Preserved text']);

        app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame('Preserved text', $pack->fresh()->site_issues_summary);
    }

    public function test_site_issues_summary_immutable_once_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si6');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['site_issues_summary' => 'Original']);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['site_issues_summary' => 'Changed after approval']);
    }

    public function test_snapshot_contains_confirmed_site_issues_text_after_regeneration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('si7');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['site_issues_summary' => 'Final confirmed issues']);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame('Final confirmed issues', $pack->snapshot_json['sections']['site_issues']['text']);
    }

    // ── Look Ahead ────────────────────────────────────────────────────────

    private function makeMilestone(Contract $contract, Project $project, array $overrides = []): ContractProgrammeMilestone
    {
        return ContractProgrammeMilestone::create(array_merge([
            'contract_id' => $contract->id, 'project_id' => $project->id, 'name' => 'A milestone',
        ], $overrides));
    }

    public function test_look_ahead_next_week_mon_fri_milestones_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la1');
        $contract = $this->makeContract($project, $editor);
        $this->makeMilestone($contract, $project, ['name' => 'In-window', 'forecast_date' => self::NEXT_MONDAY]);
        $this->makeMilestone($contract, $project, ['name' => 'Also in-window', 'forecast_date' => self::NEXT_FRIDAY]);
        $this->makeMilestone($contract, $project, ['name' => 'Too far ahead', 'forecast_date' => self::WEEK_AFTER_NEXT_MONDAY]);
        $this->makeMilestone($contract, $project, ['name' => 'This reporting week', 'forecast_date' => self::MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $names = collect($pack->snapshot_json['sections']['look_ahead']['milestones'])->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['In-window', 'Also in-window'], $names);
        $this->assertSame(2, $pack->snapshot_json['sections']['look_ahead']['source_milestone_count']);
    }

    public function test_look_ahead_never_dumps_the_full_programme(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la2');
        $contract = $this->makeContract($project, $editor);
        for ($i = 0; $i < 5; $i++) {
            $this->makeMilestone($contract, $project, ['name' => "Far future {$i}", 'planned_date' => '2027-01-01']);
        }
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['look_ahead']['milestones']);
    }

    public function test_look_ahead_prefers_forecast_date_over_planned_date(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la3');
        $contract = $this->makeContract($project, $editor);
        // planned_date falls OUTSIDE the window; forecast_date falls INSIDE — forecast must win.
        $this->makeMilestone($contract, $project, [
            'name' => 'Forecast wins', 'planned_date' => self::WEEK_AFTER_NEXT_MONDAY, 'forecast_date' => self::NEXT_MONDAY,
        ]);
        $pack = $this->generatePack($project, $editor);

        $names = collect($pack->snapshot_json['sections']['look_ahead']['milestones'])->pluck('name')->all();
        $this->assertSame(['Forecast wins'], $names);
    }

    public function test_look_ahead_uses_planned_date_when_forecast_absent(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la4');
        $contract = $this->makeContract($project, $editor);
        $this->makeMilestone($contract, $project, ['name' => 'Planned only', 'planned_date' => self::NEXT_MONDAY]);
        $pack = $this->generatePack($project, $editor);

        $names = collect($pack->snapshot_json['sections']['look_ahead']['milestones'])->pluck('name')->all();
        $this->assertSame(['Planned only'], $names);
    }

    public function test_look_ahead_confirmed_text_saves_while_draft(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la5');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['look_ahead' => 'Next week we pour slab'])
            ->assertOk();

        $this->assertSame('Next week we pour slab', $pack->fresh()->look_ahead);
    }

    public function test_look_ahead_regeneration_preserves_confirmed_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la6');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['look_ahead' => 'Preserved look ahead']);

        app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame('Preserved look ahead', $pack->fresh()->look_ahead);
    }

    public function test_look_ahead_immutable_once_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('la7');
        $pack = $this->generatePack($project, $editor);
        $pack->update(['look_ahead' => 'Original']);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['look_ahead' => 'Changed after approval']);
    }

    // ── Sign Off ──────────────────────────────────────────────────────────

    public function test_sign_off_snapshot_key_stays_an_empty_stub(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so_off1');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(['count' => 0, 'items' => []], $pack->snapshot_json['sections']['sign_off']);
    }

    public function test_sent_by_eager_loaded_on_show(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('so_off2');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}");

        $response->assertOk();
        $this->assertArrayHasKey('sent_by', $response->json());
    }

    // ── Scheduler / General ──────────────────────────────────────────────

    public function test_scheduler_assigns_report_number_and_captures_source_data_without_inventing_text(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('gen1');
        $this->makeSiteDiary($project, $editor, self::MONDAY, [
            'materials_delivered' => 'Timber', 'issues' => 'Late delivery',
        ]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertSame('001', $pack->report_number);
        $this->assertSame(1, $pack->snapshot_json['sections']['materials_delivered']['source_site_report_count']);
        $this->assertSame(1, $pack->snapshot_json['sections']['site_issues']['source_site_report_count']);
        $this->assertNull($pack->site_issues_summary);
        $this->assertNull($pack->look_ahead);
    }

    public function test_tenant_isolation_on_site_issues_sources(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('ten1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('ten1b');
        $packA = $this->generatePack($projectA, $editorB); // will fail auth below regardless of creator

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/friday-packs/{$packA->id}/site-issues-sources")->assertStatus(403);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "...and_pdf_still_blocked") — see project-context.md's R1G.1-ACT
     * entry.
     */
    public function test_schema_version_remains_2_and_pdf_now_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('gen2');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }
}
