<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Covers ordinary field edits only. Status changes, assignment, and
 * rescheduling each have their own dedicated action endpoint/service call
 * (AppointmentWorkflowService / AppointmentSchedulingService) so every
 * meaningful change gets a single, purpose-specific audit trail entry
 * rather than being inferred from a generic update diff.
 */
class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Tenant isolation and role checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'attendee_name'      => 'sometimes|string|max:255',
            'attendee_email'     => 'sometimes|email|max:255',
            'attendee_phone'     => 'nullable|string|max:50',
            'attendee_job_title' => 'nullable|string|max:255',
            'attendee_company'   => 'nullable|string|max:255',
            'company_name'       => 'nullable|string|max:255',
            'linked_user_id'     => 'nullable|integer|exists:users,id',
            'project_id'         => 'nullable|integer|exists:projects,id',
            'meeting_method'     => ['sometimes', Rule::in(['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])],
            'meeting_url'        => 'nullable|string|max:255',
            'location'           => 'nullable|string|max:255',
            'attendee_message'   => 'nullable|string|max:5000',
            'internal_notes'     => 'nullable|string|max:5000',
        ];
    }
}
