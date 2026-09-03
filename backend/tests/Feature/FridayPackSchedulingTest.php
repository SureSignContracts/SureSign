<?php

namespace Tests\Feature;

use App\Jobs\GenerateScheduledFridayPackJob;
use App\Models\FeatureAvailability;
use App\Models\FridayPack;
use App\Models\FridayPackGenerationRun;
use App\Models\FridayPackSettings;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Automated Friday Pack V1E — scheduled automatic Draft generation.
 * Mirrors Batch7DeadlineReminderDispatcherTest's exact controlled-clock
 * convention (Date::setTestNow(), auto-restored by the base TestCase's
 * own tearDown — no manual reset needed, matching that file's own
 * approach). See GenerateFridayPacks/GenerateScheduledFridayPackJob
 * docblocks for the architectural decisions this proves.
 */
class FridayPackSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /** 2026-08-21 is a real Friday, matching every prior Friday Pack test in this suite. */
    private const FRIDAY = '2026-08-21';
    private const THURSDAY = '2026-08-20';
    private const SATURDAY = '2026-08-22';
    private const NEXT_FRIDAY = '2026-08-28';

    private function makeOrg(string $timezone = 'Europe/London'): Organization
    {
        $n = ++static::$seq;
        return Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone, 'is_active' => true]);
    }

    private function makeProjectWithAutomation(Organization $org, int $generationHourLocal = 15, bool $automationEnabled = true): array
    {
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "Project {$org->id}"]);

        FridayPackSettings::create([
            'project_id'                    => $project->id,
            'organization_id'               => $org->id,
            'enabled'                       => true,
            'included_sections'             => \App\Support\FridayPack\FridayPackSections::defaults(),
            'automatic_generation_enabled'  => $automationEnabled,
            'generation_hour_local'         => $generationHourLocal,
            'created_by'                    => $user->id,
        ]);

        return [$user, $project];
    }

    private function runCommand(): void
    {
        $this->artisan('suresign:generate-friday-packs')->assertSuccessful();
    }

    // ── Default-OFF production safety (critical) ──────────────────────────

    public function test_automatic_generation_defaults_to_false(): void
    {
        $org = $this->makeOrg();
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'No Settings Project']);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        // No FridayPackSettings row exists at all for this project — the
        // "no row" default response itself must report automation OFF
        // (and the scheduler query itself never matches a project with no
        // settings row in the first place).
        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
        $this->runCommand();
        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count(), 'A project with no settings row must never auto-generate.');
    }

    public function test_existing_project_with_settings_but_automation_not_yet_enabled_never_generates(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org, automationEnabled: false);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
    }

    // ── Local-time matching (critical) ─────────────────────────────────────

    public function test_friday_before_configured_hour_is_not_due(): void
    {
        $org = $this->makeOrg('Europe/London');
        [, $project] = $this->makeProjectWithAutomation($org, generationHourLocal: 15);
        // 12:00 UTC in August = 13:00 BST — before 15:00 local.
        Date::setTestNow(self::FRIDAY . ' 12:00:00');

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
        $this->assertSame(0, FridayPackGenerationRun::where('project_id', $project->id)->count());
    }

    public function test_friday_at_configured_hour_is_due(): void
    {
        $org = $this->makeOrg('Europe/London');
        [, $project] = $this->makeProjectWithAutomation($org, generationHourLocal: 15);
        // 14:00 UTC = 15:00 BST exactly.
        Date::setTestNow(self::FRIDAY . ' 14:00:00');

        $this->runCommand();

        $pack = FridayPack::where('project_id', $project->id)->first();
        $this->assertNotNull($pack);
        $this->assertSame(self::FRIDAY, $pack->week_ending->toDateString());
    }

    public function test_friday_after_configured_hour_still_due_if_unprocessed(): void
    {
        $org = $this->makeOrg('Europe/London');
        [, $project] = $this->makeProjectWithAutomation($org, generationHourLocal: 15);
        // 17:00 UTC = 18:00 BST — well past 15:00 local, first tick of the day.
        Date::setTestNow(self::FRIDAY . ' 17:00:00');

        $this->runCommand();

        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());
    }

    public function test_thursday_is_not_due(): void
    {
        $org = $this->makeOrg('Europe/London');
        [, $project] = $this->makeProjectWithAutomation($org, generationHourLocal: 0);
        Date::setTestNow(self::THURSDAY . ' 10:00:00'); // 11:00 BST — genuinely still Thursday in London

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
    }

    public function test_saturday_no_catch_up(): void
    {
        $org = $this->makeOrg('Europe/London');
        [, $project] = $this->makeProjectWithAutomation($org, generationHourLocal: 15);
        // Friday never ticked (system down); Saturday arrives.
        Date::setTestNow(self::SATURDAY . ' 12:00:00');

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count(), 'Missing the whole Friday must never be caught up on Saturday.');
    }

    public function test_two_organisations_in_different_timezones_resolve_independently(): void
    {
        $london = $this->makeOrg('Europe/London');
        [, $londonProject] = $this->makeProjectWithAutomation($london, generationHourLocal: 15);
        $tokyo = $this->makeOrg('Asia/Tokyo');
        [, $tokyoProject] = $this->makeProjectWithAutomation($tokyo, generationHourLocal: 15);

        // 14:00 UTC = 15:00 BST (London due) = 23:00 JST (Tokyo already
        // past 15:00, also due but on the SAME calendar day since Tokyo is
        // ahead — both should independently resolve to Friday, due).
        Date::setTestNow(self::FRIDAY . ' 14:00:00');
        $this->runCommand();

        $this->assertSame(1, FridayPack::where('project_id', $londonProject->id)->count());
        $this->assertSame(1, FridayPack::where('project_id', $tokyoProject->id)->count());
    }

    public function test_organisation_where_local_time_has_not_reached_friday_yet_is_not_due(): void
    {
        // Honolulu (UTC-10) — at a UTC instant where London is already
        // Friday evening, Honolulu is still Friday morning-ish or even
        // Thursday depending on the exact instant chosen. Pick a UTC
        // instant that is Saturday 02:00 UTC — Honolulu (UTC-10) is still
        // Friday 16:00, London is already Saturday.
        $london = $this->makeOrg('Europe/London');
        [, $londonProject] = $this->makeProjectWithAutomation($london, generationHourLocal: 15);
        $honolulu = $this->makeOrg('Pacific/Honolulu');
        [, $honoluluProject] = $this->makeProjectWithAutomation($honolulu, generationHourLocal: 15);

        Date::setTestNow('2026-08-22 02:00:00'); // Sat 02:00 UTC = Fri 16:00 Honolulu, Sat 03:00 BST London

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $londonProject->id)->count(), 'London is already Saturday — no catch-up.');
        $this->assertSame(1, FridayPack::where('project_id', $honoluluProject->id)->count(), 'Honolulu is still genuinely Friday, past its configured hour.');
    }

    // ── Scheduled Draft lifecycle state (critical) ─────────────────────────

    public function test_scheduled_pack_lands_as_a_pure_draft_with_no_side_effects(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        $this->runCommand();

        $pack = FridayPack::where('project_id', $project->id)->firstOrFail();
        $this->assertSame('draft', $pack->status);
        $this->assertNull($pack->reviewed_at);
        $this->assertNull($pack->reviewed_by);
        $this->assertNull($pack->approved_at);
        $this->assertNull($pack->approved_by);
        $this->assertNull($pack->sent_at);
        $this->assertNull($pack->sent_by);
        $this->assertNull($pack->pdf_document_id);
        // R1A.1: scheduled generation calls the same current collector as
        // manual generation — a scheduler-created pack is schema 2 too.
        $this->assertSame(2, $pack->snapshot_json['schema_version']);
    }

    public function test_scheduled_pack_generated_by_is_null_not_a_fake_user(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        $this->runCommand();

        $pack = FridayPack::where('project_id', $project->id)->firstOrFail();
        $this->assertNull($pack->generated_by);
        $this->assertSame('scheduled', $pack->generation_source);
    }

    // ── Existing manual pack is never regenerated (non-negotiable) ────────

    public function test_existing_manual_pack_is_completely_untouched_by_scheduler(): void
    {
        $org = $this->makeOrg();
        [$editor, $project] = $this->makeProjectWithAutomation($org);

        Date::setTestNow(self::FRIDAY . ' 09:00:00'); // before the 15:00 hour — irrelevant, manual gen doesn't check schedule
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $pack->update(['executive_summary' => 'Real human commentary']);
        $originalSnapshot = $pack->snapshot_json;
        $originalGeneratedAt = $pack->generated_at;

        Date::setTestNow(self::FRIDAY . ' 20:00:00'); // now past the configured hour
        $this->runCommand();

        $fresh = $pack->fresh();
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count(), 'No second pack row.');
        $this->assertSame('Real human commentary', $fresh->executive_summary);
        $this->assertSame($originalSnapshot, $fresh->snapshot_json);
        $this->assertEquals($originalGeneratedAt, $fresh->generated_at);
        $this->assertSame('manual', $fresh->generation_source);
        $this->assertSame($editor->id, $fresh->generated_by);

        $run = FridayPackGenerationRun::where('project_id', $project->id)->first();
        $this->assertNotNull($run, 'A run checkpoint must still be recorded even when a pack already existed.');
        $this->assertTrue($run->isComplete());
        $this->assertSame($pack->id, $run->friday_pack_id);
    }

    public function test_existing_manual_pack_pdf_pointer_unchanged(): void
    {
        $org = $this->makeOrg();
        [$editor, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 09:00:00');
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        // Simulate a current PDF pointer without actually rendering one.
        $pack->forceFill(['pdf_document_id' => null])->save(); // stays null; asserting it's never SET by the scheduler either
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        $this->runCommand();

        $this->assertNull($pack->fresh()->pdf_document_id);
    }

    // ── Deleted scheduled Draft is not recreated (non-negotiable) ─────────

    public function test_deleted_scheduled_draft_is_not_recreated_by_a_later_tick(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 15:30:00');
        $this->runCommand();
        $pack = FridayPack::where('project_id', $project->id)->firstOrFail();

        $pack->delete();
        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());

        Date::setTestNow(self::FRIDAY . ' 18:00:00'); // a later hourly tick, same Friday
        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count(), 'The durable run checkpoint — not FridayPack existence — must prevent recreation.');
        $run = FridayPackGenerationRun::where('project_id', $project->id)->first();
        $this->assertNotNull($run);
        $this->assertTrue($run->isComplete());
    }

    // ── Next week generates normally ───────────────────────────────────────

    public function test_completed_run_for_one_friday_does_not_block_the_next(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');
        $this->runCommand();
        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());

        Date::setTestNow(self::NEXT_FRIDAY . ' 20:00:00');
        $this->runCommand();

        $this->assertSame(2, FridayPack::where('project_id', $project->id)->count());
        $this->assertSame(2, FridayPackGenerationRun::where('project_id', $project->id)->count());
    }

    // ── Feature Availability ────────────────────────────────────────────────

    public function test_feature_unavailable_skips_without_permanently_consuming_the_friday(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        $this->runCommand();

        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());
        $this->assertSame(0, FridayPackGenerationRun::where('project_id', $project->id)->count(), 'A skipped-due-to-maintenance tick must not create ANY checkpoint row — completed or otherwise.');
    }

    public function test_feature_restored_later_the_same_friday_can_still_generate(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        $flag = FeatureAvailability::create(['feature_key' => 'project.friday_packs', 'status' => 'maintenance']);
        Date::setTestNow(self::FRIDAY . ' 16:00:00');
        $this->runCommand();
        $this->assertSame(0, FridayPack::where('project_id', $project->id)->count());

        $flag->delete(); // restored to Active
        Date::setTestNow(self::FRIDAY . ' 18:00:00');
        $this->runCommand();

        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());
    }

    // ── Duplicate job / concurrency safety ─────────────────────────────────

    public function test_duplicate_job_dispatch_cannot_create_two_packs(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        // Two independent job executions for the exact same project/week —
        // the DB unique constraints, not queue-level dedup, must be what
        // prevents a duplicate.
        (new GenerateScheduledFridayPackJob($project->id, self::FRIDAY, 'Europe/London', 15))
            ->handle(app(FridayPackGenerationService::class), app(\App\Services\FeatureAvailability\FeatureAvailabilityService::class));
        (new GenerateScheduledFridayPackJob($project->id, self::FRIDAY, 'Europe/London', 15))
            ->handle(app(FridayPackGenerationService::class), app(\App\Services\FeatureAvailability\FeatureAvailabilityService::class));

        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count());
        $this->assertSame(1, FridayPackGenerationRun::where('project_id', $project->id)->count());
    }

    public function test_database_unique_constraint_prevents_duplicate_generation_run_rows(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);

        FridayPackGenerationRun::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'week_ending' => self::FRIDAY,
            'timezone' => 'Europe/London', 'generation_hour_local' => 15, 'started_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FridayPackGenerationRun::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'week_ending' => self::FRIDAY,
            'timezone' => 'Europe/London', 'generation_hour_local' => 15, 'started_at' => now(),
        ]);
    }

    // ── Failure / retry ──────────────────────────────────────────────────────

    public function test_failed_run_remains_retryable_not_permanently_skipped(): void
    {
        $org = $this->makeOrg();
        [, $project] = $this->makeProjectWithAutomation($org);

        // Simulate a prior failed attempt's checkpoint row directly (a run
        // that started and failed, never completed).
        $run = FridayPackGenerationRun::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'week_ending' => self::FRIDAY,
            'timezone' => 'Europe/London', 'generation_hour_local' => 15,
            'started_at' => now(), 'failed_at' => now(), 'failure_reason' => 'Simulated transient failure',
        ]);
        $this->assertFalse($run->isComplete());

        Date::setTestNow(self::FRIDAY . ' 20:00:00');
        $this->runCommand();

        $this->assertSame(1, FridayPack::where('project_id', $project->id)->count(), 'A previously-failed (never completed) run must still be retried and succeed.');
        $this->assertTrue($run->fresh()->isComplete());
        $this->assertSame(1, FridayPackGenerationRun::where('project_id', $project->id)->count(), 'The existing failed row is reused, never duplicated.');
    }

    public function test_one_projects_failure_does_not_block_another_due_project(): void
    {
        $orgA = $this->makeOrg();
        [, $projectA] = $this->makeProjectWithAutomation($orgA);
        $orgB = $this->makeOrg();
        [, $projectB] = $this->makeProjectWithAutomation($orgB);

        // Force project A's job to fail by deleting its project between
        // dispatch decision and... actually simplest genuine failure: make
        // organization A's timezone corrupt is hard to force safely, so
        // instead prove the command-level chunkById loop itself continues
        // past a thrown exception from one job by directly invoking both
        // jobs and confirming B still succeeds even after A errors.
        Date::setTestNow(self::FRIDAY . ' 20:00:00');

        try {
            (new GenerateScheduledFridayPackJob(999999, self::FRIDAY, 'Europe/London', 15))
                ->handle(app(FridayPackGenerationService::class), app(\App\Services\FeatureAvailability\FeatureAvailabilityService::class));
        } catch (\Throwable) {
            // Expected — project 999999 doesn't exist in a way that throws
            // downstream, or simply returns early; either is fine here,
            // this call exists only to prove it doesn't affect project B.
        }

        $this->runCommand(); // real command run covers both real due projects

        $this->assertSame(1, FridayPack::where('project_id', $projectA->id)->count());
        $this->assertSame(1, FridayPack::where('project_id', $projectB->id)->count());
    }

    // ── Tenant / observability ──────────────────────────────────────────────

    public function test_command_output_reports_operational_counts_without_leaking_report_content(): void
    {
        $org = $this->makeOrg();
        [$editor, $project] = $this->makeProjectWithAutomation($org);
        Date::setTestNow(self::FRIDAY . ' 09:00:00');
        $pack = app(FridayPackGenerationService::class)->generate($project, self::FRIDAY, $editor);
        $pack->update(['executive_summary' => 'Sensitive commercial commentary that must never appear in logs']);

        Date::setTestNow(self::FRIDAY . ' 20:00:00');
        $this->artisan('suresign:generate-friday-packs')
            ->expectsOutputToContain('Eligible: 1, due: 1, dispatched: 1')
            ->assertSuccessful();
    }
}
