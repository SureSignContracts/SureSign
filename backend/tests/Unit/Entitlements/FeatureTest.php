<?php

namespace Tests\Unit\Entitlements;

use App\Support\Entitlements\EntitlementCategory;
use App\Support\Entitlements\EntitlementValueType;
use App\Support\Entitlements\Feature;
use Tests\TestCase;

class FeatureTest extends TestCase
{
    /**
     * Eleven, not ten, since the Entitlement Specification v1 §4a amendment
     * (2026-07-27) deliberately added `ai_credits_per_month` as a recorded
     * exception to the otherwise-closed registry — see that document's §4a
     * for why it coexists with (never replaces) `ai_analyses_per_month`.
     */
    public function test_registry_contains_exactly_the_eleven_approved_keys(): void
    {
        $this->assertCount(11, Feature::ALL);
        $this->assertContains(Feature::MAX_ACTIVE_PROJECTS, Feature::ALL);
        $this->assertContains(Feature::AI_ANALYSES_PER_MONTH, Feature::ALL);
        $this->assertContains(Feature::STORAGE_GB, Feature::ALL);
        $this->assertContains(Feature::CUSTOM_BRANDING, Feature::ALL);
        $this->assertContains(Feature::ADVANCED_REPORTING, Feature::ALL);
        $this->assertContains(Feature::PRIORITY_SUPPORT, Feature::ALL);
        $this->assertContains(Feature::ACCOUNTING_EXPORTS, Feature::ALL);
        $this->assertContains(Feature::API_ACCESS, Feature::ALL);
        $this->assertContains(Feature::MAX_USERS, Feature::ALL);
        $this->assertContains(Feature::MAX_ORGANISATIONS, Feature::ALL);
        $this->assertContains(Feature::AI_CREDITS_PER_MONTH, Feature::ALL);
    }

    public function test_ai_credits_per_month_is_not_dormant_but_is_not_customer_visible(): void
    {
        // Usage-category and active (unlike MAX_USERS/MAX_ORGANISATIONS'
        // true dormant status) — but the raw registry value is still never
        // customer-visible; only a derived percentage is, via
        // AiCreditUsageService. See Entitlement Specification v1 §4a.
        $this->assertFalse(Feature::isDormant(Feature::AI_CREDITS_PER_MONTH));
        $this->assertSame(EntitlementCategory::USAGE, Feature::category(Feature::AI_CREDITS_PER_MONTH));
        $this->assertFalse(Feature::isCustomerVisible(Feature::AI_CREDITS_PER_MONTH));
        $this->assertFalse(Feature::isCurrentlySold(Feature::AI_CREDITS_PER_MONTH));
    }

    /**
     * Phase G4C.3G — the entitlement migration (Entitlement Specification
     * v1 §46.5). ai_analyses_per_month is deprecated, never deleted: it
     * remains fully resolvable, still has real values, is just no longer
     * the key new commercial decisions measure against.
     */
    public function test_ai_analyses_per_month_is_deprecated_in_favor_of_ai_credits_per_month(): void
    {
        $this->assertTrue(Feature::isDeprecated(Feature::AI_ANALYSES_PER_MONTH));
        $this->assertSame(Feature::AI_CREDITS_PER_MONTH, Feature::deprecatedInFavorOf(Feature::AI_ANALYSES_PER_MONTH));
    }

    public function test_deprecation_is_the_exception_not_the_default(): void
    {
        foreach (Feature::ALL as $key) {
            if ($key === Feature::AI_ANALYSES_PER_MONTH) {
                continue;
            }
            $this->assertFalse(Feature::isDeprecated($key), "{$key} should not be deprecated.");
            $this->assertNull(Feature::deprecatedInFavorOf($key));
        }
    }

    public function test_unknown_feature_key_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Feature::displayName('not_a_real_key');
    }

    public function test_dormant_keys_are_flagged_correctly(): void
    {
        $this->assertTrue(Feature::isDormant(Feature::MAX_USERS));
        $this->assertTrue(Feature::isDormant(Feature::MAX_ORGANISATIONS));
        $this->assertFalse(Feature::isDormant(Feature::CUSTOM_BRANDING));
    }

    public function test_dormant_keys_are_never_sold_or_customer_visible(): void
    {
        $this->assertFalse(Feature::isCurrentlySold(Feature::MAX_USERS));
        $this->assertFalse(Feature::isCustomerVisible(Feature::MAX_USERS));
        $this->assertFalse(Feature::isCurrentlySold(Feature::MAX_ORGANISATIONS));
        $this->assertFalse(Feature::isCustomerVisible(Feature::MAX_ORGANISATIONS));
    }

    public function test_unbuilt_features_are_not_currently_sold(): void
    {
        $this->assertFalse(Feature::isCurrentlySold(Feature::ACCOUNTING_EXPORTS));
        $this->assertFalse(Feature::isCurrentlySold(Feature::API_ACCESS));
    }

    public function test_category_classification_matches_the_registry(): void
    {
        $this->assertSame(EntitlementCategory::USAGE, Feature::category(Feature::MAX_ACTIVE_PROJECTS));
        $this->assertSame(EntitlementCategory::USAGE, Feature::category(Feature::AI_ANALYSES_PER_MONTH));
        $this->assertSame(EntitlementCategory::USAGE, Feature::category(Feature::STORAGE_GB));
        $this->assertSame(EntitlementCategory::FEATURE, Feature::category(Feature::CUSTOM_BRANDING));
        $this->assertSame(EntitlementCategory::RESERVED, Feature::category(Feature::MAX_USERS));
    }

    public function test_value_type_classification_matches_the_registry(): void
    {
        $this->assertSame(EntitlementValueType::INTEGER, Feature::valueType(Feature::MAX_ACTIVE_PROJECTS));
        $this->assertSame(EntitlementValueType::DECIMAL, Feature::valueType(Feature::STORAGE_GB));
        $this->assertSame(EntitlementValueType::BOOLEAN, Feature::valueType(Feature::CUSTOM_BRANDING));
    }

    public function test_feature_flag_versus_usage_allowance_classification(): void
    {
        $this->assertTrue(Feature::isFeatureFlag(Feature::CUSTOM_BRANDING));
        $this->assertFalse(Feature::isUsageAllowance(Feature::CUSTOM_BRANDING));

        $this->assertTrue(Feature::isUsageAllowance(Feature::MAX_ACTIVE_PROJECTS));
        $this->assertFalse(Feature::isFeatureFlag(Feature::MAX_ACTIVE_PROJECTS));
    }

    public function test_display_names_never_expose_raw_keys(): void
    {
        foreach (Feature::ALL as $key) {
            $this->assertNotSame($key, Feature::displayName($key));
        }
    }
}
