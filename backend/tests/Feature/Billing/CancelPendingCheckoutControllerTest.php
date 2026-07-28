<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /billing/checkout/cancel-pending (Phase E4) — the explicit
 * "Cancel Pending Checkout" customer action. Only ever valid while the
 * organisation's subscription is pending_payment; never touches an
 * active/past_due/etc. subscription (that's SubscriptionCancellationController's
 * job, a different commercial operation).
 */
class CancelPendingCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enabled' => true]);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    private function plan(): PricingPlan
    {
        $code = 'plan-' . random_int(1, 1000000);
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function pendingSubscriptionWithSession(Organization $org, User $user, PricingPlan $plan, string $sessionStatus = CheckoutSessionStatus::OPEN): array
    {
        $subscription = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-' . random_int(1, 1000000), 'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        $session = BillingCheckoutSession::create([
            'organization_id' => $org->id, 'subscription_id' => $subscription->id, 'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id, 'provider' => 'stripe', 'provider_checkout_session_id' => 'cs_fake_' . random_int(1, 1000000),
            'internal_reference' => 'CHK-' . random_int(1, 1000000), 'status' => $sessionStatus,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/session',
            'success_url' => 'https://app.test/success', 'cancel_url' => 'https://app.test/cancel',
            'expires_at' => $sessionStatus === CheckoutSessionStatus::OPEN ? now()->addHour() : now()->subHour(),
        ]);

        // The fake provider needs to know this checkout session exists so
        // expireCheckoutSession() doesn't throw "unknown fake session".
        $fake = $this->app->make(FakeBillingProvider::class);
        $fake->checkoutSessions[$session->provider_checkout_session_id] = [
            'id' => $session->provider_checkout_session_id, 'url' => $session->checkout_url,
            'expires_at' => null, 'status' => 'open', 'customer_id' => 'cus_fake_1', 'livemode' => false,
            'subscription_metadata' => [],
        ];

        return [$subscription, $session];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/billing/checkout/cancel-pending')->assertUnauthorized();
    }

    public function test_rejects_when_there_is_no_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(422)->assertJsonPath('code', 'no_pending_checkout');
    }

    public function test_rejects_when_subscription_is_not_pending_payment(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();

        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-ACTIVE-1', 'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(422)->assertJsonPath('code', 'no_pending_checkout');
    }

    public function test_cancels_a_pending_checkout_and_expires_the_local_session(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        [$subscription, $session] = $this->pendingSubscriptionWithSession($org, $user, $plan);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/checkout/cancel-pending');

        $response->assertOk()->assertJsonPath('status', SubscriptionStatus::CANCELLED);
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->fresh()->status);
        $this->assertSame(CheckoutSessionStatus::EXPIRED, $session->fresh()->status);
    }

    public function test_expires_the_stripe_side_checkout_session_too(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        [, $session] = $this->pendingSubscriptionWithSession($org, $user, $plan);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/checkout/cancel-pending')->assertOk();

        $fake = $this->app->make(FakeBillingProvider::class);
        $this->assertSame('expired', $fake->checkoutSessions[$session->provider_checkout_session_id]['status']);
    }

    public function test_cancelling_immediately_allows_choosing_a_new_plan(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_product_id' => 'prod_new', 'provider_price_id' => 'price_new',
            'livemode' => false, 'unit_amount' => 29900, 'is_active' => true,
        ]);
        $this->pendingSubscriptionWithSession($org, $user, $plan);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/checkout/cancel-pending')->assertOk();

        // Immediately available — no waiting for a webhook round trip.
        $this->postJson('/api/billing/checkout', ['plan_code' => $plan->code, 'billing_interval' => 'monthly'])
            ->assertOk();

        $this->assertSame(2, Subscription::where('organization_id', $org->id)->count());
    }

    /**
     * Phase E6 — the root-cause fix under audit: a Checkout cancelled
     * before ever being paid for must never present as a real commercial
     * "Cancelled" subscription on the Billing overview, and must never
     * block a fresh Checkout attempt.
     */
    public function test_overview_reflects_the_cancelled_checkout_as_abandoned_not_a_commercial_cancellation(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        [$subscription] = $this->pendingSubscriptionWithSession($org, $user, $plan);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/checkout/cancel-pending')->assertOk();

        $this->assertNull($subscription->fresh()->activated_at);

        $response = $this->getJson('/api/billing/overview');

        $response->assertOk()
            ->assertJsonPath('subscription.status', SubscriptionStatus::CANCELLED)
            ->assertJsonPath('subscription.activated_at', null)
            ->assertJsonPath('subscription.is_abandoned_checkout', true)
            ->assertJsonPath('can_start_new_checkout', true);
    }

    public function test_cancelling_twice_is_safely_rejected_the_second_time(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $this->pendingSubscriptionWithSession($org, $user, $plan);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/checkout/cancel-pending')->assertOk();

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(422)->assertJsonPath('code', 'no_pending_checkout');
    }

    public function test_never_touches_another_organisations_subscription(): void
    {
        $orgA = $this->org();
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $planA = $this->plan();
        $this->pendingSubscriptionWithSession($orgA, $userA, $planA);

        $orgB = $this->org();
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Sanctum::actingAs($userB);

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(422)->assertJsonPath('code', 'no_pending_checkout');

        $this->assertDatabaseHas('subscriptions', ['organization_id' => $orgA->id, 'status' => SubscriptionStatus::PENDING_PAYMENT]);
    }

    public function test_does_not_affect_an_active_subscription_even_when_a_historical_pending_one_exists(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();

        // Historical cancelled/expired subscriptions don't matter here —
        // only the ORGANISATION's latest subscription is ever considered.
        Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-OLD-1', 'status' => SubscriptionStatus::EXPIRED,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        $active = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe',
            'livemode' => false, 'internal_reference' => 'SUB-ACTIVE-2', 'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 29900, 'quantity' => 1,
            'subtotal_amount' => 29900, 'tax_amount' => 0, 'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/checkout/cancel-pending')
            ->assertStatus(422)->assertJsonPath('code', 'no_pending_checkout');

        $this->assertSame(SubscriptionStatus::ACTIVE, $active->fresh()->status);
    }
}
