<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /billing/subscription/cancel and /resume — first-party
 * cancellation (Billing Architecture Audit + Slice E1 checkpoint). Uses
 * FakeBillingProvider (bound automatically in testing).
 */
class SubscriptionCancellationControllerTest extends TestCase
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

    private function plan(string $code = null): PricingPlan
    {
        $code = $code ?? 'plan-' . random_int(1, 1000000);
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function mapping(PricingPlan $plan): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_' . $plan->code,
            'unit_amount' => 29900, 'is_active' => true, 'livemode' => false,
        ]);
    }

    private function activeSubscription(Organization $org, PricingPlan $plan, PricingPlanProviderPrice $mapping): Subscription
    {
        $context = TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN]);
        $subscription = $this->lifecycle->createDraftSubscription($org, $plan, $mapping, 'monthly', $context);
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

    public function test_unauthenticated_cancel_is_rejected(): void
    {
        $this->postJson('/api/billing/subscription/cancel')->assertUnauthorized();
    }

    public function test_cancels_an_active_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/subscription/cancel');

        $response->assertOk()->assertJsonPath('cancel_at_period_end', true);
        $this->assertSame(SubscriptionStatus::ACTIVE, $response->json('status'));
    }

    public function test_rejects_cancellation_with_no_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/subscription/cancel')
            ->assertStatus(422)->assertJsonPath('code', 'no_subscription');
    }

    public function test_rejects_cancellation_for_a_draft_subscription(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $this->lifecycle->createDraftSubscription($org, $plan, $mapping, 'monthly', TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN]));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/billing/subscription/cancel')
            ->assertStatus(409)->assertJsonPath('code', 'cancellation_conflict');

        // Phase E6 — the raw domain exception message (internal reference
        // numbers, status internals, service names) must never reach the
        // customer; only a generic, customer-safe message is returned.
        $message = $response->json('message');
        $this->assertStringNotContainsString('SubscriptionLifecycleService', $message);
        $this->assertStringNotContainsString('is not active', $message);
        $this->assertSame('This subscription could not be updated in its current state. Please refresh the page and try again.', $message);
    }

    public function test_duplicate_cancellation_request_is_idempotent(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);

        $first = $this->postJson('/api/billing/subscription/cancel')->assertOk();
        $second = $this->postJson('/api/billing/subscription/cancel')->assertOk();

        $this->assertTrue($first->json('cancel_at_period_end'));
        $this->assertTrue($second->json('cancel_at_period_end'));
        $this->assertSame(1, Subscription::where('organization_id', $org->id)->count());
    }

    public function test_resumes_a_pending_cancellation(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/subscription/cancel')->assertOk();

        $response = $this->postJson('/api/billing/subscription/resume');

        $response->assertOk()->assertJsonPath('cancel_at_period_end', false);
        $this->assertSame(SubscriptionStatus::ACTIVE, $response->json('status'));
    }

    public function test_resume_with_nothing_pending_is_a_safe_no_op(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);

        $this->postJson('/api/billing/subscription/resume')
            ->assertOk()->assertJsonPath('cancel_at_period_end', false);
    }

    public function test_organisation_isolation_on_cancellation(): void
    {
        $orgA = $this->org();
        $planA = $this->plan();
        $mappingA = $this->mapping($planA);
        $this->activeSubscription($orgA, $planA, $mappingA);

        $orgB = $this->org();
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        Sanctum::actingAs($userB);

        // Org B has no subscription of its own — cancelling never touches
        // org A's subscription no matter what.
        $this->postJson('/api/billing/subscription/cancel')
            ->assertStatus(422)->assertJsonPath('code', 'no_subscription');

        $this->assertDatabaseHas('subscriptions', ['organization_id' => $orgA->id, 'cancel_at_period_end' => false]);
    }

    public function test_cancellation_rejected_while_a_plan_change_is_pending(): void
    {
        $org = $this->org();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('essential');
        $mapping = $this->mapping($plan);
        $target = $this->plan('professional');
        $this->mapping($target);
        $this->activeSubscription($org, $plan, $mapping);

        Sanctum::actingAs($user);
        $this->postJson('/api/billing/plan-change', ['plan_code' => 'professional', 'billing_interval' => 'monthly'])->assertOk();

        $this->postJson('/api/billing/subscription/cancel')
            ->assertStatus(409)->assertJsonPath('code', 'cancellation_conflict');
    }
}
