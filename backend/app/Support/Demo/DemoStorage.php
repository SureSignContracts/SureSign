<?php

namespace App\Support\Demo;

use Illuminate\Support\Facades\Storage;

/**
 * Isolates the demo environment's generated files onto their own disk
 * root — the filesystem equivalent of config/database.php's isolated
 * `demo` connection.
 *
 * `ExcelGenerationService`/`DocumentGenerationService` write generated
 * documents via the hard-coded `Storage::disk('local')` call (production
 * code, shared with real customers — not something this environment
 * should modify). Without isolation, the path they write to
 * (`projects/{project_id}/generated/...`) is keyed only by numeric project
 * ID, which the demo connection's IDs (1-7) can and do collide with in the
 * real `suresign` database on the same filesystem — a real, if so far
 * harmless (timestamps make exact collisions astronomically unlikely),
 * risk flagged during Phase 4.
 *
 * The fix mirrors exactly how `SeedDemoEnvironment` isolates the database
 * connection: rather than changing the production services, this
 * temporarily repoints what the `local` disk *resolves to* for the
 * lifetime of the current process, using the same technique Laravel's own
 * `db:seed --database=` uses for connections. `Storage::forgetDisk()`
 * clears the cached resolved instance so the new root takes effect
 * immediately. Every demo command that writes or reads generated files
 * (`demo:seed`, `demo:validate`) must call `DemoStorage::isolate()` first.
 */
class DemoStorage
{
    public static function isolate(): void
    {
        $root = config('demo.storage_root');

        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');

        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }
    }
}
