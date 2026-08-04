<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Empty body — publish always copies the current customer_summary_draft
 * verbatim (see EngagementLifecycleService/§4 of the specification). No
 * fields to validate; this class exists so publish has its own explicit
 * request object, not because it needs input today.
 */
class PublishConsultationSummaryRequest extends FormRequest
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
