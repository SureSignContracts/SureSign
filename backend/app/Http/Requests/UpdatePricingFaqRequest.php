<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePricingFaqRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question'   => 'sometimes|required|string|max:255',
            'answer'     => 'sometimes|required|string|max:5000',
            'order'      => 'nullable|integer|min:0',
            'is_enabled' => 'nullable|boolean',
        ];
    }
}
