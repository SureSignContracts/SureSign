<?php

namespace Tests\Feature\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlanChange;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\PlanChangeType;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Read-only organisation-facing Billing API (Slice A) — BillingController /
 * BillingOverviewService. Covers the "no subscription yet" state, the
 * shape of an active subscription's overview, pending plan-change
 * surfacing, the purchasable-plans listing, and organisation-scoping/IDOR
 * isolation across invoices and payments.
 */
class BillingOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => strtolower($name) . '-' . random_int(1, 10000000),
            'timezone' => 'Europe/London',
        ]);
    }

    private function plan(string $code, int $order): PricingPlan
    {
        return PricingPlan::create([
            'code' => $code,
            'slug' => $code,
            'name' => ucfirst($code),
            'order' => $order,
            'status' => 'active',
        ]);
    }

    public function test_overview_reports_no_subscription_for_a_new_organisation(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/overview');

        $response->assertOk()->assertJson([
            'has_subscription' => false,
            'subscription' => null,
            'access' => ['mode' => 'none', 'reason_code' => 'no_subscription'],
        ]);
    }

    public function test_overview_reflects_an_active_subscription_and_latest_invoice_payment(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('professional', 2);

        $subscription = Subscription::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-000001',
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
            'plan_code_snapshot' => 'professional',
            'plan_name_snapshot' => 'Professional',
        ]);

        BillingInvoice::create([
            'provider_invoice_id' => 'in_test_1',
            'organization_id' => $org->id,
            'subscription_id' => $subscription->id,
            'provider' => 'stripe',
            'invoice_number' => 'INV-000001',
            'status' => 'paid',
            'currency' => 'GBP',
            'subtotal_amount' => 79900,
            'total_amount' => 79900,
            'amount_due' => 0,
            'amount_paid' => 79900,
            'amount_remaining' => 0,
        ]);

        BillingPayment::create([
            'organization_id' => $org->id,
            'subscription_id' => $subscription->id,
            'provider' => 'stripe',
            'internal_reference' => 'PAY-000001',
            'status' => 'succeeded',
            'currency' => 'GBP',
            'amount' => 79900,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/overview');

        $response->assertOk()
            ->assertJson([
                'has_subscription' => true,
                'access' => ['mode' => 'full', 'subscription_status' => 'active'],
            ])
            ->assertJsonPath('subscription.plan_code', 'professional')
            ->assertJsonPath('latest_invoice.invoice_number', 'INV-000001')
            ->assertJsonPath('latest_payment.internal_reference', 'PAY-000001');
    }

    public function test_pending_plan_change_is_surfaced_on_overview_and_dedicated_endpoint(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $currentPlan = $this->plan('professional', 2);
        $targetPlan = $this->plan('enterprise', 3);

        $subscription = Subscription::create([
            'internal_reference' => 'SUB-TEST-2',
            'organization_id' => $org->id,
            'pricing_plan_id' => $currentPlan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
        ]);

        $targetMapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $targetPlan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_product_id' => 'prod_enterprise',
            'provider_price_id' => 'price_enterprise_monthly',
            'livemode' => false,
            'unit_amount' => 199900,
            'is_active' => true,
        ]);

        BillingPlanChange::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $org->id,
            'source_pricing_plan_id' => $currentPlan->id,
            'target_pricing_plan_id' => $targetPlan->id,
            'target_price_mapping_id' => $targetMapping->id,
            'change_type' => PlanChangeType::UPGRADE,
            'policy' => 'immediate',
            'livemode' => false,
            'state' => PlanChangeState::SENT,
            'requested_effective_at' => now(),
            'requested_at' => now(),
            'idempotency_key' => 'plan_change:test-1',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/billing/overview')
            ->assertOk()
            ->assertJsonPath('pending_plan_change.target_plan_code', 'enterprise')
            ->assertJsonPath('pending_plan_change.state', PlanChangeState::SENT);

        $this->getJson('/api/billing/pending-plan-change')
            ->assertOk()
            ->assertJsonPath('pending_plan_change.target_plan_code', 'enterprise');
    }

    public function test_plans_endpoint_marks_the_organisations_current_plan_and_excludes_provider_price_ids(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $this->plan('professional', 2);
        $this->plan('enterprise', 3);

        PricingPlanProviderPrice::create([
            'pricing_plan_id' => $essential->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_product_id' => 'prod_test',
            'provider_price_id' => 'price_test_secret',
            'livemode' => false,
            'unit_amount' => 29900,
            'is_active' => true,
        ]);

        Subscription::create([
            'internal_reference' => 'SUB-TEST-3',
            'organization_id' => $org->id,
            'pricing_plan_id' => $essential->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 29900,
            'quantity' => 1,
            'subtotal_amount' => 29900,
            'tax_amount' => 0,
            'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/plans');

        $response->assertOk();
        $plans = $response->json('plans');

        $this->assertSame(['essential', 'professional', 'enterprise'], array_column($plans, 'code'));

        $essentialPayload = collect($plans)->firstWhere('code', 'essential');
        $this->assertTrue($essentialPayload['is_current']);
        $this->assertSame(29900, $essentialPayload['monthly']['unit_amount']);
        $this->assertStringNotContainsString('price_test_secret', json_encode($response->json()));

        $professionalPayload = collect($plans)->firstWhere('code', 'professional');
        $this->assertFalse($professionalPayload['is_current']);
        $this->assertNull($professionalPayload['monthly']);
    }

    /**
     * Phase E4 root-cause fix: an unactivated pending_payment subscription
     * (an abandoned Checkout) must never mark its plan "current" — before
     * this fix, ANY subscription status did.
     */
    public function test_a_pending_payment_subscription_never_marks_its_plan_as_current(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);

        Subscription::create([
            'internal_reference' => 'SUB-PENDING-1',
            'organization_id' => $org->id,
            'pricing_plan_id' => $essential->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 29900,
            'quantity' => 1,
            'subtotal_amount' => 29900,
            'tax_amount' => 0,
            'total_amount' => 29900,
        ]);

        Sanctum::actingAs($user);

        $plans = $this->getJson('/api/billing/plans')->assertOk()->json('plans');
        $essentialPayload = collect($plans)->firstWhere('code', 'essential');

        $this->assertFalse($essentialPayload['is_current']);
    }

    /**
     * Phase E6 — a subscription that genuinely went live (activated_at
     * set) and was later cancelled is a real commercial cancellation, NOT
     * an abandoned Checkout, even though it shares the same terminal
     * status as one. It must still never block a fresh Checkout attempt
     * (`cancelled` is a terminal, non-blocking status).
     */
    public function test_a_previously_active_then_cancelled_subscription_is_not_flagged_as_an_abandoned_checkout(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('professional', 2);

        Subscription::create([
            'internal_reference' => 'SUB-CANCELLED-1',
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::CANCELLED,
            'activated_at' => now()->subDays(30),
            'cancelled_at' => now(),
            'ended_at' => now(),
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/overview');

        $response->assertOk()
            ->assertJsonPath('subscription.status', SubscriptionStatus::CANCELLED)
            ->assertJsonPath('subscription.is_abandoned_checkout', false)
            ->assertJsonPath('can_start_new_checkout', true);
    }

    public function test_overview_exposes_pending_checkout_details_while_awaiting_payment(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);
        $mapping = PricingPlanProviderPrice::create([
            'pricing_plan_id' => $essential->id, 'provider' => 'stripe', 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'provider_product_id' => 'prod_test', 'provider_price_id' => 'price_test',
            'livemode' => false, 'unit_amount' => 29900, 'is_active' => true,
        ]);

        $subscription = Subscription::create([
            'internal_reference' => 'SUB-PENDING-2',
            'organization_id' => $org->id,
            'pricing_plan_id' => $essential->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 29900,
            'quantity' => 1,
            'subtotal_amount' => 29900,
            'tax_amount' => 0,
            'total_amount' => 29900,
        ]);

        \App\Models\BillingCheckoutSession::create([
            'organization_id' => $org->id,
            'subscription_id' => $subscription->id,
            'pricing_plan_id' => $essential->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_test_open',
            'internal_reference' => 'CHK-TEST-1',
            'status' => \App\Support\Billing\CheckoutSessionStatus::OPEN,
            'livemode' => false,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/cs_test_open',
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
            'expires_at' => now()->addHour(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/overview')->assertOk();

        $pendingCheckout = $response->json('subscription.pending_checkout');
        $this->assertNotNull($pendingCheckout);
        $this->assertSame('essential', $pendingCheckout['plan_code']);
        $this->assertSame('monthly', $pendingCheckout['billing_interval']);
        $this->assertTrue($pendingCheckout['is_resumable']);
    }

    public function test_overview_pending_checkout_is_not_resumable_once_expired(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $essential = $this->plan('essential', 1);

        $subscription = Subscription::create([
            'internal_reference' => 'SUB-PENDING-3',
            'organization_id' => $org->id,
            'pricing_plan_id' => $essential->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::PENDING_PAYMENT,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 29900,
            'quantity' => 1,
            'subtotal_amount' => 29900,
            'tax_amount' => 0,
            'total_amount' => 29900,
        ]);

        \App\Models\BillingCheckoutSession::create([
            'organization_id' => $org->id,
            'subscription_id' => $subscription->id,
            'pricing_plan_id' => $essential->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_test_expired',
            'internal_reference' => 'CHK-TEST-2',
            'status' => \App\Support\Billing\CheckoutSessionStatus::EXPIRED,
            'livemode' => false,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 29900,
            'checkout_url' => 'https://checkout.stripe.test/fake/cs_test_expired',
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
            'expires_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($user);

        $pendingCheckout = $this->getJson('/api/billing/overview')->assertOk()->json('subscription.pending_checkout');

        $this->assertNotNull($pendingCheckout);
        $this->assertFalse($pendingCheckout['is_resumable']);
    }

    public function test_invoice_detail_is_rejected_for_a_user_from_another_organisation(): void
    {
        $orgA = $this->org('Acme');
        $orgB = $this->org('Globex');
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $plan = $this->plan('professional', 2);
        $subscription = Subscription::create([
            'internal_reference' => 'SUB-TEST-4',
            'organization_id' => $orgA->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
        ]);

        $invoice = BillingInvoice::create([
            'provider_invoice_id' => 'in_test_2',
            'organization_id' => $orgA->id,
            'subscription_id' => $subscription->id,
            'provider' => 'stripe',
            'invoice_number' => 'INV-000002',
            'status' => 'paid',
            'currency' => 'GBP',
            'subtotal_amount' => 79900,
            'total_amount' => 79900,
            'amount_due' => 0,
            'amount_paid' => 79900,
            'amount_remaining' => 0,
        ]);

        Sanctum::actingAs($userB);

        $this->getJson("/api/billing/invoices/{$invoice->id}")->assertForbidden();
    }

    public function test_invoices_and_payments_lists_never_include_another_organisations_records(): void
    {
        $orgA = $this->org('Acme');
        $orgB = $this->org('Globex');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);

        $planA = $this->plan('professional', 2);
        $subA = Subscription::create([
            'internal_reference' => 'SUB-TEST-5',
            'organization_id' => $orgA->id, 'pricing_plan_id' => $planA->id, 'provider' => 'stripe',
            'livemode' => false, 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'unit_amount' => 79900, 'quantity' => 1,
            'subtotal_amount' => 79900, 'tax_amount' => 0, 'total_amount' => 79900,
        ]);
        $subB = Subscription::create([
            'internal_reference' => 'SUB-TEST-6',
            'organization_id' => $orgB->id, 'pricing_plan_id' => $planA->id, 'provider' => 'stripe',
            'livemode' => false, 'status' => SubscriptionStatus::ACTIVE, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'unit_amount' => 79900, 'quantity' => 1,
            'subtotal_amount' => 79900, 'tax_amount' => 0, 'total_amount' => 79900,
        ]);

        BillingInvoice::create([
            'provider_invoice_id' => 'in_test_3',
            'organization_id' => $orgA->id, 'subscription_id' => $subA->id, 'provider' => 'stripe',
            'invoice_number' => 'INV-A', 'status' => 'paid', 'currency' => 'GBP',
            'subtotal_amount' => 100, 'total_amount' => 100, 'amount_due' => 0, 'amount_paid' => 100, 'amount_remaining' => 0,
        ]);
        BillingInvoice::create([
            'provider_invoice_id' => 'in_test_4',
            'organization_id' => $orgB->id, 'subscription_id' => $subB->id, 'provider' => 'stripe',
            'invoice_number' => 'INV-B', 'status' => 'paid', 'currency' => 'GBP',
            'subtotal_amount' => 100, 'total_amount' => 100, 'amount_due' => 0, 'amount_paid' => 100, 'amount_remaining' => 0,
        ]);

        BillingPayment::create([
            'organization_id' => $orgA->id, 'subscription_id' => $subA->id, 'provider' => 'stripe',
            'internal_reference' => 'PAY-A', 'status' => 'succeeded', 'currency' => 'GBP', 'amount' => 100,
        ]);
        BillingPayment::create([
            'organization_id' => $orgB->id, 'subscription_id' => $subB->id, 'provider' => 'stripe',
            'internal_reference' => 'PAY-B', 'status' => 'succeeded', 'currency' => 'GBP', 'amount' => 100,
        ]);

        Sanctum::actingAs($userA);

        $invoiceNumbers = collect($this->getJson('/api/billing/invoices')->json('data'))->pluck('invoice_number');
        $this->assertSame(['INV-A'], $invoiceNumbers->all());

        $paymentRefs = collect($this->getJson('/api/billing/payments')->json('data'))->pluck('internal_reference');
        $this->assertSame(['PAY-A'], $paymentRefs->all());
    }
}
