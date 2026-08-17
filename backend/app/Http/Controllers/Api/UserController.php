<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Services\InvitationService;
use App\Services\Intelligence\SubscriptionIntelligenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // Matches the roles actually seeded in DatabaseSeeder — 'Manager'/'Viewer'
    // were previously listed here but never seeded or assignable to any real
    // user (confirmed via Role::pluck('name') against production data).
    private const ALLOWED_ROLES = ['Super Admin', 'Admin', 'Client'];

    public function __construct(
        private readonly SubscriptionAccessPolicy $accessPolicy,
        private readonly SubscriptionIntelligenceService $intelligence,
        private readonly InvitationService $invitations,
    ) {
    }

    public function index(Request $request)
    {
        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $sort    = in_array($request->input('sort'), ['name', 'email', 'created_at', 'last_login_at'])
                   ? $request->input('sort') : 'created_at';
        $dir     = $request->input('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        // organization.liveSubscription.pricingPlan eager-loaded here
        // specifically to avoid an N+1 when formatUser() reads
        // $u->organization?->name and organizationSubscriptionSummaries()
        // below reads each org's live subscription — this page can list up
        // to 100 rows, and every one of these relations is batched into a
        // single query each regardless of how many users share an
        // organisation (never one query per user).
        $query = User::with(['roles', 'organization.liveSubscription.pricingPlan'])->orderBy($sort, $dir);

        if ($search = $request->input('search')) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                                      ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($status = $request->input('status')) {
            if ($status === 'active')   $query->where('is_active', true);
            if ($status === 'disabled') $query->where('is_active', false);
        }

        $paginated = $query->paginate($perPage);

        // G4A — lightweight inherited-subscription summary per row, derived
        // entirely from the eager-loaded organization.liveSubscription
        // relation above (zero extra queries) and resolved once per
        // DISTINCT organisation present on this page (never once per
        // user) — a page full of colleagues from the same organisation
        // computes this exactly once. Deliberately just
        // plan/status/access-mode/trial here — richer usage/storage figures
        // belong in the single-user subscription() endpoint below, fetched
        // only when an operator actually opens that user's detail.
        $orgSummaries = $this->organizationSubscriptionSummaries($paginated->getCollection());

        $paginated->getCollection()->transform(fn($u) => $this->formatUser($u, $orgSummaries->get($u->organization_id)));

        return response()->json($paginated);
    }

    /**
     * @param Collection<int, User> $users
     * @return Collection<int, array<string, mixed>>
     */
    private function organizationSubscriptionSummaries(Collection $users): Collection
    {
        return $users->pluck('organization')
            ->filter()
            ->unique('id')
            ->mapWithKeys(function (Organization $organization) {
                $subscription = $organization->liveSubscription;
                $decision = $this->accessPolicy->resolve($subscription);

                return [$organization->id => [
                    'plan_name' => $subscription?->pricingPlan?->name ?? $subscription?->plan_name_snapshot,
                    'status' => $subscription?->status,
                    'access_mode' => $decision->mode,
                    'trial_ends_at' => $subscription?->trial_ends_at,
                ]];
            });
    }

    /**
     * G4A — read-only inherited organisation subscription detail for a
     * single user (the Users page's "Manage User" view). Never a user-level
     * subscription: this is always the user's ORGANISATION's subscription,
     * fetched via the same composing service the Organisation Subscription
     * Administration page and the customer-facing Subscription Intelligence
     * Centre both use — no separate implementation.
     */
    public function subscription(string $id)
    {
        $user = User::findOrFail($id);

        if ($user->organization_id === null) {
            // No organisation means there's nothing to fetch intelligence
            // for regardless of role — but is_platform_operator itself must
            // be role-based, not inferred from this null check: a Client
            // can legitimately have no organisation yet (invited, not yet
            // onboarded), and must never be reported as a platform
            // operator. Super Admin / Admin are platform-wide operators
            // with no organisation of their own — never show a fake plan
            // or usage figures for them (Stage 3's explicit requirement).
            return response()->json(['data' => [
                'is_platform_operator' => $user->hasRole('Super Admin') || $user->hasRole('Admin'),
            ]]);
        }

        return response()->json(['data' => [
            'is_platform_operator' => false,
        ] + $this->intelligence->intelligenceForOrganization($user->organization)]);
    }

    public function invite(Request $request)
    {
        $validated = $request->validate([
            // A removed user is soft-deleted, not purged — the `email` column
            // still has a real unique constraint at the DB level, so exclude
            // trashed rows here or a re-invite would wrongly 422 as "taken".
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->whereNull('deleted_at')],
            'role'  => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
            // Per-invite admin choice, not a global setting — see
            // InvitationEmailService's docblock.
            'include_beta_notice' => 'sometimes|boolean',
        ]);

        $result = $this->inviteOneUser($validated['email'], $validated['role'], $validated['include_beta_notice'] ?? false);

        return response()->json([
            'message' => "Invitation sent to {$result['email']}.",
            'data'    => $result,
        ], 201);
    }

    /**
     * Bulk Invite — same per-user invitation path as invite() above
     * (inviteOneUser()), just fed from a list instead of a single email.
     * One shared role + one shared include_beta_notice for the whole batch,
     * not per-row — the realistic case (inviting a group of similar users)
     * without the extra input-format/validation complexity of per-row
     * roles. Deliberately partial-success honest (see the Error Handling
     * Standard): a bad email in the batch never aborts the rest — each
     * email is validated independently and the response reports exactly
     * which succeeded and which didn't, with a reason.
     */
    public function bulkInvite(Request $request)
    {
        $validated = $request->validate([
            'emails'   => 'required|array|min:1|max:100',
            'emails.*' => 'string',
            'role'     => 'required|string|in:' . implode(',', self::ALLOWED_ROLES),
            'include_beta_notice' => 'sometimes|boolean',
        ]);

        $role = $validated['role'];
        $includeBetaNotice = $validated['include_beta_notice'] ?? false;

        // Normalize + dedupe within the batch itself (case-insensitive),
        // preserving first-occurrence order — a pasted list commonly has
        // blank lines or accidental repeats.
        $emails = [];
        $seen = [];
        foreach ($validated['emails'] as $raw) {
            $email = strtolower(trim((string) $raw));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $emails[] = $email;
        }

        $invited = [];
        $failed = [];

        foreach ($emails as $email) {
            $rowValidator = ValidatorFacade::make(['email' => $email], [
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->whereNull('deleted_at')],
            ]);

            if ($rowValidator->fails()) {
                $failed[] = ['email' => $email, 'reason' => $rowValidator->errors()->first('email')];
                continue;
            }

            $invited[] = $this->inviteOneUser($email, $role, $includeBetaNotice);
        }

        return response()->json([
            'message' => count($invited) . ' of ' . count($emails) . ' invitation(s) sent.',
            'data'    => [
                'invited' => $invited,
                'failed'  => $failed,
            ],
        ], 201);
    }

    /**
     * Shared by invite() and bulkInvite() — creates (or restores a
     * soft-deleted) User for an already-validated, available email,
     * assigns the role, and sends the invitation. Callers are responsible
     * for their own email validation/uniqueness check before calling this,
     * so a bulk caller can report a per-row failure instead of throwing.
     *
     * @return array{id: int, email: string, role: string}
     */
    private function inviteOneUser(string $email, string $role, bool $includeBetaNotice): array
    {
        // An internal compatibility secret only — users.password is
        // non-nullable, but this value is never surfaced anywhere (API
        // response, frontend, email, log, or activity metadata) and is
        // never intended for the recipient to log in with. It's replaced
        // by the recipient's own chosen password during invitation
        // acceptance (InvitationService::accept()).
        $tempPassword = $this->generateTempPassword();

        // Reuse the soft-deleted record for this email instead of colliding
        // with the DB-level unique constraint that a fresh insert would hit.
        $user = User::onlyTrashed()->where('email', $email)->first();

        if ($user) {
            $user->restore();
            $user->update([
                // No first/last name is collected at invite time — 'name'
                // holds the email address itself as a schema-compatible
                // internal placeholder (users.name is non-nullable), never
                // a guessed human name. The invitation email greeting reads
                // first_name only (see InvitationService::send()), which
                // stays null here, so it correctly falls back to "Hi,".
                'name'                 => $email,
                'first_name'           => null,
                'last_name'            => null,
                'password'             => Hash::make($tempPassword),
                'is_active'            => true,
                'must_change_password' => true,
                // A previous account for this email may have already been
                // verified before it was removed — reset explicitly, or a
                // re-invited user with a brand-new, unknown password would
                // wrongly show as "invitation already accepted" instead of
                // being able to set one up.
                'email_verified_at'    => null,
                'banned_at'            => null,
                'banned_reason'        => null,
            ]);
            $user->syncRoles([]);
        } else {
            $user = User::create([
                'name'      => $email,
                'email'     => $email,
                'password'  => Hash::make($tempPassword),
                'is_active' => true,
                'must_change_password' => true,
            ]);
        }

        $roleModel = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user->assignRole($roleModel);

        $this->invitations->send($user, $includeBetaNotice);

        ActivityLog::record(
            'user.invited',
            "Invited {$user->email} to SureSign as {$role}",
            Auth::user(),
            $user,
            ['role' => $role],
        );

        return [
            'id'    => $user->id,
            'email' => $user->email,
            'role'  => $role,
        ];
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

            // Only revoke on the true -> false transition, not merely because
            // is_active was present in the request (e.g. re-submitting the
            // same value, or reactivating someone) — reactivation must not
            // hand back a working session either; a fresh login is required
            // (see UserController::unban for the same rule on bans).
            if ($before['is_active'] === true && $user->is_active === false) {
                $user->tokens()->delete();

                ActivityLog::record(
                    'user.tokens_revoked',
                    "Revoked all active session(s) for {$user->email} due to deactivation",
                    Auth::user(),
                    $user,
                );
            }
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

        // Forcing a password change is meaningless as a security action if
        // the user's existing session(s) keep working with the old
        // password's token — revoke them so the only way back in is a fresh
        // login (which the must_change_password flag then gates).
        $user->tokens()->delete();

        ActivityLog::record(
            'user.password_reset_forced',
            "Required {$user->email} to change password on next login and revoked all active sessions",
            Auth::user(),
            $user,
        );

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

        // Unconditional, regardless of require_change: an admin setting a new
        // password for someone must invalidate any session opened under the
        // OLD password — that's the whole point of the action, and it's the
        // exact scenario ("this account may be compromised") where leaving
        // the old session alive would be the worst possible outcome.
        $user->tokens()->delete();

        ActivityLog::record(
            'user.password_set',
            "Set a new password for {$user->email} and revoked all active sessions",
            Auth::user(),
            $user,
        );

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

    /**
     * @param array<string, mixed>|null $organizationSubscription G4A — only
     *   populated by index() (which already computed it once per distinct
     *   organisation); every other call site leaves this null since the
     *   frontend fetches richer detail lazily via subscription() instead.
     */
    private function formatUser(User $u, ?array $organizationSubscription = null): array
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
            // G4A — platform operators (Super Admin/Admin) have no
            // organisation of their own; never show a fake plan for them.
            // is_platform_operator is role-based, NOT inferred from
            // organization_id — a Client can legitimately have no
            // organisation yet (invited, not yet onboarded) without being a
            // platform operator. organization_subscription still correctly
            // has nothing to show for ANY null-organisation user (platform
            // operator or not), so that condition stays on organization_id.
            'organization_id'       => $u->organization_id,
            'organization_name'     => $u->organization?->name,
            'is_platform_operator'  => $u->hasRole('Super Admin') || $u->hasRole('Admin'),
            'organization_subscription' => $u->organization_id === null ? null : $organizationSubscription,
        ];
    }
}
