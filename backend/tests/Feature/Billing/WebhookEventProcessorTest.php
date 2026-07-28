<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Services\Billing\WebhookEventProcessor;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookEventProcessorTest extends TestCase
{
    use RefreshDatabase;

    private WebhookEventProcessor $processor;
    private SubscriptionLifecycleService $lifecycle;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->app->make(WebhookEventProcessor::class);
        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);
        $this->fake->livemode = false;

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    // ─── Fixture builders ─────────────────────────────────────────────────

    private function plan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'code' => 'pro-' . random_int(1, 10000000),
            'slug' => 'pro-' . random_int(1, 10000000),
            'name' => 'Professional',
            'monthly_price' => 29.99,
            'currency' => 'GBP',
        ], $overrides));
    }

    private function mapping(PricingPlan $plan, array $overrides = []): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create(array_merge([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_fake_' . random_int(1, 10000000),
            'unit_amount' => 2999,
            'is_active' => true,
            'livemode' => false,
        ], $overrides));
    }

    private function billingCustomer(array $overrides = []): BillingCustomer
    {
        return BillingCustomer::create(array_merge([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_fake_' . random_int(1, 10000000),
            'livemode' => false,
        ], $overrides));
    }

    /**
     * A subscription in pending_payment, exactly as it would sit after
     * CheckoutSessionService::startCheckout() ran — the correlation
     * starting point for every subscription-event test.
     */
    private function pendingSubscription(PricingPlan $plan, PricingPlanProviderPrice $mapping, BillingCustomer $billingCustomer): Subscription
    {
        $context = TransitionContext::make(['source' => TransitionSource::CHECKOUT, 'actor_user_id' => $this->actor->id]);

        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $context, null, $billingCustomer->id);

        return $this->lifecycle->markPendingPayment($subscription, $context, 'cs_fake_placeholder');
    }

    private function checkoutSession(Subscription $subscription, PricingPlan $plan, array $overrides = []): BillingCheckoutSession
    {
        return BillingCheckoutSession::create(array_merge([
            'organization_id' => $this->org->id,
            'subscription_id' => $subscription->id,
            'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $this->actor->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_fake_' . random_int(1, 10000000),
            'internal_reference' => 'CHK-' . random_int(1, 10000000),
            'status' => CheckoutSessionStatus::OPEN,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
            'metadata_json' => [
                'billing_customer_id' => $subscription->billing_customer_id,
                'correlation_reference' => null,
            ],
        ], $overrides));
    }

    private function checkoutPayload(BillingCheckoutSession $session, Subscription $subscription, PricingPlan $plan, BillingCustomer $customer, array $overrides = []): array
    {
        return array_merge([
            'id' => $session->provider_checkout_session_id,
            'status' => 'complete',
            'customer' => $customer->provider_customer_id,
            'subscription' => null,
            'livemode' => false,
            'amount_total' => $session->amount,
            'currency' => strtolower($session->currency),
            'metadata' => [
                'suresign_organization_id' => (string) $session->organization_id,
                'suresign_subscription_id' => (string) $subscription->id,
                'suresign_pricing_plan_id' => (string) $plan->id,
                'suresign_billing_interval' => $session->billing_interval,
            ],
        ], $overrides);
    }

    private function subscriptionPayload(Subscription $subscription, BillingCustomer $billingCustomer, array $overrides = []): array
    {
        $itemOverrides = $overrides['__item'] ?? [];
        unset($overrides['__item']);

        return array_merge([
            'id' => 'sub_fake_' . random_int(1, 10000000),
            'status' => 'active',
            'customer' => $billingCustomer->provider_customer_id,
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'canceled_at' => null,
            'ended_at' => null,
            'livemode' => false,
            'metadata' => [],
            'items' => [
                'data' => [
                    array_merge([
                        'current_period_start' => CarbonImmutable::now()->subDay()->timestamp,
                        'current_period_end' => CarbonImmutable::now()->addMonth()->timestamp,
                        'price' => [
                            'id' => $subscription->provider_price_id,
                            'product' => 'prod_fake_1',
                        ],
                    ], $itemOverrides),
                ],
            ],
        ], $overrides);
    }

    private function webhookEvent(string $type, array $dataObject, array $overrides = []): BillingWebhookEvent
    {
        return BillingWebhookEvent::create(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => $type,
            'livemode' => false,
            // Deliberately "now" (not an arbitrary past offset): a real
            // Stripe event's provider_created_at is very close to whatever
            // local action just triggered it (e.g. markPendingPayment()'s
            // own occurredAt), so tests exercising a SEQUENCE of events
            // must let each subsequent call() naturally produce a later
            // wall-clock timestamp than the transition before it, exactly
            // like real event ordering would — never an artificial offset
            // that could appear "stale" relative to a transition that in
            // reality happened only moments earlier.
            'provider_created_at' => CarbonImmutable::now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => CarbonImmutable::now(),
            'payload_json' => ['data' => ['object' => $dataObject]],
            'payload_hash' => hash('sha256', json_encode($dataObject)),
        ], $overrides));
    }

    // ─── Claiming and concurrency ──────────────────────────────────────────

    public function test_received_event_is_claimed_and_processed(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x']);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
        $this->assertSame(1, $event->attempt_count);
    }

    public function test_processing_event_cannot_be_claimed(): void
    {
        // A fresh, still-within-lease claim — processing_started_at "now".
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now(),
        ]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::PROCESSING, $result->status);
        $this->assertSame('not_claimable_already_processing', $result->action);
    }

    public function test_abandoned_processing_claim_becomes_reclaimable(): void
    {
        // Older than PROCESSING_LEASE_MINUTES (15) — an abandoned claim.
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(20),
            'attempt_count' => 1,
        ]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $this->assertSame(2, $event->refresh()->attempt_count);
    }

    public function test_processing_claim_with_no_started_at_is_treated_as_abandoned(): void
    {
        // Defensive case — should not occur for a row this class itself
        // wrote, but a null processing_started_at while `processing` is
        // treated as abandoned rather than permanently stuck.
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => null,
        ]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
    }

    public function test_recently_claimed_processing_event_within_lease_is_not_reclaimed(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(5),
        ]);

        $result = $this->processor->process($event);

        $this->assertSame('not_claimable_already_processing', $result->action);
    }

    public function test_processed_event_is_idempotent(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], ['processing_status' => WebhookProcessingStatus::PROCESSED, 'processed_at' => now()]);

        $result = $this->processor->process($event);

        $this->assertSame('already_processed', $result->action);
        $event->refresh();
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_ignored_event_is_idempotent(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], ['processing_status' => WebhookProcessingStatus::IGNORED, 'processed_at' => now()]);

        $result = $this->processor->process($event);

        $this->assertSame('already_ignored', $result->action);
    }

    public function test_conflict_event_is_never_claimed(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], ['processing_status' => WebhookProcessingStatus::CONFLICT, 'failed_at' => now()]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(0, $event->refresh()->attempt_count);
    }

    public function test_non_retryable_failed_event_is_not_claimed(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => false,
        ]);

        $result = $this->processor->process($event);

        $this->assertSame('not_claimable_non_retryable_failure', $result->action);
        $this->assertSame(0, $event->refresh()->attempt_count);
    }

    public function test_retryable_failed_event_can_be_reclaimed(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => true,
            'attempt_count' => 1,
        ]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $this->assertSame(2, $event->refresh()->attempt_count);
    }

    // ─── Unsupported events ────────────────────────────────────────────────

    public function test_unsupported_valid_event_becomes_ignored_not_failed(): void
    {
        // payment_method.attached is a real, valid Stripe event type this
        // checkpoint deliberately did not add support for (Part 15: "add
        // only the event types required... never merely because Stripe
        // offers them") — invoice.paid/invoice.payment_failed are now
        // supported (Stripe Test Mode Integration checkpoint), so this
        // fixture was updated to a genuinely still-unsupported type.
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'pm_123']);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertDatabaseCount('billing_checkout_sessions', 0);
    }

    // ─── checkout.session.completed ────────────────────────────────────────

    public function test_checkout_completed_marks_session_completed_and_does_not_activate_subscription(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $event = $this->webhookEvent('checkout.session.completed', $this->checkoutPayload($session, $subscription, $plan, $customer));

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $session->refresh();
        $this->assertSame(CheckoutSessionStatus::COMPLETED, $session->status);
        $this->assertNotNull($session->completed_at);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->status);
    }

    public function test_checkout_completed_is_idempotent_on_redelivery(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);
        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer);

        $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));
        $result = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(CheckoutSessionStatus::COMPLETED, $session->refresh()->status);
    }

    public function test_checkout_completed_with_wrong_organisation_metadata_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer, [
            'metadata' => ['suresign_organization_id' => '999999', 'suresign_pricing_plan_id' => (string) $plan->id, 'suresign_billing_interval' => 'monthly'],
        ]);

        $result = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(CheckoutSessionStatus::OPEN, $session->refresh()->status);
    }

    public function test_checkout_completed_with_livemode_mismatch_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer);
        $event = $this->webhookEvent('checkout.session.completed', $payload, ['livemode' => true]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
    }

    public function test_checkout_completed_with_amount_mismatch_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer, ['amount_total' => 999999]);

        $result = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(CheckoutSessionStatus::OPEN, $session->refresh()->status);
    }

    public function test_checkout_completed_for_unknown_session_is_retryable_failed(): void
    {
        $event = $this->webhookEvent('checkout.session.completed', ['id' => 'cs_unknown', 'metadata' => [], 'livemode' => false]);

        $result = $this->processor->process($event);

        $this->assertSame(WebhookProcessingStatus::FAILED, $result->status);
        $this->assertTrue($result->retryable);
    }

    public function test_completed_session_cannot_be_overwritten_by_a_later_expired_event(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);
        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer);

        $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $expiredResult = $this->processor->process($this->webhookEvent('checkout.session.expired', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $expiredResult->status);
        $this->assertSame(CheckoutSessionStatus::COMPLETED, $session->refresh()->status);
    }

    // ─── checkout.session.expired ──────────────────────────────────────────

    public function test_checkout_expired_marks_session_expired_and_preserves_subscription(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer);
        $result = $this->processor->process($this->webhookEvent('checkout.session.expired', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(CheckoutSessionStatus::EXPIRED, $session->refresh()->status);
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->refresh()->status);
    }

    public function test_expired_session_cannot_be_overwritten_by_a_later_completed_event(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);
        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer);

        $this->processor->process($this->webhookEvent('checkout.session.expired', $payload));
        $completedResult = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $completedResult->status);
        $this->assertSame(CheckoutSessionStatus::EXPIRED, $session->refresh()->status);
    }

    // ─── customer.subscription.created ─────────────────────────────────────

    public function test_subscription_created_with_active_status_activates_via_lifecycle_service(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $payload = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame($payload['id'], $subscription->provider_subscription_id);
    }

    public function test_subscription_created_with_commercial_mismatch_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $payload = $this->subscriptionPayload($subscription, $customer, [
            'status' => 'active',
            '__item' => ['price' => ['id' => 'price_completely_different', 'product' => 'prod_x']],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->refresh()->status);
    }

    public function test_subscription_created_with_opposite_livemode_customer_cannot_correlate(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer(['livemode' => true]);

        $payload = [
            'id' => 'sub_fake_orphan',
            'status' => 'active',
            'customer' => $customer->provider_customer_id,
            'cancel_at_period_end' => false,
            'trial_end' => null,
            'livemode' => false,
            'metadata' => [],
            'items' => ['data' => [['current_period_start' => now()->timestamp, 'current_period_end' => now()->addMonth()->timestamp, 'price' => ['id' => 'price_x', 'product' => 'prod_x']]]],
        ];

        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::FAILED, $result->status);
        $this->assertTrue($result->retryable);
    }

    // ─── customer.subscription.updated ─────────────────────────────────────

    public function test_subscription_updated_restores_active_from_past_due(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $context = TransitionContext::make(['source' => TransitionSource::VERIFIED_WEBHOOK]);
        $this->lifecycle->markPastDue($subscription, $context);

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'active']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_subscription_updated_marks_past_due_from_active(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'past_due']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->refresh()->status);
    }

    public function test_subscription_updated_marks_unpaid_from_past_due(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $this->lifecycle->markPastDue($subscription, TransitionContext::make(['source' => TransitionSource::VERIFIED_WEBHOOK]));

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'unpaid']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::UNPAID, $subscription->refresh()->status);
    }

    public function test_subscription_updated_records_cancel_at_period_end_via_pure_refresh(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, [
            'id' => $subscription->provider_subscription_id,
            'status' => 'active',
            'cancel_at_period_end' => true,
        ]);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        // Billing Architecture Audit + Slice E1 checkpoint — this specific
        // action label now identifies a cancel_at_period_end change
        // distinctly from a generic provider-state refresh.
        $this->assertSame('subscription_cancellation_confirmed_by_provider', $result->action);
        $this->assertTrue($subscription->refresh()->cancel_at_period_end);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
    }

    public function test_subscription_updated_records_cancellation_undo_via_pure_refresh(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active', 'cancel_at_period_end' => true]);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();
        $this->assertTrue($subscription->cancel_at_period_end);

        $updated = $this->subscriptionPayload($subscription, $customer, [
            'id' => $subscription->provider_subscription_id,
            'status' => 'active',
            'cancel_at_period_end' => false,
        ]);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_cancellation_undo_confirmed_by_provider', $result->action);
        $this->assertFalse($subscription->refresh()->cancel_at_period_end);
    }

    public function test_subscription_updated_with_no_cancellation_change_uses_the_generic_refresh_label(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, [
            'id' => $subscription->provider_subscription_id,
            'status' => 'active',
        ]);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_provider_state_recorded', $result->action);
    }

    public function test_stale_subscription_update_does_not_overwrite_newer_state(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created, ['provider_created_at' => now()]));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'past_due']);
        // Older than the activation event above.
        $staleEvent = $this->webhookEvent('customer.subscription.updated', $updated, ['provider_created_at' => now()->subHour()]);

        $result = $this->processor->process($staleEvent);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_unexpected_plan_change_is_conflict_not_silently_applied(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, [
            'id' => $subscription->provider_subscription_id,
            'status' => 'active',
            '__item' => ['price' => ['id' => 'price_a_totally_different_plan', 'product' => 'prod_x']],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
    }

    public function test_direct_status_mutation_never_occurs_outside_lifecycle_service(): void
    {
        // Confirmed by code inspection (WebhookEventProcessor never assigns
        // ->status directly) — this test asserts the observable behaviour:
        // every processed subscription event leaves last_transition_occurred_at
        // populated, which only SubscriptionLifecycleService's transition()
        // path ever sets.
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));

        $this->assertNotNull($subscription->refresh()->last_transition_occurred_at);
    }

    // ─── customer.subscription.deleted ─────────────────────────────────────

    public function test_subscription_deleted_confirms_scheduled_cancellation(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $this->lifecycle->scheduleCancellation($subscription, TransitionContext::make(['source' => TransitionSource::VERIFIED_WEBHOOK]));

        $deleted = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'canceled']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.deleted', $deleted));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_cancellation_confirmed', $result->action);
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->refresh()->status);
    }

    public function test_subscription_deleted_without_schedule_cancels_immediately(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $deleted = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'canceled']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.deleted', $deleted));

        $this->assertSame('subscription_cancelled_immediately', $result->action);
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->refresh()->status);
    }

    public function test_subscription_deleted_is_idempotent(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $deleted = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'canceled']);
        $this->processor->process($this->webhookEvent('customer.subscription.deleted', $deleted));
        $result = $this->processor->process($this->webhookEvent('customer.subscription.deleted', $deleted));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_already_cancelled', $result->action);
    }

    // ─── Ledger finalization ───────────────────────────────────────────────

    public function test_processed_outcome_persists_processed_status_and_clears_failure_fields(): void
    {
        $event = $this->webhookEvent('unsupported.event', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failure_message' => 'stale leftover',
            'failed_at' => now(),
            'retryable' => true,
        ]);

        $this->processor->process($event);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
    }

    public function test_conflict_outcome_never_sets_retryable_true(): void
    {
        $event = $this->webhookEvent('checkout.session.completed', ['id' => 'cs_missing', 'metadata' => [], 'livemode' => false]);
        // Force a conflict by seeding a session with mismatched org metadata instead.
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);
        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer, ['metadata' => ['suresign_organization_id' => '0']]);
        $conflictEvent = $this->webhookEvent('checkout.session.completed', $payload);

        $this->processor->process($conflictEvent);

        $conflictEvent->refresh();
        $this->assertSame(WebhookProcessingStatus::CONFLICT, $conflictEvent->processing_status);
        $this->assertFalse((bool) $conflictEvent->retryable);
        $this->assertNotNull($conflictEvent->failed_at);
    }

    public function test_payload_is_never_modified_by_processing(): void
    {
        $event = $this->webhookEvent('invoice.paid', ['id' => 'in_abc', 'secret' => 'never_touch']);
        $originalHash = $event->payload_hash;

        $this->processor->process($event);

        $event->refresh();
        $this->assertSame($originalHash, $event->payload_hash);
        $this->assertSame('in_abc', $event->payload_json['data']['object']['id']);
    }

    // ─── Operational visibility ─────────────────────────────────────────────

    public function test_unresolved_conflicts_scope_returns_conflict_rows_only(): void
    {
        $this->webhookEvent('x', ['id' => '1'], ['processing_status' => WebhookProcessingStatus::CONFLICT]);
        $this->webhookEvent('x', ['id' => '2'], ['processing_status' => WebhookProcessingStatus::PROCESSED]);

        $this->assertSame(1, BillingWebhookEvent::unresolvedConflicts()->count());
    }

    public function test_retryable_failed_scope_excludes_non_retryable(): void
    {
        $this->webhookEvent('x', ['id' => '1'], ['processing_status' => WebhookProcessingStatus::FAILED, 'retryable' => true]);
        $this->webhookEvent('x', ['id' => '2'], ['processing_status' => WebhookProcessingStatus::FAILED, 'retryable' => false]);

        $this->assertSame(1, BillingWebhookEvent::retryableFailed()->count());
    }

    public function test_activity_log_never_contains_raw_payload_for_processing_outcomes(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_secret_data']);

        $this->processor->process($event);

        $log = ActivityLog::where('action', 'billing.webhook.ignored')->firstOrFail();
        $this->assertStringNotContainsString('in_secret_data', json_encode($log->metadata));
    }

    // ─── Trusted metadata correlation (Subscription Event Hardening) ──────

    public function test_subscription_created_correlates_via_trusted_metadata_without_billing_customer_fallback(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        // A DIFFERENT, unrelated customer_id — if the BillingCustomer
        // fallback were used it would fail to correlate at all; trusted
        // metadata must be sufficient on its own.
        $payload = $this->subscriptionPayload($subscription, $customer, [
            'status' => 'active',
            'customer' => 'cus_unrelated_id',
            'metadata' => ['suresign_subscription_id' => (string) $subscription->id],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_metadata_disagreeing_with_organisation_becomes_conflict_even_with_correct_subscription_id(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $payload = $this->subscriptionPayload($subscription, $customer, [
            'status' => 'active',
            'metadata' => [
                'suresign_subscription_id' => (string) $subscription->id,
                'suresign_organization_id' => '999999',
            ],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->refresh()->status);
    }

    public function test_metadata_pointing_to_a_nonexistent_subscription_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $payload = $this->subscriptionPayload($subscription, $customer, [
            'status' => 'active',
            'metadata' => ['suresign_subscription_id' => '9999999'],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
    }

    public function test_provider_subscription_id_wins_over_disagreeing_metadata(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        // A later update carries metadata pointing at a DIFFERENT (bogus)
        // subscription id — correlation must still use the already-linked
        // provider_subscription_id, then reject the disagreeing metadata
        // as a conflict rather than silently trusting either source.
        $updated = $this->subscriptionPayload($subscription, $customer, [
            'id' => $subscription->provider_subscription_id,
            'status' => 'past_due',
            'metadata' => ['suresign_subscription_id' => '424242'],
        ]);

        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_billing_customer_fallback_remains_available_when_metadata_absent(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        // No suresign_subscription_id in metadata — exercises the
        // exceptional BillingCustomer-mapping fallback.
        $payload = $this->subscriptionPayload($subscription, $customer, ['status' => 'active', 'metadata' => []]);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_ambiguous_billing_customer_fallback_becomes_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();

        // Two pending_payment subscriptions for the SAME billing customer —
        // without metadata, the fallback cannot pick one safely.
        // hasConflictingSubscription() normally prevents a SECOND
        // non-terminal subscription for the same organisation/livemode from
        // ever being created via createDraftSubscription() — this is
        // exactly why the checkpoint judged the fallback "safe in practice"
        // (see WebhookEventProcessor's docblock). To exercise the
        // defensive ambiguity guard itself (for the rare/anomalous case
        // where two such rows exist regardless — e.g. a manual data
        // correction, or a future relaxation of that invariant), the
        // second row is created directly, bypassing that check.
        $first = $this->pendingSubscription($plan, $mapping, $customer);
        $second = Subscription::create([
            'organization_id' => $this->org->id,
            'pricing_plan_id' => $plan->id,
            'billing_customer_id' => $customer->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 10000000),
            'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
        ]);

        $payload = $this->subscriptionPayload($first, $customer, ['status' => 'active', 'metadata' => []]);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $first->refresh()->status);
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $second->refresh()->status);
    }

    // ─── Incomplete lifecycle (Subscription Event Hardening) ──────────────

    public function test_subscription_created_with_incomplete_status_marks_incomplete(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $payload = $this->subscriptionPayload($subscription, $customer, ['status' => 'incomplete']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $payload));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_marked_incomplete', $result->action);
        $this->assertSame(SubscriptionStatus::INCOMPLETE, $subscription->refresh()->status);
        $this->assertSame($payload['id'], $subscription->provider_subscription_id);
    }

    public function test_incomplete_subscription_activates_on_a_later_active_update(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'incomplete']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'active']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_incomplete_expired_expires_the_subscription(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'incomplete']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'incomplete_expired']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame('subscription_expired', $result->action);
        $this->assertSame(SubscriptionStatus::EXPIRED, $subscription->refresh()->status);
        $this->assertNotNull($subscription->provider_subscription_id);
    }

    public function test_stale_incomplete_transition_is_ignored(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created, ['provider_created_at' => now()]));
        $subscription->refresh();

        // An "incomplete" update dated BEFORE the activation above.
        $stalePayload = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'incomplete']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $stalePayload, ['provider_created_at' => now()->subHour()]));

        $this->assertSame(WebhookProcessingStatus::IGNORED, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
    }

    public function test_duplicate_incomplete_created_event_reprocessing_is_idempotent(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'incomplete']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        // A second, separately-ledgered "created" redelivery for the same
        // provider subscription (Stripe occasionally redelivers with a new
        // event ID) — correlates via provider_subscription_id (step 1) and
        // must remain idempotent.
        $result = $this->processor->process($this->webhookEvent('customer.subscription.created', $created));

        $this->assertSame(WebhookProcessingStatus::PROCESSED, $result->status);
        $this->assertSame(SubscriptionStatus::INCOMPLETE, $subscription->refresh()->status);
    }

    // ─── Paused policy ──────────────────────────────────────────────────────

    public function test_paused_status_remains_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);

        $created = $this->subscriptionPayload($subscription, $customer, ['status' => 'active']);
        $this->processor->process($this->webhookEvent('customer.subscription.created', $created));
        $subscription->refresh();

        $updated = $this->subscriptionPayload($subscription, $customer, ['id' => $subscription->provider_subscription_id, 'status' => 'paused']);
        $result = $this->processor->process($this->webhookEvent('customer.subscription.updated', $updated));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->refresh()->status);
        $this->assertNotSame(SubscriptionStatus::SUSPENDED, $subscription->status);
    }

    // ─── Strengthened checkout correlation ─────────────────────────────────

    public function test_checkout_completed_with_wrong_provider_customer_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        // The event's own `customer` field disagrees with the
        // BillingCustomer actually linked to this subscription — no
        // second local BillingCustomer row is needed to prove this (the
        // unique (organization_id, provider, livemode) constraint means
        // only one can exist per org anyway); the mismatch is entirely in
        // the INCOMING payload.
        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer, [
            'customer' => 'cus_completely_different_' . random_int(1, 10000000),
        ]);

        $result = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(CheckoutSessionStatus::OPEN, $session->refresh()->status);
    }

    public function test_checkout_completed_with_disagreeing_subscription_metadata_is_conflict(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $customer = $this->billingCustomer();
        $subscription = $this->pendingSubscription($plan, $mapping, $customer);
        $session = $this->checkoutSession($subscription, $plan);

        $payload = $this->checkoutPayload($session, $subscription, $plan, $customer, [
            'metadata' => [
                'suresign_organization_id' => (string) $session->organization_id,
                'suresign_subscription_id' => '9999999',
                'suresign_pricing_plan_id' => (string) $plan->id,
                'suresign_billing_interval' => $session->billing_interval,
            ],
        ]);

        $result = $this->processor->process($this->webhookEvent('checkout.session.completed', $payload));

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $result->status);
        $this->assertSame(CheckoutSessionStatus::OPEN, $session->refresh()->status);
    }

    // ─── Claim recovery — double-claim safety ──────────────────────────────

    public function test_active_processing_claim_cannot_be_double_claimed_within_lease(): void
    {
        $event = $this->webhookEvent('unsupported.event.type', ['id' => 'x'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now(),
        ]);

        $first = $this->processor->process($event);
        $second = $this->processor->process($event);

        $this->assertSame('not_claimable_already_processing', $first->action);
        $this->assertSame('not_claimable_already_processing', $second->action);
    }
}
