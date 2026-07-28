<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /billing/checkout — the sole mutating Billing endpoint (Stripe
 * Sandbox — Slice C2). Uses FakeBillingProvider (bound automatically in
 * the testing environment) — no real Stripe call is ever made here.
 */
class CheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('billing.enabled', true);
        Config::set('billing.checkout_success_url', 'https://app.test/app/settings/billing/checkout/success');
        Config::set('billing.checkout_cancel_url', 'https://app.test/app/settings/billing/checkout/cancelled');
    }

    private function org(): Organization
    {
        return Organization::create([
            'name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000),
            'email' => 'billing@acme.test', 'timezone' => 'Europe/London',
        ]);
    }

    private function plan(string $code): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function mapping(PricingPlan $plan, string $interval = 'monthly'): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => $interval,
            'currency' => 'GBP', 'provider_product_id' => 'prod_test', 'provider_price_id' => 'price_test_' . $interval,
            'livemode' => false, 'unit_amount' => 29900, 'is_active' => true,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])
            ->assertUnauthorized();
    }

    public function test_creates_a_checkout_session_for_an_approved_plan(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly']);

        $response->assertOk();
        $this->assertNotEmpty($response->json('checkout_url'));
        $this->assertDatabaseHas('billing_checkout_sessions', ['organization_id' => $org->id]);
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'status' => SubscriptionStatus::PENDING_PAYMENT]);
    }

    public function test_response_never_exposes_a_provider_price_or_session_id(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly']);

        $body = json_encode($response->json());
        $this->assertStringNotContainsString('price_test_monthly', $body);
        $this->assertStringNotContainsString('prod_test', $body);
        $this->assertArrayNotHasKey('provider_checkout_session_id', $response->json());
    }

    public function test_rejects_a_plan_code_with_no_active_mapping(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->plan('enterprise'); // no mapping created — Contact Sales only

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout', ['plan_code' => 'enterprise', 'billing_interval' => 'monthly']);

        $response->assertStatus(422)->assertJsonPath('code', 'checkout_unavailable');
        $this->assertDatabaseMissing('subscriptions', ['organization_id' => $org->id]);
    }

    public function test_rejects_an_unknown_plan_code(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'does-not-exist', 'billing_interval' => 'monthly'])
            ->assertStatus(422);
    }

    public function test_rejects_unsupported_billing_interval(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'weekly'])
            ->assertStatus(422);
    }

    public function test_rejects_when_organisation_already_has_an_active_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-EXIST-1', 'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly']);

        $response->assertStatus(409)->assertJsonPath('code', 'subscription_conflict');
    }

    public function test_a_cancelled_historical_subscription_does_not_block_a_new_checkout(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-OLD-1', 'status' => SubscriptionStatus::CANCELLED,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])
            ->assertOk();
    }

    public function test_duplicate_request_reuses_the_same_local_subscription_and_session(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();
        $second = $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();

        $this->assertSame(1, Subscription::where('organization_id', $org->id)->count());
        $this->assertSame($first->json('id'), $second->json('id'));
    }

    public function test_caller_cannot_override_success_or_cancel_url(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', [
            'plan_code' => 'essential',
            'billing_interval' => 'monthly',
            'success_url' => 'https://evil.test/steal',
            'cancel_url' => 'https://evil.test/steal',
        ])->assertOk();

        $session = BillingCheckoutSession::where('organization_id', $org->id)->firstOrFail();
        $this->assertStringStartsWith('https://app.test/app/settings/billing/checkout/success', $session->success_url);
        $this->assertSame('https://app.test/app/settings/billing/checkout/cancelled', $session->cancel_url);
    }

    /**
     * Phase E4 — "do not trap the customer" self-heal. A pending_payment
     * subscription whose only Checkout Session has expired must never
     * permanently block a fresh attempt (previously: hasConflictingSubscription()
     * would refuse this with subscription_conflict forever).
     */
    public function test_starting_checkout_auto_expires_a_stale_pending_subscription_with_no_resumable_session(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        $stale = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-STALE-1', 'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        BillingCheckoutSession::create([
            'organization_id' => $org->id, 'subscription_id' => $stale->id, 'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id, 'provider' => 'stripe', 'provider_checkout_session_id' => 'cs_stale_1',
            'internal_reference' => 'CHK-STALE-1', 'status' => \App\Support\Billing\CheckoutSessionStatus::EXPIRED,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/cs_stale_1',
            'success_url' => 'https://app.test/success', 'cancel_url' => 'https://app.test/cancel',
            'expires_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();

        $this->assertSame(SubscriptionStatus::EXPIRED, $stale->fresh()->status);
        $this->assertSame(1, Subscription::where('organization_id', $org->id)->where('status', SubscriptionStatus::PENDING_PAYMENT)->count());
        $this->assertSame(2, Subscription::where('organization_id', $org->id)->count());
    }

    public function test_starting_checkout_for_a_different_plan_also_auto_expires_a_stale_pending_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential');
        $this->mapping($essential);
        $professional = $this->plan('professional');
        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $professional->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_product_id' => 'prod_test_pro', 'provider_price_id' => 'price_test_pro_monthly',
            'livemode' => false, 'unit_amount' => 79900, 'is_active' => true,
        ]);

        $stale = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $essential->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-STALE-2', 'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        BillingCheckoutSession::create([
            'organization_id' => $org->id, 'subscription_id' => $stale->id, 'pricing_plan_id' => $essential->id,
            'initiated_by_user_id' => $user->id, 'provider' => 'stripe', 'provider_checkout_session_id' => 'cs_stale_2',
            'internal_reference' => 'CHK-STALE-2', 'status' => \App\Support\Billing\CheckoutSessionStatus::EXPIRED,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/cs_stale_2',
            'success_url' => 'https://app.test/success', 'cancel_url' => 'https://app.test/cancel',
            'expires_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])->assertOk();

        $this->assertSame(SubscriptionStatus::EXPIRED, $stale->fresh()->status);
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'pricing_plan_id' => $professional->id, 'status' => SubscriptionStatus::PENDING_PAYMENT]);
    }

    /**
     * A still-valid (unexpired, open) pending Checkout must NOT be
     * silently discarded when the customer tries a different plan — the
     * frontend should prompt (Stage 8, Option A); the backend safety net
     * stays the existing conflict exception, unchanged.
     */
    public function test_still_blocks_a_different_plan_while_a_resumable_checkout_session_exists(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential');
        $this->mapping($essential);
        $professional = $this->plan('professional');
        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $professional->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_product_id' => 'prod_test_pro', 'provider_price_id' => 'price_test_pro_monthly',
            'livemode' => false, 'unit_amount' => 79900, 'is_active' => true,
        ]);

        $pending = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $essential->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-OPEN-1', 'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        BillingCheckoutSession::create([
            'organization_id' => $org->id, 'subscription_id' => $pending->id, 'pricing_plan_id' => $essential->id,
            'initiated_by_user_id' => $user->id, 'provider' => 'stripe', 'provider_checkout_session_id' => 'cs_open_1',
            'internal_reference' => 'CHK-OPEN-1', 'status' => \App\Support\Billing\CheckoutSessionStatus::OPEN,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/cs_open_1',
            'success_url' => 'https://app.test/success', 'cancel_url' => 'https://app.test/cancel',
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout', ['plan_code' => 'professional', 'billing_interval' => 'monthly']);

        $response->assertStatus(409)->assertJsonPath('code', 'subscription_conflict');
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $pending->fresh()->status);
    }

    public function test_never_accepts_a_raw_provider_price_id(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $this->mapping($plan);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout', [
            'plan_code' => 'essential',
            'billing_interval' => 'monthly',
            'provider_price_id' => 'price_should_be_ignored',
        ])->assertOk();

        // The mapping resolved is the ONE created above, regardless of the
        // ignored provider_price_id field — proven by the mapping's own
        // currency/amount reaching the local subscription untouched.
        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'unit_amount' => 29900]);
    }
}
