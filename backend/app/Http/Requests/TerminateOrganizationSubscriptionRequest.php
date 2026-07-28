<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input for POST /organizations/{organization}/subscriptions/{subscription}/terminate
 * (G4B.2). Manual/complimentary only — the controller rejects a
 * Stripe-source subscription before this ever reaches
 * SubscriptionLifecycleService.
 */
class TerminateOrganizationSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }
}
