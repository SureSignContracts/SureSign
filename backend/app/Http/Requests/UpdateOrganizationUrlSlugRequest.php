<?php

namespace App\Http\Requests;

use App\Services\Organizations\OrganizationUrlSlugService;
use App\Support\Organizations\UrlSlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input for PUT /organizations/{organization}/url-slug (Super Admin only —
 * see the route's own `role:Super Admin` gate). `url_slug` is nullable to
 * support explicitly removing an organisation's branded hostname (falls
 * back to the default SureSign hostname — see OrganisationUrlGenerator).
 *
 * `reason` mirrors ManageAiCreditsRequest/UpdateAiCreditOperatingModeRequest's
 * existing convention for a deliberate, auditable change to organisation-
 * facing production infrastructure.
 *
 * Validation itself delegates to `OrganizationUrlSlugService::validateCandidate()`
 * — the SAME method the customer self-service Form Request
 * (`UpdateOwnOrganizationUrlSlugRequest`) calls, so format/reserved/
 * uniqueness/history rules can never drift between the two paths.
 */
class UpdateOrganizationUrlSlugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url_slug' => ['nullable', 'string', 'max:' . UrlSlugValidator::MAX_LENGTH],
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $raw = $this->input('url_slug');

            if ($raw === null || $raw === '') {
                return;
            }

            $normalized = UrlSlugValidator::normalize($raw);
            $organizationId = $this->route('organization')?->id;

            $errors = app(OrganizationUrlSlugService::class)->validateCandidate($normalized, $organizationId);

            foreach ($errors as $error) {
                $validator->errors()->add('url_slug', $error);
            }
        });
    }

    /**
     * The normalised (lowercased/trimmed) slug, or null when the field was
     * omitted/blank — the only value the controller should ever persist.
     */
    public function normalizedUrlSlug(): ?string
    {
        $raw = $this->validated('url_slug');

        return ($raw === null || $raw === '') ? null : UrlSlugValidator::normalize($raw);
    }
}
