<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\BillingConfigGuard;
use Tests\TestCase;

class BillingConfigGuardTest extends TestCase
{
    public function test_looks_live_detects_live_secret_and_publishable_keys(): void
    {
        $this->assertTrue(BillingConfigGuard::looksLive('sk_live_abc123'));
        $this->assertTrue(BillingConfigGuard::looksLive('pk_live_abc123'));
        $this->assertFalse(BillingConfigGuard::looksLive('sk_test_abc123'));
        $this->assertFalse(BillingConfigGuard::looksLive('pk_test_abc123'));
        $this->assertFalse(BillingConfigGuard::looksLive(''));
    }

    public function test_assert_safe_throws_when_live_secret_present_in_testing(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dangerous']);
        config(['billing.allow_live_keys_in_testing' => false]);

        $this->expectException(\RuntimeException::class);
        BillingConfigGuard::assertSafe($this->app);
    }

    public function test_assert_safe_throws_when_live_publishable_key_present_in_testing(): void
    {
        config(['billing.stripe.key' => 'pk_live_dangerous']);
        config(['billing.allow_live_keys_in_testing' => false]);

        $this->expectException(\RuntimeException::class);
        BillingConfigGuard::assertSafe($this->app);
    }

    public function test_assert_safe_allows_test_keys_in_testing(): void
    {
        config(['billing.stripe.secret' => 'sk_test_fine']);
        config(['billing.stripe.key' => 'pk_test_fine']);
        config(['billing.allow_live_keys_in_testing' => false]);

        BillingConfigGuard::assertSafe($this->app);
        $this->assertTrue(true); // reaching here means no exception was thrown
    }

    public function test_assert_safe_allows_live_keys_when_explicitly_overridden(): void
    {
        config(['billing.stripe.secret' => 'sk_live_dangerous']);
        config(['billing.allow_live_keys_in_testing' => true]);

        BillingConfigGuard::assertSafe($this->app);
        $this->assertTrue(true);
    }

    public function test_billing_disabled_and_enforcement_disabled_by_default(): void
    {
        // Reads the real, uncached config as loaded from .env/.env.example
        // defaults for this checkpoint — both flags must default false.
        $this->assertFalse(config('billing.enabled'));
        $this->assertFalse(config('billing.enforcement_enabled'));
    }
}
