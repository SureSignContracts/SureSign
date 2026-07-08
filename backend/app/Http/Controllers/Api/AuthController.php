<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

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
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        if ($user->isBanned()) {
            return response()->json(['message' => 'Your account has been banned.'], 403);
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

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'password'         => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $request->user()->update(['password' => Hash::make($request->password)]);
        return response()->json(['message' => 'Password updated.']);
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
            'password' => [
                'required',
                'confirmed',
                Password::min(8)->mixedCase()->numbers()->symbols(),
            ],
        ]);

        $user->update([
            'password'              => Hash::make($request->password),
            'must_change_password'  => false,
        ]);

        return response()->json(['message' => 'Password updated.', 'user' => $this->userResource($user->fresh())]);
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
            'organization' => $user->organization ? [
                'id'           => $user->organization->id,
                'name'         => $user->organization->name,
                'slug'         => $user->organization->slug,
                'is_onboarded' => (bool) $user->organization->is_onboarded,
                'branding'     => $user->organization->branding,
            ] : null,
        ];
    }
}
