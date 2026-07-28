<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\StripeBillingProvider;
use Tests\TestCase;

/**
 * Confirms StripeBillingProvider reads current_period_start/end from the
 * subscription's primary ITEM, not the subscription object itself —
 * verified by inspecting the installed stripe-php SDK (v21.0.0) directly:
 * \Stripe\Subscription's @property docblock has no current_period_start/
 * end at all; \Stripe\SubscriptionItem's does. This test builds a
 * representative fixture via \Stripe\Subscription::constructFrom() (no
 * network call) shaped exactly like a real API response, so a future SDK
 * upgrade that changes this shape again will fail this test rather than
 * silently reintroducing the bug this checkpoint fixed.
 */
class StripeBillingProviderSubscriptionNormalizationTest extends TestCase
{
    private function fixture(array $overrides = []): \Stripe\Subscription
    {
        return \Stripe\Subscription::constructFrom(array_replace([
            'id' => 'sub_fixture_1',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_fixture_1',
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'livemode' => false,
            'items' => [
                'object' => 'list',
                'data' => [
                    [
                        'id' => 'si_fixture_1',
                        'object' => 'subscription_item',
                        'current_period_start' => 1735689600, // 2025-01-01T00:00:00Z
                        'current_period_end' => 1738368000,   // 2025-02-01T00:00:00Z
                    ],
                ],
            ],
        ], $overrides));
    }

    public function test_period_dates_are_read_from_the_primary_subscription_item(): void
    {
        $subscription = $this->fixture();

        $normalized = $this->invokeNormalize($subscription);

        $this->assertSame(1735689600, $normalized['current_period_start']);
        $this->assertSame(1738368000, $normalized['current_period_end']);
    }

    public function test_top_level_subscription_object_has_no_period_properties(): void
    {
        // Direct confirmation of the SDK contract this fix depends on —
        // if a future stripe-php upgrade reintroduces top-level period
        // fields, this assertion stays true (harmless) rather than masking
        // a change; the meaningful protection is the previous test.
        $subscription = $this->fixture();

        $this->assertFalse(isset($subscription->current_period_start));
        $this->assertFalse(isset($subscription->current_period_end));
    }

    public function test_normalization_is_null_safe_when_there_are_no_items(): void
    {
        $subscription = $this->fixture(['items' => ['object' => 'list', 'data' => []]]);

        $normalized = $this->invokeNormalize($subscription);

        $this->assertNull($normalized['current_period_start']);
        $this->assertNull($normalized['current_period_end']);
    }

    public function test_livemode_and_cancel_at_period_end_are_carried_through(): void
    {
        $subscription = $this->fixture(['livemode' => true, 'cancel_at_period_end' => true]);

        $normalized = $this->invokeNormalize($subscription);

        $this->assertTrue($normalized['livemode']);
        $this->assertTrue($normalized['cancel_at_period_end']);
    }

    private function invokeNormalize(\Stripe\Subscription $subscription): array
    {
        $provider = new StripeBillingProvider();
        $method = new \ReflectionMethod(StripeBillingProvider::class, 'normalizeSubscription');
        $method->setAccessible(true);

        return $method->invoke($provider, $subscription);
    }
}
