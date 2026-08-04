<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authenticated (Client/Admin/Super Admin) consultation booking — the
 * caller's own organisation is resolved server-side in the controller from
 * the authenticated user, never accepted here. Project linkage
 * (appointments.project_id) is deliberately not accepted in Phase C1 — see
 * internal-docs/commercial/suresign-consultancy-specification-v1.md, Phase C2.
 */
class StoreConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'consultancy_service_code' => 'required|string|exists:consultancy_services,code',

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
        ];
    }
}
