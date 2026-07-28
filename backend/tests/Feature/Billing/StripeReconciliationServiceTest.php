<?php

namespace Tests\Feature\Billing;

use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\SubscriptionPlanChangeService;
use App\Services\Billing\StripeReconciliationService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\ReconciliationFinding;
use App\Support\Billing\TransitionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StripeReconciliationService $reconciliation;
    private SubscriptionLifecycleService $lifecycle;
    private SubscriptionPlanChangeService $planChanges;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reconciliation = $this->app->make(StripeReconciliationService::class);
        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->planChanges = $this->app->make(SubscriptionPlanChangeService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function context(): TransitionContext
    {
        return TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN, 'actor_user_id' => $this->actor->id]);
    }

    private function activeSubscription(): array
    {
        $plan = PricingPlan::create(['code' => 'plan-' . random_int(1, 1000000), 'slug' => 'plan-' . random_int(1, 1000000), 'name' => 'Plan', 'monthly_price' => 49.99, 'currency' => 'GBP']);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 4999, 'is_active' => true, 'livemode' => false,
        ]);

        $org = Organization::create(['name' => 'Acme ' . random_int(1, 1000000), 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $providerCustomerId = 'cus_fake_' . random_int(1, 10000000);
        $customer = BillingCustomer::create(['organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => $providerCustomerId, 'livemode' => false]);

        $subscription = $this->lifecycle->createDraftSubscription($org, $plan, $mapping, 'monthly', $this->context(), null, $customer->id);
        $this->lifecycle->markPendingPayment($subscription, $this->context());

        $providerId = 'sub_fake_' . random_int(1, 1000000);
        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId, 'status' => 'active', 'customer_id' => $providerCustomerId,
            'cancel_at_period_end' => false, 'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'livemode' => false,
        ], $this->context());

        $this->fake->seedSubscription($providerId, [
            'status' => 'active', 'customer_id' => $providerCustomerId, 'cancel_at_period_end' => false,
            'price_id' => $mapping->provider_price_id, 'livemode' => false,
            'current_period_start' => now()->subDay()->timestamp, 'current_period_end' => now()->addMonth()->timestamp,
        ]);

        return [$activated, $plan, $mapping];
    }

    public function test_a_healthy_subscription_reports_healthy(): void
    {
        $this->activeSubscription();

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::HEALTHY]);
    }

    public function test_a_subscription_with_no_provider_id_is_local_only(): void
    {
        $plan = PricingPlan::create(['code' => 'plan-' . random_int(1, 1000000), 'slug' => 'plan-' . random_int(1, 1000000), 'name' => 'Plan', 'monthly_price' => 49.99, 'currency' => 'GBP']);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 4999, 'is_active' => true, 'livemode' => false,
        ]);
        // Directly create an "active" row with no provider_subscription_id
        // — an inconsistent state reconciliation should catch.
        Subscription::create([
            'organization_id' => $this->org->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 10000000), 'status' => 'active',
            'billing_interval' => 'monthly', 'currency' => 'GBP', 'unit_amount' => 4999,
            'pricing_plan_id' => $plan->id, 'plan_code_snapshot' => $plan->code,
        ]);

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::LOCAL_ONLY]);
    }

    public function test_a_deleted_provider_subscription_is_reported(): void
    {
        [$subscription] = $this->activeSubscription();
        unset($this->fake->subscriptions[$subscription->provider_subscription_id]);

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::PROVIDER_SUBSCRIPTION_DELETED]);
    }

    public function test_a_customer_mismatch_is_reported(): void
    {
        [$subscription] = $this->activeSubscription();
        $this->fake->subscriptions[$subscription->provider_subscription_id]['customer_id'] = 'cus_totally_different';

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::CUSTOMER_MISMATCH]);
    }

    public function test_an_unexplained_price_mismatch_is_reported_as_conflict_never_auto_resolved(): void
    {
        [$subscription] = $this->activeSubscription();
        $this->fake->subscriptions[$subscription->provider_subscription_id]['price_id'] = 'price_unexplained';

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::UNKNOWN_PRICE]);
        $this->assertSame($subscription->fresh()->pricing_plan_id, $subscription->pricing_plan_id); // untouched
    }

    /**
     * Billing Architecture Audit + Slice E1 checkpoint — a missed/failed
     * webhook could leave local cancel_at_period_end disagreeing with
     * what Stripe actually reports; reconciliation must surface this as
     * a conflict, never silently copy the provider's value.
     */
    public function test_a_cancellation_state_mismatch_is_reported_as_conflict_never_auto_resolved(): void
    {
        [$subscription] = $this->activeSubscription();
        $this->fake->subscriptions[$subscription->provider_subscription_id]['cancel_at_period_end'] = true;

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::CANCELLATION_STATE_MISMATCH]);
        $this->assertFalse($subscription->fresh()->cancel_at_period_end); // untouched
    }

    public function test_a_price_matching_a_pending_plan_change_target_is_reported_as_confirmed_not_a_conflict(): void
    {
        [$subscription] = $this->activeSubscription();
        $targetPlan = PricingPlan::create(['code' => 'target-' . random_int(1, 1000000), 'slug' => 'target-' . random_int(1, 1000000), 'name' => 'Target', 'monthly_price' => 99.99, 'currency' => 'GBP']);
        $targetMapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $targetPlan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 9999, 'is_active' => true, 'livemode' => false,
        ]);
        $planChange = $this->planChanges->requestUpgrade($subscription, $targetPlan, $targetMapping, $this->context());
        $this->planChanges->send($planChange);

        $result = $this->reconciliation->reconcile();

        $this->assertSame(1, $result['counters'][ReconciliationFinding::PENDING_CHANGE_CONFIRMED]);
        $this->assertSame(0, $result['counters'][ReconciliationFinding::UNKNOWN_PRICE]);
        $this->assertSame(0, $result['counters'][ReconciliationFinding::PRICE_MISMATCH]);
    }

    public function test_reconcile_can_target_a_single_subscription(): void
    {
        [$target] = $this->activeSubscription();
        $this->activeSubscription();

        $result = $this->reconciliation->reconcile(subscriptionId: $target->id);

        $this->assertSame(1, $result['counters']['scanned']);
    }

    public function test_reconciliation_never_mutates_anything(): void
    {
        [$subscription] = $this->activeSubscription();
        $this->fake->subscriptions[$subscription->provider_subscription_id]['price_id'] = 'price_unexplained';
        $before = $subscription->updated_at;

        $this->reconciliation->reconcile();

        $this->assertTrue($subscription->fresh()->updated_at->equalTo($before));
    }
}
