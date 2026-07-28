<?php

namespace App\Support\Entitlements;

use InvalidArgumentException;

/**
 * The entitlement key registry — Entitlement Specification v1, Section 4.
 * The ONLY ten keys that exist; per that section, "no entitlement keys
 * beyond these ten are proposed," and the existing Spatie role/permission
 * system must never be turned into billing entitlements (a user's ability
 * to edit a Site Instruction is an authorization concern, not a
 * commercial one — see `authorize()` methods across the controllers,
 * which remain the correct place for that, untouched by this class).
 *
 * Deliberately a plain class with string constants — matching this
 * codebase's existing vocabulary convention (`SubscriptionStatus`,
 * `WebhookProcessingStatus`, `TransitionSource`, etc.) — rather than a
 * native PHP `enum`, which no other part of this codebase uses yet. The
 * checkpoint brief explicitly permits "an equivalent existing project
 * convention," and introducing a first-ever native enum for one new
 * subsystem would be a more disruptive, less consistent choice than
 * reusing the established pattern.
 *
 * **Deliberately does NOT cover platform modules** (Projects, Contracts,
 * Trade Packages, RFIs, Meetings, Site Reports, QA, Snagging, Delay
 * Events, EOT, Loss & Expense, Final Accounts, reporting, notifications,
 * exports, etc.). Every one of those is available uniformly to any
 * active subscription today — there is no commercial gating at module
 * granularity anywhere in the approved Commercial Strategy or Entitlement
 * Specification, and inventing `Feature` cases for them would directly
 * contradict Entitlement Specification v1 §2 principle 10 ("dormant
 * future entitlement keys must not create current commercial promises")
 * and its explicit "no entitlement keys beyond these ten" constraint. See
 * this checkpoint's report for the full reconciliation between the
 * broader module inventory requested and this deliberately narrow
 * catalogue.
 */
class Feature
{
    // ─── Usage entitlements ────────────────────────────────────────────
    public const MAX_ACTIVE_PROJECTS = 'max_active_projects';
    public const AI_ANALYSES_PER_MONTH = 'ai_analyses_per_month';
    public const STORAGE_GB = 'storage_gb';

    // ─── Feature entitlements ──────────────────────────────────────────
    public const CUSTOM_BRANDING = 'custom_branding';
    public const ADVANCED_REPORTING = 'advanced_reporting';
    public const PRIORITY_SUPPORT = 'priority_support';
    public const ACCOUNTING_EXPORTS = 'accounting_exports';
    public const API_ACCESS = 'api_access';

    // ─── Reserved / dormant (never enforced, sold, or shown) ───────────
    public const MAX_USERS = 'max_users';
    public const MAX_ORGANISATIONS = 'max_organisations';

    // ─── Registry Amendment, 2026-07-27 (Entitlement Specification v1 §4a) ──
    // Deliberately distinct from AI_ANALYSES_PER_MONTH — analysis count and
    // weighted AI-credit consumption measure different things; this key
    // does NOT replace that one (the eventual migration described in §20
    // remains a separate, still-blocked decision). Provisional, not
    // founder-approved values; raw value never customer-visible — only a
    // derived percentage is, via a dedicated presentation service. See
    // App\Services\Intelligence\AiCreditUsageService.
    public const AI_CREDITS_PER_MONTH = 'ai_credits_per_month';

    public const ALL = [
        self::MAX_ACTIVE_PROJECTS,
        self::AI_ANALYSES_PER_MONTH,
        self::STORAGE_GB,
        self::CUSTOM_BRANDING,
        self::ADVANCED_REPORTING,
        self::PRIORITY_SUPPORT,
        self::ACCOUNTING_EXPORTS,
        self::API_ACCESS,
        self::MAX_USERS,
        self::MAX_ORGANISATIONS,
        self::AI_CREDITS_PER_MONTH,
    ];

