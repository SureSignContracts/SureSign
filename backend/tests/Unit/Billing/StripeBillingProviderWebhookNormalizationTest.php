<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\StripeBillingProvider;
use Tests\TestCase;

/**
 * Confirms normalizeSubscriptionFromWebhookPayload()/
 * normalizeCheckoutSessionFromWebhookPayload() — the array-based
 * counterparts used by WebhookEventProcessor — read the same fields the
 * SDK-object-based normalizeSubscription() does, including the
 * period-from-primary-item fix (see StripeBillingProviderSubscriptionNormalizationTest),
 * confirming normalizeSubscriptionArray() is genuinely shared rather than
 * duplicated.
 */
class StripeBillingProviderWebhookNormalizationTest extends TestCase
{
    private function provider(): StripeBillingProvider
    {
        return new StripeBillingProvider();
    }

    private function subscriptionPayload(array $overrides = []): array
    {
        return array_replace([
            'id' => 'sub_webhook_1',
            'status' => 'active',
            'customer' => 'cus_webhook_1',
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'livemode' => false,
            'metadata' => ['suresign_subscription_id' => '42'],
            'items' => [
                'data' => [[
                    'current_period_start' => 1735689600,
                    'current_period_end' => 1738368000,
                    'price' => ['id' => 'price_webhook_1', 'product' => 'prod_webhook_1'],
                ]],
            ],
        ], $overrides);
    }

    public function test_normalizes_subscription_period_from_primary_item(): void
    {
        $normalized = $this->provider()->normalizeSubscriptionFromWebhookPayload($this->subscriptionPayload());

        $this->assertSame(1735689600, $normalized['current_period_start']);
        $this->assertSame(1738368000, $normalized['current_period_end']);
    }

    public function test_normalizes_price_and_product_identifiers(): void
    {
        $normalized = $this->provider()->normalizeSubscriptionFromWebhookPayload($this->subscriptionPayload());

        $this->assertSame('price_webhook_1', $normalized['price_id']);
        $this->assertSame('prod_webhook_1', $normalized['product_id']);
    }

    public function test_normalizes_metadata_and_cancellation_timestamps(): void
    {
        $normalized = $this->provider()->normalizeSubscriptionFromWebhookPayload($this->subscriptionPayload([
            'canceled_at' => 1735689600,
            'ended_at' => 1735693200,
        ]));

        $this->assertSame(['suresign_subscription_id' => '42'], $normalized['metadata']);
        $this->assertSame(1735689600, $normalized['cancelled_at']);
        $this->assertSame(1735693200, $normalized['ended_at']);
    }

    public function test_null_safe_with_no_items(): void
    {
        $normalized = $this->provider()->normalizeSubscriptionFromWebhookPayload($this->subscriptionPayload(['items' => ['data' => []]]));

        $this->assertNull($normalized['current_period_start']);
        $this->assertNull($normalized['price_id']);
        $this->assertNull($normalized['product_id']);
    }

    public function test_normalizes_checkout_session_payload(): void
    {
        $payload = [
            'id' => 'cs_webhook_1',
            'status' => 'complete',
            'customer' => 'cus_webhook_1',
            'subscription' => 'sub_webhook_1',
            'livemode' => false,
            'amount_total' => 2999,
            'currency' => 'gbp',
            'metadata' => ['suresign_organization_id' => '7'],
        ];

        $normalized = $this->provider()->normalizeCheckoutSessionFromWebhookPayload($payload);

        $this->assertSame('cs_webhook_1', $normalized['id']);
        $this->assertSame('cus_webhook_1', $normalized['customer_id']);
        $this->assertSame('sub_webhook_1', $normalized['subscription_id']);
        $this->assertSame(2999, $normalized['amount_total']);
        $this->assertSame('GBP', $normalized['currency']);
        $this->assertSame(['suresign_organization_id' => '7'], $normalized['metadata']);
    }
}
