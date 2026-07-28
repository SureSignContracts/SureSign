<?php

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoDatabaseGuard;
use App\Support\Demo\DemoStorage;
use Database\Seeders\Demo\DemoEnvironmentSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds (or re-seeds) the demo environment. Always runs against the isolated
 * 'demo' database connection (config/demo.php) — never the platform's real
 * database, regardless of what DB_CONNECTION/.env is currently set to.
 *
 * Safe to run repeatedly: every demo seeder is idempotent (updateOrCreate /
 * firstOrCreate keyed on stable identifiers), so re-running this never
 * duplicates the demo company, its branding, or its users.
 */
class SeedDemoEnvironment extends Command
{
    protected $signature = 'demo:seed';

    protected $description = 'Seed the isolated SureSign demo environment (Halden Grove Construction Ltd.)';

    public function handle(): int
    {
        try {
            DemoDatabaseGuard::assertIsolatedFromPrimary();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $connection = config('demo.connection', 'demo');

        $this->info("Seeding demo environment on connection [{$connection}]...");

        // Isolates the 'local' disk's root to config('demo.storage_root')
        // for this process — see App\Support\Demo\DemoStorage. Must happen
        // before the seed run so ExcelGenerationService's generated payment
        // application workbooks land in the isolated demo storage tree,
        // never mixed with real customer files on the same disk.
        DemoStorage::isolate();

        // --database tells Laravel's own db:seed command to resolve
        // Eloquent's default connection to 'demo' for the duration of the
        // seeder run only (Illuminate\Database\Console\Seeds\SeedCommand
        // swaps it before running and restores the previous default
        // afterwards) — so DemoEnvironmentSeeder's Organization::create()/
        // User::create() calls land on the demo schema without any model
        // needing a hard-coded $connection override, and nothing leaks back
        // into the rest of this process.
        Artisan::call('db:seed', [
            '--class' => DemoEnvironmentSeeder::class,
            '--database' => $connection,
            '--force' => true,
        ], $this->output);

        $this->info('Demo environment seed complete.');

        return self::SUCCESS;
    }
}
