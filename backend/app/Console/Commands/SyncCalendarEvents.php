<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\CalendarSyncService;
use Illuminate\Console\Command;

class SyncCalendarEvents extends Command
{
    protected $signature   = 'calendar:sync';
    protected $description = 'Sync operational intelligence into CalendarEvent for every project (contracts + trade packages)';

    public function handle(CalendarSyncService $calendarSync): int
    {
        $projects = Project::all(['id']);
        $totals   = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
        $pruned   = 0;

        foreach ($projects as $project) {
            $result = $calendarSync->syncForProject($project->id);
            foreach ($totals as $key => $_) {
                $totals[$key] += $result[$key];
            }

            // Explicit second pass, once per project — see pruneOrphanedEvents()
            // docblock for why this isn't chained into every contract/trade
            // package sync.
            $pruned += $calendarSync->pruneOrphanedEvents($project->id);
        }

        $this->info(sprintf(
            'Calendar sync complete for %d project(s): %d created, %d updated, %d skipped, %d errors, %d pruned.',
            $projects->count(), $totals['created'], $totals['updated'], $totals['skipped'], $totals['errors'], $pruned
        ));

        return $totals['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
