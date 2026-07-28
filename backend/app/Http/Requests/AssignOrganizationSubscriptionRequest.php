<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input for the G4B.2 manual/complimentary assignment endpoints
 * (POST /organizations/{organization}/subscriptions/assign-manual and
 * .../assign-complimentary). Deliberately identical for both — the
 * commercial source itself is never a request field, it's implied by
 * which endpoint was called (no generic `assignSubscription($source)`
 * entry point, per the approved design). Authorisation is enforced by the
 * `role:Super Admin` route group, not here — matching this codebase's
 * existing FormRequest convention (see StoreCheckoutSessionRequest).
 */
class AssignOrganizationSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan_code' => 'required|string|max:100',
            'billing_interval' => 'required|string|in:monthly,annual',
            // A generic reason ("manual", "test") is still a valid string
            // and cannot be rejected by a validation rule alone — the
            // minimum length is a light guard, not a substitute for actual
            // Super Admin judgement.
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ];
    }
}
