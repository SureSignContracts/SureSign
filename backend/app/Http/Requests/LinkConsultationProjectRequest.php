<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkConsultationProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // authorizeOperatorManage() gating enforced in the controller
    }

    public function rules(): array
    {
        // Deliberately no `exists:projects,id` here — the controller's own
        // authorizeOperatorManage() must run and reject an unauthorized
        // caller BEFORE anything about the requested project (existence,
        // soft-delete state, organisation) is revealed. A FormRequest rule
        // runs before the controller body, which would leak that signal
        // through a 422 vs. 403 distinction. linkProject() performs its own
        // existence/soft-delete check after authorization instead.
        return [
            'project_id' => 'required|integer',
        ];
    }
}
