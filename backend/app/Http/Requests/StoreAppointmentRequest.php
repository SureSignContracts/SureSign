<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Tenant isolation and role checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'appointment_type_id' => 'required|integer|exists:appointment_types,id',
            'assigned_user_id'    => 'nullable|integer|exists:users,id',
            'organization_id'     => 'nullable|integer|exists:organizations,id',
            'linked_user_id'      => 'nullable|integer|exists:users,id',
            'company_name'        => 'nullable|string|max:255',
            'project_id'          => 'nullable|integer|exists:projects,id',

            'attendee_name'       => 'required|string|max:255',
            'attendee_email'      => 'required|email|max:255',
            'attendee_phone'      => 'nullable|string|max:50',
            'attendee_job_title'  => 'nullable|string|max:255',
            'attendee_company'    => 'nullable|string|max:255',
            'attendee_timezone'   => 'required|timezone',

            // Local wall-clock scheduling fields — the controller builds the
            // UTC starts_at/ends_at itself via TimezoneResolver, mirroring
            // MeetingMinutesController's buildSchedule() convention.
            'date'                => 'required|date',
            'start_time'          => 'required|date_format:H:i',
            'timezone'            => 'required|timezone',

            'meeting_method'      => ['nullable', Rule::in(['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])],
            'meeting_url'         => 'nullable|string|max:255',
            'location'            => 'nullable|string|max:255',
            'attendee_message'    => 'nullable|string|max:5000',
            'internal_notes'      => 'nullable|string|max:5000',
            'booking_source'      => 'nullable|string|max:50',

            // Super-Admin-only conflict override (Phase 2) — enforced in
            // the controller, not here; Appointment::$fillable doesn't
            // include these so they never reach the created record itself.
            'override'            => 'sometimes|boolean',
            'override_reason'     => 'required_if:override,true|string|max:255',
        ];
    }
}
