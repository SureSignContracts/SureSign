<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkAwaitingConsultantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorizeOperatorManage() gating enforced in the controller
    }

    public function rules(): array
    {
        return [];
    }
}
