<?php

namespace Tests\Feature\Billing;

use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\SubscriptionPlanChangeService;
use App\Services\Billing\TransitionContext;
use App\Services\Billing\WebhookEventProcessor;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe Test Mode Integration checkpoint, Part 16 — confirms
 * WebhookEventProcessor::reconcilePlanChangeIfPending() only ever applies
 * a plan change when a VERIFIED webhook reports the exact target Price,
 * and treats an unexpected Price as drift, never a silent local change.
 */
class PlanChangeWebhookReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private WebhookEventProcessor $processor;
    private SubscriptionLifecycleService $lifecycle;
    private SubscriptionPlanChangeService $planChanges;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->app->make(WebhookEventProcessor::class);
        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->planChanges = $this->app->make(SubscriptionPlanChangeService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function context(): TransitionContext
    {
        return TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN, 'actor_user_id' => $this->actor->id]);
    }

    private function plan(): PricingPlan
    {
        return PricingPlan::create([
            'code' => 'plan-' . random_int(1, 1000000),
            'slug' => 'plan-' . random_int(1, 1000000),
            'name' => 'Plan',
            'monthly_price' => 49.99,
            'currency' => 'GBP',
        ]);
    }

    private function mapping(PricingPlan $plan): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 4999,
            'is_active' => true,
            'livemode' => false,
        ]);
    }

    private function activeSubscriptionWithPendingUpgrade(): array
    {
        $sourcePlan = $this->plan();
        $sourceMapping = $this->mapping($sourcePlan);

        $subscription = $this->lifecycle->createDraftSubscription($this->org, $sourcePlan, $sourceMapping, 'monthly', $this->context());
        $this->lifecycle->markPendingPayment($subscription, $this->context());

        $providerId = 'sub_fake_' . random_int(1, 1000000);

        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId,
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ], $this->context());

        $this->fake->seedSubscription($providerId, [
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'price_id' => $sourceMapping->provider_price_id,
        ]);

        $targetPlan = $this->plan();
        $targetMapping = $this->mapping($targetPlan);

        $planChange = $this->planChanges->requestUpgrade($activated, $targetPlan, $targetMapping, $this->context());
        $this->planChanges->send($planChange->fresh());

        return [$activated->fresh(), $targetPlan, $targetMapping, $planChange->fresh()];
    }

    private function webhookEvent(string $type, array $dataObject): BillingWebhookEvent
    {
        return BillingWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => $type,
            'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(),
            'payload_json' => ['data' => ['object' => $dataObject]],
            'payload_hash' => hash('sha256', json_encode($dataObject)),
        ]);
    }

    private function subscriptionUpdatedPayload(Subscription $subscription, string $reportedPriceId): array
    {
        return [
            'id' => $subscription->provider_subscription_id,
            'status' => 'active',
            'customer' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'livemode' => false,
            'metadata' => [],
            'items' => [
                'data' => [[
                    'current_period_start' => CarbonImmutable::now()->subDay()->timestamp,
                    'current_period_end' => CarbonImmutable::now()->addMonth()->timestamp,
                    'price' => ['id' => $reportedPriceId, 'product' => 'prod_fake_1'],
                ]],
            ],
        ];
    }

    public function test_a_webhook_reporting_the_expected_target_price_confirms_and_applies_the_plan_change(): void
    {
        [$subscription, $targetPlan, $targetMapping, $planChange] = $this->activeSubscriptionWithPendingUpgrade();

        $event = $this->webhookEvent(
            'customer.subscription.updated',
            $this->subscriptionUpdatedPayload($subscription, $targetMapping->provider_price_id),
        );

        $result = $this->processor->process($event);

        $this->assertSame('processed', $result->status);
        $this->assertSame('plan_change_confirmed_and_applied', $result->action);

        $subscription->refresh();
        $this->assertSame($targetPlan->id, $subscription->pricing_plan_id);
        $this->assertSame(PlanChangeState::APPLIED, $planChange->fresh()->state);
        $this->assertSame(2, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    /**
     * A reported Price matching NEITHER the subscription's own recorded
     * Price NOR any pending plan change's target is provider drift —
     * `validateCommercialSnapshot()`'s pre-existing "unknown mismatch"
     * guard catches this before plan-change reconciliation ever runs,
     * exactly the same conflict path an unrelated, unexplained Price
     * change would already hit. Never silently applied either way.
     */
    public function test_a_webhook_reporting_an_unexpected_price_is_drift_never_silently_applied(): void
    {
        [$subscription, $targetPlan, , $planChange] = $this->activeSubscriptionWithPendingUpgrade();

        $event = $this->webhookEvent(
            'customer.subscription.updated',
            $this->subscriptionUpdatedPayload($subscription, 'price_totally_unexpected'),
        );

        $result = $this->processor->process($event);

        $this->assertSame('conflict', $result->status);
        $this->assertSame('subscription_price_mismatch', $result->action);

        $subscription->refresh();
        $this->assertNotSame($targetPlan->id, $subscription->pricing_plan_id);
        $this->assertSame(PlanChangeState::SENT, $planChange->fresh()->state);
    }

    public function test_duplicate_webhook_redelivery_after_confirmation_is_a_safe_no_op(): void
    {
        [$subscription, $targetPlan, $targetMapping, $planChange] = $this->activeSubscriptionWithPendingUpgrade();

        $payload = $this->subscriptionUpdatedPayload($subscription, $targetMapping->provider_price_id);
        $this->processor->process($this->webhookEvent('customer.subscription.updated', $payload));
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $payload));

        $this->assertSame('processed', $result->status);
        $this->assertSame(2, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_a_subscription_with_no_pending_plan_change_is_unaffected(): void
    {
        $sourcePlan = $this->plan();
        $sourceMapping = $this->mapping($sourcePlan);
        $subscription = $this->lifecycle->createDraftSubscription($this->org, $sourcePlan, $sourceMapping, 'monthly', $this->context());
        $this->lifecycle->markPendingPayment($subscription, $this->context());
        $providerId = 'sub_fake_' . random_int(1, 1000000);
        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId, 'status' => 'active', 'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false, 'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'livemode' => false,
        ], $this->context());

        $event = $this->webhookEvent('customer.subscription.updated', $this->subscriptionUpdatedPayload($activated, $sourceMapping->provider_price_id));
        $result = $this->processor->process($event);

        $this->assertSame('subscription_provider_state_recorded', $result->action);
    }
}
