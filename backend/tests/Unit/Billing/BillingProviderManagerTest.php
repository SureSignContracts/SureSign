<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\BillingProviderManager;
use App\Services\Billing\FakeBillingProvider;
use Tests\TestCase;

class BillingProviderManagerTest extends TestCase
{
    public function test_configured_provider_defaults_to_stripe(): void
    {
        $this->assertSame('stripe', (new BillingProviderManager())->configuredProvider());
    }

    public function test_rejects_an_unsupported_configured_provider(): void
    {
        config(['billing.provider' => 'paddle']);

        $this->expectException(\InvalidArgumentException::class);
        (new BillingProviderManager())->configuredProvider();
    }

    public function test_is_enabled_reflects_config(): void
    {
        config(['billing.enabled' => false]);
        $this->assertFalse((new BillingProviderManager())->isEnabled());

        config(['billing.enabled' => true]);
        $this->assertTrue((new BillingProviderManager())->isEnabled());
    }

    public function test_container_resolves_the_fake_provider_in_testing(): void
    {
        // The whole point of BillingServiceProvider's binding: automated
        // tests always get FakeBillingProvider, never a real Stripe client,
        // regardless of what billing.stripe.secret contains.
        $resolved = $this->app->make(BillingProviderInterface::class);

        $this->assertInstanceOf(FakeBillingProvider::class, $resolved);
    }

    public function test_resolve_webhook_secret_selects_test_secret_for_test_mode(): void
    {
        config(['billing.stripe.webhook_secret_test' => 'whsec_test_x']);
        config(['billing.stripe.webhook_secret_live' => 'whsec_live_x']);

        $this->assertSame('whsec_test_x', (new BillingProviderManager())->resolveWebhookSecret(false));
    }

    public function test_resolve_webhook_secret_selects_live_secret_for_live_mode(): void
    {
        config(['billing.stripe.webhook_secret_test' => 'whsec_test_x']);
        config(['billing.stripe.webhook_secret_live' => 'whsec_live_x']);

        $this->assertSame('whsec_live_x', (new BillingProviderManager())->resolveWebhookSecret(true));
    }

    public function test_resolve_webhook_secret_never_falls_back_to_the_other_mode(): void
    {
        config(['billing.stripe.webhook_secret_test' => '']);
        config(['billing.stripe.webhook_secret_live' => 'whsec_live_x']);

        // Test mode requested, but only the LIVE secret is configured —
        // must return empty, never silently use the live one.
        $this->assertSame('', (new BillingProviderManager())->resolveWebhookSecret(false));
    }
}