    /**
     * One row per registry entry — Section 4's table, transcribed
     * directly. `sold` / `enforced` mirror that table's "Currently sold" /
     * "Currently enforced" columns exactly (both `false` for every key
     * this checkpoint, since nothing enforces anything yet — see
     * `FeatureGate`).
     */
    private const REGISTRY = [
        self::MAX_ACTIVE_PROJECTS => [
            'display_name' => 'Active Projects',
            'description' => 'Maximum number of projects in a non-archived/active state.',
            'category' => EntitlementCategory::USAGE,
            'value_type' => EntitlementValueType::INTEGER,
            'unit' => 'projects',
            'enforcement_level' => EnforcementLevel::SOFT_LIMIT,
            'sold' => true,
            'customer_visible' => true,
            'overrideable' => true,
        ],
        self::AI_ANALYSES_PER_MONTH => [
            'display_name' => 'AI Analyses per Month',
            'description' => 'Number of AI contract/trade-package analyses permitted per period.',
            'category' => EntitlementCategory::USAGE,
            'value_type' => EntitlementValueType::INTEGER,
            'unit' => 'analyses',
            'enforcement_level' => EnforcementLevel::SOFT_LIMIT,
            'sold' => true,
            // Entitlement Specification v1 §20/§46.5 — deprecated in favour of
            // AI_CREDITS_PER_MONTH (2026-07-27), never deleted (historical
            // snapshots/rows under this key remain valid forever; see
            // FeatureGate's snapshot-resolution docblock). Still resolvable,
            // still has real configured values, but is no longer the entitlement
            // customers' AI usage is measured against — see AiCreditUsageService.
            'deprecated_in_favor_of' => self::AI_CREDITS_PER_MONTH,
            'customer_visible' => true,
            'overrideable' => true,
        ],
        self::STORAGE_GB => [
            'display_name' => 'Storage Allowance',
            'description' => 'Total stored document/generated-file volume permitted.',
            'category' => EntitlementCategory::USAGE,
            'value_type' => EntitlementValueType::DECIMAL,
            'unit' => 'GB',
            'enforcement_level' => EnforcementLevel::WARNING,
            'sold' => true,
            'customer_visible' => true,
            'overrideable' => true,
        ],
        self::CUSTOM_BRANDING => [
            'display_name' => 'Custom Branding',
            'description' => "Organisation's own logo/letterhead/colours applied to generated documents.",
            'category' => EntitlementCategory::FEATURE,
            'value_type' => EntitlementValueType::BOOLEAN,
            'unit' => null,
            'enforcement_level' => EnforcementLevel::HARD_LIMIT,
            'sold' => true,
            'customer_visible' => true,
            'overrideable' => false,
        ],
        self::ADVANCED_REPORTING => [
            'display_name' => 'Advanced Reporting',
            'description' => 'Cross-project reporting (e.g. consolidated upcoming-deadline views).',
            'category' => EntitlementCategory::FEATURE,
            'value_type' => EntitlementValueType::BOOLEAN,
            'unit' => null,
            'enforcement_level' => EnforcementLevel::HARD_LIMIT,
            'sold' => true,
            'customer_visible' => true,
            'overrideable' => true,
        ],
        self::PRIORITY_SUPPORT => [
            'display_name' => 'Priority Support',
            'description' => 'Faster support response expectation, possible named contact.',
            'category' => EntitlementCategory::FEATURE,
            'value_type' => EntitlementValueType::BOOLEAN,
            'unit' => null,
            'enforcement_level' => EnforcementLevel::INFORMATIONAL,
            'sold' => true,
            'customer_visible' => true,
            'overrideable' => true,
        ],
        self::ACCOUNTING_EXPORTS => [
            'display_name' => 'Accounting Exports',
            'description' => 'Export or integration with accounting software (Xero/QuickBooks/Sage).',
            'category' => EntitlementCategory::FEATURE,
            'value_type' => EntitlementValueType::BOOLEAN,
            'unit' => null,
            'enforcement_level' => EnforcementLevel::HARD_LIMIT,
            // Not sold: the feature itself doesn't exist yet — see
            // Commercial Strategy §20. Never enable/sell until built.
            'sold' => false,
            'customer_visible' => true,
            'overrideable' => false,
        ],
        self::API_ACCESS => [
            'display_name' => 'API Access',
            'description' => 'Access to a public SureSign API.',
            'category' => EntitlementCategory::FEATURE,
            'value_type' => EntitlementValueType::BOOLEAN,
            'unit' => null,
            'enforcement_level' => EnforcementLevel::HARD_LIMIT,
            // Not sold: no public API exists in this codebase today.
            'sold' => false,
            'customer_visible' => true,
            'overrideable' => false,
        ],
        self::MAX_USERS => [
            'display_name' => 'Max Users',
            'description' => 'Reserved for possible future seat-based licensing. Must never be enforced, sold, or shown — exists only as a vocabulary placeholder.',
            'category' => EntitlementCategory::RESERVED,
            'value_type' => EntitlementValueType::INTEGER,
            'unit' => 'users',
            'enforcement_level' => null,
            'sold' => false,
            'customer_visible' => false,
            'overrideable' => false,
        ],
        self::MAX_ORGANISATIONS => [
            'display_name' => 'Max Organisations',
            'description' => 'Reserved for possible future organisation-group/subsidiary support. Same dormant status as Max Users.',
            'category' => EntitlementCategory::RESERVED,
            'value_type' => EntitlementValueType::INTEGER,
            'unit' => 'organisations',
            'enforcement_level' => null,
            'sold' => false,
            'customer_visible' => false,
            'overrideable' => false,
        ],
        self::AI_CREDITS_PER_MONTH => [
            'display_name' => 'AI Credits per Month',
            'description' => 'Provisional, weighted monthly AI-credit allowance — distinct from ai_analyses_per_month. See Entitlement Specification v1 §4a.',
            'category' => EntitlementCategory::USAGE,
            'value_type' => EntitlementValueType::INTEGER,
            'unit' => 'credits',
            'enforcement_level' => EnforcementLevel::SOFT_LIMIT,
            'sold' => false,
            // The raw allowance/used numbers are never customer-visible —
            // only a derived 0-100% is, via AiCreditUsageService. This
            // flag governs the registry entry itself (e.g. internal admin
            // display), not the customer presentation contract.
            'customer_visible' => false,
            'overrideable' => true,
        ],
    ];

