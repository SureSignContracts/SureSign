<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\AppointmentAvailabilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateConsultancyConsultantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Super Admin-only gating enforced in the controller
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * An explicit user_id must be an eligible consultant (active, not
     * banned, Admin or Super Admin) — reuses
     * AppointmentAvailabilityService::isEligibleStaff() rather than
     * inventing a second eligibility rule. A null user_id is always valid —
     * it explicitly unconfigures the Consultancy consultant.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $userId = $this->input('user_id');
            if (!$userId) {
                return;
            }

            $user = User::find($userId);
            if (!$user || !app(AppointmentAvailabilityService::class)->isEligibleStaff($user)) {
                $validator->errors()->add('user_id', 'This user is not eligible to be the Consultancy consultant (must be an active, non-banned Admin or Super Admin).');
            }
        });
    }
}
