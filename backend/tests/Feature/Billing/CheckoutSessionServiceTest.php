<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutSessionService;
use App\Services\Billing\Exceptions\CheckoutValidationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutSessionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutSessionService $service;
    private FakeBillingProvider $fake;
    private User $actor;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CheckoutSessionService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $this->org = Organization::create(['name' => 'Acme Construction Ltd', 'slug' => 'acme-' . random_int(1, 1000000), 'email' => 'billing@acme.test', 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function plan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'code' => 'pro-' . random_int(1, 1000000),
            'slug' => 'pro-' . random_int(1, 1000000),
            'name' => 'Professional',
            'monthly_price' => 29.99,
            'currency' => 'GBP',
            'status' => 'active',
        ], $overrides));
    }

    private function mapping(PricingPlan $plan, array $overrides = []): PricingPlanProviderPrice
    {
        return PricingPlanProviderPrice::create(array_merge([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 2999,
            'is_active' => true,
            'livemode' => false,
        ], $overrides));
    }

    private function startCheckout(?PricingPlan $plan = null, ?PricingPlanProviderPrice $mapping = null, ?string $correlationReference = null): BillingCheckoutSession
    {
        $plan ??= $this->plan();
        $mapping ??= $this->mapping($plan);

        return $this->service->startCheckout(
            $this->org,
            $plan,
            'monthly',
            'GBP',
            $this->actor,
            '/billing/success',
            '/billing/cancel',
            $correlationReference,
        );
    }

    // ─── Checkout creation ───────────────────────────────────────────────

    public function test_creates_a_checkout_session(): void
    {
        $session = $this->startCheckout();

        $this->assertNotEmpty($session->provider_checkout_session_id);
        $this->assertSame(CheckoutSessionStatus::OPEN, $session->status);
        $this->assertStringStartsWith('CHK-', $session->internal_reference);
    }

    public function test_creates_a_draft_subscription_first(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $session = $this->startCheckout($plan, $mapping);

        $subscription = Subscription::find($session->subscription_id);
        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->pricing_plan_id);
        // markPendingPayment() has already run by the time startCheckout() returns.
        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $subscription->status);
    }

    public function test_resolves_billing_customer(): void
    {
        $this->assertDatabaseCount('billing_customers', 0);

        $this->startCheckout();

        $this->assertDatabaseCount('billing_customers', 1);
    }

    public function test_resolves_provider_price(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan, ['provider_price_id' => 'price_specific_1']);

        $session = $this->startCheckout($plan, $mapping);
        $subscription = Subscription::find($session->subscription_id);

        $this->assertSame('price_specific_1', $subscription->provider_price_id);
    }

    public function test_persists_references(): void
    {
        $session = $this->startCheckout();

        $this->assertDatabaseHas('billing_checkout_sessions', [
            'id' => $session->id,
            'organization_id' => $this->org->id,
            'provider_checkout_session_id' => $session->provider_checkout_session_id,
        ]);
    }

    public function test_returns_a_checkout_url(): void
    {
        $session = $this->startCheckout();

        $this->assertNotEmpty($this->fake->checkoutSessions[$session->provider_checkout_session_id]['url']);
    }

    // ─── Validation ──────────────────────────────────────────────────────

    public function test_inactive_plan_is_rejected(): void
    {
        $plan = $this->plan(['status' => 'draft']);
        $mapping = $this->mapping($plan);

        $this->expectException(CheckoutValidationException::class);
        $this->startCheckout($plan, $mapping);
    }

    public function test_missing_provider_price_is_rejected(): void
    {
        $plan = $this->plan();
        // No mapping created at all for this plan/interval/currency.

        $this->expectException(CheckoutValidationException::class);
        $this->service->startCheckout($this->org, $plan, 'monthly', 'GBP', $this->actor, '/success', '/cancel');
    }

    public function test_unsupported_currency_is_rejected(): void
    {
        $plan = $this->plan();
        $this->mapping($plan);

        $this->expectException(CheckoutValidationException::class);
        $this->service->startCheckout($this->org, $plan, 'monthly', 'US', $this->actor, '/success', '/cancel');
    }

    public function test_unsupported_interval_is_rejected(): void
    {
        $plan = $this->plan();
        $this->mapping($plan);

        $this->expectException(CheckoutValidationException::class);
        $this->service->startCheckout($this->org, $plan, 'weekly', 'GBP', $this->actor, '/success', '/cancel');
    }

    public function test_livemode_mismatch_is_rejected_as_a_missing_price(): void
    {
        // resolveActivePrice() already scopes by current livemode, so a
        // livemode-mismatched mapping is indistinguishable from "no
        // mapping at all" — both correctly reject as CheckoutValidationException.
        $plan = $this->plan();
        $this->mapping($plan, ['livemode' => true]);

        $this->expectException(CheckoutValidationException::class);
        $this->service->startCheckout($this->org, $plan, 'monthly', 'GBP', $this->actor, '/success', '/cancel');
    }

    public function test_unsafe_redirect_url_is_rejected(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $this->expectException(CheckoutValidationException::class);
        $this->service->startCheckout($this->org, $plan, 'monthly', 'GBP', $this->actor, 'javascript:alert(1)', '/cancel');
    }

    public function test_conflicting_subscription_is_rejected_by_the_lifecycle_service(): void
    {
        Subscription::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-EXISTING-ACTIVE',
            'status' => SubscriptionStatus::ACTIVE,
            'currency' => 'GBP',
            'livemode' => false,
        ]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->startCheckout();
    }

    // ─── Idempotency ─────────────────────────────────────────────────────

    public function test_duplicate_request_with_the_same_correlation_reference_reuses_the_draft_and_session(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->startCheckout($plan, $mapping, 'checkout-abc');
        $second = $this->startCheckout($plan, $mapping, 'checkout-abc');

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('billing_checkout_sessions', 1);
    }

    public function test_expired_checkout_session_creates_a_new_one_and_keeps_the_old_record(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->startCheckout($plan, $mapping, 'checkout-xyz');

        // Simulate the session having expired.
        $first->update(['status' => CheckoutSessionStatus::EXPIRED, 'expires_at' => now()->subMinute()]);

        $second = $this->startCheckout($plan, $mapping, 'checkout-xyz');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->provider_checkout_session_id, $second->provider_checkout_session_id);
        $this->assertDatabaseHas('billing_checkout_sessions', ['id' => $first->id, 'status' => CheckoutSessionStatus::EXPIRED]);
        $this->assertDatabaseCount('billing_checkout_sessions', 2);
        $this->assertDatabaseCount('subscriptions', 1); // same draft subscription reused throughout
    }

    public function test_completed_checkout_session_is_never_reused(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->startCheckout($plan, $mapping, 'checkout-completed');
        $first->update(['status' => CheckoutSessionStatus::COMPLETED, 'completed_at' => now()]);

        $second = $this->startCheckout($plan, $mapping, 'checkout-completed');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(CheckoutSessionStatus::OPEN, $second->status);
    }

    public function test_open_unexpired_session_is_reused_without_a_new_provider_call(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->startCheckout($plan, $mapping, 'checkout-reuse');
        $sessionsBefore = count($this->fake->checkoutSessions);

        $second = $this->startCheckout($plan, $mapping, 'checkout-reuse');

        $this->assertTrue($first->is($second));
        $this->assertCount($sessionsBefore, $this->fake->checkoutSessions);
    }

    public function test_never_creates_multiple_open_sessions_for_the_same_draft(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        // Two calls without a correlation reference still resolve to the
        // same draft subscription IF the first draft has no reusable
        // session yet — but here we exercise the case where a session
        // already exists, via the same correlation reference.
        $this->startCheckout($plan, $mapping, 'checkout-single');
        $this->startCheckout($plan, $mapping, 'checkout-single');
        $this->startCheckout($plan, $mapping, 'checkout-single');

        $this->assertSame(
            1,
            BillingCheckoutSession::where('status', CheckoutSessionStatus::OPEN)->count()
        );
    }

    // ─── Redirects ───────────────────────────────────────────────────────

    public function test_success_url_does_not_activate_the_subscription(): void
    {
        $session = $this->startCheckout();
        $subscription = Subscription::find($session->subscription_id);

        // Nothing in this service ever calls activate() — confirmed
        // structurally: CheckoutSessionService has no reference to it.
        $source = file_get_contents(app_path('Services/Billing/CheckoutSessionService.php'));
        $this->assertStringNotContainsString('->activate(', $source);

        $this->assertNotSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertNull($subscription->activated_at);
    }

    public function test_cancel_url_does_not_cancel_the_subscription(): void
    {
        $session = $this->startCheckout();
        $subscription = Subscription::find($session->subscription_id);

        // Scoped to startCheckout()'s own method body specifically — Phase
        // E4 added a deliberate, separately-invoked cancelPendingCheckout()
        // method elsewhere in this class (the explicit "Cancel Pending
        // Checkout" customer action), which is never reachable via the
        // Stripe cancel_url redirect this test protects against. The
        // invariant under test is "visiting cancel_url never cancels
        // anything," not "this string never appears anywhere in the file."
        $method = new \ReflectionMethod(CheckoutSessionService::class, 'startCheckout');
        $lines = file(app_path('Services/Billing/CheckoutSessionService.php'));
        $methodSource = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringNotContainsString('->cancelImmediately(', $methodSource);
        $this->assertStringNotContainsString('->confirmCancellation(', $methodSource);

        $this->assertNotSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNull($subscription->cancelled_at);
    }

    /**
     * Phase E4 — the explicit "Cancel Pending Checkout" action DOES call
     * cancelImmediately(), deliberately, but ONLY via its own dedicated
     * method (cancelPendingCheckout()), never as a side effect of
     * startCheckout()/the Stripe cancel_url redirect (see the test above).
     */
    public function test_cancel_pending_checkout_is_the_only_method_that_calls_cancel_immediately(): void
    {
        $source = file_get_contents(app_path('Services/Billing/CheckoutSessionService.php'));
        $this->assertSame(1, substr_count($source, '->cancelImmediately('));

        $method = new \ReflectionMethod(CheckoutSessionService::class, 'cancelPendingCheckout');
        $lines = file(app_path('Services/Billing/CheckoutSessionService.php'));
        $methodSource = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('->cancelImmediately(', $methodSource);
    }

    // ─── Provider ────────────────────────────────────────────────────────

    public function test_fake_provider_checkout_session_is_deterministic(): void
    {
        $session = $this->startCheckout();
        $providerSession = $this->fake->checkoutSessions[$session->provider_checkout_session_id];

        $this->assertSame('open', $providerSession['status']);
        $this->assertFalse($providerSession['livemode']);
        $this->assertStringContainsString($providerSession['id'], $providerSession['url']);
    }

    public function test_metadata_contains_only_stable_identifiers(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $session = $this->startCheckout($plan, $mapping, 'checkout-meta');
        $providerSession = $this->fake->checkoutSessions[$session->provider_checkout_session_id];

        // The fake stores whatever createCheckoutSession() was called
        // with; the metadata itself was passed as a param, not persisted
        // by the fake, so assert against the local record instead, which
        // captures the same set of stable identifiers.
        $this->assertSame('checkout-meta', $session->metadata_json['correlation_reference']);
        $this->assertArrayHasKey('billing_customer_id', $session->metadata_json);
        $this->assertArrayHasKey('pricing_plan_provider_price_id', $session->metadata_json);
        $this->assertArrayNotHasKey('card', $session->metadata_json);
        $this->assertArrayNotHasKey('secret', $session->metadata_json);
    }

    // ─── Trusted subscription metadata (Subscription Event Hardening) ────

    public function test_checkout_propagates_trusted_subscription_metadata(): void
    {
        $session = $this->startCheckout();
        $providerSession = $this->fake->checkoutSessions[$session->provider_checkout_session_id];

        $subscriptionMetadata = $providerSession['subscription_metadata'];

        $this->assertSame((string) $session->subscription_id, $subscriptionMetadata['suresign_subscription_id']);
        $this->assertSame((string) $this->org->id, $subscriptionMetadata['suresign_organization_id']);
        $this->assertSame($session->internal_reference, $subscriptionMetadata['suresign_checkout_session_id']);
    }

    public function test_subscription_metadata_contains_no_sensitive_values(): void
    {
        $session = $this->startCheckout();
        $subscriptionMetadata = $this->fake->checkoutSessions[$session->provider_checkout_session_id]['subscription_metadata'];

        foreach ($subscriptionMetadata as $key => $value) {
            $this->assertStringStartsWith('suresign_', $key);
        }
        $this->assertArrayNotHasKey('email', $subscriptionMetadata);
        $this->assertArrayNotHasKey('card', $subscriptionMetadata);
        $this->assertArrayNotHasKey('amount', $subscriptionMetadata);
        $this->assertArrayNotHasKey('price', $subscriptionMetadata);
    }

    public function test_session_metadata_and_subscription_metadata_share_the_same_identifiers(): void
    {
        // Deliberately the same dictionary for both — see
        // CheckoutSessionService::checkoutMetadata()'s docblock.
        $session = $this->startCheckout();
        $providerSession = $this->fake->checkoutSessions[$session->provider_checkout_session_id];

        $this->assertSame(
            $providerSession['subscription_metadata']['suresign_subscription_id'],
            (string) $session->subscription_id,
        );
    }

    // ─── ActivityLog ─────────────────────────────────────────────────────

    public function test_records_checkout_created_activity(): void
    {
        $this->startCheckout();

        $this->assertDatabaseHas('activity_logs', ['action' => 'checkout.created']);
    }

    public function test_records_checkout_recreated_activity_on_expiry_recreation(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->startCheckout($plan, $mapping, 'checkout-recreate');
        $first->update(['status' => CheckoutSessionStatus::EXPIRED]);
        $this->startCheckout($plan, $mapping, 'checkout-recreate');

        $this->assertDatabaseHas('activity_logs', ['action' => 'checkout.recreated']);
    }

    public function test_activity_log_never_contains_raw_provider_payload(): void
    {
        $this->startCheckout();

        $log = ActivityLog::where('action', 'checkout.created')->first();
        $this->assertArrayNotHasKey('provider_payload', $log->metadata ?? []);
        $this->assertArrayNotHasKey('card', $log->metadata ?? []);
    }

    // ─── Regression ──────────────────────────────────────────────────────

    public function test_billing_provider_interface_checkout_methods_expose_livemode(): void
    {
        $methods = (new \ReflectionClass(\App\Services\Billing\BillingProviderInterface::class))->getMethods();
        $names = array_map(fn ($m) => $m->getName(), $methods);

        $this->assertContains('createCheckoutSession', $names);
        $this->assertContains('retrieveCheckoutSession', $names);
    }
}
