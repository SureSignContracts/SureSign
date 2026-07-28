<?php

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoClock;
use App\Support\Demo\DemoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Generates the Demo Environment manifest — a single JSON snapshot of
 * exactly what's seeded, when, and against which anchor date. This is the
 * "Demo Freeze" mechanism: run `demo:manifest --write` immediately before
 * a screenshot/documentation/sales-recording capture session, and the
 * resulting `manifest.json` becomes the permanent record of exactly what
 * that batch of assets was captured against — organisation, every
 * project's id/code/status, per-project module coverage, table counts,
 * demo version, and anchor date.
 *
 * Without `--write`, the command only prints the manifest and — if a
 * previously-frozen manifest.json already exists — reports whether the
 * live environment has drifted from it (different version, different
 * project count, different table counts), so a developer can tell at a
 * glance whether previously-captured screenshots might now be stale.
 *
 * Never writes to the database — only ever reads it and (with --write)
 * writes a single JSON file to the isolated demo storage root.
 */
class DemoManifest extends Command
{
    protected $signature = 'demo:manifest {--write : Save this manifest as the frozen snapshot (manifest.json)}';

    protected $description = 'Generate the demo environment manifest, and optionally freeze it as the reference snapshot for a screenshot/asset capture session';

    public function handle(): int
    {
        DemoStorage::isolate();
        $connection = DB::connection(config('demo.connection', 'demo'));

        $manifest = $this->buildManifest($connection);

        $this->line('<fg=cyan>SureSign Demo Environment Manifest</>');
        $this->line('');
        $this->line("Demo version:   {$manifest['demo_version']}");
        $this->line("Anchor date:    {$manifest['anchor_date']}");
        $this->line("Generated at:   {$manifest['generated_at']}");
        $this->line("Organisation:   {$manifest['organization']['name']}");
        $this->line('');
        $this->line('<fg=cyan>Projects</>');
        foreach ($manifest['projects'] as $project) {
            $this->line(sprintf('  [%d] %-45s %-10s %s', $project['id'], $project['name'], $project['status'], $project['code']));
        }
        $this->line('');
        $this->line('<fg=cyan>Table counts</>');
        foreach ($manifest['table_counts'] as $table => $count) {
            $this->line(sprintf('  %-25s %d', $table, $count));
        }

        $manifestPath = 'manifest.json';
        $existing = Storage::disk('local')->exists($manifestPath)
            ? json_decode(Storage::disk('local')->get($manifestPath), true)
            : null;

        if ($this->option('write')) {
            Storage::disk('local')->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT));
            $this->line('');
            $this->line("<fg=green>Frozen — saved to {$manifest['storage_root']}/{$manifestPath}</>");

            return self::SUCCESS;
        }

        $this->line('');
        if (! $existing) {
            $this->line('<fg=yellow>No frozen manifest exists yet — run with --write before a screenshot capture session to create one.</>');

            return self::SUCCESS;
        }

        $this->reportDrift($existing, $manifest);

        return self::SUCCESS;
    }

    private function buildManifest($connection): array
    {
        $organization = $connection->table('organizations')->orderBy('id')->first();
        $projects = $connection->table('projects')->orderBy('id')->get(['id', 'name', 'code', 'status']);

        $tables = [
            'users', 'projects', 'contracts', 'trade_packages', 'contract_programme_milestones',
            'contract_risks', 'variations', 'payment_applications', 'payment_notices',
            'pay_less_notices', 'final_accounts', 'retention_releases', 'delay_events',
            'eot_requests', 'loss_and_expense_claims', 'adjudication_cases', 'closeouts',
            'rfis', 'site_instructions', 'site_diaries', 'meeting_minutes', 'snags',
            'qa_reports', 'documents', 'appointments', 'project_activities',
        ];

        $tableCounts = [];
        foreach ($tables as $table) {
            $tableCounts[$table] = $connection->table($table)->count();
        }

        return [
            'demo_version' => config('demo.version.version'),
            'platform_version_compatibility' => config('demo.version.platform_version_compatibility'),
            'anchor_date' => DemoClock::anchorDate()->toDateString(),
            'generated_at' => now()->toIso8601String(),
            'storage_root' => config('demo.storage_root'),
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ] : null,
            'projects' => $projects->map(fn ($project) => [
                'id' => $project->id,
                'name' => $project->name,
                'code' => $project->code,
                'status' => $project->status,
            ])->all(),
            'table_counts' => $tableCounts,
        ];
    }

    private function reportDrift(array $frozen, array $current): void
    {
        $this->line('<fg=cyan>Drift vs. last frozen manifest (' . ($frozen['generated_at'] ?? 'unknown') . ')</>');
        $driftFound = false;

        if (($frozen['demo_version'] ?? null) !== $current['demo_version']) {
            $driftFound = true;
            $this->line("  <fg=yellow>!</> Demo version changed: {$frozen['demo_version']} -> {$current['demo_version']}");
        }

        if (($frozen['anchor_date'] ?? null) !== $current['anchor_date']) {
            $driftFound = true;
            $this->line("  <fg=yellow>!</> Anchor date changed: {$frozen['anchor_date']} -> {$current['anchor_date']}");
        }

        $frozenProjectCodes = collect($frozen['projects'] ?? [])->pluck('code')->sort()->values();
        $currentProjectCodes = collect($current['projects'])->pluck('code')->sort()->values();
        if ($frozenProjectCodes->all() !== $currentProjectCodes->all()) {
            $driftFound = true;
            $added = $currentProjectCodes->diff($frozenProjectCodes)->all();
            $removed = $frozenProjectCodes->diff($currentProjectCodes)->all();
            if ($added) {
                $this->line('  <fg=yellow>!</> Project(s) added since freeze: ' . implode(', ', $added));
            }
            if ($removed) {
                $this->line('  <fg=yellow>!</> Project(s) removed since freeze: ' . implode(', ', $removed));
            }
        }

        foreach ($current['table_counts'] as $table => $count) {
            $frozenCount = $frozen['table_counts'][$table] ?? null;
            if ($frozenCount !== null && $frozenCount !== $count) {
                $driftFound = true;
                $this->line("  <fg=yellow>!</> '{$table}' count changed: {$frozenCount} -> {$count}");
            }
        }

        if (! $driftFound) {
            $this->line('  <fg=green>No drift — the live environment still matches the last frozen manifest.</>');
        } else {
            $this->line('');
            $this->line('  Screenshots/assets captured against the frozen manifest may no longer match the live environment. Re-freeze with --write once you\'re satisfied with the current state, or investigate the drift above if unintended.');
        }
    }
}
