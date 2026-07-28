<?php

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoClock;
use App\Support\Demo\DemoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Read-only health check for the demo environment. Never writes data —
 * this is purely a developer diagnostic for confirming the demo stays
 * healthy as more phases are added, per
 * internal-docs/demo-environment/index.md.
 */
class DemoStatus extends Command
{
    protected $signature = 'demo:status';

    protected $description = 'Report the health and version of the isolated SureSign demo environment (read-only)';

    public function handle(): int
    {
        $connectionName = config('demo.connection', 'demo');
        $version = config('demo.version', []);
        $warnings = [];
        DemoStorage::isolate();

        $this->line('<fg=cyan>SureSign Demo Environment Status</>');
        $this->line('');

        $this->line("Demo version:        " . ($version['version'] ?? 'unknown'));
        $this->line("Platform compat:      " . ($version['platform_version_compatibility'] ?? 'unknown'));
        $this->line("Story timeline:       " . ($version['story_timeline'] ?? 'unknown'));
        $this->line("Metadata last edited: " . ($version['last_updated'] ?? 'unknown'));
        $this->line("DB connection:        {$connectionName}");

        $daysSinceAnchor = DemoClock::daysSinceAnchor();
        $anchorNote = $daysSinceAnchor > 30
            ? "<fg=yellow>{$daysSinceAnchor} days past anchor — consider re-seeding before further live use</>"
            : "{$daysSinceAnchor} days past anchor";
        $this->line("Anchor date:          " . DemoClock::anchorDate()->toDateString() . " ({$anchorNote})");

        if (Storage::disk('local')->exists('manifest.json')) {
            $manifest = json_decode(Storage::disk('local')->get('manifest.json'), true);
            $this->line("Last frozen manifest: " . ($manifest['generated_at'] ?? 'unknown') . ' (run `php artisan demo:manifest` to check for drift)');
        } else {
            $this->line('Last frozen manifest: none yet — run `php artisan demo:manifest --write` before a screenshot capture session.');
        }

        $this->line('');

        try {
            $connection = DB::connection($connectionName);
            $database = $connection->getDatabaseName();
            $connection->getPdo();
            $this->line("Database:             {$database} (reachable)");
        } catch (Throwable $e) {
            $this->error("Database:             UNREACHABLE ({$e->getMessage()})");

            return self::FAILURE;
        }

        $orgCount = $connection->table('organizations')->count();
        $userCount = $connection->table('users')->count();
        $projectCount = $connection->table('projects')->count();

        $organization = $connection->table('organizations')->orderBy('id')->first();

        $this->line('');
        $this->line('<fg=cyan>Seeded data</>');
        $this->line("Organisations:        {$orgCount}" . ($organization ? " (primary: {$organization->name})" : ''));
        $this->line("Users:                {$userCount}");
        $this->line("Projects:             {$projectCount}");

        if ($orgCount === 0) {
            $warnings[] = 'No organisation found — run `php artisan demo:seed` (or `demo:reset` if the schema is missing).';
        } elseif ($orgCount > 1) {
            $warnings[] = "Found {$orgCount} organisations — the demo environment expects exactly one (Halden Grove). Check for a seeding bug or leftover manual data.";
        }

        $moduleCounts = [
            'contracts' => 'contracts',
            'trade_packages' => 'trade_packages',
            'payment_applications' => 'payment_applications',
            'variations' => 'variations',
            'contract_risks' => 'contract_risks',
            'final_accounts' => 'final_accounts',
            'retention_releases' => 'retention_releases',
            'closeouts' => 'closeouts',
            'adjudication_cases' => 'adjudication_cases',
            'rfis' => 'rfis',
            'meeting_minutes' => 'meeting_minutes',
            'site_diaries' => 'site_diaries',
            'documents' => 'documents',
            'appointments' => 'appointments',
        ];

        $this->line('');
        $this->line('<fg=cyan>Module coverage (record counts)</>');
        foreach ($moduleCounts as $label => $table) {
            try {
                $count = $connection->table($table)->count();
                $this->line(sprintf('  %-22s %d', $label, $count));

                if ($count === 0 && ($version['feature_coverage'][$label] ?? false) === true) {
                    $warnings[] = "config/demo.php marks '{$label}' as covered, but 0 rows exist in '{$table}' on the demo connection.";
                }
            } catch (Throwable $e) {
                $warnings[] = "Could not count '{$table}': {$e->getMessage()}";
            }
        }

        $this->line('');
        $this->line('<fg=cyan>Per-project module coverage</>');
        $projects = $connection->table('projects')->orderBy('id')->get(['id', 'name']);
        foreach ($projects as $project) {
            $this->renderProjectCoverage($connection, $project);
        }

        $lastSeeded = $connection->table('organizations')->max('updated_at');
        $this->line('');
        $this->line('Last organisation update: ' . ($lastSeeded ?? 'never'));

        if (! empty($version['feature_coverage'])) {
            $notCovered = array_keys(array_filter($version['feature_coverage'], fn ($covered) => ! $covered));
            if ($notCovered) {
                $this->line('Not yet implemented (per config/demo.php): ' . implode(', ', $notCovered));
            }
        }

        if ($warnings) {
            $this->line('');
            $this->line('<fg=yellow>Warnings</>');
            foreach ($warnings as $warning) {
                $this->warn("  - {$warning}");
            }
        } else {
            $this->line('');
            $this->line('<fg=green>No warnings.</>');
        }

        return self::SUCCESS;
    }

    /**
     * One row per module for a single project: a tick if the project has
     * at least one record in that module's table(s), a cross otherwise.
     * This is deliberately presence-based, not depth-based — it tells a
     * developer "has this module been touched for this project at all",
     * which is exactly what's needed to spot a project that was seeded
     * halfway and never finished.
     */
    private function renderProjectCoverage($connection, object $project): void
    {
        $modules = [
            'Contracts' => fn () => $connection->table('contracts')->where('project_id', $project->id)->exists(),
            'Trade Packages' => fn () => $connection->table('trade_packages')->where('project_id', $project->id)->exists(),
            'Programme' => fn () => $connection->table('contract_programme_milestones')->where('project_id', $project->id)->exists(),
            'Risks' => fn () => $connection->table('contract_risks')->where('project_id', $project->id)->exists(),
            'Commercial' => fn () => $connection->table('payment_applications')->where('project_id', $project->id)->exists()
                || $connection->table('variations')->where('project_id', $project->id)->exists()
                || $connection->table('final_accounts')->where('project_id', $project->id)->exists(),
            'Site Management' => fn () => $connection->table('rfis')->where('project_id', $project->id)->exists()
                || $connection->table('meeting_minutes')->where('project_id', $project->id)->exists()
                || $connection->table('site_diaries')->where('project_id', $project->id)->exists(),
            'Documents' => fn () => $connection->table('documents')->where('project_id', $project->id)->exists(),
            'Appointments' => fn () => $connection->table('appointments')->where('project_id', $project->id)->exists(),
            'Notifications' => fn () => $connection->table('suresign_notifications')->where('project_id', $project->id)->exists(),
        ];

        $this->line("  <fg=white;options=bold>{$project->name}</>");
        foreach ($modules as $label => $check) {
            try {
                $covered = $check();
            } catch (Throwable $e) {
                $covered = false;
            }
            $symbol = $covered ? '<fg=green>✔</>' : '<fg=red>✖</>';
            $this->line(sprintf('    %-18s %s', $label, $symbol));
        }
    }
}
