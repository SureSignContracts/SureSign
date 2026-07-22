<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetAppointmentAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Self/staff-selection checks enforced in the controller
    }

    public function rules(): array
    {
        return [
            'windows'                 => 'present|array',
            'windows.*.weekday'       => 'required|integer|min:0|max:6',
            'windows.*.start_time'    => 'required|date_format:H:i',
            'windows.*.end_time'      => 'required|date_format:H:i',
            'windows.*.is_active'     => 'nullable|boolean',
        ];
    }
}
