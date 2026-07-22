<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAppointmentBlockedPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Self/staff-selection checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'start_date' => 'required|date',
            'start_time' => 'required|date_format:H:i',
            'end_date'   => 'required|date',
            'end_time'   => 'required|date_format:H:i',
            'timezone'   => 'required|timezone',
            'reason'     => 'nullable|string|max:255',
        ];
    }
}
