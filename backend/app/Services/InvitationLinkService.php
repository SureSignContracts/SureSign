<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Builds and validates the signed "Accept Invitation & Set Up Account" link
 * sent to an admin-invited user.
 *
 * Signing is Laravel's own built-in signed-URL mechanism only — the same
 * primitive App\Services\AppointmentPublicLinkService already uses for
 * public Appointment/Consultation actions — no custom token generation, no
 * new database table. The self-registration email-verification flow
 * (EmailVerificationService, email_verification_tokens table) is completely
 * separate and untouched; invitation acceptance never shares that
 * mechanism, so the two flows can never be confused with one another.
 *
 * The route is keyed on the invited User's numeric id. Laravel's signature
 * already makes the id untamperable (swapping it invalidates the
 * signature), so there is no need for a separate opaque token column —
 * see the Invitation & First-Time Account Setup phase report for the full
 * reasoning on why this avoids any schema change.
 */
class InvitationLinkService
{
    /**
     * The signed API URL itself (`GET`/`POST /public/invitations/{user}`).
     * Laravel's `signed` middleware validates the same signature for both
     * verbs at the same path — see routes/api.php.
     */
    public function apiUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'invitations.show',
            now()->addDays((int) config('suresign.invitation.link_expiry_days')),
            ['user' => $user->id],
        );
    }

    /**
     * The link actually placed in the invitation email — the authenticated
     * app's `/accept-invitation` page, carrying the exact same `expires`/
     * `signature` query values Laravel generated against the API route
     * above, plus the `user` id so the frontend can reconstruct the
     * identical API URL when it calls back.
     */
    public function acceptUrl(User $user): string
    {
        $query = parse_url($this->apiUrl($user), PHP_URL_QUERY) ?: '';
        $frontendUrl = rtrim((string) config('suresign.frontend_url'), '/');

        return "{$frontendUrl}/accept-invitation?user={$user->id}&{$query}";
    }
}
