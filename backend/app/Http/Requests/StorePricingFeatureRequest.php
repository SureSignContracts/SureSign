<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePricingFeatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => 'required|integer|exists:pricing_feature_sections,id',
            'name'       => 'required|string|max:150',
            'order'      => 'nullable|integer|min:0',
            'is_visible' => 'nullable|boolean',
        ];
    }
}
