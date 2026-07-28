<?php

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the demo environment from scratch: drops and re-migrates the
 * 'demo' connection's schema, then re-runs demo:seed. The --database flag
 * passed to migrate:fresh scopes the drop/rebuild to that connection only —
 * it can never affect the platform's real database, whatever DB_CONNECTION
 * currently is.
 *
 * Requires --force in production-like environments, consistent with how
 * migrate:fresh itself behaves — this command is a thin, explicit wrapper
 * around two existing, well-understood Artisan commands, not a new
 * destructive primitive.
 */
class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset {--force : Skip the confirmation prompt}';

    protected $description = 'Drop, re-migrate, and re-seed the isolated SureSign demo environment';

    public function handle(): int
    {
        try {
            DemoDatabaseGuard::assertIsolatedFromPrimary();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $connection = config('demo.connection', 'demo');

        if (! $this->option('force') && ! $this->confirm(
            "This will DROP ALL TABLES on the '{$connection}' database connection and rebuild the demo environment from scratch. Continue?"
        )) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $this->info("Rebuilding schema on connection [{$connection}]...");

        Artisan::call('migrate:fresh', [
            '--database' => $connection,
            '--force' => true,
        ], $this->output);

        // migrate:fresh drops and recreates every table on this connection,
        // but the PHP process keeps running — its PDO connection (and any
        // Eloquent-side statement caching) still reflects the pre-drop
        // schema. Without forcing a fresh connection, a query run moments
        // later in the same process (in particular the "does this document
        // already exist" guard in DemoDocumentSeeder/DemoColdfieldSeeder)
        // can silently miss rows that the seed run itself just inserted,
        // causing a duplicate real-file generation. Purging and
        // reconnecting eliminates that stale-connection window.
        DB::purge($connection);
        DB::reconnect($connection);

        $this->call('demo:seed');

        $this->info('Demo environment reset complete.');

        return self::SUCCESS;
    }
}
