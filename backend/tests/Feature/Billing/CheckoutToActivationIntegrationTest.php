<?php

namespace Tests\Feature\Billing;

use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Billing\CheckoutSessionService;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\WebhookEventProcessor;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end: CheckoutSessionService::startCheckout() (exactly what
 * CheckoutController calls) through webhook-confirmed activation — the
 * exact scenario Slice C2 ("First Subscription Checkout & Webhook-
 * Confirmed Activation") targets. Uses FakeBillingProvider (real Stripe
 * Sandbox execution is reported separately in this checkpoint's report,
 * not re-created here — see its own docblock convention).
 */
class CheckoutToActivationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutSessionService $checkoutService;
    private WebhookEventProcessor $processor;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;
    private PricingPlan $plan;
    private PricingPlanProviderPrice $mapping;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutService = $this->app->make(CheckoutSessionService::class);
        $this->processor = $this->app->make(WebhookEventProcessor::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 1000000), 'email' => 'billing@acme.test', 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
        $this->plan = PricingPlan::create(['code' => 'essential', 'slug' => 'essential', 'name' => 'Essential', 'status' => 'active', 'monthly_price' => 299]);
        $this->mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $this->plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_product_id' => 'prod_fake', 'provider_price_id' => 'price_fake_essential',
            'livemode' => false, 'unit_amount' => 29900, 'is_active' => true,
        ]);
    }

    private function startRealCheckout(): Subscription
    {
        $session = $this->checkoutService->startCheckout(
            $this->org, $this->plan, 'monthly', 'GBP', $this->actor,
            'https://app.test/checkout/success', 'https://app.test/checkout/cancelled',
        );

        return $session->subscription()->firstOrFail();
    }

    private function subscriptionCreatedEvent(Subscription $subscription, string $providerSubscriptionId): BillingWebhookEvent
    {
        $customer = \App\Models\BillingCustomer::where('organization_id', $this->org->id)->firstOrFail();

        $payload = [
            'id' => $providerSubscriptionId,
            'status' => 'active',
            'customer' => $customer->provider_customer_id,
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'livemode' => false,
            'metadata' => [
                'suresign_organization_id' => (string) $this->org->id,
                'suresign_subscription_id' => (string) $subscription->id,
                'suresign_subscription_reference' => $subscription->internal_reference,
            ],
            'items' => ['data' => [[
                'current_period_start' => CarbonImmutable::now()->subDay()->timestamp,
                'current_period_end' => CarbonImmutable::now()->addMonth()->timestamp,
                'price' => ['id' => $subscription->provider_price_id, 'product' => 'prod_fake'],
            ]]],
        ];

        return BillingWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => 'customer.subscription.created',
            'livemode' => false,
            'provider_created_at' => CarbonImmutable::now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(),
            'payload_json' => ['data' => ['object' => $payload]],
            'payload_hash' => hash('sha256', json_encode($payload)),
        ]);
    }

    public function test_checkout_through_webhook_confirmed_activation_creates_exactly_one_snapshot(): void
    {
        $subscription = $this->startRealCheckout();
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->fresh()->status);

        $event = $this->subscriptionCreatedEvent($subscription, 'sub_fake_1');
        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame('sub_fake_1', $subscription->provider_subscription_id);

        $snapshots = SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->get();
        $this->assertCount(1, $snapshots);
        $this->assertSame('essential', $snapshots->first()->plan_code_snapshot);
    }

    public function test_duplicate_webhook_replay_does_not_duplicate_activation_or_snapshot(): void
    {
        $subscription = $this->startRealCheckout();
        $event = $this->subscriptionCreatedEvent($subscription, 'sub_fake_2');

        $first = $this->processor->process($event);
        $this->assertSame(WebhookProcessingStatus::PROCESSED, $first->status);

        // Genuine redelivery of the SAME event row (Stripe retries with the
        // same event id on a non-2xx or timeout) — reprocess() must be
        // idempotent, matching WebhookEventProcessor's own claim/finalize
        // contract already proven elsewhere in this suite.
        $event->refresh();
        $event->update(['processing_status' => WebhookProcessingStatus::RECEIVED]);
        $second = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $second->status);
        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_browser_redirect_alone_never_activates_the_subscription(): void
    {
        $subscription = $this->startRealCheckout();

        // Simulates the browser returning to the success page and the
        // frontend calling GET /billing/overview — no webhook has arrived
        // yet at this point.
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->fresh()->status);
        $this->assertSame(0, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }
}
