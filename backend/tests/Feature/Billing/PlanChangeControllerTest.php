<?php

namespace Tests\Feature\Billing;

use App\Models\BillingPlanChange;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /billing/plan-change and /billing/plan-change/{id}/cancel — the
 * customer-facing upgrade/downgrade surface for an already-subscribed
 * Organisation (Stripe Sandbox Plan-Change checkpoint — Slice D). Uses
 * FakeBillingProvider (bound automatically in testing).
 */
class PlanChangeControllerTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $lifecycle;
    private FakeBillingProvider $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config(['billing.enabled' => true]);
        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'email' => 'billing@acme.test', 'timezone' => 'Europe/London']);
    }

    private function plan(string $code, int $order): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active', 'order' => $order]);
    }

    private function mapping(PricingPlan $plan, int $amount, string $interval = 'monthly'): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => $interval,
            'currency' => 'GBP', 'provider_product_id' => 'prod_' . $plan->code, 'provider_price_id' => 'price_' . $plan->code . '_' . $interval,
            'livemode' => false, 'unit_amount' => $amount, 'is_active' => true,
        ]);
    }

    private function activeSubscription(Organization $org, PricingPlan $plan, PricingPlanProviderPrice $mapping): Subscription
    {
        $context = TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN]);
        $subscription = $this->lifecycle->createDraftSubscription($org, $plan, $mapping, $mapping->billing_interval, $context);
        $this->lifecycle->markPendingPayment($subscription, $context);

        $providerId = 'sub_fake_' . random_int(1, 1000000);
        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId, 'status' => 'active', 'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null, 'livemode' => false,
        ], $context);

        $this->fake->seedSubscription($providerId, [
            'status' => 'active', 'customer_id' => 'cus_fake_1', 'cancel_at_period_end' => false,
            'price_id' => $mapping->provider_price_id,
        ]);

        return $activated;
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])
            ->assertUnauthorized();
    }

    public function test_immediate_upgrade_is_sent_synchronously_without_changing_the_current_plan(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $essentialMapping = $this->mapping($essential, 29900);
        $professional = $this->plan('professional', 2);
        $professionalMapping = $this->mapping($professional, 79900);

        $subscription = $this->activeSubscription($org, $essential, $essentialMapping);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly']);

        $response->assertOk()->assertJsonPath('state', PlanChangeState::SENT);
        $this->assertSame('upgrade', $response->json('change_type'));

        // Local current plan never changes from the outbound response alone.
        $subscription->refresh();
        $this->assertSame($essential->id, $subscription->pricing_plan_id);
        $this->assertSame($professional->id, $subscription->pending_pricing_plan_id);

        // The fake provider really was called (proration + price update).
        $this->assertSame($professionalMapping->provider_price_id, $this->fake->subscriptions[$subscription->provider_subscription_id]['price_id']);
    }

    public function test_downgrade_is_requested_but_not_sent_until_the_effective_date(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $professional = $this->plan('professional', 2);
        $professionalMapping = $this->mapping($professional, 79900);
        $essential = $this->plan('essential', 1);
        $this->mapping($essential, 29900);

        $subscription = $this->activeSubscription($org, $professional, $professionalMapping);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly']);

        $response->assertOk()->assertJsonPath('state', PlanChangeState::REQUESTED);
        $this->assertSame('downgrade', $response->json('change_type'));

        $subscription->refresh();
        $this->assertSame($professional->id, $subscription->pricing_plan_id); // unchanged
        $this->assertSame($essential->id, $subscription->pending_pricing_plan_id);
        $this->assertNotNull($subscription->plan_change_effective_at);
    }

    public function test_rejects_same_plan_and_same_interval(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential', 1);
        $mapping = $this->mapping($plan, 29900);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('code', 'no_change');
    }

    public function test_rejects_interval_only_change_as_ambiguous(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential', 1);
        $monthly = $this->mapping($plan, 29900, 'monthly');
        $this->mapping($plan, 305000, 'annual');
        $this->activeSubscription($org, $plan, $monthly);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'annual'])
            ->assertStatus(422)->assertJsonPath('code', 'interval_change_unsupported');
    }

    public function test_rejects_enterprise_with_no_active_mapping(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $mapping = $this->mapping($essential, 29900);
        $this->plan('enterprise', 3); // no mapping created — Contact Sales only
        $this->activeSubscription($org, $essential, $mapping);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'enterprise', 'billing_interval' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('code', 'plan_change_unavailable');
    }

    public function test_rejects_when_no_active_subscription_exists(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->plan('professional', 2);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])
            ->assertStatus(422)->assertJsonPath('code', 'subscription_not_eligible');
    }

    public function test_rejects_when_a_cancellation_is_pending(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $mapping = $this->mapping($essential, 29900);
        $this->plan('professional', 2);
        $this->mapping(PricingPlan::where('code', 'professional')->first(), 79900);
        $subscription = $this->activeSubscription($org, $essential, $mapping);

        $subscription->update(['cancel_at_period_end' => true]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])
            ->assertStatus(409)->assertJsonPath('code', 'plan_change_conflict');
    }

    public function test_identical_repeated_request_returns_the_existing_pending_change(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $professional = $this->plan('professional', 2);
        $professionalMapping = $this->mapping($professional, 79900);
        $essential = $this->plan('essential', 1);
        $this->mapping($essential, 29900);
        $this->activeSubscription($org, $professional, $professionalMapping);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();
        $second = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, BillingPlanChange::count());
    }

    public function test_new_downgrade_supersedes_a_pending_one(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $enterprise = $this->plan('enterprise', 3);
        $enterpriseMapping = $this->mapping($enterprise, 199900);
        $professional = $this->plan('professional', 2);
        $this->mapping($professional, 79900);
        $essential = $this->plan('essential', 1);
        $this->mapping($essential, 29900);
        $this->activeSubscription($org, $enterprise, $enterpriseMapping);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])->assertOk();
        $second = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();

        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertSame(PlanChangeState::SUPERSEDED, BillingPlanChange::find($first->json('id'))->state);
        $this->assertSame(PlanChangeState::REQUESTED, BillingPlanChange::find($second->json('id'))->state);
    }

    public function test_cancel_a_pending_downgrade(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $professional = $this->plan('professional', 2);
        $professionalMapping = $this->mapping($professional, 79900);
        $essential = $this->plan('essential', 1);
        $this->mapping($essential, 29900);
        $this->activeSubscription($org, $professional, $professionalMapping);

        Sanctum::actingAs($user);

        $created = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();
        $planChangeId = $created->json('id');

        $this->postJson("/api/billing/plan-change/{$planChangeId}/cancel")
            ->assertOk()->assertJsonPath('state', PlanChangeState::CANCELLED);

        $this->assertDatabaseHas('subscriptions', ['organization_id' => $org->id, 'pending_pricing_plan_id' => null]);
    }

    public function test_cannot_cancel_another_organisations_plan_change(): void
    {
        $orgA = $this->org();
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $professional = $this->plan('professional', 2);
        $professionalMapping = $this->mapping($professional, 79900);
        $essential = $this->plan('essential', 1);
        $this->mapping($essential, 29900);
        $this->activeSubscription($orgA, $professional, $professionalMapping);

        Sanctum::actingAs($userA);
        $created = $this->postJson('/api/billing/plan-change', ['plan_code' => 'essential', 'billing_interval' => 'monthly'])->assertOk();

        $orgB = $this->org();
        $userB = User::factory()->create(['organization_id' => $orgB->id]);
        Sanctum::actingAs($userB);

        $this->postJson("/api/billing/plan-change/{$created->json('id')}/cancel")->assertForbidden();
    }

    public function test_cancelling_an_already_sent_upgrade_is_rejected(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $essentialMapping = $this->mapping($essential, 29900);
        $professional = $this->plan('professional', 2);
        $this->mapping($professional, 79900);
        $this->activeSubscription($org, $essential, $essentialMapping);

        Sanctum::actingAs($user);
        $created = $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])->assertOk();
        $this->assertSame(PlanChangeState::SENT, $created->json('state'));

        $this->postJson("/api/billing/plan-change/{$created->json('id')}/cancel")
            ->assertStatus(409)->assertJsonPath('code', 'no_longer_cancellable');
    }
}
