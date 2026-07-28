<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\StripeBillingProvider;
use Tests\TestCase;

/**
 * isLivemode() is a pure config read, no network call — safe to test
 * directly against the real StripeBillingProvider (unlike any method that
 * would reach the Stripe API).
 */
class StripeBillingProviderLivemodeTest extends TestCase
{
    public function test_test_mode_secret_key_is_not_livemode(): void
    {
        config(['billing.stripe.secret' => 'sk_test_abc123']);
        $this->assertFalse((new StripeBillingProvider())->isLivemode());
    }

    public function test_live_mode_secret_key_is_livemode(): void
    {
        config(['billing.stripe.secret' => 'sk_live_abc123']);
        $this->assertTrue((new StripeBillingProvider())->isLivemode());
    }

    public function test_empty_secret_key_is_not_livemode(): void
    {
        config(['billing.stripe.secret' => '']);
        $this->assertFalse((new StripeBillingProvider())->isLivemode());
    }
}
