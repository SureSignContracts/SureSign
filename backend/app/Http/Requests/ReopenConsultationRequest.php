<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReopenConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super-Admin-only gating enforced in the controller
    }

    public function rules(): array
    {
        return [];
    }
}
