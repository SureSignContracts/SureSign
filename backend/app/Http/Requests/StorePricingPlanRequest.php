<?php

namespace App\Http\Requests;

use App\Rules\SafeUrl;
use App\Support\Pricing\PricingOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super Admin-only route middleware enforces access
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:100|alpha_dash|unique:pricing_plans,code',
            'slug' => 'required|string|max:100|alpha_dash|unique:pricing_plans,slug',
            'name' => 'required|string|max:150',
            'order' => 'nullable|integer|min:0',

            'monthly_price' => 'nullable|numeric|min:0|max:99999999.99',
            'annual_price'  => 'nullable|numeric|min:0|max:99999999.99',
            'currency'      => 'nullable|string|size:3',

            'price_prefix' => 'nullable|string|max:50',
            'price_suffix' => 'nullable|string|max:50',

            'description' => 'nullable|string|max:2000',
            'summary'     => 'nullable|string|max:255',

            'cta_text'    => 'nullable|string|max:100',
            'cta_url'     => ['nullable', new SafeUrl],
            'cta_new_tab' => 'nullable|boolean',

            'is_visible' => 'nullable|boolean',
            'is_popular' => 'nullable|boolean',

            'badge_text'       => 'nullable|string|max:50',
            'badge_color'      => ['nullable', Rule::in(PricingOptions::BADGE_COLORS)],
            'accent_color'     => ['nullable', Rule::in(PricingOptions::ACCENT_COLORS)],
            'background_style' => ['nullable', Rule::in(PricingOptions::BACKGROUND_STYLES)],
            'icon'             => ['nullable', Rule::in(PricingOptions::ICONS)],
            'custom_label'     => 'nullable|string|max:100',
        ];
    }
}
