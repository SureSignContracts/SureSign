<?php

namespace App\Http\Requests;

use App\Support\FeatureAvailability\FeatureAvailabilityRegistry;
use App\Support\FeatureAvailability\FeatureAvailabilityStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Input for PUT /admin/feature-availability/{feature_key} — the
 * Super-Admin-only Feature Availability status change. Mirrors
 * ManageAiCreditsRequest / UpdateAiCreditOperatingModeRequest's shape
 * exactly (reason + explicit confirmation) since this is a real
 * operational decision affecting every customer, not a routine settings
 * change. Route-level authorization (`role:Super Admin` only — Admin is
 * deliberately excluded from this management surface entirely, per the
 * Phase A specification) is enforced by the route group, not here.
 */
class UpdateFeatureAvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(FeatureAvailabilityStatus::ALL)],
            'message' => 'nullable|string|max:2000',
            'available_at' => 'nullable|date',
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }

    /**
     * feature_key comes from the route, not the request body — validated
     * here (rather than only in the controller) so an unregistered key or
     * an unsupported status for this specific registry entry both fail as
     * ordinary 422 validation errors, matching this codebase's existing
     * convention of surfacing this kind of rejection through validation
     * rather than a bespoke error response.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $featureKey = (string) $this->route('feature_key');

            if (!FeatureAvailabilityRegistry::isValid($featureKey)) {
                $validator->errors()->add('feature_key', 'This feature is not registered.');

                return;
            }

            $status = $this->input('status');
            if (is_string($status) && !FeatureAvailabilityRegistry::supportsStatus($featureKey, $status)) {
                $validator->errors()->add('status', "This feature does not support the \"{$status}\" status.");
            }
        });
    }
}
