<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConsultationSummaryDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorizeOperatorManage() gating enforced in the controller
    }

    public function rules(): array
    {
        return [
            'customer_summary_draft' => 'nullable|string|max:20000',
        ];
    }
}
