<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePricingFeatureSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => 'sometimes|required|string|max:150',
            'order'      => 'nullable|integer|min:0',
            'is_visible' => 'nullable|boolean',
        ];
    }
}
