<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input for POST /billing/plan-change — exactly the same discipline as
 * StoreCheckoutSessionRequest: only an approved local plan code and
 * billing interval. No Stripe Price/Product/subscription-item ID, no
 * amount, no currency, no effective date, no proration parameter, no
 * billing-cycle anchor, no organisation ID. Every one of those is
 * resolved or decided server-side (see PlanChangeController).
 */
class StorePlanChangeRequest extends FormRequest
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
