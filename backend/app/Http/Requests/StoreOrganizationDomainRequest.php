<?php

namespace App\Http\Requests;

use App\Models\OrganizationDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Input for POST /organizations/{organization}/domains — Super Admin only
 * (see routes/api.php). Registers a new customer-owned domain claim
 * (pending verification) — see App\Services\Organizations\DomainVerificationService.
 */
class StoreOrganizationDomainRequest extends FormRequest
{
    /** RFC 1035-ish hostname: labels of letters/digits/hyphens, at least one dot (a bare TLD is never valid here). */
    private const HOSTNAME_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'hostname' => ['required', 'string', 'max:255'],
            'reason' => 'required|string|min:10|max:1000',
            'confirmed' => 'required|accepted',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $raw = $this->input('hostname');
            if (! is_string($raw) || $raw === '') {
                return;
            }

            $normalized = strtolower(trim($raw));

            if (! preg_match(self::HOSTNAME_PATTERN, $normalized)) {
                $validator->errors()->add('hostname', 'Enter a valid domain hostname, e.g. contracts.example.com.');

                return;
            }

            $rootDomain = config('organisation_branding.root_domain');
            if ($rootDomain && ($normalized === strtolower($rootDomain) || str_ends_with($normalized, '.' . strtolower($rootDomain)))) {
                $validator->errors()->add('hostname', 'That hostname is reserved for SureSign-branded subdomains and cannot be registered as a customer domain.');

                return;
            }

            if (OrganizationDomain::where('hostname', $normalized)->exists()) {
                $validator->errors()->add('hostname', 'That domain has already been claimed.');
            }
        });
    }

    public function normalizedHostname(): string
    {
        return strtolower(trim($this->validated('hostname')));
    }
}
