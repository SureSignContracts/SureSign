<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SuresignSetting;

class CurrencyService
{
    /**
     * Resolve the display currency symbol for a project, following platform priority:
     *
     *   1. Project-level currency (user-configured, reliable)
     *   2. SureSign platform global currency symbol
     *   3. GBP / £ hard fallback
     *
     * Contract.currency is intentionally excluded here because it may have been
     * populated by AI extraction (e.g. the contract document mentions "AUD" in a
     * clause but the project itself is GBP). Use resolveForContract() if you
     * need to include contract currency as a last resort.
     */
    public static function resolveSymbol(Project $project): string
    {
        // 1. Project-level currency code → symbol
        if (!empty($project->currency)) {
            return self::codeToSymbol($project->currency);
        }

        // 2. Platform global setting
        $settings = SuresignSetting::instance();
        if (!empty($settings->currency_symbol)) {
            return $settings->currency_symbol;
        }

        // 3. Hard fallback — GBP
        return '£';
    }

    /**
     * Returns the currency code to store on a contract from AI analysis, but
     * only if it is consistent with the platform or project currency.
     *
     * Returns null when the AI-extracted code should be rejected (prevents AUD
     * silently overriding GBP on a UK project just because the contract PDF
     * contains the word "AUD" somewhere in its text).
     */
    public static function validateAiExtractedCode(string $extractedCode, Project $project): ?string
    {
        $code = strtoupper(trim($extractedCode));
        if (strlen($code) !== 3) {
            return null;
        }

        // Project-level currency overrides everything
        if (!empty($project->currency)) {
            return strtoupper($project->currency) === $code ? $code : null;
        }

        // Platform-level currency
        $platformCode = strtoupper(SuresignSetting::instance()->currency ?? 'GBP');
        return $platformCode === $code ? $code : null;
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
            'CAD'   => 'CA$',
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
