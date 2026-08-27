<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Illuminate\Console\Command;

/**
 * Super Admin Configurable Admin Access — the one-time, manual,
 * backward-compatibility backfill for existing Admin users (mirrors the
 * manual/on-demand convention already established by
 * ai:credits:backfill-simulations / domains:verify-pending — never run
 * automatically from a migration, seeder, or application boot).
 *
 * Grants the DEFAULT admin.module.* baseline (App\Support\Admin\AdminAccess::defaultKeys(), never the full 23-key catalogue) to every existing
 * Admin who has never been explicitly initialised (i.e. does not hold
 * AdminAccess::INITIALIZED_SENTINEL) — this is what preserves today's
 * broad Admin access unchanged the moment this feature ships, rather than
 * silently locking every existing Admin out until a Super Admin manually
 * configures them.
 *
 * Idempotent and safe to re-run: an Admin who already holds the
 * initialisation sentinel is skipped entirely, regardless of how many
 * admin.module.* permissions they currently hold — including exactly
 * zero, if a Super Admin has deliberately restricted them all the way
 * down. Checking "holds any admin.module.* permission" (the earlier,
 * incorrect version of this command) is NOT equivalent: a legacy Admin
 * who has never been initialised and an Admin who has been deliberately
 * restricted to zero modules are indistinguishable by permission COUNT
 * alone, and only the sentinel tells them apart. This command must never
 * silently re-grant full access to an Admin a Super Admin has since
 * restricted — including to zero. Never touches Super Admin or Client
 * users.
 *
 * FIXED (Admin Access Configuration — Final Deployment/Cold-Start
 * Hardening phase): the initialisation check below now goes through
 * AdminAccessService::isInitialized() — a safe relation query — rather
 * than calling $admin->hasPermissionTo() directly. hasPermissionTo()
 * throws Spatie\Permission\Exceptions\PermissionDoesNotExist when the
 * named permission row doesn't exist in the `permissions` table at all,
 * which is exactly the true cold-start production state (a fresh
 * install, or any environment where nothing has ever called
 * ensurePermissionsExist() yet): zero admin.module.* rows, zero sentinel
 * row. Reproduced directly before this fix — even `--dry-run` crashed on
 * the very first Admin. isInitialized() never throws regardless of
 * whether the permission row exists, so this command needs no separate
 * catalogue-bootstrap call of its own before evaluating initialisation —
 * see Phase 3 below (--dry-run semantics) for why bootstrapping the
 * catalogue is deliberately NOT done here, only inside the real
 * (non-dry-run) grant path via grantDefaultAccess()'s own
 * ensurePermissionsExist() call.
 */
class BackfillAdminAccess extends Command
{
    protected $signature = 'admin:permissions:backfill {--dry-run}';

    protected $description = 'One-time backfill: grants the default admin.module.* baseline to existing Admin users who have never been explicitly initialised.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $admins = User::role('Admin')->get();

        $granted = 0;
        $skipped = 0;

        foreach ($admins as $admin) {
            // AdminAccessService::isInitialized() — never a direct
            // hasPermissionTo() call here. See this class's own docblock
            // for why: hasPermissionTo() throws when the sentinel
            // permission row doesn't exist yet, which is exactly true on
            // a cold-start production database before this command (or
            // anything else in this feature) has ever run.
            if (AdminAccessService::isInitialized($admin)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("Would grant default baseline to {$admin->email} (id {$admin->id}).");
            } else {
                AdminAccessService::grantDefaultAccess($admin);
                $this->line("Granted default baseline to {$admin->email} (id {$admin->id}).");
            }

            $granted++;
        }

        $this->info(
            ($dryRun ? '[dry run] ' : '')
            . "{$granted} Admin(s) granted the default baseline; {$skipped} already initialised and were left unchanged."
        );

        return self::SUCCESS;
    }
}
