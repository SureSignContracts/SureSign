<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Admin\AdminAccessService;
use Spatie\Permission\Events\RoleAttachedEvent;

/**
 * Super Admin Configurable Admin Access — the authoritative, catch-all
 * safety net for "every path a user can become an Admin" (Phase 7's own
 * requirement). UserController::inviteOneUser()/update() and
 * DatabaseSeeder (the platform's own initial Admin seed) already call
 * AdminAccessService::grantFullAccess() explicitly for every real,
 * non-test production code path this phase's audit found; this listener
 * exists as a structural safety net for any OTHER path that assigns the
 * Admin role — including one this phase's own regression run empirically
 * proved exists: many pre-existing test fixtures create an Admin via a
 * bare `assignRole('Admin')`, bypassing every explicit call site
 * entirely. Rather than editing dozens of unrelated test files, this
 * listener makes the guarantee hold structurally for any code path,
 * present or future.
 *
 * Deliberately listens ONLY to RoleAttachedEvent, never RoleDetachedEvent.
 * Spatie's syncRoles() always detaches-then-reattaches even when the new
 * role set is unchanged (e.g. an Admin's profile edited with `role: Admin`
 * resubmitted unchanged) — hooking the detach side too would strip a
 * restricted Admin's custom permissions on every such no-op resubmission,
 * then this listener would immediately re-grant the FULL baseline on the
 * following attach, silently undoing a Super Admin's restriction. Removal
 * on a genuine transition AWAY from Admin remains owned exclusively by
 * UserController::update()'s own before/after role comparison, which (unlike
 * this listener) can see whether the role genuinely changed within the
 * same request.
 *
 * FIXED (Admin Access Configuration — Final Initialisation phase): this
 * previously keyed off "holds none of the catalogue's admin.module.*
 * permissions" — which is NOT equivalent to "never initialised". A Super
 * Admin using "Clear All" to deliberately restrict an Admin down to zero
 * modules is indistinguishable from an uninitialised Admin under that old
 * check, so ANY later RoleAttachedEvent for that same user (a no-op role
 * resubmission included, since syncRoles() always detaches-then-reattaches
 * even when unchanged) would have silently "healed" the deliberate
 * zero-module restriction back to the full baseline. This now keys off
 * AdminAccess::INITIALIZED_SENTINEL absence instead: an Admin who has
 * been explicitly initialised (full baseline OR any deliberate
 * restriction, including zero) is left completely untouched, regardless
 * of how many admin.module.* permissions they currently hold.
 */
class GrantBaselineAdminAccessOnRoleAttached
{
    public function handle(RoleAttachedEvent $event): void
    {
        $model = $event->model;

        if (! $model instanceof User || ! $model->hasRole('Admin')) {
            return;
        }

        if (! AdminAccessService::isInitialized($model)) {
            AdminAccessService::grantFullAccess($model);
        }
    }
}
