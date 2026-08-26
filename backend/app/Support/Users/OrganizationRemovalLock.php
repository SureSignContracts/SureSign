<?php

namespace App\Support\Users;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Remove & Detach Concurrency Hardening — the single serialization
 * primitive every Client-removal-or-deletion decision for a given
 * organisation goes through: UserController::destroy()/bulkRemove()'s
 * per-row loop/removeAndDetach(), and AuthController::deleteAccount()'s
 * self-service sole-Client guard (Self-Service Account Deletion). Extracted
 * verbatim from UserController's own private withOrganizationLock() so a
 * second implementation is never written.
 *
 * `SELECT ... FOR UPDATE` on the Organisation row inside a short transaction
 * means two concurrent removal operations for the SAME organisation are
 * strictly ordered — the second can only acquire the lock after the first's
 * transaction has committed, so it always recomputes "remaining Client
 * users" against the first operation's committed result, never a stale
 * pre-commit snapshot. A different organisation's row is never touched,
 * locked, or blocked — this is per-organisation serialization, not a global
 * one.
 *
 * Deliberately narrow in scope: only holds the lock for the DB writes the
 * callback performs. Never call this around email/provider calls or any
 * other external I/O.
 */
class OrganizationRemovalLock
{
    public static function run(int $organizationId, callable $callback): mixed
    {
        return DB::transaction(function () use ($organizationId, $callback) {
            Organization::where('id', $organizationId)->lockForUpdate()->first();

            return $callback();
        });
    }
}
