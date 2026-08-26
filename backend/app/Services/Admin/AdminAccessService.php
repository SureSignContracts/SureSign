<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Support\Admin\AdminAccess;
use Spatie\Permission\Models\Permission;

/**
 * Super Admin Configurable Admin Access — the ONE shared service every
 * lifecycle transition into/out of the Admin role goes through. Never
 * duplicate this permission logic in a controller branch — see
 * UserController::inviteOneUser()/update() for the only call sites.
 *
 * ensurePermissionsExist() is the lazy, idempotent catalogue bootstrap —
 * mirrors this codebase's existing Role::firstOrCreate() convention
 * exactly (called from the same places that need the rows, never from
 * AppServiceProvider::boot() on every request/boot). It also bootstraps
 * the AdminAccess::INITIALIZED_SENTINEL permission row alongside the
 * catalogue rows — the sentinel lives in the same Spatie permissions
 * table, it is simply never included in AdminAccess::keys().
 */
class AdminAccessService
{
    /**
     * Idempotent — safe to call on every invocation of the methods below.
     * Never called from a global boot hook; only from the specific call
     * sites that are about to read or write these permissions.
     */
    public static function ensurePermissionsExist(): void
    {
        foreach (AdminAccess::keys() as $key) {
            Permission::firstOrCreate(['name' => $key, 'guard_name' => AdminAccess::GUARD]);
        }

        Permission::firstOrCreate([
            'name' => AdminAccess::INITIALIZED_SENTINEL,
            'guard_name' => AdminAccess::GUARD,
        ]);
    }

    /**
     * The full configurable Admin baseline — called ONLY when a user
     * actually transitions INTO the Admin role (a fresh invite, a restored
     * invite, or an existing user's role being changed TO Admin). Never
     * called on an ordinary profile edit of an already-Admin user — doing
     * so would silently undo any restriction a Super Admin had previously
     * configured for them (see UserController::update()'s own guard for
     * why "role stays Admin" and "role becomes Admin" are handled
     * differently).
     *
     * Always grants the full catalogue AND the initialisation sentinel
     * together, in the same call — a genuine transition into Admin is
     * always a fresh start, never a restoration of any previous
     * configuration this same account might have held before an earlier
     * departure from the Admin role (see removeManagedAccess()).
     */
    public static function grantFullAccess(User $user): void
    {
        self::ensurePermissionsExist();
        $user->givePermissionTo([...AdminAccess::keys(), AdminAccess::INITIALIZED_SENTINEL]);
    }

    /**
     * Removes the catalogue-managed admin.module.* permissions AND the
     * initialisation sentinel — never a blind syncPermissions([]), since
     * that would also strip any unrelated direct permission this account
     * might legitimately hold for a completely different reason. Called
     * when a user transitions AWAY from the Admin role (destination role
     * is Client or Super Admin). Super Admin doesn't need these
     * permissions at all (Gate::before() bypasses every check
     * unconditionally), so removing them on a promotion to Super Admin is
     * just tidiness, not a security requirement.
     *
     * The sentinel is removed here deliberately: if this account is ever
     * made an Admin again later, that must be treated as a fresh start
     * (full baseline again), never a silent restoration of whatever
     * restriction was configured before this departure.
     */
    public static function removeManagedAccess(User $user): void
    {
        $user->revokePermissionTo([...AdminAccess::keys(), AdminAccess::INITIALIZED_SENTINEL]);
    }

    /**
     * Whether this user's Admin access has ever been explicitly
     * initialised (full baseline granted, or a deliberate restriction —
     * including all the way to zero modules — configured). False for a
     * legacy/never-initialised Admin, who should receive the full
     * baseline. This is the ONLY correct check for "does this Admin need
     * the baseline" — checking whether the user currently HOLDS any
     * admin.module.* permission is not equivalent, since an intentionally
     * fully-restricted (zero-module) Admin holds none either.
     */
    public static function isInitialized(User $user): bool
    {
        // A direct relation query rather than hasPermissionTo() — the
        // latter throws Spatie\Permission\Exceptions\PermissionDoesNotExist
        // when the sentinel row doesn't exist yet (e.g. a brand-new
        // install/test database before ensurePermissionsExist() has ever
        // run), which would turn "not initialised" into a 500 instead of
        // the correct `false`.
        return $user->permissions()
            ->where('name', AdminAccess::INITIALIZED_SENTINEL)
            ->exists();
    }
}
