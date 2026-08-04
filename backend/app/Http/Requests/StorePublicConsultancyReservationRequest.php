<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — public temporary slot
 * reservation. Only the customer-selected values needed to identify the
 * desired booking are accepted; consultant, duration, end time, and
 * availability context are always server-derived (see
 * PublicConsultancyReservationController) — never trusted from the
 * browser even if supplied.
 */
class StorePublicConsultancyReservationRequest extends FormRequest
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

            // Client-held idempotency boundary — see
            // ConsultancySlotReservationService's own docblock. Never
            // consultant/service/start-time alone.
            'booking_attempt_token' => 'required|string|min:16|max:64',

            // Honeypot, matching every other public booking form's identical rationale.
            'website' => 'nullable|string|max:255',
        ];
    }
}
