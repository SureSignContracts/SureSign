<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms snapshot creation happens at the authoritative lifecycle
 * boundary itself (SubscriptionLifecycleService::activate()/startTrial())
 * — not only via the scheduler — per the Subscription Commercial State
 * Automation checkpoint's Part 4 requirement, since activation can be
 * reached from a verified webhook or a sales-assisted path, not only
 * automation.
 */
class SubscriptionLifecycleSnapshotIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $service;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(SubscriptionLifecycleService::class);
        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function context(array $overrides = []): TransitionContext
    {
        return TransitionContext::make(array_merge([
            'source' => TransitionSource::SUPER_ADMIN,
            'actor_user_id' => $this->actor->id,
        ], $overrides));
    }

    private function draftSubscription(): Subscription
    {
        $plan = PricingPlan::create([
            'code' => 'pro-' . random_int(1, 1000000),
            'slug' => 'pro-' . random_int(1, 1000000),
            'name' => 'Professional',
            'monthly_price' => 29.99,
            'currency' => 'GBP',
        ]);

        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 2999,
            'is_active' => true,
            'livemode' => false,
        ]);

        return $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
    }

    public function test_activation_creates_an_entitlement_snapshot(): void
    {
        $subscription = $this->draftSubscription();
        $this->service->markPendingPayment($subscription, $this->context());

        $activated = $this->service->activate($subscription, [
            'id' => 'sub_fake_1',
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ], $this->context());

        $snapshot = SubscriptionEntitlementSnapshot::where('subscription_id', $activated->id)->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('activation', $snapshot->lifecycle_reason);
        $this->assertSame($activated->plan_code_snapshot, $snapshot->plan_code_snapshot);
    }

    public function test_duplicate_activation_does_not_duplicate_the_snapshot(): void
    {
        $subscription = $this->draftSubscription();
        $this->service->markPendingPayment($subscription, $this->context());

        $providerData = [
            'id' => 'sub_fake_2',
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ];

        $this->service->activate($subscription, $providerData, $this->context());
        $this->service->activate($subscription, $providerData, $this->context());

        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_starting_a_trial_creates_a_trial_snapshot(): void
    {
        $subscription = $this->draftSubscription();

        $trialing = $this->service->startTrial($subscription, CarbonImmutable::now()->addDays(14), $this->context());

        $snapshot = SubscriptionEntitlementSnapshot::where('subscription_id', $trialing->id)->first();

        $this->assertNotNull($snapshot);
        $this->assertSame('trial_start', $snapshot->lifecycle_reason);
    }
}
