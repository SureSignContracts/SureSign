<?php

namespace App\Http\Requests;

use App\Support\Pricing\PricingOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePricingMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'updates'                 => 'required|array|min:1',
            'updates.*.plan_id'       => 'required|integer|exists:pricing_plans,id',
            'updates.*.feature_id'    => 'required|integer|exists:pricing_features,id',
            'updates.*.status'        => ['required', Rule::in(PricingOptions::PLAN_FEATURE_STATUSES)],
            'updates.*.value_text'    => 'nullable|string|max:150',
            'updates.*.icon_override' => ['nullable', Rule::in(PricingOptions::ICONS)],
        ];
    }
}
