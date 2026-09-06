<?php

namespace Tests\Feature;

use App\Models\FeatureAvailability;
use App\Models\FridayPack;
use App\Models\FridayPackSettings;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\ToolboxTalk;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use App\Support\FridayPack\FridayPackImmutableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GivesFridayPackEntitlement;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack V1B — mirrors ToolboxTalkTest's exact conventions.
 * See FridayPackSnapshotService/FridayPackGenerationService/
 * FridayPackIntegrityGuard docblocks for the architectural decisions this
 * proves. Organization is created with timezone => 'Europe/London' so
 * FridayPackPeriodResolver's Friday check has a real, deterministic
 * timezone to resolve (matches the V1B "organisation timezone" decision).
 */
class FridayPackTest extends TestCase
{
    use RefreshDatabase;
    use GivesFridayPackEntitlement;

    private function makeOrgProjectAndEditor(string $suffix, string $role = 'Client'): array
    {
        $org = Organization::create(['name' => "Org {$suffix}", 'slug' => "org-{$suffix}", 'timezone' => 'Europe/London']);
        $this->giveFridayPackEntitlement($org);
        $editor = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $editor->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $editor->id,
            'name'            => "Project {$suffix}",
        ]);

        return [$org, $editor, $project];
    }

    /** A known real Friday used throughout — 21 August 2026. */
    private const FRIDAY = '2026-08-21';
    // R1A — Friday Pack Realignment: the reporting period is now Monday
    // through Friday (was Saturday through Friday under V1B) — see
    // FridayPackPeriodResolver's own docblock.
    private const MONDAY_BEFORE = '2026-08-17';

    // ── CRUD / list ───────────────────────────────────────────────────────

    public function test_generate_creates_a_draft_friday_pack(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c1');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('week_ending', self::FRIDAY . 'T00:00:00.000000Z')
            ->assertJsonPath('generated_by.id', $editor->id);
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());

        $pack = FridayPack::first();
        $this->assertSame(self::MONDAY_BEFORE, $pack->period_start->toDateString());
        $this->assertSame(self::FRIDAY, $pack->period_end->toDateString());
    }

    public function test_non_friday_week_ending_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('c2');

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => '2026-08-20'])
            ->assertStatus(422);
        $this->assertSame(0, FridayPack::count());
    }

    public function test_list_and_show(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('l1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])->assertStatus(201);
        $pack = FridayPack::first();

        $this->getJson("/api/projects/{$project->id}/friday-packs")->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(200)->assertJsonPath('id', $pack->id);
    }

    // ── Idempotency (critical proof) ─────────────────────────────────────

    public function test_unique_constraint_prevents_two_rows_for_same_project_and_week(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('idem1');
        Sanctum::actingAs($editor);

        $first = $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY]);
        $second = $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY]);

        $first->assertStatus(201);
        $second->assertStatus(201); // regenerates the same draft, never a second row
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->whereDate('week_ending', self::FRIDAY)->count());
        $this->assertSame(
            $first->json('id'),
            $second->json('id'),
            'A second generate call for the same project+week must return the SAME row, not a new one.'
        );
    }

    public function test_database_unique_constraint_is_the_true_authority_not_application_logic_alone(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('idem2');
        $existing = FridayPack::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'week_ending' => self::FRIDAY, 'period_start' => self::MONDAY_BEFORE, 'period_end' => self::FRIDAY,
            'status' => 'draft', 'snapshot_json' => ['schema_version' => 1], 'settings_snapshot_json' => [],
            'generated_at' => now(), 'generated_by' => $editor->id,
        ]);

        // A raw duplicate insert attempt (bypassing all application logic
        // entirely) must fail at the database layer — proves the
        // UNIQUE(project_id, week_ending) constraint itself is what
        // prevents duplicates, not merely the service's own pre-check.
        $this->expectException(\Illuminate\Database\QueryException::class);
        FridayPack::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'week_ending' => self::FRIDAY, 'period_start' => self::MONDAY_BEFORE, 'period_end' => self::FRIDAY,
            'status' => 'draft', 'snapshot_json' => ['schema_version' => 1], 'settings_snapshot_json' => [],
            'generated_at' => now(), 'generated_by' => $editor->id,
        ]);
    }

    // ── Snapshot freeze (critical proof) ─────────────────────────────────

    public function test_snapshot_freezes_and_only_updates_on_explicit_regeneration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('freeze1');
        ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'Original Talk', 'talk_date' => self::FRIDAY, 'attendee_count' => 5,
        ]);

        Sanctum::actingAs($editor);
        $service = app(FridayPackGenerationService::class);
        $pack = $service->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['toolbox_talks']['items']);
        $this->assertSame('Original Talk', $pack->snapshot_json['sections']['toolbox_talks']['items'][0]['title']);

        // Mutate the SOURCE data after generation.
        ToolboxTalk::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'title' => 'New Talk Added After Generation', 'talk_date' => self::FRIDAY, 'attendee_count' => 3,
        ]);

        // The existing, already-generated pack must still show the OLD data.
        $fetched = $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(200);
        $sections = $fetched->json('snapshot_json.sections');
        $this->assertCount(1, $sections['toolbox_talks']['items'], 'An already-generated Friday Pack must not silently reflect new source data.');

        // Regenerate — NOW the snapshot must update to reflect live data.
        $regenerated = $service->generate($project, self::FRIDAY, $editor);
        $this->assertCount(2, $regenerated->snapshot_json['sections']['toolbox_talks']['items'], 'Regenerating a draft must refresh the snapshot to current live data.');
        $this->assertSame($pack->id, $regenerated->id, 'Regeneration must update the SAME row, never create a new one.');
    }

    public function test_regeneration_preserves_manual_commentary_unless_explicitly_edited(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('freeze2');
        Sanctum::actingAs($editor);
        $service = app(FridayPackGenerationService::class);
        $pack = $service->generate($project, self::FRIDAY, $editor);
        $pack->update(['executive_summary' => 'A carefully written summary.']);

        $regenerated = $service->generate($project, self::FRIDAY, $editor);

        $this->assertSame('A carefully written summary.', $regenerated->executive_summary);
    }

    // ── Immutability once approved/sent ──────────────────────────────────

    public function test_snapshot_becomes_immutable_once_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('immut1');
        Sanctum::actingAs($editor);
        $service = app(FridayPackGenerationService::class);
        $pack = $service->generate($project, self::FRIDAY, $editor);
        $pack->update(['status' => 'approved']);

        $this->expectException(FridayPackImmutableException::class);
        $pack->update(['executive_summary' => 'Trying to change after approval']);
    }

    public function test_regeneration_is_prohibited_once_approved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('immut2');
        Sanctum::actingAs($editor);
        $service = app(FridayPackGenerationService::class);
        $pack = $service->generate($project, self::FRIDAY, $editor);
        $pack->update(['status' => 'approved']);

        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])
            ->assertStatus(409);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/regenerate")
            ->assertStatus(409);
    }

    // ── Delete behavior ───────────────────────────────────────────────────

    public function test_draft_pack_can_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del1');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(204);
        $this->assertNull(FridayPack::find($pack->id));
    }

    public function test_approved_pack_cannot_be_deleted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('del2');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $pack->update(['status' => 'approved']);

        $this->deleteJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(409);
        $this->assertNotNull(FridayPack::find($pack->id));
    }

    // ── Tenant security ───────────────────────────────────────────────────

    public function test_wrong_organisation_is_rejected(): void
    {
        [, , $project] = $this->makeOrgProjectAndEditor('t1');
        $foreignOrg = Organization::create(['name' => 'Foreign t1', 'slug' => 'foreign-t1', 'timezone' => 'Europe/London']);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrg->id, 'is_active' => true]);
        $foreignUser->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        Sanctum::actingAs($foreignUser);
        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => self::FRIDAY])->assertStatus(403);
    }

    public function test_wrong_project_in_same_organisation_is_rejected(): void
    {
        [$org, $user, $projectA] = $this->makeOrgProjectAndEditor('t2');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project B t2']);
        Sanctum::actingAs($user);
        $packB = app(FridayPackGenerationService::class)->generate($projectB, self::FRIDAY, $user);

        $this->getJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}")->assertStatus(404);
        $this->putJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}", ['executive_summary' => 'x'])->assertStatus(404);
        $this->deleteJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}")->assertStatus(404);
    }

    public function test_admin_platform_access_does_not_bypass_parent_project_integrity(): void
    {
        [$org, $user, $projectA] = $this->makeOrgProjectAndEditor('t3');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project B t3']);
        Sanctum::actingAs($user);
        $packB = app(FridayPackGenerationService::class)->generate($projectB, self::FRIDAY, $user);

        $admin = User::factory()->create(['organization_id' => null, 'is_active' => true]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $this->getJson("/api/projects/{$projectB->id}/friday-packs/{$packB->id}")->assertStatus(200);
        $this->getJson("/api/projects/{$projectA->id}/friday-packs/{$packB->id}")->assertStatus(404);
    }

    public function test_spoofed_organization_id_is_ignored(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('spoof1');
        $foreignOrg = Organization::create(['name' => 'Foreign spoof1', 'slug' => 'foreign-spoof1']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", [
            'week_ending' => self::FRIDAY, 'organization_id' => $foreignOrg->id,
        ])->assertStatus(201);

        $this->assertSame($org->id, FridayPack::first()->organization_id);
    }

    public function test_spoofed_generated_by_is_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('spoof2');
        $otherUser = User::factory()->create(['organization_id' => $project->organization_id, 'is_active' => true]);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs", [
            'week_ending' => self::FRIDAY, 'generated_by' => $otherUser->id,
        ])->assertStatus(201);

        $this->assertSame($editor->id, FridayPack::first()->generated_by);
    }

    public function test_direct_snapshot_json_injection_via_update_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('spoof3');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $originalSnapshot = $pack->snapshot_json;

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", [
            'snapshot_json' => ['sections' => ['commercial' => ['certified_this_period' => 999999999]]],
        ])->assertStatus(200); // request succeeds — but the field is simply not accepted/updated

        $this->assertSame($originalSnapshot, $pack->fresh()->snapshot_json, 'update() must never accept a client-supplied snapshot_json.');
    }

    public function test_direct_status_manipulation_via_update_is_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('spoof4');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->putJson("/api/projects/{$project->id}/friday-packs/{$pack->id}", ['status' => 'approved'])
            ->assertStatus(200);

        $this->assertSame('draft', $pack->fresh()->status, 'update() must never accept a client-supplied status transition.');
    }

    // ── Feature Availability ──────────────────────────────────────────────

    public function test_maintenance_blocks_generation_but_not_reads(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fa1');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        $this->postJson("/api/projects/{$project->id}/friday-packs", ['week_ending' => '2026-08-28'])->assertStatus(503);
        $this->getJson("/api/projects/{$project->id}/friday-packs")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")->assertStatus(200);
    }

    public function test_settings_update_requires_feature_available(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fa2');
        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
            'enabled' => true, 'included_sections' => ['toolbox_talks'],
            'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
        ])->assertStatus(503);
    }

    // ── Settings ───────────────────────────────────────────────────────────

    public function test_settings_default_when_no_row_exists(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('set1');
        Sanctum::actingAs($editor);

        $response = $this->getJson("/api/projects/{$project->id}/friday-pack-settings");
        $response->assertStatus(200)->assertJsonPath('is_default', true)->assertJsonPath('enabled', true);
        $this->assertCount(count(\App\Support\FridayPack\FridayPackSections::ALL), $response->json('included_sections'));
    }

    public function test_settings_update_persists(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('set2');
        Sanctum::actingAs($editor);

        $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
            'enabled' => false, 'included_sections' => ['toolbox_talks', 'workforce'],
            'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
        ])->assertStatus(200)->assertJsonPath('enabled', false);

        $this->assertSame(1, FridayPackSettings::where('project_id', $project->id)->count());
    }

    public function test_settings_rejects_unknown_section_key(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('set3');
        Sanctum::actingAs($editor);

        $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
            'enabled' => true, 'included_sections' => ['toolbox_talks', 'not_a_real_section'],
            'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
        ])->assertStatus(422);
    }

    /**
     * R1A — the old management-report keys (programme, commercial, etc.)
     * are no longer part of the active registry at all; a NEW settings
     * write must reject them exactly like any other unrecognised key.
     */
    public function test_settings_rejects_removed_management_report_section_keys(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('set3b');
        Sanctum::actingAs($editor);

        foreach (['programme', 'risks', 'rfis', 'variations', 'commercial', 'delays_eot', 'meetings_actions', 'delivery_documents', 'drawings', 'qa_snagging', 'upcoming_actions', 'executive_summary', 'progress', 'site_reports'] as $removedKey) {
            $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
                'enabled' => true, 'included_sections' => [$removedKey],
                'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
            ])->assertStatus(422);
        }
    }

    public function test_disabled_sections_are_omitted_from_generated_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('set4');
        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/friday-pack-settings", [
            'enabled' => true, 'included_sections' => ['toolbox_talks'],
            'automatic_generation_enabled' => false, 'generation_hour_local' => 15,
        ])->assertStatus(200);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertArrayHasKey('toolbox_talks', $pack->snapshot_json['sections']);
        $this->assertArrayNotHasKey('workforce', $pack->snapshot_json['sections']);
        $this->assertSame(['enabled' => true, 'included_sections' => ['toolbox_talks']], $pack->settings_snapshot_json);
    }

    // ── Representative section proofs ─────────────────────────────────────

    /**
     * R1A — the old management-report sections (site_reports, commercial,
     * etc.) are no longer part of the active registry, so a newly
     * generated pack's snapshot never contains them, regardless of what
     * real underlying data (Site Diaries, commercial figures) exists.
     * Their collector logic in FridayPackSnapshotService is preserved,
     * unmodified, and uncalled — a future Weekly Project Report / Client
     * Weekly Report candidate, not deleted code.
     */
    public function test_removed_management_report_sections_are_absent_from_a_newly_generated_pack(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sec1');
        SiteDiary::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id, 'created_by' => $editor->id,
            'diary_date' => self::FRIDAY, 'workers_on_site' => 12, 'status' => 'submitted',
        ]);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        foreach (['site_reports', 'commercial', 'programme', 'risks', 'rfis', 'variations', 'delays_eot', 'meetings_actions', 'delivery_documents', 'drawings', 'qa_snagging', 'upcoming_actions'] as $removedKey) {
            $this->assertArrayNotHasKey($removedKey, $pack->snapshot_json['sections']);
        }
    }

    public function test_empty_but_enabled_section_is_structured_not_omitted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('sec3');

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertArrayHasKey('toolbox_talks', $pack->snapshot_json['sections']);
        $this->assertSame(0, $pack->snapshot_json['sections']['toolbox_talks']['count']);
        $this->assertSame([], $pack->snapshot_json['sections']['toolbox_talks']['items']);
    }

    // ── R1A.1: snapshot schema version boundary ─────────────────────────────

    private function makeLegacySchema1Pack(Project $project, User $editor, string $status = 'draft'): FridayPack
    {
        return FridayPack::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'week_ending' => self::FRIDAY, 'period_start' => self::MONDAY_BEFORE, 'period_end' => self::FRIDAY,
            'status' => $status,
            'snapshot_json' => ['schema_version' => 1, 'sections' => ['toolbox_talks' => ['count' => 0, 'items' => []]]],
            'settings_snapshot_json' => [],
            'generated_at' => now(), 'generated_by' => $editor->id,
        ]);
    }

    public function test_new_generation_writes_schema_version_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen1');
        Sanctum::actingAs($editor);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    public function test_existing_schema_1_row_remains_unchanged_without_explicit_regeneration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen2');
        $legacy = $this->makeLegacySchema1Pack($project, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$project->id}/friday-packs/{$legacy->id}")->assertStatus(200);
        $this->getJson("/api/projects/{$project->id}/friday-packs")->assertStatus(200);

        $this->assertSame(1, $legacy->fresh()->snapshot_json['schema_version'], 'A legacy row must never be silently rewritten to schema 2 merely by being read.');
    }

    public function test_legacy_schema_1_draft_regeneration_upgrades_to_schema_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen3');
        $legacy = $this->makeLegacySchema1Pack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$legacy->id}/regenerate")->assertStatus(200);

        $this->assertSame(2, $legacy->fresh()->snapshot_json['schema_version'], 'Explicit regeneration must upgrade a legacy Draft to schema 2.');
    }

    public function test_approved_legacy_schema_1_pack_cannot_be_upgraded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen4');
        $legacy = $this->makeLegacySchema1Pack($project, $editor);
        $legacy->update(['status' => 'approved']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$legacy->id}/regenerate")->assertStatus(409);

        $this->assertSame(1, $legacy->fresh()->snapshot_json['schema_version'], 'An approved legacy pack must remain permanently schema 1.');
    }

    public function test_sent_legacy_schema_1_pack_cannot_be_upgraded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen5');
        $legacy = $this->makeLegacySchema1Pack($project, $editor);
        $legacy->update(['status' => 'sent']);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$legacy->id}/regenerate")->assertStatus(409);

        $this->assertSame(1, $legacy->fresh()->snapshot_json['schema_version'], 'A sent legacy pack must remain permanently schema 1.');
    }

    public function test_schema_2_pack_detail_retrieval_returns_schema_2_unchanged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('v2gen6');
        Sanctum::actingAs($editor);
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->getJson("/api/projects/{$project->id}/friday-packs/{$pack->id}")
            ->assertStatus(200)
            ->assertJsonPath('snapshot_json.schema_version', 2);
    }
}
