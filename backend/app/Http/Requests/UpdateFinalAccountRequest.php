<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFinalAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Tenant isolation enforced in controller
    }

    public function rules(): array
    {
        return [
            // Only free-text fields are editable directly.
            // Financial line items are managed via /final-account-items endpoints.
            // Status transitions happen via dedicated action endpoints.
            'notes' => 'nullable|string|max:5000',
        ];
    }
}
