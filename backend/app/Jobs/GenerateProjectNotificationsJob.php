<?php

namespace App\Jobs;

use App\Services\NotificationEngineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that runs the NotificationEngineService for a single project.
 *
 * Dispatch from:
 *   - AiController::confirmAnalysis() after a contract is confirmed
 *   - CalendarSyncService after a full sync
 *   - A scheduled command (future: daily digest trigger)
 *
 * Example dispatch:
 *   GenerateProjectNotificationsJob::dispatch($project->id);
 */
class GenerateProjectNotificationsJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(public readonly int $projectId) {}

    public function handle(NotificationEngineService $engine): void
    {
        $stats = $engine->generateForProject($this->projectId);

        Log::info("GenerateProjectNotificationsJob: project {$this->projectId}", $stats);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateProjectNotificationsJob failed for project {$this->projectId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
