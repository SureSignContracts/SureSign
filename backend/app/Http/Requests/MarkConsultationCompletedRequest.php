<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual completion without publishing a summary — the expected path for
 * an `is_introductory` service, which C1's specification already says has
 * "no written consultancy report" as a matter of product policy.
 */
class MarkConsultationCompletedRequest extends FormRequest
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
