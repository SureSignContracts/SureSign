<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Public, unauthenticated consultation booking submission. Every field is
 * untrusted input — see PublicConsultationController for slug/eligibility/
 * rate-limit enforcement, none of which lives in this request. Mirrors
 * StorePublicAppointmentRequest's shape plus the Consultancy enquiry fields.
 */
class StorePublicConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attendee_name'      => 'required|string|max:255',
            'attendee_email'     => 'required|email|max:255',
            'attendee_phone'     => 'nullable|string|max:50',
            'attendee_job_title' => 'nullable|string|max:255',
            'attendee_company'   => 'nullable|string|max:255',
            'attendee_timezone'  => 'required|timezone',

            'date'       => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'timezone'   => 'required|timezone',

            'title'              => 'required|string|max:255',
            'description'        => 'required|string|max:5000',
            'project_stage'      => 'nullable|string|max:255',
            'contract_form'      => 'nullable|string|max:255',
            'preferred_outcome'  => 'nullable|string|max:2000',

            'consent' => 'accepted',
            'source'  => 'nullable|string|max:100',

            // Honeypot — see StorePublicAppointmentRequest for the identical rationale.
            'website' => 'nullable|string|max:255',
        ];
    }
}
