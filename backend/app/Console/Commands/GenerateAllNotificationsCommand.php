<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Queue notification generation jobs for every active project.
 *
 * Usage:
 *   php artisan notifications:generate-all
 *   php artisan notifications:generate-all --project=42
 *
 * This command does NOT generate notifications inline.
 * It simply dispatches one GenerateProjectNotificationsJob per project.
 *
 * Intended for:
 *   - Manual on-demand refresh
 *   - Future cron scheduling (register in app/Console/Kernel.php when ready)
 */
class GenerateAllNotificationsCommand extends Command
{
    protected $signature = 'notifications:generate-all
                            {--project= : Only process a specific project ID}
                            {--dry-run  : List projects that would be queued without dispatching}';

    protected $description = 'Queue notification generation for all active projects';

    public function handle(): int
    {
        $isDryRun   = $this->option('dry-run');
        $projectId  = $this->option('project');

        $query = Project::query()->where('status', 'active');

        if ($projectId) {
            $query->where('id', (int) $projectId);
        }

        $projects = $query->select(['id', 'name'])->get();

        if ($projects->isEmpty()) {
            $this->warn('No active projects found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d active project%s.',
            $isDryRun ? 'Would queue' : 'Queuing',
            $projects->count(),
            $projects->count() !== 1 ? 's' : ''
        ));

        foreach ($projects as $project) {
            if ($isDryRun) {
                $this->line("  [dry-run] #{$project->id} — {$project->name}");
                continue;
            }

            GenerateProjectNotificationsJob::dispatch($project->id);
            $this->line("  Queued #{$project->id} — {$project->name}");
        }

        if (!$isDryRun) {
            $this->newLine();
            $this->info('All jobs dispatched. Run `php artisan queue:work` if not already running.');
        }

        return self::SUCCESS;
    }
}
