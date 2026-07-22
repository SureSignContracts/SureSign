<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAppointmentTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super-Admin-only gating enforced in the controller
    }

    public function rules(): array
    {
        return [
            'name'                       => 'required|string|max:255',
            'slug'                       => 'required|string|max:255|alpha_dash|unique:appointment_types,slug',
            'description'                => 'nullable|string|max:5000',
            'public_title'               => 'nullable|string|max:255',
            'public_description'         => 'nullable|string|max:5000',
            'internal_notes'             => 'nullable|string|max:5000',
            'duration_minutes'           => 'required|integer|min:5|max:1440',
            'buffer_before_minutes'      => 'nullable|integer|min:0|max:480',
            'buffer_after_minutes'       => 'nullable|integer|min:0|max:480',
            'min_notice_hours'           => 'nullable|integer|min:0|max:8760',
            'max_advance_days'           => 'nullable|integer|min:1|max:730',
            'is_public'                  => 'nullable|boolean',
            'is_active'                  => 'nullable|boolean',
            'color'                      => 'nullable|string|max:20',
            'default_assigned_user_id'   => 'nullable|integer|exists:users,id',
            'assignment_mode'            => ['nullable', Rule::in(['fixed', 'manual'])],
            'requires_confirmation'      => 'nullable|boolean',
            'meeting_method'             => ['nullable', Rule::in(['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])],
            'default_location'           => 'nullable|string|max:255',
            'cancellation_notice_hours'  => 'nullable|integer|min:0|max:8760',
            'reschedule_notice_hours'    => 'nullable|integer|min:0|max:8760',
            'display_order'              => 'nullable|integer|min:0',
        ];
    }
}