    public static function isValid(string $key): bool
    {
        return in_array($key, self::ALL, true);
    }

    public static function displayName(string $key): string
    {
        return self::entry($key)['display_name'];
    }

    /**
     * Stage X — the Pricing Management entitlement editor's "Description"
     * field (Entitlement Specification v1's table, transcribed verbatim).
     * Purely descriptive registry metadata, same as displayName()/unit() —
     * never persisted anywhere else.
     */
    public static function description(string $key): string
    {
        return self::entry($key)['description'];
    }

    public static function category(string $key): string
    {
        return self::entry($key)['category'];
    }

    public static function valueType(string $key): string
    {
        return self::entry($key)['value_type'];
    }

    public static function unit(string $key): ?string
    {
        return self::entry($key)['unit'];
    }

    /**
     * Null for reserved/dormant keys — enforcement is a meaningless
     * question for something that is never enforced at all.
     */
    public static function enforcementLevel(string $key): ?string
    {
        return self::entry($key)['enforcement_level'];
    }

    /**
     * `max_users`/`max_organisations` only — see Section 3/Section 2
     * principle 10. A dormant key must never be interpreted as sold,
     * enforced, or customer-visible regardless of what any plan
     * definition might otherwise claim — `FeatureGate` consults this
     * directly rather than trusting plan data alone (defence in depth).
     */
    public static function isDormant(string $key): bool
    {
        return self::category($key) === EntitlementCategory::RESERVED;
    }

    public static function isCurrentlySold(string $key): bool
    {
        return self::entry($key)['sold'] && !self::isDormant($key);
    }

    public static function isCustomerVisible(string $key): bool
    {
        return self::entry($key)['customer_visible'] && !self::isDormant($key);
    }

    public static function isOverrideable(string $key): bool
    {
        return self::entry($key)['overrideable'];
    }

    /**
     * Entitlement Specification v1 §20 — a deprecated key is never deleted
     * and remains fully resolvable (existing snapshots/rows under it stay
     * valid), it simply isn't the entitlement new commercial decisions
     * should measure against going forward. `?? null` rather than requiring
     * every registry entry to declare this — only AI_ANALYSES_PER_MONTH
     * sets it today.
     */
    public static function isDeprecated(string $key): bool
    {
        return (self::entry($key)['deprecated_in_favor_of'] ?? null) !== null;
    }

    public static function deprecatedInFavorOf(string $key): ?string
    {
        return self::entry($key)['deprecated_in_favor_of'] ?? null;
    }

    public static function isFeatureFlag(string $key): bool
    {
        return self::category($key) === EntitlementCategory::FEATURE;
    }

    public static function isUsageAllowance(string $key): bool
    {
        return self::category($key) === EntitlementCategory::USAGE;
    }

    private static function entry(string $key): array
    {
        if (!isset(self::REGISTRY[$key])) {
            throw new InvalidArgumentException("Unknown entitlement feature key: \"{$key}\".");
        }

        return self::REGISTRY[$key];
    }
}
