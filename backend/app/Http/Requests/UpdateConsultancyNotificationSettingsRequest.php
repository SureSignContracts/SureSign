<?php

namespace App\Http\Requests;

use App\Support\Consultancy\ConsultancyNewBookingNotificationRecipients;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsultancyNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super Admin-only gating enforced in the controller
    }

    public function rules(): array
    {
        return [
            'recipients' => ['required', 'string', Rule::in(ConsultancyNewBookingNotificationRecipients::ALL)],
        ];
    }
}
