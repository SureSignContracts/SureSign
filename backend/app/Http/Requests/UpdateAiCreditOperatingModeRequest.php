<?php

namespace App\Http\Requests;

use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Input for PUT /admin/ai-credits/operating-mode — the Super-Admin-only AI
 * Credit operating mode control (App\Support\AI\AiCreditOperatingMode —
 * disabled/shadow/enforced). Mirrors ManageAiCreditsRequest's shape (reason
 * + explicit confirmation) since changing this is a real commercial/
 * operational decision (ENFORCED can start blocking paying customers from AI
 * analysis; DISABLED stops accounting entirely), not a routine settings
 * change. Authorisation is enforced by the `role:Super Admin` route group,
 * not here.
 */
class UpdateAiCreditOperatingModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::in(AiCreditOperatingMode::ALL)],
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }
}
