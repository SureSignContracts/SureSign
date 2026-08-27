<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Admin\AdminAccessService;
use App\Rules\DiffersFromCurrentPassword;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Services\InvitationService;
use App\Services\Intelligence\SubscriptionIntelligenceService;
use App\Support\Auth\PasswordSecurityNotifier;
use App\Support\Auth\SuperAdminGuard;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // Users Module Super Admin Exclusion (2026-08-27) — a Super Admin
        // account is entirely outside the ordinary Admin user-management
        // surface, not merely non-mutable. An Admin (reaching this via
        // admin.module.users) never sees a Super Admin row in this list
        // at all; only a genuine Super Admin actor does.
        if (! $request->user()->hasRole('Super Admin')) {
            $query->whereDoesntHave('roles', fn($q) => $q->where('name', 'Super Admin'));
        }

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
        // Users Module Super Admin Exclusion (2026-08-27) — an Admin can
        // never fetch a Super Admin's (even minimal) detail here either.
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);

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

        // Full Parity Access Expansion — an Admin (even with
        // admin.module.users) can never create a new Super Admin account.
        SuperAdminGuard::assertActorMayAssignRole(Auth::user(), $validated['role']);

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

        // Full Parity Access Expansion — one shared role for the whole
        // batch, so this is checked once, the same as invite().
        SuperAdminGuard::assertActorMayAssignRole(Auth::user(), $validated['role']);

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

        // Super Admin Configurable Admin Access — a fresh or restored
        // invite that assigns the Admin role always starts with the
        // DEFAULT admin.module.* baseline (never "zero rows", and never
        // the full 23-key catalogue either — see
        // AdminAccessService::grantDefaultAccess()'s own docblock for why
        // the eight formerly-permanently-Super-Admin-only modules require
        // an explicit Super Admin grant regardless). This is the ONE
        // place a brand-new Admin identity is created via invite, so it's
        // also the one place that baseline needs to be granted — see that
        // same docblock for why this must never run on an ordinary Admin
        // profile edit instead.
        if ($role === 'Admin') {
            AdminAccessService::grantDefaultAccess($user);
        }

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
        // Users Module Super Admin Exclusion (2026-08-27) — an Admin can
        // never fetch a Super Admin's detail, same generic 403 as every
        // other guarded action in this controller — never a distinct
        // message that would confirm the target's role.
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
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

        // Full Parity Access Expansion — an Admin (even with
        // admin.module.users) can never mutate an existing Super Admin
        // account in any way, and can never promote anyone TO Super Admin.
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
        if (isset($validated['role'])) {
            SuperAdminGuard::assertActorMayAssignRole(Auth::user(), $validated['role']);
        }

        // Last Active Super Admin Concurrency Hardening — only THIS
        // request's own intended change (deactivating, or changing role
        // away from Super Admin) is a reduction worth locking for; a plain
        // name edit on a Super Admin never takes the lock at all, per
        // SuperAdminGuard::guardedMutate()'s own "no-op for a non-reducing
        // case" contract. Precedence matches the original sequential
        // checks this replaces: a deactivation attempt is reported as such
        // even if a role change was also requested in the same call.
        $deactivatesSuperAdmin = isset($validated['is_active']) && ! $validated['is_active'];
        $changesRoleAwayFromSuperAdmin = isset($validated['role']) && $validated['role'] !== 'Super Admin';
        $wouldReduceSuperAdminCount = $user->hasRole('Super Admin') && ($deactivatesSuperAdmin || $changesRoleAwayFromSuperAdmin);

        $applyUpdate = function (User $target) use ($validated) {
            $before = $target->only(['name', 'is_active']);

            if (isset($validated['name']))      $target->name      = $validated['name'];
            if (isset($validated['is_active'])) $target->is_active = $validated['is_active'];
            $target->save();

            if (isset($validated['role'])) {
                $beforeRoles = $target->roles->pluck('name')->all();
                $wasAdmin = in_array('Admin', $beforeRoles, true);
                $target->syncRoles([]);
                $role = Role::firstOrCreate(['name' => $validated['role'], 'guard_name' => 'web']);
                $target->assignRole($role);

                // Super Admin Configurable Admin Access — role-change
                // lifecycle (see AdminAccessService's own docblock).
                // Deliberately keyed on the TRANSITION, not merely "role is
                // now Admin": re-submitting the SAME role ('Admin' -> 'Admin')
                // must never reset a Super Admin's prior restriction back to
                // full access, and a genuine transition away from Admin
                // must never leave dormant admin.module.* grants on a
                // Client/Super Admin account.
                if ($validated['role'] === 'Admin' && ! $wasAdmin) {
                    AdminAccessService::grantDefaultAccess($target);
                } elseif ($validated['role'] !== 'Admin' && $wasAdmin) {
                    AdminAccessService::removeManagedAccess($target);
                }

                ActivityLog::record(
                    'user.role_changed',
                    "Changed {$target->email}'s role from " . (implode(', ', $beforeRoles) ?: 'none') . " to {$validated['role']}",
                    Auth::user(),
                    $target,
                    ['from' => $beforeRoles, 'to' => $validated['role']],
                );
            }

            if (isset($validated['is_active']) && $before['is_active'] !== $target->is_active) {
                ActivityLog::record(
                    $target->is_active ? 'user.activated' : 'user.deactivated',
                    ($target->is_active ? 'Activated ' : 'Deactivated ') . $target->email,
                    Auth::user(),
                    $target,
                );

                // Only revoke on the true -> false transition, not merely
                // because is_active was present in the request (e.g.
                // re-submitting the same value, or reactivating someone) —
                // reactivation must not hand back a working session
                // either; a fresh login is required (see
                // UserController::unban for the same rule on bans).
                if ($before['is_active'] === true && $target->is_active === false) {
                    $target->tokens()->delete();

                    ActivityLog::record(
                        'user.tokens_revoked',
                        "Revoked all active session(s) for {$target->email} due to deactivation",
                        Auth::user(),
                        $target,
                    );
                }
            }

            return $target;
        };

        if ($wouldReduceSuperAdminCount) {
            $outcome = SuperAdminGuard::guardedMutate($user, $applyUpdate);

            if ($outcome['blocked']) {
                $message = $deactivatesSuperAdmin
                    ? 'Cannot deactivate the last Super Admin.'
                    : 'Cannot change the role of the last Super Admin.';

                return response()->json(['message' => $message], 422);
            }

            $user = $outcome['result'];
        } else {
            $user = $applyUpdate($user);
        }

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function destroy(string $id)
    {
        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove your own account.'], 422);
        }

        $user = User::findOrFail($id);

        // Full Parity Access Expansion — an Admin can never remove an
        // existing Super Admin account.
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);

        // Last Active Super Admin Concurrency Hardening — see
        // SuperAdminGuard::guardedMutate()'s own docblock. A no-op lock for
        // any non-Super-Admin target (the overwhelmingly common case);
        // for a Super Admin target, the last-active decision and the
        // removal itself now happen inside the same shared lock, so a
        // concurrent operation reducing a DIFFERENT Super Admin's active
        // status can never be decided against simultaneously.
        $outcome = SuperAdminGuard::guardedMutate($user, function (User $target) {
            // Remove & Detach Concurrency Hardening — a Client's own
            // removal must be serialized on their organisation's row (see
            // withOrganizationLock()'s docblock) so a concurrent
            // removeAndDetach() request for a DIFFERENT Client in the SAME
            // organisation can never decide "another Client still exists"
            // against a stale, pre-commit view. This does not change
            // Remove User's own behaviour or add any confirmation step
            // here — a plain Client/Admin/Super Admin removal never itself
            // requires last-Client confirmation (see that method's own
            // docblock for the exact, narrower invariant this protects).
            $this->removeUserWithinOrganizationLock($target);

            return $target;
        });

        if ($outcome['blocked']) {
            return response()->json(['message' => 'Cannot remove the last Super Admin.'], 422);
        }

        $user = $outcome['result'];
        ActivityLog::record('user.removed', "Removed {$user->email}", Auth::user(), $user);

        return response()->json(['message' => 'User removed.']);
    }

    /**
     * Remove & Detach Concurrency Hardening — the token-revocation +
     * soft-delete pair every "Remove User" call site performs (destroy(),
     * bulkRemove()'s per-row loop), wrapped in the same short
     * organisation-row lock removeAndDetach() uses. Never changes Remove
     * User's own behaviour (still: revoke tokens, soft-delete, preserve
     * organization_id, reversible via restore) — the lock exists purely so
     * a concurrent removeAndDetach() for a sibling Client in the same
     * organisation observes this removal's committed effect rather than a
     * stale pre-commit read. A user with no organisation (organization_id
     * null — Admin/Super Admin, or an already pre-onboarding Client) has
     * nothing to serialize against, so no lock is taken at all in that
     * case — identical to today's unlocked behaviour.
     */
    private function removeUserWithinOrganizationLock(User $user): void
    {
        $organizationId = $user->organization_id;

        if ($organizationId === null) {
            $user->tokens()->delete();
            $user->delete();

            return;
        }

        $this->withOrganizationLock($organizationId, function () use ($user) {
            // User Removal Token Revocation hardening — revoke BEFORE
            // soft-delete, not after. SoftDeletes alone is not an
            // authentication boundary: a still-valid Sanctum token merely
            // fails to resolve a soft-deleted user (Sanctum's tokenable
            // lookup respects the model's own SoftDeletingScope), but the
            // exact same token becomes valid again the moment the row is
            // restored (e.g. via a later re-invite of the same email — see
            // UserController::inviteOneUser()). Revoking here, same
            // pattern as ban()/forcePasswordReset()/setPassword() below,
            // makes removal durable regardless of any future restore.
            // Revoking first means a failure on the delete() call below
            // leaves the user active-but-signed-out (recoverable) rather
            // than the unsafe opposite — removed, with old tokens quietly
            // left live for restore. Both writes happen inside the same
            // transaction as the organisation lock, so a failure here
            // never leaves the row soft-deleted with its tokens still live.
            $user->tokens()->delete();
            $user->delete();
        });
    }

    /**
     * Remove & Detach Concurrency Hardening — the single serialization
     * primitive every Client-removal decision for a given organisation
     * goes through: destroy(), bulkRemove()'s per-row loop, and
     * removeAndDetach()'s last-Client check + mutation. `SELECT ... FOR
     * UPDATE` on the Organisation row inside a short transaction means two
     * concurrent removal operations for the SAME organisation are
     * strictly ordered — the second can only acquire the lock after the
     * first's transaction has committed, so it always recomputes
     * "remaining Client users" against the first operation's committed
     * result, never a stale pre-commit snapshot. A different
     * organisation's row is never touched, locked, or blocked — this is
     * per-organisation serialization, not a global one.
     *
     * Deliberately narrow in scope: only holds the lock for the DB
     * writes the callback performs. Never call this around email/provider
     * calls or any other external I/O.
     */
    private function withOrganizationLock(int $organizationId, callable $callback): mixed
    {
        // Delegates to the shared, authoritative implementation — see
        // OrganizationRemovalLock's own docblock. Kept as a thin wrapper so
        // every existing call site in this controller is unaffected.
        return \App\Support\Users\OrganizationRemovalLock::run($organizationId, $callback);
    }

    /**
     * Two User Removal Modes — "Remove & Detach". A deliberately SEPARATE
     * action from destroy() above, not a hidden mode flag on it (per the
     * approved product decision): destroy() ("Remove User") preserves
     * organization_id so a later re-invite restores the same person to the
     * same organisation; this action additionally clears organization_id
     * first, so a later re-invite instead re-enters the ordinary
     * pre-onboarding null-org lifecycle (see InvitedUserOrganisationLifecycleTest)
     * and the previous organisation is never silently restored.
     *
     * Never touches the Organisation row itself, or any Project/Contract/
     * Document/Branding/Billing/AI-usage record — those are all scoped by
     * organization_id, not by any reference to this user, so clearing this
     * one column changes nothing else. Organisation deletion does not exist
     * in this codebase and is explicitly out of scope here.
     *
     * Only meaningful for a Client currently attached to an organisation —
     * see eligibility check below. Bulk removal deliberately still only
     * offers "Remove User" (destroy()/bulkRemove()) — bulk detachment can
     * span multiple organisations and its own last-Client/orphan-organisation
     * confirmation UX is deferred, not required to ship these two per-user
     * modes safely.
     */
    public function removeAndDetach(Request $request, string $id)
    {
        if ((int) $id === Auth::id()) {
            return response()->json(['message' => 'You cannot remove your own account.'], 422);
        }

        $user = User::findOrFail($id);

        // Eligibility — the smallest correct rule: Remove & Detach only
        // makes semantic sense for a Client who currently has an
        // organisation to be detached from. Super Admin/Admin (platform
        // operators with no organisation of their own) and a Client already
        // at organization_id = null (nothing to detach) are all rejected
        // here with one generic, non-leaking message — never naming a role
        // or organisation, matching this codebase's existing
        // authorize()/abort() convention.
        if (! $user->hasRole('Client') || $user->organization_id === null) {
            return response()->json([
                'message' => 'Remove & Detach only applies to a Client user who is currently associated with an organisation.',
            ], 422);
        }

        $organizationId = $user->organization_id;

        // Remove & Detach Concurrency Hardening — the last-Client check AND
        // the mutation it gates now live INSIDE the same organisation-row
        // lock (withOrganizationLock()), closing the race the earlier,
        // unlocked version had: two concurrent detach requests for two
        // different Clients in the same organisation could previously both
        // read "another Client still exists" before either committed, so
        // neither was ever asked for confirm_last_client even though the
        // organisation ended up with zero. Locking here does not add any
        // new confirmation requirement to Remove User (destroy()/
        // bulkRemove()) — only Remove & Detach's own decision must be this
        // fresh; see removeUserWithinOrganizationLock()'s docblock for the
        // narrower reason a plain removal still takes the same lock.
        //
        // Re-fetches the target INSIDE the lock rather than trusting the
        // $user instance captured above — a concurrent operation holding
        // this same lock immediately before us (a normal remove of this
        // exact user, or another detach of them) may have already changed
        // it, and this decision must never be made against a stale copy.
        $result = $this->withOrganizationLock($organizationId, function () use ($request, $id) {
            $fresh = User::find($id);

            if (! $fresh || ! $fresh->hasRole('Client') || $fresh->organization_id === null) {
                return [
                    'status' => 422,
                    'body'   => ['message' => 'Remove & Detach only applies to a Client user who is currently associated with an organisation.'],
                ];
            }

            $organization = $fresh->organization;
            $previousOrganizationId = $organization->id;
            $previousOrganizationName = $organization->name;

            // Last-Client guard — an organisation with zero remaining
            // Client users is technically safe (its Organisation/Projects/
            // Contracts/Documents/Billing all remain intact and Super
            // Admin/Admin retain full access) but operationally awkward
            // enough that it must never happen silently. Counts only real,
            // currently-attached Client users of THIS organisation,
            // excluding the target themselves and (via the default
            // Eloquent query, since no ::withTrashed() is used) any
            // already-soft-deleted row. Safe to evaluate here because
            // this whole closure runs while holding this organisation's
            // row lock — no concurrent removal for this same organisation
            // can be mid-flight.
            $isLastClient = ! User::role('Client')
                ->where('organization_id', $previousOrganizationId)
                ->where('id', '!=', $fresh->id)
                ->exists();

            if ($isLastClient && ! $request->boolean('confirm_last_client')) {
                return [
                    'status' => 409,
                    'body'   => [
                        'message' => 'This is the last Client user attached to this organisation. The organisation, its projects, billing and data will remain intact, but no Client user will be able to access it after this action.',
                        'code'    => 'LAST_CLIENT_DETACH_REQUIRES_CONFIRMATION',
                    ],
                ];
            }

            // Same ordering rationale as destroy()'s token revocation —
            // revoke first, so a failure on the write below leaves the
            // user active-but-signed-out (recoverable), never
            // removed-with-old-tokens still live for a future restore.
            // Both writes are inside the same transaction as the
            // organisation lock, so a failure here can never leave the row
            // soft-deleted/detached with its tokens still live.
            $fresh->tokens()->delete();
            $fresh->organization_id = null;
            $fresh->save();
            $fresh->delete();

            return [
                'status' => 200,
                'body'   => ['message' => 'User removed and detached from their organisation.'],
                'meta'   => [
                    'email'                      => $fresh->email,
                    'previous_organization_id'   => $previousOrganizationId,
                    'previous_organization_name' => $previousOrganizationName,
                ],
            ];
        });

        if ($result['status'] === 200) {
            ActivityLog::record(
                'user.removed_and_detached',
                "Removed {$result['meta']['email']} and detached them from their organisation",
                Auth::user(),
                $user,
                [
                    'previous_organization_id'   => $result['meta']['previous_organization_id'],
                    'previous_organization_name' => $result['meta']['previous_organization_name'],
                ],
            );
        }

        return response()->json($result['body'], $result['status']);
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

            // Full Parity Access Expansion — an Admin can never remove an
            // existing Super Admin account. Reported as a per-row failure
            // (never a hard abort) to preserve this method's own
            // partial-success contract — a Super Admin target in the
            // batch must not prevent every other row from being processed.
            if (! SuperAdminGuard::actorMayActOnTarget(Auth::user(), $user)) {
                $failed[] = ['id' => $id, 'email' => $user->email, 'reason' => 'Access denied.'];
                continue;
            }

            // Last Active Super Admin Concurrency Hardening — re-evaluated
            // per row (not just once up front), same reason the comment
            // above always gave, now made authoritative under concurrency
            // too: removing one Super Admin in this same batch (or in a
            // concurrent request entirely) can make the next row newly
            // "the last" active Super Admin. Each row acquires/releases
            // its OWN short Super-Admin-role-row lock independently — see
            // SuperAdminGuard::guardedMutate()'s docblock — never held
            // across the whole batch.
            $outcome = SuperAdminGuard::guardedMutate($user, function (User $target) {
                // Remove & Detach Concurrency Hardening — same per-row
                // organisation-row lock destroy() takes, scoped to only
                // THIS row's own short transaction (see
                // removeUserWithinOrganizationLock()'s docblock). Each row
                // acquires, mutates, and releases its own organisation's
                // lock independently — never holds more than one
                // organisation's lock at a time, so a batch spanning
                // several organisations never contends across them, and
                // partial-success semantics (a later row's failure never
                // undoes an earlier row's already-committed removal) are
                // unchanged. A row rejected above (not found / self) never
                // reaches this line, so its tokens are never touched.
                $this->removeUserWithinOrganizationLock($target);

                return $target;
            });

            if ($outcome['blocked']) {
                $failed[] = ['id' => $id, 'email' => $user->email, 'reason' => 'Cannot remove the last Super Admin.'];
                continue;
            }

            $user = $outcome['result'];
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
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
        $user->update(['email_verified_at' => now()]);

        ActivityLog::record('user.email_verified', "Marked {$user->email} as verified", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function unverifyEmail(string $id)
    {
        $user = User::findOrFail($id);
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
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
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);

        $validated = $request->validate(['reason' => 'required|string|max:500']);

        // Last Active Super Admin Concurrency Hardening — see
        // SuperAdminGuard::guardedMutate()'s docblock.
        $outcome = SuperAdminGuard::guardedMutate($user, function (User $target) use ($validated) {
            $target->update(['banned_at' => now(), 'banned_reason' => $validated['reason']]);
            $target->tokens()->delete();

            return $target;
        });

        if ($outcome['blocked']) {
            return response()->json(['message' => 'Cannot ban the last Super Admin.'], 422);
        }

        $user = $outcome['result'];
        ActivityLog::record('user.banned', "Banned {$user->email}", Auth::user(), $user, ['reason' => $validated['reason']]);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    public function unban(string $id)
    {
        $user = User::findOrFail($id);
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
        $user->update(['banned_at' => null, 'banned_reason' => null]);

        ActivityLog::record('user.unbanned', "Unbanned {$user->email}", Auth::user(), $user);

        return response()->json(['data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Password controls ────────────────────────────────────────────────

    public function forcePasswordReset(string $id)
    {
        $user = User::findOrFail($id);
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
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
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);

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
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        ActivityLog::record('user.tokens_revoked', "Revoked {$count} active session(s) for {$user->email}", Auth::user(), $user, ['count' => $count]);

        return response()->json(['message' => "Revoked {$count} active session(s).", 'data' => $this->formatUser($user->fresh('roles'))]);
    }

    // ── Guided tours ─────────────────────────────────────────────────────

    public function resetTours(string $id)
    {
        $user = User::findOrFail($id);
        SuperAdminGuard::assertActorMayActOnTarget(Auth::user(), $user);
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
