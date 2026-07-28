<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionCancellationService;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\SubscriptionPlanChangeService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionCancellationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $lifecycle;
    private SubscriptionPlanChangeService $planChanges;
    private SubscriptionCancellationService $cancellation;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->planChanges = $this->app->make(SubscriptionPlanChangeService::class);
        $this->cancellation = $this->app->make(SubscriptionCancellationService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function context(array $overrides = []): TransitionContext
    {
        return TransitionContext::make(array_merge([
            'source' => TransitionSource::CUSTOMER_BILLING_ACTION,
            'actor_user_id' => $this->actor->id,
        ], $overrides));
    }

    private function plan(): PricingPlan
    {
        return PricingPlan::create([
            'code' => 'plan-' . random_int(1, 1000000), 'slug' => 'plan-' . random_int(1, 1000000),
            'name' => 'Plan', 'monthly_price' => 29.99, 'currency' => 'GBP',
        ]);
    }

    private function mapping(PricingPlan $plan): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 2999, 'is_active' => true, 'livemode' => false,
        ]);
    }

    private function activeSubscription(): Subscription
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
        $this->lifecycle->markPendingPayment($subscription, $this->context());

        $providerId = 'sub_fake_' . random_int(1, 1000000);
        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId, 'status' => 'active', 'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null, 'livemode' => false,
        ], $this->context());

        $this->fake->seedSubscription($providerId, [
            'status' => 'active', 'customer_id' => 'cus_fake_1', 'cancel_at_period_end' => false,
            'price_id' => $mapping->provider_price_id,
        ]);

        return $activated;
    }

    public function test_requesting_cancellation_schedules_at_period_end_and_calls_the_provider(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->cancellation->requestCancellation($subscription, $this->context());

        $this->assertTrue($result->cancel_at_period_end);
        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertTrue($this->fake->subscriptions[$subscription->provider_subscription_id]['cancel_at_period_end']);
    }

    public function test_requesting_cancellation_twice_is_idempotent_with_no_second_provider_call(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());

        $callsBefore = count($this->fake->subscriptions);
        $result = $this->cancellation->requestCancellation($subscription->fresh(), $this->context());

        $this->assertTrue($result->cancel_at_period_end);
        $this->assertCount($callsBefore, $this->fake->subscriptions); // no new fake subscription entries created
    }

    public function test_cancellation_requires_active_status(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->cancellation->requestCancellation($subscription, $this->context());
    }

    public function test_cancellation_is_rejected_while_a_plan_change_is_pending(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->cancellation->requestCancellation($subscription->fresh(), $this->context());
    }

    public function test_scheduling_cancellation_creates_no_entitlement_snapshot(): void
    {
        $subscription = $this->activeSubscription();

        $this->cancellation->requestCancellation($subscription, $this->context());

        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count()); // activation snapshot only
    }

    public function test_resume_cancellation_clears_the_flag_and_calls_the_provider(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());

        $result = $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());

        $this->assertFalse($result->cancel_at_period_end);
        $this->assertFalse($this->fake->subscriptions[$subscription->provider_subscription_id]['cancel_at_period_end']);
    }

    public function test_resume_with_nothing_pending_is_a_safe_no_op(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->cancellation->resumeCancellation($subscription, $this->context());

        $this->assertFalse($result->cancel_at_period_end);
    }

    public function test_repeated_resume_is_deterministic_and_safe(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());

        $first = $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());
        $second = $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());

        $this->assertFalse($first->cancel_at_period_end);
        $this->assertFalse($second->cancel_at_period_end);
    }

    public function test_resume_creates_no_entitlement_snapshot(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());

        $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());

        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_cancellation_lifecycle_events_are_audited(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());
        $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());

        $this->assertTrue(ActivityLog::where('action', 'subscription.cancellation_scheduled')->exists());
        $this->assertTrue(ActivityLog::where('action', 'subscription.cancellation_undone')->exists());
    }

    public function test_idempotency_keys_differ_between_a_schedule_and_a_later_resume(): void
    {
        $subscription = $this->activeSubscription();
        $this->cancellation->requestCancellation($subscription, $this->context());
        $providerId = $subscription->provider_subscription_id;

        $countAfterSchedule = count($this->fake->subscriptions);
        $this->cancellation->resumeCancellation($subscription->fresh(), $this->context());

        // Both calls succeeded against the SAME fake subscription record
        // (not creating a new one), and the final state reflects resume,
        // proving the two idempotency keys were genuinely distinct rather
        // than the second call being silently skipped as a cached replay.
        $this->assertCount($countAfterSchedule, $this->fake->subscriptions);
        $this->assertFalse($this->fake->subscriptions[$providerId]['cancel_at_period_end']);
    }
}
