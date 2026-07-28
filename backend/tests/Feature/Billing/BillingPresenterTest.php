<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_presenter_never_includes_the_raw_provider_payload(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme', 'timezone' => 'Europe/London']);

        $invoice = BillingInvoice::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_invoice_id' => 'in_1',
            'status' => 'paid',
            'currency' => 'GBP',
            'provider_payload_json' => ['secret_internal_field' => 'should-never-leak', 'lines' => ['a', 'b']],
        ]);

        $presented = BillingPresenter::invoice($invoice);

        $this->assertArrayNotHasKey('provider_payload_json', $presented);
        $this->assertStringNotContainsString('should-never-leak', json_encode($presented));
    }

    public function test_payment_presenter_omits_failure_code_and_provider_payload(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme2', 'timezone' => 'Europe/London']);

        $payment = BillingPayment::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'internal_reference' => 'PAY-000001',
            'status' => 'failed',
            'currency' => 'GBP',
            'amount' => 2999,
            'failure_code' => 'card_declined',
            'provider_payload_json' => ['raw' => 'stripe-internal-detail'],
        ]);

        $presented = BillingPresenter::payment($payment);

        $this->assertArrayNotHasKey('failure_code', $presented);
        $this->assertArrayNotHasKey('provider_payload_json', $presented);
    }

    public function test_webhook_event_presenter_never_includes_the_payload(): void
    {
        $event = BillingWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_1',
            'event_type' => 'invoice.paid',
            'received_at' => now(),
            'payload_json' => ['data' => ['object' => ['id' => 'in_1', 'customer_email' => 'someone@example.com']]],
        ]);

        $presented = BillingPresenter::webhookEvent($event);

        $this->assertArrayNotHasKey('payload_json', $presented);
        $this->assertStringNotContainsString('someone@example.com', json_encode($presented));
    }

    /**
     * Phase E6 — activated_at is the only reliable signal distinguishing a
     * subscription that genuinely went live from one cancelled/expired
     * while still pending_payment; the presenter must always expose it.
     */
    public function test_subscription_presenter_exposes_activated_at(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-presenter', 'timezone' => 'Europe/London']);
        $activatedAt = now()->subDays(5);

        $subscription = Subscription::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-000099',
            'status' => SubscriptionStatus::ACTIVE,
            'activated_at' => $activatedAt,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 29900,
            'quantity' => 1,
            'subtotal_amount' => 29900,
            'tax_amount' => 0,
            'total_amount' => 29900,
        ]);

        $presented = BillingPresenter::subscription($subscription);

        $this->assertArrayHasKey('activated_at', $presented);
        $this->assertNotNull($presented['activated_at']);
    }
}
