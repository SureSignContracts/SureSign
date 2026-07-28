<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Services\Billing\WebhookEventProcessor;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stripe Test Mode Integration checkpoint, Part 17/18/19 — invoice
 * persistence and the payment-failure/recovery lifecycle
 * (invoice.payment_failed -> past_due, invoice.paid -> restored).
 */
class InvoiceWebhookSyncTest extends TestCase
{
    use RefreshDatabase;

    private WebhookEventProcessor $processor;
    private SubscriptionLifecycleService $lifecycle;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->app->make(WebhookEventProcessor::class);
        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);

        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function context(): TransitionContext
    {
        return TransitionContext::make(['source' => TransitionSource::SUPER_ADMIN, 'actor_user_id' => $this->actor->id]);
    }

    private function activeSubscription(): Subscription
    {
        $plan = PricingPlan::create([
            'code' => 'plan-' . random_int(1, 1000000), 'slug' => 'plan-' . random_int(1, 1000000),
            'name' => 'Plan', 'monthly_price' => 49.99, 'currency' => 'GBP',
        ]);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 4999, 'is_active' => true, 'livemode' => false,
        ]);

        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
        $this->lifecycle->markPendingPayment($subscription, $this->context());

        return $this->lifecycle->activate($subscription, [
            'id' => 'sub_fake_' . random_int(1, 1000000), 'status' => 'active', 'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false, 'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp, 'trial_end' => null, 'livemode' => false,
        ], $this->context());
    }

    private function invoiceEvent(string $type, Subscription $subscription, array $overrides = []): BillingWebhookEvent
    {
        $dataObject = array_merge([
            'id' => 'in_' . random_int(1, 10000000),
            'number' => 'STRIPE-INV-' . random_int(1, 10000000),
            'status' => $type === 'invoice.paid' ? 'paid' : 'open',
            'customer' => 'cus_fake_1',
            'subscription' => $subscription->provider_subscription_id,
            'livemode' => false,
            'currency' => 'gbp',
            'subtotal' => 4999,
            'tax' => 0,
            'total' => 4999,
            'amount_due' => 4999,
            'amount_paid' => $type === 'invoice.paid' ? 4999 : 0,
            'amount_remaining' => $type === 'invoice.paid' ? 0 : 4999,
            'hosted_invoice_url' => 'https://invoice.stripe.test/fake',
            'invoice_pdf' => 'https://invoice.stripe.test/fake.pdf',
            'billing_reason' => 'subscription_cycle',
            'period_start' => CarbonImmutable::now()->subMonth()->timestamp,
            'period_end' => CarbonImmutable::now()->timestamp,
            'due_date' => CarbonImmutable::now()->timestamp,
            'payment_intent' => $type === 'invoice.paid' ? 'pi_' . random_int(1, 10000000) : null,
            'metadata' => [],
        ], $overrides);

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

    public function test_invoice_paid_persists_an_invoice_and_payment_record(): void
    {
        $subscription = $this->activeSubscription();
        $event = $this->invoiceEvent('invoice.paid', $subscription);

        $result = $this->processor->process($event);

        $this->assertSame('processed', $result->status);
        $invoice = BillingInvoice::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertNotNull($invoice->invoice_number);
        $this->assertStringStartsWith('INV-', $invoice->invoice_number);

        $payment = BillingPayment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(4999, $payment->amount);
        $this->assertStringStartsWith('PAY-', $payment->internal_reference);
    }

    /**
     * Phase E3 finance-readiness finding: `invoice_number` (SUREsign's own
     * internal reference, asserted above) must never be confused with
     * Stripe's own invoice number — this is the passthrough field an
     * accountant reconciling against the actual Stripe document needs.
     */
    public function test_invoice_paid_persists_stripes_own_invoice_number_separately(): void
    {
        $subscription = $this->activeSubscription();
        $event = $this->invoiceEvent('invoice.paid', $subscription, ['number' => 'STRIPE-FIXED-0001']);

        $this->processor->process($event);

        $invoice = BillingInvoice::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('STRIPE-FIXED-0001', $invoice->provider_invoice_number);
        $this->assertNotSame($invoice->invoice_number, $invoice->provider_invoice_number);
    }

    public function test_invoice_payment_failed_marks_an_active_subscription_past_due(): void
    {
        $subscription = $this->activeSubscription();
        $event = $this->invoiceEvent('invoice.payment_failed', $subscription);

        $result = $this->processor->process($event);

        $this->assertSame('processed', $result->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->fresh()->status);

        $invoice = BillingInvoice::where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('open', $invoice->status);
    }

    public function test_invoice_paid_restores_a_past_due_subscription(): void
    {
        $subscription = $this->activeSubscription();
        $this->lifecycle->markPastDue($subscription, $this->context());

        $event = $this->invoiceEvent('invoice.paid', $subscription->fresh());
        $result = $this->processor->process($event);

        $this->assertSame('processed', $result->status);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);
    }

    public function test_invoice_paid_does_not_auto_restore_a_suspended_subscription(): void
    {
        $subscription = $this->activeSubscription();
        $this->lifecycle->suspend($subscription, 'compliance hold', $this->context());

        $event = $this->invoiceEvent('invoice.paid', $subscription->fresh());
        $result = $this->processor->process($event);

        $this->assertSame('processed', $result->status);
        $this->assertSame('invoice_synced', $result->action);
        $this->assertSame(SubscriptionStatus::SUSPENDED, $subscription->fresh()->status);
    }

    public function test_duplicate_invoice_paid_event_does_not_duplicate_the_invoice_or_payment_row(): void
    {
        $subscription = $this->activeSubscription();
        $providerInvoiceId = 'in_duplicate_test';

        $this->processor->process($this->invoiceEvent('invoice.paid', $subscription, ['id' => $providerInvoiceId, 'payment_intent' => 'pi_fixed']));
        $this->processor->process($this->invoiceEvent('invoice.paid', $subscription, ['id' => $providerInvoiceId, 'payment_intent' => 'pi_fixed']));

        $this->assertSame(1, BillingInvoice::where('provider_invoice_id', $providerInvoiceId)->count());
        $this->assertSame(1, BillingPayment::where('provider_payment_intent_id', 'pi_fixed')->count());
    }

    public function test_invoice_for_an_unknown_subscription_fails_retryably_for_correlation(): void
    {
        $subscription = $this->activeSubscription();
        $event = $this->invoiceEvent('invoice.paid', $subscription, ['subscription' => 'sub_does_not_exist_locally']);

        $result = $this->processor->process($event);

        $this->assertSame('failed', $result->status);
        $this->assertTrue($result->retryable);
        $this->assertSame(0, BillingInvoice::count());
    }

    public function test_no_card_or_sensitive_payment_details_are_persisted(): void
    {
        $subscription = $this->activeSubscription();
        $this->processor->process($this->invoiceEvent('invoice.paid', $subscription));

        $payment = BillingPayment::firstOrFail();
        $this->assertArrayNotHasKey('card_number', $payment->getAttributes());
        $this->assertArrayNotHasKey('cvc', $payment->getAttributes());
    }
}
