<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase G2, Stage 6 — "Copy Existing Plan". Only the fields that identify
 * the NEW plan are accepted here; every other commercial field and every
 * entitlement default row are copied from the source plan by
 * PricingManagementService::copyPlan() itself. Stripe identifiers are never
 * accepted or copied — a copied plan always starts with no
 * pricing_plan_provider_prices rows and requires its own new mapping.
 */
class CopyPricingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role:Super Admin|Admin route middleware enforces access
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:100|alpha_dash|unique:pricing_plans,code',
            'slug' => 'required|string|max:100|alpha_dash|unique:pricing_plans,slug',
            'name' => 'required|string|max:150',
            'is_visible' => 'nullable|boolean',
        ];
    }
}
