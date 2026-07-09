<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // Matches the roles actually seeded in DatabaseSeeder — 'Manager'/'Viewer'
    // were previously listed here but never seeded or assignable to any real
    // user (confirmed via Role::pluck('name') against production data).
    private const ALLOWED_ROLES = ['Super Admin', 'Admin', 'Client'];

    public function index(Request $request)
    {
        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $sort    = in_array($request->input('sort'), ['name', 'email', 'created_at', 'last_login_at'])
                   ? $request->input('sort') : 'created_at';
        $dir     = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = User::with('roles')->orderBy($sort, $dir);

        if ($search = $request->input('search')) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                                      ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            if ($status === 'active')   $query->where('is_active', true);
            if ($status === 'disabled') $query->where('is_active', false);
        }

        $paginated = $query->paginate($perPage);
        $paginated->getCollection()->transform(fn($u) => $this->formatUser($u));

        return response()->json($paginated);
    }

    public function invite(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255|unique:users,email',
            'role'  => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
        ]);

        $tempPassword = $this->generateTempPassword();

        $user = User::create([
            'name'      => explode('@', $validated['email'])[0],
            'email'     => $validated['email'],
            'password'  => Hash::make($tempPassword),
            'is_active' => true,
        ]);

        $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
        $user->assignRole($role);

        EmailVerificationService::sendVerificationLink($user);

        return response()->json([
            'message' => 'User created successfully.',
            'data'    => [
                'id'            => $user->id,
                'email'         => $user->email,
                'role'          => $validated['role'],
                'temp_password' => $tempPassword,
            ],
        ], 201);
    }

    public function show(string $id)
    {
        $user = User::with('roles')->findOrFail($id);
        return response()->json(['data' => $this->formatUser($user)]);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === Auth::id() && $request->has('role')) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        if ((int) $id === Auth::id() && $request->has('is_active') && ! $request->boolean('is_active')) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        $validated = $request->validate([
            'role'      => 'sometimes|string|in:' . implode(',', self::ALLOWED_ROLES),
            'name'      => 'sometimes|string|max:255',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['is_active']) && ! $validated['is_active'] && $this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Cannot deactivate the last Super Admin.'], 422);
        }

        if (isset($validated['role']) && $validated['role'] !== 'Super Admin' && $this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Cannot change the role of the last Super Admin.'], 422);
        }

        $before = $user->only(['name', 'is_active']);

        if (isset($validated['name']))      $user->name      = $validated['name'];
        if (isset($validated['is_active'])) $user->is_active = $validated['is_active'];
        $user->save();

        if (isset($validated['role'])) {
            $beforeRoles = $user->roles->pluck('name')->all();
            $user->syncRoles([]);
            $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
            $user->assignRole($role);

            ActivityLog::record(
                'user.role_changed',
                "Changed {$user->email}'s role from " . (implode(', ', $beforeRoles) ?: 'none') . " to {$validated['role']}",
                Auth::user(),
                $user,
                ['from' => $beforeRoles, 'to' => $validated['role']],
            );
        }

        if (isset($validated['is_active']) && $before['is_active'] !== $user->is_active) {
            ActivityLog::record(
                $user->is_active ? 'user.activated' : 'user.deactivated',
                ($user->is_active ? 'Activated ' : 'Deactivated ') . $user->email,
                Auth::user(),
                $user,
            );
        }

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function destroy(string $id)
    {
        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove your own account.'], 422);
        }

        $user = User::findOrFail($id);

        if ($this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Cannot remove the last Super Admin.'], 422);
        }

        $user->delete();

        ActivityLog::record('user.removed', "Removed {$user->email}", Auth::user(), $user);

        return response()->json(['message' => 'User removed.']);
    }

    // ── Verification ─────────────────────────────────────────────────────

    public function verifyEmail(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['email_verified_at' => now()]);

        ActivityLog::record('user.email_verified', "Marked {$user->email} as verified", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function unverifyEmail(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['email_verified_at' => null]);

        ActivityLog::record('user.email_unverified', "Marked {$user->email} as unverified", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Ban / unban ──────────────────────────────────────────────────────

    public function ban(Request $request, string $id)
    {
        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot ban your own account.'], 422);
        }

        $user = User::findOrFail($id);

        if ($this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'Cannot ban the last Super Admin.'], 422);
        }

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        $user->update(['banned_at' => now(), 'banned_reason' => $validated['reason']]);
        $user->tokens()->delete();

        ActivityLog::record('user.banned', "Banned {$user->email}", Auth::user(), $user, ['reason' => $validated['reason']]);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function unban(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['banned_at' => null, 'banned_reason' => null]);

        ActivityLog::record('user.unbanned', "Unbanned {$user->email}", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Password controls ────────────────────────────────────────────────

    public function forcePasswordReset(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['must_change_password' => true]);

        ActivityLog::record('user.password_reset_forced', "Required {$user->email} to change password on next login", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function setPassword(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'password'       => ['required', Password::min(8)->mixedCase()->numbers()->symbols()],
            'require_change' => 'sometimes|boolean',
        ]);

        $user->update([
            'password'             => Hash::make($validated['password']),
            'must_change_password' => $validated['require_change'] ?? true,
        ]);

        ActivityLog::record('user.password_set', "Set a new password for {$user->email}", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Sessions ─────────────────────────────────────────────────────────

    public function revokeTokens(string $id)
    {
        $user = User::findOrFail($id);
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        ActivityLog::record('user.tokens_revoked', "Revoked {$count} active session(s) for {$user->email}", Auth::user(), $user, ['count' => $count]);

        return response()->json(['message' => "Revoked {$count} active session(s).", 'data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Guided tours ─────────────────────────────────────────────────────

    public function resetTours(string $id)
    {
        $user = User::findOrFail($id);
        $user->update(['tours_reset_at' => now()]);

        ActivityLog::record('user.tours_reset', "Reset onboarding tour progress for {$user->email}", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * True if $user currently holds the Super Admin role and is the only
     * active one left — the action being attempted would strip the
     * organisation of any Super Admin able to manage it.
     */
    /**
     * Every server-generated temp password (invite(), and previously a
     * divergent inline generator here) goes through this one helper, so
     * there is a single source of truth for what "policy-compliant" means —
     * the same Password::min(8)->mixedCase()->numbers()->symbols() rule
     * enforced on admin/self-service password changes elsewhere in this
     * codebase. The old inline generator (Str::random, no symbols) could
     * produce a password that would fail that very rule if resubmitted.
     */
    private function generateTempPassword(): string
    {
        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $digits  = '23456789';
        $symbols = '!@#$%';

        $pick = fn (string $chars) => $chars[random_int(0, strlen($chars) - 1)];

        $required = [$pick($upper), $pick($upper), $pick($lower), $pick($lower), $pick($digits), $pick($digits), $pick($symbols)];
        $filler   = array_map(fn () => $pick($upper . $lower . $digits), range(1, 3));

        $chars = array_merge($required, $filler);
        shuffle($chars);

        return implode('', $chars);
    }

    private function isLastActiveSuperAdmin(User $user): bool
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

    private function formatUser(User $u): array
    {
        return [
            'id'                    => $u->id,
            'name'                  => $u->name,
            'email'                 => $u->email,
            'roles'                 => $u->roles->pluck('name'),
            'is_active'             => $u->is_active ?? true,
            'email_verified_at'     => $u->email_verified_at,
            'banned_at'             => $u->banned_at,
            'banned_reason'         => $u->banned_reason,
            'must_change_password'  => $u->must_change_password,
            'tours_reset_at'        => $u->tours_reset_at,
            'last_login_at'         => $u->last_login_at,
            'created_at'            => $u->created_at,
        ];
    }
}
