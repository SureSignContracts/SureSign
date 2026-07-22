<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentAvailabilityOverrideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Self/staff-selection checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'local_date'     => 'required|date',
            'is_unavailable' => 'nullable|boolean',
            'start_time'     => 'nullable|date_format:H:i',
            'end_time'       => 'nullable|date_format:H:i',
        ];
    }
}
