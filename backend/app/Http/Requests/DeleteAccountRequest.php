<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Self-Service Account Deletion — input for the authenticated
 * POST /auth/delete-account endpoint. Deliberately accepts nothing but the
 * three fields below — no user_id/email/organization_id/role: the target is
 * always $request->user(), never client-supplied (see AuthController::
 * deleteAccount()).
 *
 * `current_password` uses Laravel's own built-in `current_password` rule
 * (same convention as AuthController::updatePassword()) — verifies against
 * the authenticated user's own password, generic failure message, no
 * password-specific detail ever exposed.
 *
 * `confirmed` mirrors ManageAiCreditsRequest's `confirmed => required|accepted`
 * shape — the explicit "I understand this is permanent" acknowledgement.
 *
 * `confirm_last_client` is optional and only meaningful for a Client who
 * turns out to be the last non-deleted Client in their organisation — see
 * AuthController::deleteAccount()'s sole-Client guard, which mirrors
 * UserController::removeAndDetach()'s existing LAST_CLIENT_*_REQUIRES_
 * CONFIRMATION contract shape.
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password'    => ['required', 'current_password'],
            'confirmed'            => ['required', 'accepted'],
            'confirm_last_client'  => ['sometimes', 'boolean'],
        ];
    }
}
