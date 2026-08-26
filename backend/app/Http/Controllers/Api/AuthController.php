<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteAccountRequest;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\DiffersFromCurrentPassword;
use App\Services\CurrencyService;
use App\Services\EmailVerificationService;
use App\Services\NotificationService;
use App\Services\Organizations\AuthenticatedWorkspaceContextService;
use App\Services\TimezoneResolver;
use App\Support\Auth\PasswordSecurityNotifier;
use App\Support\Auth\SureSignPasswordPolicy;
use App\Support\Users\OrganizationRemovalLock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('organization.branding')
            ->where('email', $request->email)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // Deliberately identical wording/status regardless of whether the
            // email exists — never reveal which of the two was wrong (see
            // AuthRateLimitingTest::test_login_response_does_not_reveal_whether_the_email_exists).
            return response()->json(['message' => 'The email or password is incorrect.'], 401);
        }

        // Same wording/code as EnsureAccountIsActive's mid-session check
        // (below the frontend's account_unavailable interceptor handling in
        // lib/api.ts) — a deactivated/banned account is the same customer
        // situation whether it's caught here (before a token is even issued)
        // or on a later request against an already-issued token; the two
        // paths previously used different, untagged wording for the same
        // state (see internal-docs/error-messaging-recovery-ux-audit.md §7
        // P2-1). Deliberately does not distinguish deactivated vs. banned in
        // the customer-facing message — same reasoning as not revealing
        // which credential was wrong above.
        if (! $user->is_active || $user->isBanned()) {
            return response()->json([
                'message' => 'Your account is not currently permitted to access the platform.',
                'code'    => 'account_unavailable',
            ], 403);
        }

        // Captured before the update below overwrites it — this is what
        // makes the notification below fire exactly once, on the genuinely
        // first login, never on every subsequent one.
        $isFirstLogin = $user->last_login_at === null;

        $user->update(['last_login_at' => now()]);

        if ($isFirstLogin) {
            $this->notifyPlatformOperatorsOfInvitedUserFirstLogin($user);
        }

        $token = $user->createToken('suresign-token')->plainTextToken;

        AuditLog::create([
            'user_id'         => $user->id,
            'organization_id' => $user->organization_id,
            'event'           => 'login',
            'ip_address'      => $request->ip(),
            'user_agent'      => $request->userAgent(),
            'created_at'      => now(),
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $this->userResource($user),
        ]);
    }

    /**
     * Notifies every Super Admin/Admin the first time an admin-invited user
     * actually logs in — never for a self-registered/onboarded account, and
     * never on that same user's second-or-later login (see the
     * `$isFirstLogin` guard at the call site). "Invited" here means this
     * exact account was created via UserController::invite()/bulkInvite()
     * — checked via the 'user.invited' ActivityLog entry those methods
     * record on the account, not by role or organisation membership, since
     * an invited account can hold any of the allowed roles. Best-effort,
     * never fatal — this must never turn an otherwise-successful login into
     * an error.
     */
    private function notifyPlatformOperatorsOfInvitedUserFirstLogin(User $user): void
    {
        try {
            $wasInvited = ActivityLog::where('action', 'user.invited')
                ->where('subject_type', User::class)
                ->where('subject_id', $user->id)
                ->exists();

            if (!$wasInvited) {
                return;
            }

            NotificationService::sendToPlatformOperators(
                NotificationService::INVITED_USER_FIRST_LOGIN,
                'Invited user logged in for the first time',
                "{$user->name} ({$user->email}) accepted their invitation and just logged in for the first time.",
                ['user_id' => $user->id],
                ['action_url' => '/admin/users']
            );
        } catch (\Throwable $e) {
            // Must never turn an otherwise-successful login into an error —
            // same discipline as NotificationService::sendToPlatformOperators()
            // itself, extended to cover this method's own ActivityLog lookup.
            \Illuminate\Support\Facades\Log::warning(
                "AuthController::notifyPlatformOperatorsOfInvitedUserFirstLogin: exception for user {$user->id}: " . $e->getMessage()
            );
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('organization.branding');
        return response()->json($this->userResource($user));
    }

    /**
     * Organisation URL Branding, Phase 5 (Stage 3) — the entirely
     * server-side wrong-workspace decision. `X-Suresign-Org-Host`
     * (same header convention as `EnforcesPublicOrganizationHost`) is the
     * hostname the frontend is actually being viewed on — never trusted
     * from this request's own Host header, which is always the fixed API
     * host regardless of which frontend origin called it. Absent header
     * is treated identically to the fixed app host (a safe default, not
     * an error) — see AuthenticatedWorkspaceContextService's own
     * docblock for the full state machine.
     */
    public function workspaceContext(Request $request, AuthenticatedWorkspaceContextService $service)
    {
        $requestedHost = $request->header('X-Suresign-Org-Host');

        return response()->json(
            $service->resolve($request->user(), $requestedHost)
        );
    }

    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|current_password',
            'password'         => array_merge(
                ['required', 'confirmed'],
                SureSignPasswordPolicy::rules(),
                [new DiffersFromCurrentPassword($user)],
            ),
        ]);

        $currentTokenId = $user->currentAccessToken()?->id;

        $user->update(['password' => Hash::make($request->password)]);

        // Policy: revoke every other session, keep the one actively making
        // this change alive — a stolen/old token elsewhere is logged out
        // immediately, without forcing the user to re-authenticate on the
        // device they just proved they control (they supplied
        // current_password moments ago). Mirrors the same choice made for
        // forcePasswordChange() below.
        self::revokeOtherTokens($user, $currentTokenId);

        // Non-essential, safe-by-construction (ActivityLog::record() never
        // throws) and never allowed to make an already-successful password
        // write look like a failure — see PasswordSecurityNotifier's own
        // docblock for the identical guarantee on the email side.
        ActivityLog::record('user.password_changed', "{$user->email} changed their own password", $user, $user);
        PasswordSecurityNotifier::notifyChanged($user);

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * Self-Service Account Deletion — POST /auth/delete-account. Always
     * targets $request->user() — never a client-supplied id, email,
     * organization_id, or role.
     *
     * CLIENT ONLY, by explicit product decision — Admin and Super Admin
     * are platform operators, not customers, and must never be able to
     * close their own account through this endpoint, enforced server-side
     * (see the role check immediately below) rather than left to the
     * Settings UI simply never showing the action for them. Because a
     * Super Admin can never reach the mutation here, this method never
     * touches the Last Active Super Admin invariant/lock at all — that
     * invariant is fully owned by UserController's admin-management
     * mutators (destroy()/bulkRemove()/ban()/update()) via
     * SuperAdminGuard::guardedMutate().
     *
     * Deliberately NOT the same operation as UserController::destroy()
     * ("Remove User", identity preserved, organisation preserved, fully
     * restorable via re-invite) or ::removeAndDetach() ("Remove & Detach",
     * identity preserved, organisation cleared, restorable as a null-org
     * account): self-delete is a permanent identity closure. See the
     * Self-Service Account Deletion audit + implementation notes —
     * anonymising the row's email is what makes a later invite of the same
     * real address create a genuinely NEW User row (inviteOneUser()'s
     * onlyTrashed()->where('email', $email) match can never find this
     * tombstoned row again), without needing any change to that method.
     *
     * Never hard-deletes — the Phase 0 audit found many historical
     * users.id foreign keys are RESTRICT (would block a real delete
     * outright) and several tenant-record tables CASCADE on created_by
     * (would silently destroy real Snag/QA/Closeout/Adjudication rows).
     * Soft-delete + anonymisation is what keeps every existing
     * created_by/assigned_to/ActivityLog reference intact while still
     * genuinely closing the account.
     */
    public function deleteAccount(DeleteAccountRequest $request)
    {
        $user = $request->user();

        // Self-Service Account Deletion is Client-only, by explicit
        // product decision — Admin/Super Admin are platform operators and
        // must never be able to self-delete, enforced here (not merely by
        // the Settings UI never showing the action for them) so a direct
        // API call is rejected identically. Checked before any lock/
        // transaction/mutation, zero mutation on rejection. This is also
        // why this method never consults SuperAdminGuard/participates in
        // the Last Active Super Admin Concurrency Hardening lock at all —
        // a Super Admin can never reach this far, so there is no Super
        // Admin count for this endpoint to ever reduce.
        if (! $user->hasRole('Client')) {
            return response()->json([
                'message' => 'Self-service account deletion is only available to Client accounts.',
            ], 403);
        }

        if ($user->organization_id !== null) {
            // Sole-Client guard — same organisation-row lock
            // (OrganizationRemovalLock, extracted from UserController's own
            // Remove & Detach Concurrency Hardening) so this decision is
            // never made against a stale, pre-commit view of who else is a
            // non-deleted Client in this organisation. Re-fetches the
            // target inside the lock rather than trusting the $user
            // instance captured above.
            $result = OrganizationRemovalLock::run($user->organization_id, function () use ($request, $user) {
                $fresh = User::find($user->id);

                if (! $fresh) {
                    return ['status' => 404, 'body' => ['message' => 'Account not found.']];
                }

                $organizationId = $fresh->organization_id;

                $isLastClient = $organizationId !== null && ! User::role('Client')
                    ->where('organization_id', $organizationId)
                    ->where('id', '!=', $fresh->id)
                    ->exists();

                if ($isLastClient && ! $request->boolean('confirm_last_client')) {
                    return [
                        'status' => 409,
                        'body'   => [
                            'message' => 'You are the last Client user attached to this organisation. Deleting your account will leave the organisation with no Client users who can log in. The organisation, its projects, billing and data will remain intact.',
                            'code'    => 'LAST_CLIENT_ACCOUNT_DELETE_REQUIRES_CONFIRMATION',
                        ],
                    ];
                }

                $meta = ['role_at_deletion' => 'Client', 'was_last_client' => $isLastClient, 'organization_id' => $organizationId];
                $this->anonymiseAndDeleteAccount($fresh);

                return ['status' => 200, 'body' => ['message' => 'Your account has been deleted.'], 'meta' => $meta];
            });
        } else {
            // A Client with no organisation yet (pre-onboarding) — no
            // sole-Client concern applies since they are not counted as a
            // member of any organisation, but the mutation is still
            // all-or-nothing.
            $result = DB::transaction(function () use ($user) {
                $meta = ['role_at_deletion' => 'Client', 'was_last_client' => false, 'organization_id' => null];
                $this->anonymiseAndDeleteAccount($user);

                return ['status' => 200, 'body' => ['message' => 'Your account has been deleted.'], 'meta' => $meta];
            });
        }

        if ($result['status'] === 200) {
            // Neutral, non-identifying description — the account's real
            // email/name are already gone by the time this runs (or, for
            // the Client branch, gone by the time the lock's transaction
            // committed). Never logs the original email.
            ActivityLog::record(
                'user.self_deleted',
                'User deleted their own account',
                null,
                $user,
                $result['meta'],
                null,
                $result['meta']['organization_id'],
            );
        }

        return response()->json($result['body'], $result['status']);
    }

    /**
     * Self-Service Account Deletion — the actual mutation, shared by both
     * branches of deleteAccount() above. Order matters: tokens revoked
     * first (same rationale as UserController's Remove/Detach hardening —
     * a failure partway through must never leave old tokens live), then
     * role/permission cleanup, then the anonymising field overwrite, then
     * soft-delete. Both callers already run this inside a transaction, so a
     * failure anywhere in here rolls back the whole thing rather than
     * leaving a half-anonymised active account.
     */
    private function anonymiseAndDeleteAccount(User $user): void
    {
        $user->tokens()->delete();

        // Defense-in-depth (Self-Service Account Deletion, decision 3): if
        // this tombstoned row were ever manually restored outside the
        // normal product flow, it must not silently regain its previous
        // Client/Admin/Super Admin role or any direct permission — unlike
        // admin-initiated Remove User/Remove & Detach, which deliberately
        // keep role assignments intact for their own, different,
        // recoverable-by-design restore semantics.
        $user->syncRoles([]);
        $user->syncPermissions([]);

        $tombstoneEmail = 'deleted-' . (string) Str::uuid() . '@deleted.invalid';

        $user->update([
            'name'            => 'Deleted User',
            'first_name'      => null,
            'last_name'       => null,
            'email'           => $tombstoneEmail,
            'phone'           => null,
            'avatar'          => null,
            'address'         => null,
            'city'            => null,
            'province'        => null,
            'postal_code'     => null,
            'country'         => null,
            'organization_id' => null,
        ]);

        $user->delete();
    }

    /**
     * Self-service timezone override — same pattern as updatePassword()
     * above (the authenticated user acting on their own account, no role
     * gate needed). `timezone: null` explicitly means "use company
     * timezone" (clears any existing override); a real IANA identifier sets
     * one. See App\Services\TimezoneResolver for how this is resolved.
     */
    public function updateTimezone(Request $request)
    {
        $validated = $request->validate([
            'timezone' => 'nullable|timezone',
        ]);

        $user = $request->user();
        $user->update(['timezone' => $validated['timezone'] ?? null]);

        return response()->json($this->userResource($user->fresh('organization.branding')));
    }

    /**
     * Self-service Notification Sound preference — same pattern as
     * updateTimezone() above (the authenticated user acting on their own
     * account only; `auth:sanctum` + `$request->user()` already scope this
     * to the caller's own row, so one user can never alter another's).
     */
    public function updateNotificationSound(Request $request)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update(['notification_sound_enabled' => $validated['enabled']]);

        return response()->json($this->userResource($user->fresh('organization.branding')));
    }

    // Used only for the admin-forced "must change password" flow — the user
    // is already authenticated via a valid token, so no current_password is
    // required, but the must_change_password flag (settable only by a Super
    // Admin action) gates this endpoint so it can't be used as a bypass for
    // the ordinary current-password-required change flow above.
    public function forcePasswordChange(Request $request)
    {
        $user = $request->user();

        if (! $user->must_change_password) {
            return response()->json(['message' => 'Password change is not required.'], 422);
        }

        $request->validate([
            'password' => array_merge(
                ['required', 'confirmed'],
                SureSignPasswordPolicy::rules(),
                [new DiffersFromCurrentPassword($user)],
            ),
        ]);

        $currentTokenId = $user->currentAccessToken()?->id;

        $user->update([
            'password'              => Hash::make($request->password),
            'must_change_password'  => false,
        ]);

        // Same policy as updatePassword() above: revoke every other session
        // (e.g. a stale session opened under the old/temporary password on
        // another device), keep the current one — the frontend's
        // ForcePasswordChangeGate immediately calls GET /auth/me with this
        // same token afterward and expects to land in the app, not be
        // logged out.
        self::revokeOtherTokens($user, $currentTokenId);

        ActivityLog::record('user.password_changed', "{$user->email} changed their own password", $user, $user);
        // Still the account holder choosing their own replacement password
        // — the same "your password was changed" notification as the
        // ordinary Settings flow, not a distinct "forced" wording.
        PasswordSecurityNotifier::notifyChanged($user);

        return response()->json(['message' => 'Password updated.', 'user' => $this->userResource($user->fresh())]);
    }

    private static function revokeOtherTokens(User $user, ?int $currentTokenId): void
    {
        if ($currentTokenId !== null) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        } else {
            $user->tokens()->delete();
        }
    }

    // Always returns the same generic message regardless of whether the
    // email exists or the request was throttled — avoids leaking which
    // emails are registered.
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        PasswordBroker::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => array_merge(['required', 'confirmed'], SureSignPasswordPolicy::rules()),
        ]);

        $status = PasswordBroker::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->update([
                    'password'             => Hash::make($password),
                    'must_change_password' => false,
                ]);

                // There is no trusted "current authenticated device" during
                // password recovery (unlike updatePassword()/
                // forcePasswordChange(), where the user just proved control
                // of one specific session) — revoke every existing token,
                // full stop.
                $user->tokens()->delete();

                ActivityLog::record('user.password_reset', "{$user->email} reset their password via the Forgot Password flow", $user, $user);
                PasswordSecurityNotifier::notifyReset($user);
            }
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            return response()->json(['message' => 'This password reset link is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    public function sendEmailVerification(Request $request)
    {
        $user = $request->user();

        if ($user->email_verified_at) {
            return response()->json(['message' => 'Email already verified.']);
        }

        EmailVerificationService::sendVerificationLink($user);

        return response()->json(['message' => 'Verification email sent.']);
    }

    public function verifyEmailLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
        ]);

        if (! EmailVerificationService::verify($request->email, $request->token)) {
            return response()->json(['message' => 'This verification link is invalid or has expired.'], 422);
        }

        return response()->json(['message' => 'Email verified successfully.']);
    }

    private function userResource(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'job_title'   => $user->job_title,
            'avatar'      => $user->avatar,
            'address'     => $user->address,
            'city'        => $user->city,
            'province'    => $user->province,
            'postal_code' => $user->postal_code,
            'country'     => $user->country,
            'roles'       => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'email_verified_at'    => $user->email_verified_at,
            'is_active'            => $user->is_active,
            'banned_at'            => $user->banned_at,
            'must_change_password' => $user->must_change_password,
            'tours_reset_at'       => $user->tours_reset_at,
            // Notification Sound System — user-level only, defaults true at
            // the DB/model level (see the 2026_09_06_000002 migration and
            // User::$attributes). Exposed here so the frontend reads it from
            // the same auth/me + login response it already fetches, with no
            // second request needed.
            'notification_sound_enabled' => $user->notification_sound_enabled,
            // `timezone` is the raw override (null = inheriting the
            // organisation's timezone). `effective_timezone` is what
            // actually applies right now, per TimezoneResolver's
            // user → organisation → platform → UTC hierarchy — provided so
            // clients don't have to reimplement that resolution themselves.
            'timezone'             => $user->timezone,
            'effective_timezone'   => TimezoneResolver::effectiveTimezone($user, $user->organization),
            'organization' => $user->organization ? [
                'id'           => $user->organization->id,
                'name'         => $user->organization->name,
                'slug'         => $user->organization->slug,
                'is_onboarded' => (bool) $user->organization->is_onboarded,
                'timezone'     => $user->organization->timezone,
                'branding'     => $user->organization->branding,
                // `currency` is the raw override (null = inheriting the
                // platform default). `effective_currency` is what actually
                // applies right now — organisation's own, else platform, else
                // GBP — so clients (e.g. "Use organisation default — GBP" on
                // the project creation form) don't have to reimplement
                // CurrencyService's resolution themselves.
                'currency'            => $user->organization->currency,
                'effective_currency'  => CurrencyService::resolveOrganizationCode($user->organization),
            ] : null,
        ];
    }
}
