<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\StripeBillingProvider;
use Tests\TestCase;

/**
 * Slice E2 — confirms StripeBillingProvider's normalized Portal
 * Configuration shape is the exact flat shape BillingPortalService's drift
 * check (`configurationIsSafe()`) expects, built from
 * \Stripe\BillingPortal\Configuration::constructFrom() (no network call) —
 * matching the installed stripe-php SDK (v21.0.0) property layout exactly
 * (features.{feature}.enabled, features.customer_update.allowed_updates).
 */
class StripeBillingProviderPortalConfigurationNormalizationTest extends TestCase
{
    private function fixture(array $overrides = []): \Stripe\BillingPortal\Configuration
    {
        return \Stripe\BillingPortal\Configuration::constructFrom(array_replace_recursive([
            'id' => 'bpc_fixture_1',
            'object' => 'billing_portal.configuration',
            'active' => true,
            'is_default' => false,
            'livemode' => false,
            'name' => 'SureSign Restricted Billing Portal — Test',
            'metadata' => ['suresign_restricted_billing_portal' => 'v1'],
            'features' => [
                'payment_method_update' => ['enabled' => true],
                'invoice_history' => ['enabled' => true],
                'customer_update' => ['enabled' => true, 'allowed_updates' => ['address', 'phone', 'tax_id']],
                'subscription_cancel' => ['enabled' => false, 'mode' => 'at_period_end', 'cancellation_reason' => ['enabled' => false, 'options' => []]],
                'subscription_update' => ['enabled' => false, 'default_allowed_updates' => [], 'proration_behavior' => 'none'],
            ],
        ], $overrides));
    }

    public function test_flattens_feature_enabled_flags(): void
    {
        $normalized = $this->invokeNormalize($this->fixture());

        $this->assertTrue($normalized['features']['payment_method_update']);
        $this->assertTrue($normalized['features']['invoice_history']);
        $this->assertTrue($normalized['features']['customer_update']);
        $this->assertFalse($normalized['features']['subscription_cancel']);
        $this->assertFalse($normalized['features']['subscription_update']);
    }

    public function test_carries_through_customer_update_allowed_fields(): void
    {
        $normalized = $this->invokeNormalize($this->fixture());

        $this->assertSame(['address', 'phone', 'tax_id'], $normalized['features']['customer_update_allowed_fields']);
    }

    public function test_carries_through_metadata_and_mode(): void
    {
        $normalized = $this->invokeNormalize($this->fixture());

        $this->assertSame('v1', $normalized['metadata']['suresign_restricted_billing_portal']);
        $this->assertFalse($normalized['livemode']);
        $this->assertSame('bpc_fixture_1', $normalized['id']);
    }

    public function test_detects_drifted_unsafe_configuration(): void
    {
        $normalized = $this->invokeNormalize($this->fixture([
            'features' => ['subscription_cancel' => ['enabled' => true]],
        ]));

        $this->assertTrue($normalized['features']['subscription_cancel']);
    }

    private function invokeNormalize(\Stripe\BillingPortal\Configuration $configuration): array
    {
        $provider = new StripeBillingProvider();
        $method = new \ReflectionMethod(StripeBillingProvider::class, 'normalizePortalConfiguration');
        $method->setAccessible(true);

        return $method->invoke($provider, $configuration);
    }
}
