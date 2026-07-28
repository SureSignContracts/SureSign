<?php

namespace Tests\Feature\Billing;

use App\Models\BillingAdjustment;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Services\Billing\BillingReferenceService;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSchemaFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function organization(): Organization
    {
        return Organization::create(['name' => 'Acme Ltd', 'slug' => 'acme-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
    }

    private function plan(): PricingPlan
    {
        return PricingPlan::create([
            'code' => 'pro-' . random_int(1, 1000000),
            'slug' => 'pro-' . random_int(1, 1000000),
            'name' => 'Professional',
            'monthly_price' => 29.99,
            'currency' => 'GBP',
        ]);
    }

    public function test_billing_customer_maps_one_per_organization_per_provider(): void
    {
        $org = $this->organization();

        BillingCustomer::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_1',
        ]);

        $this->expectException(QueryException::class);
        BillingCustomer::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_customer_id' => 'cus_2',
        ]);
    }

    public function test_billing_customer_allows_one_test_mode_and_one_live_mode_row_per_organization(): void
    {
        $org = $this->organization();

        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_test_1', 'livemode' => false,
        ]);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_live_1', 'livemode' => true,
        ]);

        $this->assertDatabaseCount('billing_customers', 2);
    }

    public function test_pricing_plan_provider_price_scopes_by_livemode(): void
    {
        $plan = $this->plan();

        $testMapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_test_1', 'livemode' => false, 'unit_amount' => 2999,
        ]);
        $liveMapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_price_id' => 'price_live_1', 'livemode' => true, 'unit_amount' => 2999,
        ]);

        $testResults = PricingPlanProviderPrice::forLivemode(false)->pluck('id');
        $liveResults = PricingPlanProviderPrice::forLivemode(true)->pluck('id');

        $this->assertTrue($testResults->contains($testMapping->id));
        $this->assertFalse($testResults->contains($liveMapping->id));
        $this->assertTrue($liveResults->contains($liveMapping->id));
        $this->assertFalse($liveResults->contains($testMapping->id));
    }

    public function test_billing_customer_provider_customer_id_is_unique_per_provider(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        BillingCustomer::create(['organization_id' => $orgA->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_shared']);

        $this->expectException(QueryException::class);
        BillingCustomer::create(['organization_id' => $orgB->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_shared']);
    }

    public function test_subscription_relationships_resolve(): void
    {
        $org = $this->organization();
        $plan = $this->plan();
        $customer = BillingCustomer::create(['organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_1']);

        $subscription = Subscription::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'billing_customer_id' => $customer->id,
            'provider' => 'stripe',
            'internal_reference' => (new BillingReferenceService())->generate(\App\Support\Billing\BillingReferenceType::SUBSCRIPTION),
            'status' => SubscriptionStatus::DRAFT,
            'currency' => 'GBP',
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
        ]);

        $this->assertTrue($subscription->organization->is($org));
        $this->assertTrue($subscription->pricingPlan->is($plan));
        $this->assertTrue($subscription->billingCustomer->is($customer));
        $this->assertTrue($org->subscriptions->contains($subscription));
        $this->assertTrue($org->liveSubscription->is($subscription));
    }

    public function test_subscription_internal_reference_must_be_unique(): void
    {
        $org = $this->organization();

        Subscription::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-000001',
            'status' => SubscriptionStatus::DRAFT,
            'currency' => 'GBP',
        ]);

        $this->expectException(QueryException::class);
        Subscription::create([
            'organization_id' => $this->organization()->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-000001',
            'status' => SubscriptionStatus::DRAFT,
            'currency' => 'GBP',
        ]);
    }

    public function test_subscription_item_belongs_to_subscription(): void
    {
        $org = $this->organization();
        $subscription = Subscription::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-000002',
            'status' => SubscriptionStatus::ACTIVE,
            'currency' => 'GBP',
        ]);

        $item = SubscriptionItem::create([
            'subscription_id' => $subscription->id,
            'item_type' => 'plan',
            'code' => 'pro',
            'name' => 'Professional',
            'quantity' => 1,
            'unit_amount' => 2999,
            'currency' => 'GBP',
        ]);

        $this->assertTrue($subscription->items->contains($item));
        $this->assertTrue($item->subscription->is($subscription));
    }

    public function test_billing_invoice_unique_per_provider_invoice_id(): void
    {
        $org = $this->organization();

        BillingInvoice::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_invoice_id' => 'in_1',
            'status' => 'open',
            'currency' => 'GBP',
        ]);

        $this->expectException(QueryException::class);
        BillingInvoice::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_invoice_id' => 'in_1',
            'status' => 'open',
            'currency' => 'GBP',
        ]);
    }

    public function test_billing_payment_unique_per_provider_payment_intent(): void
    {
        $org = $this->organization();

        BillingPayment::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_payment_intent_id' => 'pi_1',
            'internal_reference' => 'PAY-000001',
            'status' => 'succeeded',
            'currency' => 'GBP',
            'amount' => 2999,
        ]);

        $this->expectException(QueryException::class);
        BillingPayment::create([
            'organization_id' => $org->id,
            'provider' => 'stripe',
            'provider_payment_intent_id' => 'pi_1',
            'internal_reference' => 'PAY-000002',
            'status' => 'succeeded',
            'currency' => 'GBP',
            'amount' => 2999,
        ]);
    }

    public function test_billing_payment_allows_multiple_null_payment_intents(): void
    {
        $org = $this->organization();

        BillingPayment::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'internal_reference' => 'PAY-000003', 'status' => 'pending', 'currency' => 'GBP', 'amount' => 1000,
        ]);
        BillingPayment::create([
            'organization_id' => $org->id, 'provider' => 'stripe',
            'internal_reference' => 'PAY-000004', 'status' => 'pending', 'currency' => 'GBP', 'amount' => 1000,
        ]);

        $this->assertDatabaseCount('billing_payments', 2);
    }

    public function test_billing_webhook_event_unique_per_provider_event_id(): void
    {
        BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_1', 'event_type' => 'checkout.session.completed',
            'received_at' => now(), 'payload_json' => ['id' => 'evt_1'],
        ]);

        $this->expectException(QueryException::class);
        BillingWebhookEvent::create([
            'provider' => 'stripe', 'provider_event_id' => 'evt_1', 'event_type' => 'checkout.session.completed',
            'received_at' => now(), 'payload_json' => ['id' => 'evt_1'],
        ]);
    }

    public function test_billing_checkout_session_relationships_and_uniqueness(): void
    {
        $org = $this->organization();
        $plan = $this->plan();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $session = BillingCheckoutSession::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_1',
            'internal_reference' => 'CHK-000001',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
        ]);

        $this->assertTrue($session->organization->is($org));
        $this->assertTrue($session->pricingPlan->is($plan));
        $this->assertTrue($session->initiatedBy->is($user));

        $this->expectException(QueryException::class);
        BillingCheckoutSession::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_1',
            'internal_reference' => 'CHK-000002',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
        ]);
    }

    public function test_billing_adjustment_records_a_signed_amount(): void
    {
        $org = $this->organization();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $adjustment = BillingAdjustment::create([
            'organization_id' => $org->id,
            'type' => 'credit',
            'description' => 'Goodwill credit',
            'currency' => 'GBP',
            'amount' => -500,
            'effective_at' => now(),
            'created_by_user_id' => $user->id,
        ]);

        $this->assertSame(-500, $adjustment->amount);
        $this->assertTrue($adjustment->createdBy->is($user));
    }

    public function test_pricing_plan_provider_price_maps_to_plan_without_touching_pricing_columns(): void
    {
        $plan = $this->plan();

        $price = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_1',
            'unit_amount' => 2999,
        ]);

        $this->assertTrue($plan->providerPrices->contains($price));
        // Pricing Management's own decimal display field is untouched.
        $this->assertSame('29.99', (string) $plan->monthly_price);
    }

    public function test_billing_reference_service_generates_sequential_padded_references(): void
    {
        $service = new BillingReferenceService();

        $this->assertSame('SUB-000001', $service->generate(\App\Support\Billing\BillingReferenceType::SUBSCRIPTION));
        $this->assertSame('SUB-000002', $service->generate(\App\Support\Billing\BillingReferenceType::SUBSCRIPTION));
        $this->assertSame('INV-000001', $service->generate(\App\Support\Billing\BillingReferenceType::INVOICE));
    }

    public function test_billing_reference_service_rejects_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BillingReferenceService())->generate('not_a_real_type');
    }
}
