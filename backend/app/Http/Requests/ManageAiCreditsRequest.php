<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase G4C.3H — input for the Super-Admin-only AI Credits grant/
 * adjustment/expiry endpoints (POST .../grant, .../adjust-credit,
 * .../adjust-debit, .../expire). Deliberately identical shape for all
 * four — which ledger transaction type is written is implied by which
 * endpoint was called, never a request field (mirrors
 * AssignOrganizationSubscriptionRequest's "no generic action+type"
 * convention exactly). Authorisation is enforced by the `role:Super Admin`
 * route group, not here.
 */
class ManageAiCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:1000000',
            // A generic reason is still a valid string and cannot be rejected
            // by a rule alone — the minimum length is a light guard, not a
            // substitute for actual Super Admin judgement, matching
            // AssignOrganizationSubscriptionRequest's identical reasoning.
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }
}
