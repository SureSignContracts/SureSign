<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\DiffersFromCurrentPassword;
use App\Services\CurrencyService;
use App\Services\EmailVerificationService;
use App\Services\Organizations\AuthenticatedWorkspaceContextService;
use App\Services\TimezoneResolver;
use App\Support\Auth\PasswordSecurityNotifier;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;

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

        $user->update(['last_login_at' => now()]);

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
