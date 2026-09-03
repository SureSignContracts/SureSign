<?php

namespace Tests\Feature;

use App\Models\FridayPack;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Mirrors SiteInductionTest's exact conventions. See Incident model /
 * FridayPackIncidentSourceService docblocks for the architectural
 * decisions this proves — ONE ROW = ONE INDEPENDENTLY RECORDED SAFETY
 * EVENT, nullable injury tri-state, manual-only regulatory
 * reportability, and real UTC-vs-local timezone conversion (the first
 * Friday Pack source in this initiative that needs it).
 */
class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    // Organisation timezone used throughout: Europe/London — BST (UTC+1)
    // in effect for this week (August).
    private const MONDAY_UTC_NOON = '2026-08-17 12:00:00';

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

    private function makeIncident(Project $project, User $editor, array $overrides = []): Incident
    {
        return Incident::create(array_merge([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'created_by' => $editor->id,
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'A near miss',
        ], $overrides));
    }

    private function generatePack(Project $project, User $editor, string $friday = self::FRIDAY): FridayPack
    {
        return app(FridayPackGenerationService::class)->generate($project, $friday, $editor);
    }

    // ── Domain ───────────────────────────────────────────────────────────

    public function test_accident_type_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'accident', 'title' => 'Slip', 'description' => 'Slipped on wet floor.',
        ])->assertCreated();
    }

    public function test_incident_type_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d2');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'incident', 'title' => 'Dropped tool', 'description' => 'A tool was dropped.',
        ])->assertCreated();
    }

    public function test_near_miss_type_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d3');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'Near miss at entrance', 'description' => 'A vehicle near miss.',
        ])->assertCreated();
    }

    public function test_invalid_type_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d4');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'catastrophe', 'title' => 'X', 'description' => 'Y',
        ])->assertStatus(422);
    }

    public function test_occurred_at_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d5');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'type' => 'near_miss', 'title' => 'X', 'description' => 'Y',
        ])->assertStatus(422);
    }

    public function test_title_required(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d6');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'description' => 'Y',
        ])->assertStatus(422);
    }

    public function test_location_optional(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d7');
        $incident = $this->makeIncident($project, $editor);
        $this->assertNull($incident->location);
    }

    public function test_soft_delete_behavior(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d8');
        $incident = $this->makeIncident($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/incidents/{$incident->id}")->assertNoContent();

        $this->assertSoftDeleted('incidents', ['id' => $incident->id]);
        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
    }

    public function test_description_not_required_in_friday_pack_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('d9');
        $this->makeIncident($project, $editor, ['description' => 'Extremely sensitive narrative text']);
        $pack = $this->generatePack($project, $editor);

        $json = json_encode($pack->snapshot_json['sections']['incidents']);
        $this->assertArrayNotHasKey('description', $pack->snapshot_json['sections']['incidents']['items'][0]);
        $this->assertStringNotContainsString('Extremely sensitive narrative text', $json);
    }

    // ── Injury tri-state ─────────────────────────────────────────────────

    public function test_injury_null_preserved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('i1');
        $incident = $this->makeIncident($project, $editor, ['injury_occurred' => null]);
        $this->assertNull($incident->fresh()->injury_occurred);
    }

    public function test_injury_false_preserved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('i2');
        $incident = $this->makeIncident($project, $editor, ['injury_occurred' => false]);
        $this->assertFalse($incident->fresh()->injury_occurred);
    }

    public function test_injury_true_preserved(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('i3');
        $incident = $this->makeIncident($project, $editor, ['injury_occurred' => true]);
        $this->assertTrue($incident->fresh()->injury_occurred);
    }

    public function test_injury_null_does_not_render_as_no_injury_in_snapshot(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('i4');
        $this->makeIncident($project, $editor, ['injury_occurred' => null]);
        $pack = $this->generatePack($project, $editor);

        $item = $pack->snapshot_json['sections']['incidents']['items'][0];
        $this->assertNull($item['injury_occurred']);
        $this->assertNotSame(false, $item['injury_occurred']);
    }

    public function test_no_false_db_default(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('i5');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'X', 'description' => 'Y',
        ]);

        // injury_occurred omitted entirely — must remain null, never
        // silently default to false at the DB or application layer.
        $this->assertNull($response->json('injury_occurred'));
    }

    // ── Regulatory reportability ─────────────────────────────────────────

    public function test_reportability_unknown_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r1');
        $incident = $this->makeIncident($project, $editor, ['regulatory_reportability' => 'unknown']);
        $this->assertSame('unknown', $incident->regulatory_reportability);
    }

    public function test_reportability_not_reportable_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r2');
        $incident = $this->makeIncident($project, $editor, ['regulatory_reportability' => 'not_reportable']);
        $this->assertSame('not_reportable', $incident->regulatory_reportability);
    }

    public function test_reportability_reportable_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r3');
        $incident = $this->makeIncident($project, $editor, ['regulatory_reportability' => 'reportable']);
        $this->assertSame('reportable', $incident->regulatory_reportability);
    }

    public function test_invalid_reportability_state_rejected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r4');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'X', 'description' => 'Y',
            'regulatory_reportability' => 'definitely_reportable_trust_me',
        ])->assertStatus(422);
    }

    public function test_no_automatic_legal_classification(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r5');
        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'accident', 'title' => 'Fall from height', 'description' => 'Y',
            'injury_occurred' => true,
        ]);

        // Even a severe-sounding accident with a confirmed injury is never
        // auto-classified — defaults to 'unknown', never 'reportable'.
        $this->assertSame('unknown', $response->json('regulatory_reportability'));
    }

    public function test_reportability_separate_from_status(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('r6');
        $incident = $this->makeIncident($project, $editor, [
            'type' => 'accident', 'injury_occurred' => true, 'regulatory_reportability' => 'reportable', 'status' => 'open',
        ]);

        $this->assertSame('accident', $incident->type);
        $this->assertTrue($incident->injury_occurred);
        $this->assertSame('reportable', $incident->regulatory_reportability);
        $this->assertSame('open', $incident->status);
    }

    // ── Status ───────────────────────────────────────────────────────────

    public function test_status_defaults_open(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s1');
        $incident = $this->makeIncident($project, $editor);
        $this->assertSame('open', $incident->fresh()->status);
    }

    public function test_status_closed_accepted(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s2');
        $incident = $this->makeIncident($project, $editor, ['status' => 'closed']);
        $this->assertSame('closed', $incident->status);
    }

    public function test_incident_can_be_reopened(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s3');
        $incident = $this->makeIncident($project, $editor, ['status' => 'closed']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/incidents/{$incident->id}", ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('status', 'open');
    }

    public function test_status_change_activity_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('s4');
        $incident = $this->makeIncident($project, $editor, ['status' => 'open']);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/incidents/{$incident->id}", ['status' => 'closed'])->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'incident_status_changed']);
    }

    // ── Privacy ──────────────────────────────────────────────────────────

    public function test_no_injured_person_field_exists(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p1');
        $incident = $this->makeIncident($project, $editor);

        $this->assertArrayNotHasKey('injured_person_id', $incident->toArray());
        $this->assertArrayNotHasKey('injured_person_name', $incident->toArray());
        $this->assertArrayNotHasKey('worker_id', $incident->toArray());
    }

    public function test_no_medical_data_fields_exist(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p2');
        $incident = $this->makeIncident($project, $editor);

        $this->assertArrayNotHasKey('medical_diagnosis', $incident->toArray());
        $this->assertArrayNotHasKey('treatment_details', $incident->toArray());
        $this->assertArrayNotHasKey('date_of_birth', $incident->toArray());
    }

    public function test_no_attachment_routes_implemented(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('p3');
        $incident = $this->makeIncident($project, $editor);

        Sanctum::actingAs($editor);
        $response = $this->getJson("/api/projects/{$project->id}/incidents/{$incident->id}/attachments");

        $response->assertStatus(404);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_cross_org_rejected(): void
    {
        [, , $projectA] = $this->makeOrgProjectAndEditor('t1a');
        [, $editorB, $projectB] = $this->makeOrgProjectAndEditor('t1b');
        $incidentA = $this->makeIncident($projectA, $editorB);

        Sanctum::actingAs($editorB);
        $this->getJson("/api/projects/{$projectB->id}/incidents/{$incidentA->id}")->assertStatus(403);
    }

    public function test_wrong_project_parent_rejected(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t2a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $incidentA = $this->makeIncident($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->getJson("/api/projects/{$projectB->id}/incidents/{$incidentA->id}")->assertStatus(404);
    }

    public function test_injected_organization_id_ignored(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('t3');
        [$otherOrg] = $this->makeOrgProjectAndEditor('t3other');

        Sanctum::actingAs($editor);
        $response = $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'X', 'description' => 'Y',
            'organization_id' => $otherOrg->id,
        ]);

        $response->assertCreated();
        $this->assertSame($project->organization_id, $response->json('organization_id'));
    }

    public function test_incident_cannot_be_moved_to_another_project_via_update(): void
    {
        [$org, $editor, $projectA] = $this->makeOrgProjectAndEditor('t4a');
        $projectB = Project::create(['organization_id' => $org->id, 'created_by' => $editor->id, 'name' => 'Project B']);
        $incident = $this->makeIncident($projectA, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$projectA->id}/incidents/{$incident->id}", [
            'title' => 'Still here', 'project_id' => $projectB->id,
        ])->assertOk();

        $this->assertSame($projectA->id, $incident->fresh()->project_id);
    }

    // ── Timezone / period boundary ───────────────────────────────────────

    public function test_local_monday_event_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tz1');
        // UTC 2026-08-16 23:30 = local (Europe/London, BST +1) Monday 00:30.
        $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-16 23:30:00', 'title' => 'Just after local midnight Monday']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['incidents']['items']);
        $this->assertSame('2026-08-17', $pack->snapshot_json['sections']['incidents']['items'][0]['local_date']);
    }

    public function test_local_friday_late_night_event_included(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tz2');
        // UTC 2026-08-21 22:30 = local Friday 23:30 (BST +1).
        $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-21 22:30:00', 'title' => 'Late Friday night']);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['incidents']['items']);
        $this->assertSame('2026-08-21', $pack->snapshot_json['sections']['incidents']['items'][0]['local_date']);
    }

    public function test_local_saturday_event_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tz3');
        // UTC 2026-08-21 23:30 = local Saturday 00:30 (BST +1) — outside the period.
        $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-21 23:30:00', 'title' => 'Just after local midnight Saturday']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['incidents']['items']);
    }

    public function test_utc_timestamp_crossing_local_date_boundary_classified_correctly(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('tz4');
        // The raw UTC column genuinely reads 2026-08-16 (Sunday) — a naive
        // whereDate('occurred_at', ...) against it would classify this as
        // outside the Mon-Fri period and exclude it. The correct
        // local-timezone conversion (Europe/London, BST +1) classifies
        // this same instant as Monday 2026-08-17 00:15 and includes it.
        $incident = $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-16 23:15:00']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame('2026-08-16', $incident->fresh()->occurred_at->toDateString());
        $this->assertCount(1, $pack->snapshot_json['sections']['incidents']['items']);
        $this->assertSame('2026-08-17', $pack->snapshot_json['sections']['incidents']['items'][0]['local_date']);
    }

    public function test_frozen_display_survives_later_timezone_change(): void
    {
        [$org, $editor, $project] = $this->makeOrgProjectAndEditor('tz5');
        $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-16 23:30:00']);
        $pack = $this->generatePack($project, $editor);
        $originalLocalDate = $pack->snapshot_json['sections']['incidents']['items'][0]['local_date'];

        // Changing the organisation's timezone after generation must never
        // retroactively rewrite the already-frozen historical display.
        $org->update(['timezone' => 'Pacific/Auckland']);

        $this->assertSame($originalLocalDate, $pack->fresh()->snapshot_json['sections']['incidents']['items'][0]['local_date']);
    }

    // ── Friday Pack ──────────────────────────────────────────────────────

    public function test_correct_incident_records_selected(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp1');
        $this->makeIncident($project, $editor, ['occurred_at' => self::MONDAY_UTC_NOON]);
        $pack = $this->generatePack($project, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['incidents']['items']);
    }

    public function test_soft_deleted_incident_excluded(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp2');
        $incident = $this->makeIncident($project, $editor);
        $incident->delete();
        $pack = $this->generatePack($project, $editor);

        $this->assertSame([], $pack->snapshot_json['sections']['incidents']['items']);
    }

    public function test_source_count_correct(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp3');
        $this->makeIncident($project, $editor);
        $this->makeIncident($project, $editor, ['occurred_at' => '2026-08-19 09:00:00']);
        $pack = $this->generatePack($project, $editor);

        $this->assertSame(2, $pack->snapshot_json['sections']['incidents']['source_count']);
    }

    public function test_no_fabricated_no_incidents_declaration(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp4');
        $pack = $this->generatePack($project, $editor);

        $section = $pack->snapshot_json['sections']['incidents'];
        $this->assertSame([], $section['items']);
        $this->assertSame(0, $section['source_count']);
        $this->assertStringNotContainsString('no incidents', strtolower(json_encode($section)));
        $this->assertStringNotContainsString('accident-free', strtolower(json_encode($section)));
    }

    public function test_snapshot_freeze(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp5');
        $pack = $this->generatePack($project, $editor);
        $this->assertSame([], $pack->snapshot_json['sections']['incidents']['items']);

        $this->makeIncident($project, $editor);

        $this->assertSame([], $pack->fresh()->snapshot_json['sections']['incidents']['items']);
    }

    public function test_draft_regeneration_refreshes(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp6');
        $pack = $this->generatePack($project, $editor);
        $this->makeIncident($project, $editor);

        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);

        $this->assertCount(1, $pack->snapshot_json['sections']['incidents']['items']);
    }

    public function test_approved_sent_remains_immutable(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp7');
        $this->makeIncident($project, $editor);
        $pack = $this->generatePack($project, $editor);
        $pack->update(['status' => 'ready_for_review']);
        $pack->update(['status' => 'approved']);

        $this->expectException(\App\Support\FridayPack\FridayPackImmutableException::class);
        $pack->update(['snapshot_json' => array_merge($pack->snapshot_json, ['sections' => []])]);
    }

    public function test_scheduler_captures_real_events_only(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp8');
        $this->makeIncident($project, $editor, ['type' => 'accident', 'injury_occurred' => true]);

        $pack = app(FridayPackGenerationService::class)->generateScheduledIfMissing($project, self::FRIDAY);

        $item = $pack->snapshot_json['sections']['incidents']['items'][0];
        $this->assertSame('accident', $item['type']);
        $this->assertTrue($item['injury_occurred']);
        $this->assertSame('unknown', $item['regulatory_reportability']);
    }

    public function test_schema_version_remains_2(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp9');
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
        [, $editor, $project] = $this->makeOrgProjectAndEditor('fp10');
        $pack = $this->generatePack($project, $editor);

        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/friday-packs/{$pack->id}/pdf")->assertStatus(201);
    }

    // ── Activity / Feature / Nav ────────────────────────────────────────

    public function test_create_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af1');
        Sanctum::actingAs($editor);
        $this->postJson("/api/projects/{$project->id}/incidents", [
            'occurred_at' => self::MONDAY_UTC_NOON, 'type' => 'near_miss', 'title' => 'X', 'description' => 'Y',
        ])->assertCreated();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'incident_added']);
    }

    public function test_update_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af2');
        $incident = $this->makeIncident($project, $editor);

        Sanctum::actingAs($editor);
        $this->putJson("/api/projects/{$project->id}/incidents/{$incident->id}", ['title' => 'Updated title'])->assertOk();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'incident_updated']);
    }

    public function test_delete_logged(): void
    {
        [, $editor, $project] = $this->makeOrgProjectAndEditor('af3');
        $incident = $this->makeIncident($project, $editor);

        Sanctum::actingAs($editor);
        $this->deleteJson("/api/projects/{$project->id}/incidents/{$incident->id}")->assertNoContent();

        $this->assertDatabaseHas('project_activities', ['activity_type' => 'incident_deleted']);
    }

    public function test_feature_gate_works(): void
    {
        $this->assertTrue(\App\Support\FeatureAvailability\FeatureAvailabilityRegistry::isValid('project.incidents'));
    }

    public function test_health_and_safety_nav_lists_only_implemented_modules(): void
    {
        // Backend-side proxy for the nav requirement: only feature keys for
        // genuinely implemented H&S modules exist in the registry so far.
        // R1E.2C added project.hs_inspections, R1E.2D added
        // project.plant_equipment, R1E.2E added
        // project.statutory_inspections — updated here to match (the same
        // assertion is re-verified per-phase in StatutoryInspectionTest,
        // which is now the authoritative version of this check).
        $registry = \App\Support\FeatureAvailability\FeatureAvailabilityRegistry::ALL;
        $this->assertContains('project.site_inductions', $registry);
        $this->assertContains('project.incidents', $registry);
        $this->assertContains('project.hs_inspections', $registry);
        $this->assertContains('project.plant_equipment', $registry);
        $this->assertContains('project.statutory_inspections', $registry);
        $this->assertNotContains('project.health_safety', $registry);
    }
}
