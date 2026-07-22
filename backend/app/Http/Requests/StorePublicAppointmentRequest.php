<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, unauthenticated booking submission. Every field here is treated
 * as untrusted input — no assumption is made about the caller (see
 * PublicAppointmentController for eligibility/type-active/rate-limit
 * enforcement, none of which lives in this request).
 */
class StorePublicAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appointment_type_slug' => 'required|string|max:255',

            'attendee_name'      => 'required|string|max:255',
            'attendee_email'     => 'required|email|max:255',
            'attendee_phone'     => 'nullable|string|max:50',
            'attendee_job_title' => 'nullable|string|max:255',
            'attendee_company'   => 'nullable|string|max:255',
            'attendee_timezone'  => 'required|timezone',

            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'timezone'   => 'required|timezone',

            'attendee_message' => 'nullable|string|max:2000',
            'consent'          => 'accepted',
            'source'           => 'nullable|string|max:100',

            // Honeypot — a real visitor never sees or fills this field
            // (hidden via CSS on the public form). Not required; presence
            // of a non-empty value is treated as a bot signal by the
            // controller, which returns a normal-looking response without
            // creating anything.
            'website' => 'nullable|string|max:255',
        ];
    }
}
