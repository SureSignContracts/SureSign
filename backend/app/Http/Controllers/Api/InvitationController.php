<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\InvitationService;
use App\Support\Auth\SureSignPasswordPolicy;
use Illuminate\Http\Request;

/**
 * Invitation & First-Time Account Setup phase — the public, signed-URL
 * counterpart to UserController::invite(). Both routes sit behind
 * Laravel's `signed` middleware (routes/api.php), keyed on the invited
 * User's id — see InvitationLinkService's docblock for why no separate
 * token table exists. Read-only show(); accept() is the sole mutation, and
 * is atomic (InvitationService::accept()).
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
    ) {
    }

    /**
     * Never consumes the invitation — safe to load the setup page any
     * number of times before it's actually accepted.
     */
    public function show(Request $request, string $user)
    {
        $user = User::find($user); // route param is `{user}` — matches InvitationLinkService::apiUrl()'s signed key

        if (!$user) {
            return response()->json(['message' => 'This invitation is no longer valid.'], 404);
        }

        if ($user->email_verified_at !== null) {
            return response()->json(['data' => [
                'already_accepted' => true,
            ]]);
        }

        return response()->json(['data' => [
            'already_accepted'  => false,
            'email'              => $user->email,
            'first_name'         => $user->first_name,
            'organization_name'  => $user->organization?->name,
            'expiry_days'        => (int) config('suresign.invitation.link_expiry_days'),
        ]]);
    }

    public function accept(Request $request, string $user)
    {
        $user = User::find($user); // route param is `{user}` — matches InvitationLinkService::apiUrl()'s signed key

        if (!$user) {
            return response()->json(['message' => 'This invitation is no longer valid.'], 404);
        }

        $validated = $request->validate([
            'password' => array_merge(['required', 'confirmed'], SureSignPasswordPolicy::rules()),
        ]);

        try {
            $this->invitations->accept($user, $validated['password']);
        } catch (InvitationAlreadyAcceptedException $e) {
            return response()->json([
                'message' => 'This SureSign account has already been set up.',
                'code'    => 'invitation_already_accepted',
            ], 409);
        }

        return response()->json(['message' => 'Your SureSign account is ready.']);
    }
}
