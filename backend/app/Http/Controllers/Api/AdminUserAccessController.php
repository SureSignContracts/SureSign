<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Support\Admin\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Super Admin Configurable Admin Access — the configuration endpoints
 * (GET/PUT /users/{id}/permissions). Sits inside the same `role:Super
 * Admin` ONLY route group as every other user-management mutation
 * (ban/set-password/etc. — see routes/api.php) — an Admin can never reach
 * this controller at all, so there is no risk of an Admin editing their
 * own or another Admin's permissions, or reaching this endpoint by any
 * means. The target must be an Admin (not Client, not Super Admin, not a
 * soft-deleted/nonexistent user) — Super Admin needs no configuration at
 * all (Gate::before() bypass), and Client never has any admin.module.*
 * concept.
 */
class AdminUserAccessController extends Controller
{
    public function show(string $id)
    {
        $user = User::findOrFail($id);

        if (! $user->hasRole('Admin')) {
            return response()->json([
                'message' => 'Access configuration only applies to an Admin user.',
            ], 422);
        }

        return response()->json([
            'data' => [
                'modules'  => AdminAccess::catalogue(),
                'granted'  => $user->getPermissionNames()->intersect(AdminAccess::keys())->values(),
            ],
        ]);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot configure your own access.'], 422);
        }

        if (! $user->hasRole('Admin')) {
            return response()->json([
                'message' => 'Access configuration only applies to an Admin user.',
            ], 422);
        }

        // Only recognised catalogue keys are ever accepted — an unknown,
        // Super-Admin-only-adjacent, or sentinel permission name fails
        // validation outright, never silently ignored and never granted.
        // AdminAccess::INITIALIZED_SENTINEL is not in AdminAccess::keys(),
        // so it can never be submitted or accepted through this payload.
        // This is also what makes a malicious payload structurally unable
        // to grant anything outside the catalogue: givePermissionTo() below
        // is never called with anything the request didn't pass, and
        // nothing the request could pass is outside AdminAccess::keys()
        // once validation has passed.
        // 'present' (not 'required') — Laravel's `required` rule treats an
        // EMPTY array as "missing" and rejects it with a validation error.
        // "Clear All" (a deliberate, legitimate zero-module configuration)
        // submits permissions: [] — that must be accepted, not rejected.
        // 'present' only requires the key to exist in the payload at all;
        // 'array' still enforces the type.
        $validated = $request->validate([
            'permissions'   => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(AdminAccess::keys())],
        ]);

        AdminAccessService::ensurePermissionsExist();

        $before = $user->getPermissionNames()->intersect(AdminAccess::keys())->values()->all();
        $after  = array_values(array_unique($validated['permissions']));

        // An explicit configuration call is itself an initialisation act —
        // it must never be silently "healed" back to full access later,
        // including if this happens to be a legacy Admin's very first
        // configuration (skipping the backfill command entirely). Granting
        // the sentinel here is idempotent (no-op if already held).
        if (! $user->hasPermissionTo(AdminAccess::INITIALIZED_SENTINEL)) {
            $user->givePermissionTo(AdminAccess::INITIALIZED_SENTINEL);
        }

        // Scoped diff only — never syncPermissions(), which would wholesale
        // replace this user's ENTIRE permission set and silently destroy
        // the AdminAccess::INITIALIZED_SENTINEL permission (and any
        // unrelated direct permission this account might hold) since
        // neither is present in $after. givePermissionTo()/revokePermissionTo()
        // only ever touch the catalogue keys this request actually changed.
        $granted = array_values(array_diff($after, $before));
        $revoked = array_values(array_diff($before, $after));

        if ($granted) {
            $user->givePermissionTo($granted);
        }
        if ($revoked) {
            $user->revokePermissionTo($revoked);
        }

        if ($granted || $revoked) {
            ActivityLog::record(
                'admin.permissions.updated',
                "Updated {$user->email}'s Admin module access",
                Auth::user(),
                $user,
                ['granted' => $granted, 'revoked' => $revoked],
            );
        }

        return response()->json([
            'data' => [
                'modules' => AdminAccess::catalogue(),
                'granted' => $after,
            ],
        ]);
    }
}
