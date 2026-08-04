<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsultancyServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super Admin/Admin gating enforced in the controller
    }

    public function rules(): array
    {
        return [
            'code'                             => 'required|string|max:255|alpha_dash|unique:consultancy_services,code',
            'display_name'                     => 'required|string|max:255',
            'description'                      => 'nullable|string|max:5000',
            'public_description'               => 'nullable|string|max:5000',
            'enabled'                          => 'nullable|boolean',
            'publicly_bookable'                => 'nullable|boolean',
            'available_to_existing_customers'  => 'nullable|boolean',
            'price_minor_units'                => 'nullable|integer|min:0',
            'currency'                         => 'nullable|string|size:3',
            'display_order'                    => 'nullable|integer|min:0',
            'is_introductory'                  => 'nullable|boolean',
            'max_bookings_per_day'             => 'nullable|integer|min:1',

            // Linked AppointmentType scheduling fields
            'duration_minutes'                 => 'required|integer|min:5|max:1440',
            'buffer_before_minutes'            => 'nullable|integer|min:0|max:480',
            'buffer_after_minutes'             => 'nullable|integer|min:0|max:480',
            'min_notice_hours'                 => 'nullable|integer|min:0|max:8760',
            'max_advance_days'                 => 'nullable|integer|min:1|max:730',
            // Consultancy Live Booking Upgrade, Stage 1 — the consultant is
            // an operational, platform-wide setting
            // (App\Services\Consultancy\ConsultancyConsultantResolver), never
            // a per-service field. 'default_consultant_user_id'/
            // 'assignment_mode' are deliberately NOT accepted here — see
            // that class's docblock for why storing it redundantly per
            // service would create two competing sources of truth.
            'requires_confirmation'            => 'nullable|boolean',
            'meeting_method'                   => ['nullable', Rule::in(['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])],
            'cancellation_notice_hours'        => 'nullable|integer|min:0|max:8760',
            'reschedule_notice_hours'          => 'nullable|integer|min:0|max:8760',
        ];
    }
}
