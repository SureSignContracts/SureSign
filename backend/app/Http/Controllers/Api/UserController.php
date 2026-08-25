<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Rules\DiffersFromCurrentPassword;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Services\InvitationService;
use App\Services\Intelligence\SubscriptionIntelligenceService;
use App\Support\Auth\PasswordSecurityNotifier;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
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

        // ── PASS 1 (preflight) — P2 Security Remediation ────────────────
        // Determine exactly which recipients would genuinely proceed to
        // inviteOneUser() WITHOUT creating/restoring any User, assigning
        // any role, dispatching any job, or writing any ActivityLog. This
        // is the authoritative recipient count the volume budget below
        // consumes — never the raw submitted count, so a batch padded with
        // duplicates/already-existing users can't cheaply exhaust an
        // operator's quota without any real invitation ever happening.
        $eligibleEmails = [];
        $failed = [];

        foreach ($emails as $email) {
            $rowValidator = ValidatorFacade::make(['email' => $email], [
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->whereNull('deleted_at')],
            ]);

            if ($rowValidator->fails()) {
                $failed[] = ['email' => $email, 'reason' => $rowValidator->errors()->first('email')];
                continue;
            }

            $eligibleEmails[] = $email;
        }

        // Zero-eligible must never consume quota or return 429 — a fully
        // invalid/already-existing batch is a no-op for real invitation
        // volume, so the existing partial-success response is returned
        // exactly as before this fix.
        if (count($eligibleEmails) === 0) {
            return response()->json([
                'message' => '0 of ' . count($emails) . ' invitation(s) sent.',
                'data'    => ['invited' => [], 'failed' => $failed],
            ], 201);
        }

        $reservation = $this->reserveBulkInviteRecipients($request->user()->id, count($eligibleEmails));

        if (!$reservation['accepted']) {
            $headers = $reservation['retry_after'] !== null
                ? ['Retry-After' => $reservation['retry_after']]
                : [];

            return response()->json([
                'message' => 'Too many invitations have been sent recently. Please try again later.',
            ], 429, $headers);
        }

        // ── PASS 2 — unchanged invitation pipeline ──────────────────────
        // Every email here already passed Pass 1's validation; no
        // re-validation, no double-counting.
        $invited = [];
        foreach ($eligibleEmails as $email) {
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
     * P2 Security Remediation (Bulk Invite Email-Volume Abuse) — atomically
     * admits or rejects a batch of $eligibleCount recipients against three
     * independent limiter dimensions (operator hour, operator day, platform
     * hour), via one short global Cache::lock() around the whole read →
     * decide → increment sequence. Deliberately NOT an increment-then-
     * rollback design — see project-context.md's Bulk Invite entry for the
     * full reasoning: Illuminate\Cache\RateLimiter::decrement() recreates
     * an expired counter key via the same Cache::add() calls increment()
     * uses, so decrementing after a window has already rolled over would
     * start the NEW window at a negative count, briefly granting it extra
     * headroom — a real, if narrow and self-healing, correctness gap this
     * lock-serialized design avoids entirely by never incrementing
     * anything that isn't going to be kept.
     *
     * The lock protects ONLY these six cache operations (3 reads, up to 3
     * writes) — never any User/database/queue/email work, which all
     * happens after this method returns and the lock has already been
     * released.
     *
     * @return array{accepted: bool, retry_after: ?int}
     */
    /**
     * Reads an env-backed recipient-limit config value as a positive
     * integer, falling back to the given secure default whenever the
     * configured value is not a positive integer — never a large
     * configuration-validation system, just the smallest safe
     * normalization against a typo'd/malformed env value.
     */
    private function positiveIntConfig(string $key, int $secureDefault): int
    {
        $value = (int) config($key);

        return $value > 0 ? $value : $secureDefault;
    }

    private function reserveBulkInviteRecipients(int $operatorId, int $eligibleCount): array
    {
        $operatorHourKey  = "bulk-invite-recipients:operator:{$operatorId}:hour";
        $operatorDayKey   = "bulk-invite-recipients:operator:{$operatorId}:day";
        $platformHourKey  = 'bulk-invite-recipients:platform:hour';

        // A malformed/zero/negative configured value would only ever bias
        // this admission check toward rejecting MORE aggressively (never
        // toward disabling it — see reserveBulkInviteRecipients()'s own
        // $wouldExceed check below), so this is already safe from a pure
        // security standpoint. Still fall back to the documented secure
        // default rather than a broken value, purely to avoid a confusing
        // "bulk invite silently rejects everything forever" operational
        // trap from a simple env-var typo.
        $operatorHourLimit = $this->positiveIntConfig('suresign.invitation.bulk_invite_operator_hourly_recipients', 500);
        $operatorDayLimit  = $this->positiveIntConfig('suresign.invitation.bulk_invite_operator_daily_recipients', 2000);
        $platformHourLimit = $this->positiveIntConfig('suresign.invitation.bulk_invite_platform_hourly_recipients', 1500);

        try {
            // block()'s callback form acquires and releases the lock via
            // its own try/finally — the lock is never left held on any
            // exit path out of the closure below.
            return Cache::lock('bulk-invite-recipients:reservation-lock', 10)->block(3, function () use (
                $operatorHourKey, $operatorDayKey, $platformHourKey,
                $operatorHourLimit, $operatorDayLimit, $platformHourLimit,
                $eligibleCount,
            ) {
                $operatorHourAttempts = RateLimiter::attempts($operatorHourKey);
                $operatorDayAttempts  = RateLimiter::attempts($operatorDayKey);
                $platformHourAttempts = RateLimiter::attempts($platformHourKey);

                $wouldExceed = ($operatorHourAttempts + $eligibleCount > $operatorHourLimit)
                    || ($operatorDayAttempts + $eligibleCount > $operatorDayLimit)
                    || ($platformHourAttempts + $eligibleCount > $platformHourLimit);

                if ($wouldExceed) {
                    // Increment NOTHING — no rollback is ever needed
                    // because nothing was ever incremented for a rejected
                    // batch. Retry-After is taken from whichever dimension
                    // is currently furthest from resetting, a safe
                    // over-estimate that never tells the caller anything
                    // about which specific dimension fired.
                    //
                    // availableIn() correctly returns 0 when a dimension's
                    // window timer was never established at all (this
                    // operator's very first attempt already exceeding a
                    // limit on its own) — since all three dimensions are
                    // only ever incremented together, either all three
                    // timers exist or none do. Fall back to a full hour
                    // in that case rather than omitting Retry-After
                    // entirely; a client retrying then will simply be
                    // re-evaluated correctly either way.
                    $retryAfter = max(
                        RateLimiter::availableIn($operatorHourKey),
                        RateLimiter::availableIn($operatorDayKey),
                        RateLimiter::availableIn($platformHourKey),
                    );

                    return ['accepted' => false, 'retry_after' => $retryAfter > 0 ? $retryAfter : 3600];
                }

                // All three fit — increment all three, back-to-back, no
                // I/O between them.
                RateLimiter::increment($operatorHourKey, 3600, $eligibleCount);
                RateLimiter::increment($operatorDayKey, 86400, $eligibleCount);
                RateLimiter::increment($platformHourKey, 3600, $eligibleCount);

                return ['accepted' => true, 'retry_after' => null];
            });
        } catch (LockTimeoutException) {
            // Lock contention itself exceeded the bounded wait — fail
            // closed exactly like an exhausted counter, with the same
            // generic response. Never bypass recipient limits just
            // because the lock store is momentarily busy.
            return ['accepted' => false, 'retry_after' => 3];
        }
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

    /**
     * Bulk Remove — same per-user removal path as destroy() above, just fed
     * from a list of ids instead of one route id, for the Users page's
     * multi-select "Remove Selected" action (e.g. clearing out a batch of
     * test/invited accounts without removing them one at a time). Mirrors
     * bulkInvite()'s partial-success shape: a bad id in the batch never
     * aborts the rest — each row is checked independently and the response
     * reports exactly which were removed and which weren't, with a reason.
     * A soft-delete (same as destroy()) — recoverable via restore(), not a
     * permanent purge.
     */
    public function bulkRemove(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1|max:100',
            'ids.*' => 'integer',
        ]);

        // Dedupe within the batch — a pasted/checkbox-driven id list
        // shouldn't ever repeat, but stay defensive regardless.
        $ids = array_values(array_unique($validated['ids']));

        $removed = [];
        $failed = [];

        foreach ($ids as $id) {
            if ((int) $id === Auth::id()) {
                $failed[] = ['id' => $id, 'reason' => 'You cannot remove your own account.'];
                continue;
            }

            $user = User::find($id);

            if (! $user) {
                $failed[] = ['id' => $id, 'reason' => 'User not found.'];
                continue;
            }

            // Re-checked per row (not just once up front) — removing one
            // Super Admin in this same batch can make the next one in the
            // list newly "the last" active Super Admin.
            if ($this->isLastActiveSuperAdmin($user)) {
                $failed[] = ['id' => $id, 'email' => $user->email, 'reason' => 'Cannot remove the last Super Admin.'];
                continue;
            }

            $user->delete();

            ActivityLog::record('user.removed', "Removed {$user->email}", Auth::user(), $user);

            $removed[] = ['id' => $user->id, 'email' => $user->email];
        }

        return response()->json([
            'message' => count($removed) . ' of ' . count($ids) . ' user(s) removed.',
            'data'    => [
                'removed' => $removed,
                'failed'  => $failed,
            ],
        ]);
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
            // Consistency with every other password-write path in this
            // codebase: rejecting a value identical to the account's
            // current password applies here too — there's no established
            // reason an admin-set password should be exempt from a check
            // every other flow enforces.
            'password'       => array_merge(
                ['required'],
                SureSignPasswordPolicy::rules(),
                [new DiffersFromCurrentPassword($user)],
            ),
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

        PasswordSecurityNotifier::notifyAdminChanged($user);

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
     * Unified Password Security Hardening — replaced this method's own
     * inline generator (guaranteed character-category slots + a
     * non-cryptographic `shuffle()`, both leftovers from the old
     * composition-rule policy this phase removes) with
     * `SureSignPasswordPolicy::generateTemporarySecret()`, the one
     * authoritative generator for every internal temp-secret need in this
     * codebase. See that method's own docblock for why this value is
     * deliberately NOT run through the same HIBP-checking policy real
     * user-chosen passwords use.
     */
    private function generateTempPassword(): string
    {
        return SureSignPasswordPolicy::generateTemporarySecret();
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
