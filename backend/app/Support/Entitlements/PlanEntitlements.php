<?php

namespace App\Support\Entitlements;

/**
 * Part 4 / Entitlement Specification v1 Section 4/7 — the single
 * authoritative place defining what each plan includes. Deliberately a
 * code-level registry, NOT a database table: Section 7 says a plan's
 * defaults "live conceptually alongside the plan (their exact storage
 * location is a Phase 5+ implementation decision, not fixed by this
 * document)" — this class is that Phase 5+ decision, deferred exactly as
 * the specification anticipated, since no `subscription_entitlements`
 * snapshot table exists yet either (see this checkpoint's report on why).
 *
 * **All figures below are indicative placeholders, not approved
 * commercial values** — mirroring the Entitlement Specification's own
 * explicit caveat on its Section 4 table ("indicative recommendations
 * only... not approved values"). Changing these numbers before a real
 * founder sign-off is a business decision, not a code review comment.
 *
 * **Known, accepted limitation of this checkpoint (architecture only, no
 * persistence yet)**: because there is no `subscription_entitlements`
 * snapshot table, `FeatureGate` resolves a subscription's entitlements by
 * calling THIS class with the subscription's current `plan_code_snapshot`
 * — which means a future edit to the numbers in this file WOULD
 * retroactively change what an existing subscription resolves to,
 * something Entitlement Specification v1 §2 principle 4 explicitly
 * forbids ("later pricing-plan changes must not silently alter existing
 * subscriptions"). This is an accepted, documented gap for this
 * architecture-only checkpoint, not an oversight — closing it requires
 * building the `subscription_entitlements` snapshot table (Section 8) in
 * a future checkpoint, at which point `FeatureGate` would read the frozen
 * snapshot instead of calling this class live. See this checkpoint's
 * report for the full reasoning.
 */
class PlanEntitlements
{
    public const ESSENTIAL = 'essential';
    public const PROFESSIONAL = 'professional';
    public const ENTERPRISE = 'enterprise';

    /**
     * @return array<string, EntitlementValue> keyed by Feature::* constant
     */
    public static function forPlanCode(string $planCode): array
    {
        return match ($planCode) {
            self::ESSENTIAL => self::essential(),
            self::PROFESSIONAL => self::professional(),
            self::ENTERPRISE => self::enterpriseBaseline(),
            default => [],
        };
    }

    public static function isKnownPlanCode(string $planCode): bool
    {
        return in_array($planCode, [self::ESSENTIAL, self::PROFESSIONAL, self::ENTERPRISE], true);
    }

