<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentBlockedPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Self/staff-selection checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'start_date' => 'sometimes|date',
            'start_time' => 'sometimes|date_format:H:i',
            'end_date'   => 'sometimes|date',
            'end_time'   => 'sometimes|date_format:H:i',
            'timezone'   => 'sometimes|timezone',
            'reason'     => 'nullable|string|max:255',
        ];
    }
}
