<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\DeliveryDocument;
use App\Models\FridayPack;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.1 — RAMS + Permits. Mirrors
 * FridayPackR1DTest's exact conventions. See
 * FridayPackComplianceDocumentService's own docblock for the
 * architectural decisions this proves — RAMS/Permits are sourced
 * exclusively from the EXISTING App\Models\DeliveryDocument register,
 * never a new model.
 */
class FridayPackR1E1Test extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private const FRIDAY = '2026-08-21';
    private const MONDAY = '2026-08-17';

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $editor->id, 'name' => "Project {$suffix}", 'code' => strtoupper($suffix),
        ]);

        return [$org, $editor, $project];
    }

    private function makeContract(Project $project, User $editor, array $overrides = []): Contract
    {
        return Contract::create(array_merge([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'type' => 'main_contract', 'title' => 'A Contract',
        ], $overrides));
    }

    private function makeTradePackage(Project $project, array $overrides = []): TradePackage
    {
        return TradePackage::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'name' => 'A Package', 'slug' => 'pkg-' . uniqid(), 'status' => 'active',
        ], $overrides));
    }

    private function makeDeliveryDocument(Project $project, array $overrides = []): DeliveryDocument
    {
        $creatorId = $overrides['created_by'] ?? User::factory()->create(['organization_id' => $project->organization_id])->id;

        return DeliveryDocument::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id,
            'title' => 'A Document', 'category' => 'other', 'status' => 'required',
            'created_by' => $creatorId,
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    // ── RAMS ─────────────────────────────────────────────────────────────

    public function test_rams_category_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r1');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'title' => 'RAMS Doc']);
        $pack = $this->generatePack($project, $editor);

        $items = $pack->snapshot_json['sections']['rams']['items'];
        $this->assertCount(1, $items);
        $this->assertSame('RAMS Doc', $items[0]['title']);
    }

    public function test_other_categories_excluded_from_rams(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r2');
        $this->makeDeliveryDocument($project, ['category' => 'method_statement']);
        $this->makeDeliveryDocument($project, ['category' => 'permit']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['rams']['items']);
    }

    public function test_project_contract_rams_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r3');
        $contract = $this->makeContract($project, $editor);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'contract_id' => $contract->id]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['rams']['items']);
    }

    public function test_project_trade_package_rams_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r4');
        $package = $this->makeTradePackage($project);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'trade_package_id' => $package->id]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['rams']['items']);
    }

    public function test_another_projects_rams_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r5');
        [, , $otherProject] = $this->makeOrgProjectAndEditor('r5other');
        $this->makeDeliveryDocument($otherProject, ['category' => 'rams', 'title' => 'Not mine']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['rams']['items']);
    }

    public function test_current_status_represented_factually(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r6');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'approved']);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['rams']['items'][0];
        $this->assertSame('approved', $item['status']);
        $this->assertTrue($item['current']);
    }

    public function test_expired_and_superseded_never_current(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r7');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'expired', 'title' => 'Expired']);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'superseded', 'title' => 'Superseded']);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'approved', 'expiry_date' => '2026-08-01', 'title' => 'Past expiry']);
        $pack = $this->generatePack($project, $editor);

        foreach ($pack->snapshot_json['sections']['rams']['items'] as $item) {
            $this->assertFalse($item['current'], "{$item['title']} should not be current");
        }
    }

    public function test_submitted_this_week_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r8');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'title' => 'This week', 'submitted_at' => self::MONDAY . ' 09:00:00']);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'submitted_at' => '2026-08-01 09:00:00', 'title' => 'Old submission']);
        $pack = $this->generatePack($project, $editor);

        $flags = collect($pack->snapshot_json['sections']['rams']['items'])->pluck('submitted_this_week', 'title');
        $this->assertTrue($flags['This week']);
        $this->assertFalse($flags['Old submission']);
    }

    public function test_approved_this_week_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r9');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'approved', 'approved_at' => self::FRIDAY . ' 17:00:00']);
        $pack = $this->generatePack($project, $editor);

        $this->assertTrue($pack->snapshot_json['sections']['rams']['items'][0]['approved_this_week']);
    }

    public function test_no_fabricated_revised_this_week_field(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r10');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'revision' => 'Rev B']);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['rams']['items'][0];
        $this->assertArrayNotHasKey('revised_this_week', $item);
    }

    public function test_empty_rams_does_not_produce_all_compliant_claim(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r11');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['rams'];
        $this->assertSame([], $section['items']);
        $this->assertSame(0, $section['source_count']);
        $this->assertStringNotContainsString('compliant', json_encode($section));
    }

    public function test_rams_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r12');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame([], $pack->snapshot_json['sections']['rams']['items']);

        $this->makeDeliveryDocument($project, ['category' => 'rams', 'title' => 'Added after generation']);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['rams']['items']);
    }

    public function test_draft_regeneration_refreshes_rams(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r13');
        $pack = $this->generatePack($project, $editor);
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'title' => 'Added later']);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['rams']['items']);
    }

    public function test_approved_sent_rams_snapshot_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r14');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'title' => 'Frozen doc']);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    // ── Permits ──────────────────────────────────────────────────────────

    public function test_permit_category_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p1');
        $this->makeDeliveryDocument($project, ['category' => 'permit', 'title' => 'Hot Works Permit']);
        $pack = $this->generatePack($project, $editor);

        $permits = $pack->snapshot_json['sections']['permits_inspections']['permits'];
        $this->assertCount(1, $permits);
        $this->assertSame('Hot Works Permit', $permits[0]['title']);
    }

    public function test_unrelated_categories_excluded_from_permits(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2');
        $this->makeDeliveryDocument($project, ['category' => 'rams']);
        $this->makeDeliveryDocument($project, ['category' => 'coshh']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['permits']);
    }

    public function test_permit_project_ownership_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p3');
        [, , $otherProject] = $this->makeOrgProjectAndEditor('p3other');
        $this->makeDeliveryDocument($otherProject, ['category' => 'permit', 'title' => 'Not mine']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['permits']);
    }

    public function test_permit_lifecycle_represented_factually(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p4');
        $this->makeDeliveryDocument($project, ['category' => 'permit', 'status' => 'under_review']);
        $pack = $this->generatePack($project, $editor);

        $permit = $pack->snapshot_json['sections']['permits_inspections']['permits'][0];
        $this->assertSame('under_review', $permit['status']);
        $this->assertFalse($permit['current']);
    }

    public function test_permit_expiry_represented_without_validity_overclaim(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p5');
        $this->makeDeliveryDocument($project, ['category' => 'permit', 'status' => 'approved', 'expiry_date' => '2026-09-04']);
        $pack = $this->generatePack($project, $editor);

        $permit = $pack->snapshot_json['sections']['permits_inspections']['permits'][0];
        $this->assertSame('2026-09-04', $permit['expiry_date']);
        $this->assertArrayNotHasKey('valid', $permit);
    }

    public function test_empty_permits_remain_missing_not_none_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p6');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['permits_inspections'];
        $this->assertSame([], $section['permits']);
        $this->assertSame(0, $section['permit_source_count']);
        $this->assertStringNotContainsString('none required', strtolower(json_encode($section)));
    }

    public function test_inspections_remains_empty(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p7');
        $this->makeDeliveryDocument($project, ['category' => 'permit']);
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['permits_inspections'];
        $this->assertSame([], $section['inspections']);
        $this->assertSame(0, $section['inspection_source_count']);
    }

    public function test_temporary_works_does_not_become_an_inspection(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p8');
        $this->makeDeliveryDocument($project, ['category' => 'temporary_works', 'title' => 'Temp Works Design']);
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['permits_inspections'];
        $this->assertSame([], $section['inspections']);
        $this->assertSame([], $section['permits']);
    }

    public function test_permits_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p9');
        $pack = $this->generatePack($project, $editor);

        $this->makeDeliveryDocument($project, ['category' => 'permit', 'title' => 'Added after generation']);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['permits_inspections']['permits']);
    }

    public function test_draft_regeneration_refreshes_permits(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p10');
        $pack = $this->generatePack($project, $editor);
        $this->makeDeliveryDocument($project, ['category' => 'permit', 'title' => 'Added later']);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['permits_inspections']['permits']);
    }

    public function test_tenant_isolation_on_permits_and_rams(): void
    {
        [, $editorA, $projectA] = $this->makeOrgProjectAndEditor('p11a');
        [, , $projectB] = $this->makeOrgProjectAndEditor('p11b');
        $this->makeDeliveryDocument($projectB, ['category' => 'rams']);
        $this->makeDeliveryDocument($projectB, ['category' => 'permit']);
        $pack = $this->generatePack($projectA, $editorA);

        $this->assertSame([], $pack->snapshot_json['sections']['rams']['items']);
        $this->assertSame([], $pack->snapshot_json['sections']['permits_inspections']['permits']);
    }

    // ── General ──────────────────────────────────────────────────────────

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g1');
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    public function test_scheduler_captures_existing_compliance_sources_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g2');
        $this->makeDeliveryDocument($project, ['category' => 'rams', 'status' => 'approved']);
        $this->makeDeliveryDocument($project, ['category' => 'permit', 'status' => 'approved']);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $this->assertCount(1, $pack->snapshot_json['sections']['rams']['items']);
        $this->assertCount(1, $pack->snapshot_json['sections']['permits_inspections']['permits']);
    }

    /**
     * R1G.1-ACT (2026-09-03) — SCHEMA_TWO_LIVE is now `true`; a schema-2
     * pack's PDF now succeeds via the real route. Updated in place (was
     * "test_schema_2_pdf_still_blocked") — see project-context.md's
     * R1G.1-ACT entry.
     */
    public function test_schema_2_pdf_now_succeeds(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('g3');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }
}
