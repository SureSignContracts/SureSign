<?php

namespace App\Http\Requests;

use App\Rules\SafeUrl;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePricingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hero_title'    => 'nullable|string|max:150',
            'hero_subtitle' => 'nullable|string|max:500',
            'section_title' => 'nullable|string|max:150',

            'monthly_billing_enabled' => 'nullable|boolean',
            'annual_billing_enabled'  => 'nullable|boolean',
            'discount_label'          => 'nullable|string|max:100',

            'everything_included_title'    => 'nullable|string|max:150',
            'everything_included_subtitle' => 'nullable|string|max:500',

            'final_cta_title'    => 'nullable|string|max:150',
            'final_cta_subtitle' => 'nullable|string|max:500',

            'primary_cta_text'      => 'nullable|string|max:100',
            'primary_cta_url'       => ['nullable', new SafeUrl],
            'primary_cta_new_tab'   => 'nullable|boolean',
            'secondary_cta_text'    => 'nullable|string|max:100',
            'secondary_cta_url'     => ['nullable', new SafeUrl],
            'secondary_cta_new_tab' => 'nullable|boolean',

            'published' => 'nullable|boolean',
        ];
    }
}
