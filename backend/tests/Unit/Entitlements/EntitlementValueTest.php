<?php

namespace Tests\Unit\Entitlements;

use App\Support\Entitlements\EntitlementSource;
use App\Support\Entitlements\EntitlementValue;
use App\Support\Entitlements\EntitlementValueType;
use App\Support\Entitlements\Feature;
use Tests\TestCase;

class EntitlementValueTest extends TestCase
{
    public function test_unlimited_value_must_have_a_null_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 5, true, EntitlementSource::PLAN_DEFAULT);
    }

    public function test_unlimited_helper_produces_a_null_value_with_the_flag_set(): void
    {
        $value = EntitlementValue::unlimited(Feature::MAX_ACTIVE_PROJECTS, EntitlementSource::NEGOTIATED_OVERRIDE, 'projects');

        $this->assertTrue($value->isUnlimited);
        $this->assertNull($value->value);
        $this->assertSame(EntitlementValueType::INTEGER, $value->valueType);
    }

    public function test_value_type_mismatch_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, 'true', false, EntitlementSource::PLAN_DEFAULT);
    }

    public function test_invalid_value_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementValue::make(Feature::CUSTOM_BRANDING, 'not_a_type', true, false, EntitlementSource::PLAN_DEFAULT);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, 'not_a_source');
    }

    public function test_not_applicable_is_distinct_from_not_included(): void
    {
        $notApplicable = EntitlementValue::notApplicable(Feature::ACCOUNTING_EXPORTS);
        $notIncluded = EntitlementValue::notIncluded(Feature::ADVANCED_REPORTING, EntitlementSource::PLAN_DEFAULT);

        $this->assertNull($notApplicable->value);
        $this->assertSame(EntitlementSource::NONE, $notApplicable->source);

        $this->assertFalse($notIncluded->value);
        $this->assertSame(EntitlementSource::PLAN_DEFAULT, $notIncluded->source);
    }

    public function test_as_boolean_rejects_a_non_boolean_entitlement(): void
    {
        $value = EntitlementValue::make(Feature::MAX_ACTIVE_PROJECTS, EntitlementValueType::INTEGER, 5, false, EntitlementSource::PLAN_DEFAULT);

        $this->expectException(\InvalidArgumentException::class);
        $value->asBoolean();
    }

    public function test_as_boolean_returns_the_underlying_value(): void
    {
        $value = EntitlementValue::make(Feature::CUSTOM_BRANDING, EntitlementValueType::BOOLEAN, true, false, EntitlementSource::PLAN_DEFAULT);

        $this->assertTrue($value->asBoolean());
    }
}
