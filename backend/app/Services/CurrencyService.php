<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;

class CurrencyService
{
    /**
     * Resolve the authoritative ISO 4217 currency code for a project, per the
     * inheritance hierarchy:
     *
     *   1. Project-level currency (explicit override, set on create/edit)
     *   2. Organisation default currency
     *   3. Platform default currency (SuresignSetting, Super Admin-configured)
     *   4. GBP — hard system fallback
     *
     * Deliberately never infers currency from project country/location, the
     * browser locale, or AI contract analysis — those are explicitly excluded
     * by design, not omissions. See CurrencyService's currency-inheritance
     * audit in project-context.md for why.
     */
    public static function resolveCode(Project $project): string
    {
        if (!empty($project->currency)) {
            return strtoupper($project->currency);
        }

        $organization = $project->relationLoaded('organization')
            ? $project->organization
            : ($project->organization_id ? Organization::find($project->organization_id) : null);

        return self::resolveOrganizationCode($organization);
    }

    /**
     * Resolve the default currency code for an organisation: its own setting,
     * else the platform default, else GBP. Used both directly (organisation
     * settings UI) and as the middle tier of resolveCode() above.
     */
    public static function resolveOrganizationCode(?Organization $organization): string
    {
        if ($organization && !empty($organization->currency)) {
            return strtoupper($organization->currency);
        }

        return self::platformCode();
    }

    /**
     * Platform default currency code — Super Admin-configured, GBP if unset.
     */
    public static function platformCode(): string
    {
        return strtoupper(SuresignSetting::instance()->currency ?? 'GBP');
    }

    /**
     * Resolve the display currency symbol for a project, following the same
     * hierarchy as resolveCode(). Always derives the symbol from the resolved
     * code (codeToSymbol) rather than a separately-editable symbol string, so
     * there is exactly one source of truth for "what does this currency look
     * like", not two fields that can drift out of sync.
     */
    public static function resolveSymbol(Project $project): string
    {
        return self::codeToSymbol(self::resolveCode($project));
    }

    /**
     * Returns the currency code to store on a contract from AI analysis, but
     * only if it is consistent with the project's already-resolved currency
     * (project override, organisation default, or platform default).
     *
     * Returns null when the AI-extracted code should be rejected — this is
     * what prevents AUD silently overriding GBP on a UK project just because
     * the contract PDF contains the word "AUD" somewhere in its text. AI
     * analysis is never permitted to introduce a currency that doesn't
     * already match the project's authoritative resolved currency; it can
     * only ever confirm it.
     */
    public static function validateAiExtractedCode(string $extractedCode, Project $project): ?string
    {
        $code = strtoupper(trim($extractedCode));
        if (strlen($code) !== 3) {
            return null;
        }

        return self::resolveCode($project) === $code ? $code : null;
    }

    /**
     * Map ISO 4217 currency codes to their common display symbols.
     */
    public static function codeToSymbol(string $code): string
    {
        return match (strtoupper(trim($code))) {
            'GBP'   => '£',
            'USD'   => '$',
            'EUR'   => '€',
            'AUD'   => 'A$',
            'NZD'   => 'NZ$',
            'CAD'   => 'C$',
            'CHF'   => 'CHF',
            'SGD'   => 'S$',
            'HKD'   => 'HK$',
            'JPY'   => '¥',
            'CNY'   => '¥',
            'INR'   => '₹',
            'ZAR'   => 'R',
            'AED'   => 'AED',
            default => strtoupper(trim($code)),
        };
    }
}