    private static function essential(): array
    {
        return [
            Feature::MAX_ACTIVE_PROJECTS => EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 5, false, EntitlementSource::PLAN_DEFAULT, 'projects'),
            Feature::AI_ANALYSES_PER_MONTH => EntitlementValue::make(Feature::AI_ANALYSES_PER_MONTH, EntitlementValueType::INTEGER, 10, false, EntitlementSource::PLAN_DEFAULT, 'analyses'),
            Feature::STORAGE_GB => EntitlementValue::make(Feature::STORAGE_GB, EntitlementValueType::DECIMAL, 50.0, false, EntitlementSource::PLAN_DEFAULT, 'GB'),
            // Included from Essential, deliberately — Commercial Strategy §6:
            // branding is a professionalism baseline, not an upsell.
            Feature::CUSTOM_BRANDING => EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            Feature::ADVANCED_REPORTING => EntitlementValue::notIncluded(Feature::ADVANCED_REPORTING, EntitlementSource::PLAN_DEFAULT),
            Feature::PRIORITY_SUPPORT => EntitlementValue::notIncluded(Feature::PRIORITY_SUPPORT, EntitlementSource::PLAN_DEFAULT),
            // Not yet built — see Feature::REGISTRY's 'sold' => false.
            Feature::ACCOUNTING_EXPORTS => EntitlementValue::notApplicable(Feature::ACCOUNTING_EXPORTS),
            Feature::API_ACCESS => EntitlementValue::notApplicable(Feature::API_ACCESS),
        ];
    }

    private static function professional(): array
    {
        return [
            Feature::MAX_ACTIVE_PROJECTS => EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 25, false, EntitlementSource::PLAN_DEFAULT, 'projects'),
            Feature::AI_ANALYSES_PER_MONTH => EntitlementValue::make(Feature::AI_ANALYSES_PER_MONTH, EntitlementValueType::INTEGER, 50, false, EntitlementSource::PLAN_DEFAULT, 'analyses'),
            Feature::STORAGE_GB => EntitlementValue::make(Feature::STORAGE_GB, EntitlementValueType::DECIMAL, 200.0, false, EntitlementSource::PLAN_DEFAULT, 'GB'),
            Feature::CUSTOM_BRANDING => EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            // The main Essential/Professional differentiator — Commercial
            // Strategy §6.
            Feature::ADVANCED_REPORTING => EntitlementValue::make(Feature::ADVANCED_REPORTING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            Feature::PRIORITY_SUPPORT => EntitlementValue::make(Feature::PRIORITY_SUPPORT, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            Feature::ACCOUNTING_EXPORTS => EntitlementValue::notApplicable(Feature::ACCOUNTING_EXPORTS),
            Feature::API_ACCESS => EntitlementValue::notApplicable(Feature::API_ACCESS),
        ];
    }

    /**
     * A BASELINE only — per Entitlement Specification v1 §18, an
     * Enterprise subscription's entitlements are individually negotiated,
     * never automatically unlimited "for convenience." These values are
     * what a brand-new Enterprise subscription resolves to BEFORE any
     * negotiated override is recorded (Part 8/9's future override seam —
     * see `EntitlementOverrideRepository`) — in real commercial practice,
     * an actual Enterprise deal is expected to immediately layer
     * negotiated overrides on top of this baseline, not rely on it as the
     * final word.
     */
    private static function enterpriseBaseline(): array
    {
        return [
            Feature::MAX_ACTIVE_PROJECTS => EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 100, false, EntitlementSource::PLAN_DEFAULT, 'projects'),
            Feature::AI_ANALYSES_PER_MONTH => EntitlementValue::make(Feature::AI_ANALYSES_PER_MONTH, EntitlementValueType::INTEGER, 200, false, EntitlementSource::PLAN_DEFAULT, 'analyses'),
            Feature::STORAGE_GB => EntitlementValue::make(Feature::STORAGE_GB, EntitlementValueType::DECIMAL, 500.0, false, EntitlementSource::PLAN_DEFAULT, 'GB'),
            Feature::CUSTOM_BRANDING => EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            Feature::ADVANCED_REPORTING => EntitlementValue::make(Feature::ADVANCED_REPORTING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            Feature::PRIORITY_SUPPORT => EntitlementValue::make(Feature::PRIORITY_SUPPORT, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT),
            // Still not built regardless of plan — see Feature registry.
            Feature::ACCOUNTING_EXPORTS => EntitlementValue::notApplicable(Feature::ACCOUNTING_EXPORTS),
            Feature::API_ACCESS => EntitlementValue::notApplicable(Feature::API_ACCESS),
        ];
    }

    /**
     * Entitlement Specification v1 Section 17 — a DEDICATED trial
     * profile, never simply Essential/Professional defaults reused.
     * Generous on active-project/feature-flag dimensions (to demonstrate
     * real product value — Commercial Strategy's "first real workflow"),
     * capped tightly on AI analyses specifically (the one dimension with
     * real, uncontrolled Anthropic API cost exposure during an unpaid
     * period).
     */
    public static function trialProfile(): array
    {
        return [
            Feature::MAX_ACTIVE_PROJECTS => EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 5, false, EntitlementSource::TRIAL, 'projects'),
            Feature::AI_ANALYSES_PER_MONTH => EntitlementValue::make(Feature::AI_ANALYSES_PER_MONTH, EntitlementValueType::INTEGER, 3, false, EntitlementSource::TRIAL, 'analyses'),
            Feature::STORAGE_GB => EntitlementValue::make(Feature::STORAGE_GB, EntitlementValueType::DECIMAL, 10.0, false, EntitlementSource::TRIAL, 'GB'),
            Feature::CUSTOM_BRANDING => EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::TRIAL),
            // Demonstrates Professional-level value during the trial, per
            // Section 17's reasoning — this is the one non-AI dimension
            // deliberately set above Essential's default.
            Feature::ADVANCED_REPORTING => EntitlementValue::make(Feature::ADVANCED_REPORTING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::TRIAL),
            Feature::PRIORITY_SUPPORT => EntitlementValue::notIncluded(Feature::PRIORITY_SUPPORT, EntitlementSource::TRIAL),
            Feature::ACCOUNTING_EXPORTS => EntitlementValue::notApplicable(Feature::ACCOUNTING_EXPORTS),
            Feature::API_ACCESS => EntitlementValue::notApplicable(Feature::API_ACCESS),
        ];
    }
}
