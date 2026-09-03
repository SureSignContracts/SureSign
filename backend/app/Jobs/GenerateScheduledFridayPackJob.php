<?php

namespace App\Jobs;

use App\Models\FridayPack;
use App\Models\FridayPackGenerationRun;
use App\Models\Project;
use App\Services\FeatureAvailability\FeatureAvailabilityService;
use App\Services\FridayPack\FridayPackGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automated Friday Pack, V1E — dispatched once per due project+week by
 * `suresign:generate-friday-packs`. Mirrors SendDeadlineReminders'
 * exact durable-checkpoint + short-lock-as-optimization-only pattern.
 *
 * No DomPDF, no email, no lifecycle transition (submitForReview/
 * markReviewed/approve) — this job's only job is: create a Draft if
 * genuinely missing, or record that one already existed. Never
 * regenerates. One project's failure never stops another (the command
 * dispatches one job per project; a thrown exception here only affects
 * this job's own retry, never sibling jobs already queued).
 */
class GenerateScheduledFridayPackJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $timeout = 120;

    public function __construct(
        public readonly int $projectId,
        public readonly string $weekEndingDate,
        public readonly string $timezone,
        public readonly int $generationHourLocal,
    ) {
    }

    public function handle(FridayPackGenerationService $service, FeatureAvailabilityService $featureAvailability): void
    {
        $project = Project::find($this->projectId);
        if (!$project) {
            // Project was deleted between dispatch and execution — nothing
            // to do, and no run row to create (there is no project/org to
            // attach it to).
            return;
        }

        // Re-checked at execution time, not only at dispatch time — a
        // maintenance window could begin between the command tick and this
        // job actually running. Deliberately does NOT create/complete a
        // run row here — a later tick, once availability is restored, must
        // still be able to generate for this same project/week (V1E spec).
        if (!$featureAvailability->isActive('project.friday_packs')) {
            Log::info('friday-pack-scheduler: feature unavailable at execution time, skipping', [
                'project_id' => $this->projectId,
            ]);
            return;
        }

        // Short lock as an efficiency optimization only — mirrors
        // SendDeadlineReminders' identical reasoning. The REAL correctness
        // boundary is the unique constraints on friday_pack_generation_runs
        // and friday_packs themselves, not this lock.
        $lock = Cache::lock("friday-pack-scheduled:{$this->projectId}:{$this->weekEndingDate}", 30);
        if (!$lock->get()) {
            Log::info('friday-pack-scheduler: lock contention, deferring to a later attempt', [
                'project_id' => $this->projectId,
            ]);
            return;
        }

        $run = null;

        try {
            $run = FridayPackGenerationRun::where('project_id', $this->projectId)
                ->whereDate('week_ending', $this->weekEndingDate)
                ->first();

            if (!$run) {
                try {
                    $run = FridayPackGenerationRun::create([
                        'project_id'            => $project->id,
                        'organization_id'       => $project->organization_id,
                        'week_ending'           => $this->weekEndingDate,
                        'timezone'              => $this->timezone,
                        'generation_hour_local' => $this->generationHourLocal,
                        'started_at'            => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    // The lock above already makes this exclusive in the
                    // normal case; this is defense-in-depth for a lock TTL
                    // exceeded by a very slow prior attempt — the unique
                    // constraint is the actual guarantee.
                    $run = FridayPackGenerationRun::where('project_id', $this->projectId)
                        ->whereDate('week_ending', $this->weekEndingDate)
                        ->firstOrFail();
                }
            }

            if ($run->isComplete()) {
                return;
            }

            // Existing-pack skip — CREATE-ONLY, never regenerate. This
            // check exists at the job level (in addition to the service's
            // own identical check) so a pack created by a manual user
            // between this job's dispatch and execution is detected and
            // linked without ever calling the create path at all.
            $existingPack = FridayPack::where('project_id', $this->projectId)
                ->whereDate('week_ending', $this->weekEndingDate)
                ->first();

            if ($existingPack) {
                $run->update(['friday_pack_id' => $existingPack->id, 'completed_at' => now()]);
                Log::info('friday-pack-scheduler: pack already existed, skipped generation', [
                    'project_id' => $this->projectId, 'friday_pack_id' => $existingPack->id,
                ]);
                return;
            }

            $pack = $service->generateScheduledIfMissing($project, $this->weekEndingDate);

            // null means a concurrent attempt won the race after our own
            // check above — re-fetch whatever now exists rather than
            // treating this as a failure.
            $pack ??= FridayPack::where('project_id', $this->projectId)
                ->whereDate('week_ending', $this->weekEndingDate)
                ->first();

            $run->update(['friday_pack_id' => $pack?->id, 'completed_at' => now()]);

            Log::info('friday-pack-scheduler: draft generated', [
                'project_id' => $this->projectId, 'friday_pack_id' => $pack?->id,
            ]);
        } catch (\Throwable $e) {
            // Never include snapshot_json/commentary/exception trace —
            // Str::limit() caps this to a short, safe operational message.
            if ($run) {
                $run->update([
                    'failed_at'       => now(),
                    'failure_reason'  => Str::limit($e->getMessage(), 250),
                ]);
            }
            Log::error('friday-pack-scheduler: generation failed', [
                'project_id' => $this->projectId, 'error' => $e->getMessage(),
            ]);

            // Rethrown so the queue's own tries/backoff can retry — a
            // failed run row (failed_at set, completed_at still null)
            // remains resumable on the next attempt, never permanently
            // skipped merely because a row exists.
            throw $e;
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateScheduledFridayPackJob permanently failed for project #{$this->projectId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
