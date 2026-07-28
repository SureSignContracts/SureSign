<?php

namespace App\Support\Entitlements;

/**
 * The three entitlement categories from
 * internal-docs/commercial/suresign-entitlement-specification-v1.md
 * (Section 3). "Enterprise or negotiated" is deliberately NOT a fourth
 * category here — per that section, a negotiated entitlement is still a
 * `FEATURE` or `USAGE` entitlement in shape; only its *value* is
 * individually agreed rather than inherited from a standard plan default
 * (see `EntitlementSource::NEGOTIATED_OVERRIDE`).
 */
class EntitlementCategory
{
    public const FEATURE = 'feature';
    public const USAGE = 'usage';

    /**
     * `max_users`/`max_organisations` — exist in the vocabulary with no
     * current commercial meaning. Never enforced, never sold, never
     * customer-visible. See Feature::isDormant().
     */
    public const RESERVED = 'reserved';

    public const ALL = [
        self::USAGE,
        self::FEATURE,
        self::RESERVED,
    ];

    /**
     * Stage X — the Pricing Management entitlement editor's section
     * headers are generated from this metadata, never hardcoded per
     * feature key. Adding a future approved category here (Section 3 of a
     * later Entitlement Specification version) is the ONLY change needed
     * for it to appear in the editor, correctly labelled, with no UI code
     * change — see PricingManagementService::entitlementsForPlan().
     */
    private const REGISTRY = [
        self::USAGE => [
            'label' => 'Usage Limits',
            'description' => 'Quantitative allowances, generally with a reset period — a number or "unlimited", not a plain on/off.',
        ],
        self::FEATURE => [
            'label' => 'Commercial Features',
            'description' => 'Boolean or enumerated capabilities — either included on this plan or not.',
        ],
        self::RESERVED => [
            'label' => 'Reserved',
            'description' => 'Vocabulary placeholders for possible future capabilities. Never enforced, sold, or shown to customers today — not currently configurable.',
        ],
    ];

    public static function isValid(string $category): bool
    {
        return in_array($category, self::ALL, true);
    }

    public static function label(string $category): string
    {
        return self::entry($category)['label'];
    }

    public static function description(string $category): string
    {
        return self::entry($category)['description'];
    }

    private static function entry(string $category): array
    {
        if (!isset(self::REGISTRY[$category])) {
            throw new \InvalidArgumentException("Invalid entitlement category: \"{$category}\".");
        }

        return self::REGISTRY[$category];
    }
}
