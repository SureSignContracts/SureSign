<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * The single authoritative "is this the last active Super Admin" check —
 * extracted from UserController::isLastActiveSuperAdmin() (unchanged logic)
 * so it has one implementation. UserController keeps its own private method
 * as a thin delegate to this, so every existing call site there is
 * unaffected.
 *
 * "Active" is unchanged from the original definition: holds the Super Admin
 * role, `is_active = true`, `banned_at IS NULL` (soft-deleted rows are
 * excluded automatically by Eloquent's own default scope, never counted).
 *
 * Last Active Super Admin Concurrency Hardening — isLastActive() alone is
 * a plain, unlocked COUNT and is NOT concurrency-safe on its own: two
 * concurrent mutations each reducing a different Super Admin's active
 * status could both read "another active Super Admin exists" before
 * either commits. guardedMutate()/withLock() below are what make the
 * platform's "never zero active Super Admins" invariant authoritative —
 * every existing admin-management mutation capable of reducing the active
 * Super Admin set (UserController::destroy()/bulkRemove()'s per-row loop/
 * ban()/update()'s deactivate-and-role-change branches) now goes through
 * guardedMutate() instead of a bare isLastActive() pre-check.
 *
 * Self-Service Account Deletion (AuthController::deleteAccount()) does
 * NOT participate here — Client-only self-delete no longer makes a Super
 * Admin decision at all (see that method's own docblock).
 */
class SuperAdminGuard
{
    public static function isLastActive(User $user): bool
    {
        if (! $user->hasRole('Super Admin')) {
            return false;
        }

        $activeSuperAdmins = User::role('Super Admin')
            ->where('is_active', true)
            ->whereNull('banned_at')
            ->count();

        return $activeSuperAdmins <= 1;
    }

    /**
     * Locks the single, stable `roles` row for 'Super Admin' inside a short
     * transaction — the shared serialization boundary every Super-Admin-
     * reducing mutation contends on, even though the target User rows
     * differ (mirrors App\Support\Users\OrganizationRemovalLock's identical
     * shape for the Client/Organisation case). `firstOrCreate()` is called
     * OUTSIDE the transaction (idempotent, matches this codebase's existing
     * lazy-role-bootstrap convention) purely to guarantee the row exists to
     * lock — a role, once created, is never deleted, so this is a real
     * lock after the very first Super Admin the platform ever had.
     *
     * Deliberately narrow in scope: only holds the lock for the DB writes
     * the callback performs. Never call this around email/provider calls
     * or any other external I/O.
     */
    public static function withLock(callable $callback): mixed
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);

        return DB::transaction(function () use ($role, $callback) {
            Role::where('id', $role->id)->lockForUpdate()->first();

            return $callback();
        });
    }

    /**
     * Runs $mutate($target) only if doing so would not reduce the active
     * Super Admin count to zero. If $user does not currently hold the
     * Super Admin role at all, $mutate() runs immediately with no lock —
     * there is nothing to protect. Otherwise acquires withLock() first,
     * re-fetches a fresh copy of $user inside it, and re-evaluates
     * isLastActive() against that fresh copy — never against the
     * possibly-stale $user instance the caller captured before any lock.
     *
     * @return array{blocked: bool, result: mixed} `result` is $mutate()'s
     *   own return value, only present when `blocked` is false.
     */
    public static function guardedMutate(User $user, callable $mutate): array
    {
        if (! $user->hasRole('Super Admin')) {
            return ['blocked' => false, 'result' => $mutate($user)];
        }

        return self::withLock(function () use ($user, $mutate) {
            $fresh = User::find($user->id) ?? $user;

            if (self::isLastActive($fresh)) {
                return ['blocked' => true, 'result' => null];
            }

            return ['blocked' => false, 'result' => $mutate($fresh)];
        });
    }

    /**
     * Full Parity Access Expansion (2026-08-26) — the Users module carve-
     * out. Once admin.module.users became a grantable permission (Users
     * was previously permanently Super-Admin-only at the route level), an
     * Admin holding it could reach every UserController mutation —
     * including one targeting an existing Super Admin account. This is
     * the one place that still refuses that, independent of the
     * admin.module.users permission check: the route/permission layer
     * only decides whether an Admin can reach the Users module AT ALL;
     * this decides whether the SPECIFIC target is off-limits regardless.
     * A genuine Super Admin actor always passes (the check is a no-op for
     * them). Aborts with the same generic, tenant-safe 403 message this
     * codebase's authorize()/authorizeProject() convention already uses
     * (see AGENTS.md's Authorization section) — never reveals that the
     * target specifically is a Super Admin.
     */
    public static function actorMayActOnTarget(User $actor, User $target): bool
    {
        return ! ($target->hasRole('Super Admin') && ! $actor->hasRole('Super Admin'));
    }

    public static function assertActorMayActOnTarget(User $actor, User $target): void
    {
        if (! self::actorMayActOnTarget($actor, $target)) {
            abort(403, 'Access denied.');
        }
    }

    /**
     * The invite()/bulk-invite()/role-change counterpart of
     * actorMayActOnTarget() above — refuses an Admin (even one holding
     * admin.module.users) from ever creating a NEW Super Admin account or
     * promoting an existing one, regardless of target.
     */
    public static function actorMayAssignRole(User $actor, string $role): bool
    {
        return ! ($role === 'Super Admin' && ! $actor->hasRole('Super Admin'));
    }

    public static function assertActorMayAssignRole(User $actor, string $role): void
    {
        if (! self::actorMayAssignRole($actor, $role)) {
            abort(403, 'Access denied.');
        }
    }
}
