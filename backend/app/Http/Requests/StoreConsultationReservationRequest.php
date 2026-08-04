<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — authenticated temporary
 * slot reservation. Mirrors StorePublicConsultancyReservationRequest's
 * shape; consultant/duration/end-time/context remain server-derived.
 */
class StoreConsultationReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendee_name'     => 'required|string|max:255',
            'attendee_email'    => 'required|email|max:255',
            'attendee_timezone' => 'required|timezone',

            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'timezone'   => 'required|timezone',

            'booking_attempt_token' => 'required|string|min:16|max:64',
        ];
    }
}
