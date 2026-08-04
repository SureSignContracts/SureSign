<?php

namespace App\Http\Requests;

use App\Services\Organizations\OrganizationUrlSlugService;
use App\Support\Organizations\UrlSlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input for PUT /organization/url-slug — the customer self-service Custom
 * URL section on Company Branding. Deliberately NO `reason`/`confirmed`
 * fields (those are a Super-Admin-audit convention, not appropriate
 * customer-facing copy — the frontend shows its own plain-language
 * confirmation dialog instead, per the approved UI requirements).
 *
 * Validation delegates to the SAME `OrganizationUrlSlugService::validateCandidate()`
 * the Super Admin Form Request calls — format/reserved/uniqueness/history
 * rules can never drift between the two paths.
 */
class UpdateOwnOrganizationUrlSlugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url_slug' => ['required', 'string', 'max:' . UrlSlugValidator::MAX_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $raw = $this->input('url_slug');

            if (! is_string($raw) || $raw === '') {
                return;
            }

            $normalized = UrlSlugValidator::normalize($raw);
            $organizationId = $this->user()?->organization_id;

            $errors = app(OrganizationUrlSlugService::class)->validateCandidate($normalized, $organizationId);

            foreach ($errors as $error) {
                $validator->errors()->add('url_slug', $error);
            }
        });
    }

    public function normalizedUrlSlug(): string
    {
        return UrlSlugValidator::normalize($this->validated('url_slug'));
    }
}
