<?php

namespace App\Http\Requests;

use App\Support\Pricing\PricingOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingIncludedItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'text'       => 'required|string|max:255',
            'icon'       => ['nullable', Rule::in(PricingOptions::ICONS)],
            'order'      => 'nullable|integer|min:0',
            'is_visible' => 'nullable|boolean',
        ];
    }
}
