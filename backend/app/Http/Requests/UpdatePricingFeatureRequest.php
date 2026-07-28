<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePricingFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => 'sometimes|required|integer|exists:pricing_feature_sections,id',
            'name'       => 'sometimes|required|string|max:150',
            'order'      => 'nullable|integer|min:0',
            'is_visible' => 'nullable|boolean',
        ];
    }
}
