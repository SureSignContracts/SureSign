<?php

namespace App\Console\Commands;

use App\Jobs\GenerateScheduledFridayPackJob;
use App\Models\FridayPackSettings;
use App\Services\FeatureAvailability\FeatureAvailabilityService;
use App\Services\TimezoneResolver;
use Illuminate\Console\Command;

/**
 * Automated Friday Pack, V1E — mirrors SendDeadlineReminders' exact
 * architecture: the Laravel scheduler invokes this hourly, in UTC (see
 * routes/console.php); this command stays a lightweight DISPATCHER —
 * it only decides WHICH project/week is due and dispatches one queued
 * job per due project, never generating anything itself. All actual
 * work (including the durable per-project/week checkpoint) lives in
 * GenerateScheduledFridayPackJob.
 *
 * V1E is Friday-only by design (no configurable weekday) — see
 * FridayPackSettings' own docblock.
 */
class GenerateFridayPacks extends Command
{
    protected $signature   = 'suresign:generate-friday-packs';
    protected $description = 'Dispatch scheduled Friday Pack Draft generation for projects that have opted in and are currently due';

    public function handle(FeatureAvailabilityService $featureAvailability): int
    {
        $eligible = 0;
        $due      = 0;
        $dispatched = 0;
        $skippedNotDue = 0;
        $skippedUnavailable = 0;

        // Only projects that have explicitly opted in — enabled AND
        // automatic_generation_enabled both true. Both default false at
        // the schema level, so a project never appears here without an
        // explicit opt-in (V1E production-safety requirement).
        FridayPackSettings::where('enabled', true)
            ->where('automatic_generation_enabled', true)
            ->with('project.organization')
            ->chunkById(50, function ($settingsRows) use ($featureAvailability, &$eligible, &$due, &$dispatched, &$skippedNotDue, &$skippedUnavailable) {
                foreach ($settingsRows as $settings) {
                    $eligible++;
                    $project = $settings->project;

                    if (!$project || !$project->organization) {
                        continue; // orphaned settings row — nothing to schedule against
                    }

                    // Command-level pre-filter (efficiency only — the job
                    // itself re-checks at execution time, which is the
                    // authoritative check for a background process).
                    if (!$featureAvailability->isActive('project.friday_packs')) {
                        $skippedUnavailable++;
                        continue;
                    }

                    $timezone = TimezoneResolver::effectiveTimezone(null, $project->organization);
                    $localNow = TimezoneResolver::now(null, $project->organization);

                    // Friday-only, no Saturday catch-up (V1E product
                    // decision — generating after the reporting Friday has
                    // ended would snapshot state later than the actual
                    // week-ending day). `>=` not `===` on the hour, exactly
                    // like SendDeadlineReminders — a delayed tick later the
                    // same Friday still generates.
                    if ($localNow->dayOfWeekIso !== \Carbon\Carbon::FRIDAY) {
                        $skippedNotDue++;
                        continue;
                    }

                    if ($localNow->hour < $settings->generation_hour_local) {
                        $skippedNotDue++;
                        continue;
                    }

                    $due++;
                    $weekEnding = $localNow->toDateString(); // today IS the Friday

                    GenerateScheduledFridayPackJob::dispatch(
                        $project->id,
                        $weekEnding,
                        $timezone,
                        $settings->generation_hour_local,
                    );
                    $dispatched++;
                }
            });

        $this->info(
            "Eligible: {$eligible}, due: {$due}, dispatched: {$dispatched}, "
            . "skipped (not due): {$skippedNotDue}, skipped (feature unavailable): {$skippedUnavailable}."
        );

        return self::SUCCESS;
    }
}
