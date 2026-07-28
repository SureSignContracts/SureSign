<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input for POST /billing/checkout — deliberately accepts ONLY an approved
 * local Pricing identifier (plan code) and billing interval. No Stripe
 * Price/Product ID, no amount, no currency, no return URL, no provider
 * customer/subscription ID, no organisation ID — every one of those is
 * resolved server-side (see CheckoutController). Authorisation (any
 * authenticated organisation member, matching Billing's existing read
 * endpoints) is enforced in the controller, not here — no per-plan
 * ownership check applies to a plan code, which is public information.
 */
class StoreCheckoutSessionRequest extends FormRequest
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
        ];
    }
}
