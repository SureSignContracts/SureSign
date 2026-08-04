<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsultancyServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super Admin/Admin gating enforced in the controller
    }

    public function rules(): array
    {
        // 'code' is deliberately absent — immutable after creation
        // (ConsultancyCatalogueService::update() never accepts it either).
        return [
            'display_name'                     => 'sometimes|string|max:255',
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

            'duration_minutes'                 => 'sometimes|integer|min:5|max:1440',
            'buffer_before_minutes'            => 'nullable|integer|min:0|max:480',
            'buffer_after_minutes'             => 'nullable|integer|min:0|max:480',
            'min_notice_hours'                 => 'nullable|integer|min:0|max:8760',
            'max_advance_days'                 => 'nullable|integer|min:1|max:730',
            // Consultancy Live Booking Upgrade, Stage 1 — see the identical
            // note in StoreConsultancyServiceRequest.
            'requires_confirmation'            => 'nullable|boolean',
            'meeting_method'                   => ['nullable', Rule::in(['google_meet', 'teams', 'zoom', 'phone', 'in_person', 'custom', 'tbc'])],
            'cancellation_notice_hours'        => 'nullable|integer|min:0|max:8760',
            'reschedule_notice_hours'          => 'nullable|integer|min:0|max:8760',
        ];
    }
}
