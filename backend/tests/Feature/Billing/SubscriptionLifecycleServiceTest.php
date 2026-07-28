<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionActivationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $service;
    private FakeBillingProvider $fake;
    private User $actor;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(SubscriptionLifecycleService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $this->org = Organization::create(['name' => 'Acme Construction Ltd', 'slug' => 'acme-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
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

    private function context(array $overrides = []): TransitionContext
    {
        return TransitionContext::make(array_merge([
            'source' => TransitionSource::SUPER_ADMIN,
            'actor_user_id' => $this->actor->id,
        ], $overrides));
    }

    private function draft(?PricingPlan $plan = null, ?PricingPlanProviderPrice $mapping = null): Subscription
    {
        $plan ??= $this->plan();
        $mapping ??= $this->mapping($plan);

        return $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
    }

    private function providerSubscriptionData(array $overrides = []): array
    {
        return array_merge([
            'id' => 'sub_fake_' . random_int(1, 1000000),
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ], $overrides);
    }

    // ─── Creation ────────────────────────────────────────────────────────

    public function test_creates_a_draft_subscription_from_an_approved_plan_and_mapping(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $subscription = $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());

        $this->assertSame(SubscriptionStatus::DRAFT, $subscription->status);
        $this->assertSame($this->org->id, $subscription->organization_id);
        $this->assertSame($plan->id, $subscription->pricing_plan_id);
        $this->assertNotEmpty($subscription->internal_reference);
        $this->assertStringStartsWith('SUB-', $subscription->internal_reference);
    }

    public function test_snapshots_the_intended_commercial_values(): void
    {
        $plan = $this->plan(['code' => 'pro-snap', 'name' => 'Professional Snap']);
        $mapping = $this->mapping($plan, ['unit_amount' => 3999, 'currency' => 'GBP']);

        $subscription = $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());

        $this->assertSame('pro-snap', $subscription->plan_code_snapshot);
        $this->assertSame('Professional Snap', $subscription->plan_name_snapshot);
        $this->assertSame(3999, $subscription->unit_amount);
        $this->assertSame(3999, $subscription->total_amount);
        $this->assertSame('GBP', $subscription->currency);
        $this->assertSame(1, $subscription->quantity);
    }

    public function test_rejects_archived_plan_for_a_new_sale(): void
    {
        $plan = $this->plan(['status' => 'archived']);
        $mapping = $this->mapping($plan);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
    }

    public function test_rejects_inactive_provider_price_mapping(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan, ['is_active' => false]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
    }

    public function test_rejects_mapping_belonging_to_a_different_plan(): void
    {
        $planA = $this->plan();
        $planB = $this->plan();
        $mappingForB = $this->mapping($planB);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->createDraftSubscription($this->org, $planA, $mappingForB, 'monthly', $this->context());
    }

    public function test_rejects_test_live_mismatch_on_creation(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan, ['livemode' => true]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
    }

    public function test_created_subscription_never_carries_a_seat_quantity_beyond_one(): void
    {
        $subscription = $this->draft();

        $this->assertSame(1, $subscription->quantity);
    }

    public function test_repeated_creation_with_the_same_correlation_reference_is_idempotent(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $first = $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context(), correlationReference: 'checkout-abc');
        $second = $this->service->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context(), correlationReference: 'checkout-abc');

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('subscriptions', 1);
    }

    // ─── Conflicting-subscription invariant ─────────────────────────────

    public function test_each_conflicting_status_blocks_a_new_draft_subscription(): void
    {
        $conflictingStatuses = [
            SubscriptionStatus::TRIALING,
            SubscriptionStatus::PENDING_PAYMENT,
            SubscriptionStatus::INCOMPLETE,
            SubscriptionStatus::ACTIVE,
            SubscriptionStatus::PAST_DUE,
            SubscriptionStatus::UNPAID,
            SubscriptionStatus::PAUSED,
            SubscriptionStatus::SUSPENDED,
        ];

        foreach ($conflictingStatuses as $status) {
            $org = Organization::create(['name' => "Org {$status}", 'slug' => 'org-' . $status . '-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);

            Subscription::create([
                'organization_id' => $org->id,
                'provider' => 'stripe',
                'internal_reference' => 'SUB-CONFLICT-' . $status,
                'status' => $status,
                'currency' => 'GBP',
                'livemode' => false,
            ]);

            $this->assertTrue($this->service->hasConflictingSubscription($org), "Expected status \"{$status}\" to conflict.");

            $plan = $this->plan();
            $mapping = $this->mapping($plan);

            try {
                $this->service->createDraftSubscription($org, $plan, $mapping, 'monthly', $this->context());
                $this->fail("Expected createDraftSubscription() to reject status \"{$status}\".");
            } catch (SubscriptionLifecycleConflictException) {
                // expected
            }
        }
    }

    public function test_terminal_historical_statuses_never_conflict(): void
    {
        foreach ([SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED] as $status) {
            $org = Organization::create(['name' => "Org {$status}", 'slug' => 'org-' . $status . '-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);

            Subscription::create([
                'organization_id' => $org->id,
                'provider' => 'stripe',
                'internal_reference' => 'SUB-TERMINAL-' . $status,
                'status' => $status,
                'currency' => 'GBP',
                'livemode' => false,
            ]);

            $this->assertFalse($this->service->hasConflictingSubscription($org), "Expected status \"{$status}\" not to conflict.");

            $plan = $this->plan();
            $mapping = $this->mapping($plan);
            $subscription = $this->service->createDraftSubscription($org, $plan, $mapping, 'monthly', $this->context());

            $this->assertSame(SubscriptionStatus::DRAFT, $subscription->status);
        }
    }

    public function test_active_with_scheduled_cancellation_still_conflicts(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleCancellation($subscription, $this->context());

        $this->assertTrue($this->service->hasConflictingSubscription($this->org));

        $newPlan = $this->plan();
        $newMapping = $this->mapping($newPlan);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->createDraftSubscription($this->org, $newPlan, $newMapping, 'monthly', $this->context());
    }

    public function test_bare_draft_with_no_checkout_session_does_not_conflict(): void
    {
        $this->draft(); // a draft with no checkout session at all

        $this->assertFalse($this->service->hasConflictingSubscription($this->org));
    }

    public function test_draft_with_only_expired_checkout_sessions_does_not_conflict(): void
    {
        $draft = $this->draft();
        \App\Models\BillingCheckoutSession::create([
            'organization_id' => $this->org->id,
            'subscription_id' => $draft->id,
            'pricing_plan_id' => $draft->pricing_plan_id,
            'initiated_by_user_id' => $this->actor->id,
            'provider' => 'stripe',
            'internal_reference' => 'CHK-TEST-0001',
            'status' => \App\Support\Billing\CheckoutSessionStatus::EXPIRED,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertFalse($this->service->hasConflictingSubscription($this->org));
    }

    public function test_draft_with_an_open_unexpired_checkout_session_conflicts(): void
    {
        $draft = $this->draft();
        \App\Models\BillingCheckoutSession::create([
            'organization_id' => $this->org->id,
            'subscription_id' => $draft->id,
            'pricing_plan_id' => $draft->pricing_plan_id,
            'initiated_by_user_id' => $this->actor->id,
            'provider' => 'stripe',
            'internal_reference' => 'CHK-TEST-0002',
            'status' => \App\Support\Billing\CheckoutSessionStatus::OPEN,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
            'expires_at' => now()->addHours(24),
        ]);

        $this->assertTrue($this->service->hasConflictingSubscription($this->org));
    }

    public function test_draft_with_a_completed_checkout_session_does_not_conflict(): void
    {
        $draft = $this->draft();
        \App\Models\BillingCheckoutSession::create([
            'organization_id' => $this->org->id,
            'subscription_id' => $draft->id,
            'pricing_plan_id' => $draft->pricing_plan_id,
            'initiated_by_user_id' => $this->actor->id,
            'provider' => 'stripe',
            'internal_reference' => 'CHK-TEST-0003',
            'status' => \App\Support\Billing\CheckoutSessionStatus::COMPLETED,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
            'completed_at' => now(),
        ]);

        // A completed checkout session for a draft that never got activated
        // (no webhook has run in this checkpoint) should not itself be
        // treated as a reusable "open" intent — completed means the
        // provider-side flow finished, not that a new one may reopen it.
        $this->assertFalse($this->service->hasConflictingSubscription($this->org));
    }

    public function test_conflicting_subscription_check_is_scoped_to_current_livemode(): void
    {
        Subscription::create([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-LIVE-CONFLICT',
            'status' => SubscriptionStatus::ACTIVE,
            'currency' => 'GBP',
            'livemode' => true, // a live-mode subscription
        ]);

        // Current environment (FakeBillingProvider default) is test mode —
        // a live-mode subscription must never block a test-mode attempt.
        $this->assertFalse($this->service->hasConflictingSubscription($this->org));
    }

    // ─── Trial ───────────────────────────────────────────────────────────

    public function test_starts_a_trial_from_draft(): void
    {
        $subscription = $this->draft();
        $trialEndsAt = CarbonImmutable::now()->addDays(14);

        $result = $this->service->startTrial($subscription, $trialEndsAt, $this->context());

        $this->assertSame(SubscriptionStatus::TRIALING, $result->status);
        $this->assertSame($trialEndsAt->timestamp, $result->trial_ends_at->timestamp);
    }

    public function test_rejects_trial_from_an_incompatible_state(): void
    {
        $subscription = $this->draft();
        $this->service->cancelImmediately($subscription, 'abandoned', $this->context());

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->startTrial($subscription, CarbonImmutable::now()->addDays(14), $this->context());
    }

    public function test_repeated_trial_start_does_not_duplicate_transition_history(): void
    {
        $subscription = $this->draft();
        $trialEndsAt = CarbonImmutable::now()->addDays(14);

        $this->service->startTrial($subscription, $trialEndsAt, $this->context());
        $countAfterFirst = \App\Models\ActivityLog::where('action', 'subscription.trial_started')->count();

        $this->service->startTrial($subscription, $trialEndsAt, $this->context());
        $countAfterSecond = \App\Models\ActivityLog::where('action', 'subscription.trial_started')->count();

        $this->assertSame(1, $countAfterFirst);
        $this->assertSame(1, $countAfterSecond);
    }

    // ─── Pending payment ─────────────────────────────────────────────────

    public function test_marks_draft_subscription_as_pending_payment(): void
    {
        $subscription = $this->draft();

        $result = $this->service->markPendingPayment($subscription, $this->context(), 'cs_fake_1');

        $this->assertSame(SubscriptionStatus::PENDING_PAYMENT, $result->status);
        $this->assertSame('cs_fake_1', $result->provider_checkout_session_id);
    }

    public function test_pending_payment_does_not_activate_access(): void
    {
        $subscription = $this->draft();
        $result = $this->service->markPendingPayment($subscription, $this->context());

        $this->assertNull($result->activated_at);
        $this->assertNotSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function test_duplicate_pending_payment_event_is_idempotent(): void
    {
        $subscription = $this->draft();

        $this->service->markPendingPayment($subscription, $this->context());
        $count1 = \App\Models\ActivityLog::where('action', 'subscription.payment_pending')->count();

        $this->service->markPendingPayment($subscription, $this->context());
        $count2 = \App\Models\ActivityLog::where('action', 'subscription.payment_pending')->count();

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    // ─── Incomplete (Subscription Event Hardening) ────────────────────────

    public function test_marks_pending_payment_subscription_incomplete(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $result = $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_1', 'livemode' => false], $this->context());

        $this->assertSame(SubscriptionStatus::INCOMPLETE, $result->status);
        $this->assertSame('sub_incomplete_1', $result->provider_subscription_id);
    }

    public function test_rejects_incomplete_from_an_incompatible_state(): void
    {
        $subscription = $this->draft();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_2', 'livemode' => false], $this->context());
    }

    public function test_incomplete_requires_a_provider_subscription_identifier(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->markIncomplete($subscription, ['id' => '', 'livemode' => false], $this->context());
    }

    public function test_incomplete_requires_matching_livemode(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_3', 'livemode' => true], $this->context());
    }

    public function test_duplicate_incomplete_event_is_idempotent(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_4', 'livemode' => false], $this->context());
        $count1 = \App\Models\ActivityLog::where('action', 'subscription.marked_incomplete')->count();

        $result = $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_4', 'livemode' => false], $this->context());
        $count2 = \App\Models\ActivityLog::where('action', 'subscription.marked_incomplete')->count();

        $this->assertSame(SubscriptionStatus::INCOMPLETE, $result->status);
        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    public function test_conflicting_incomplete_identity_is_rejected(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_5', 'livemode' => false], $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_DIFFERENT', 'livemode' => false], $this->context());
    }

    public function test_incomplete_subscription_can_activate(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_6', 'livemode' => false], $this->context());

        $result = $this->service->activate($subscription, $this->providerSubscriptionData(['id' => 'sub_incomplete_6']), $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function test_incomplete_subscription_can_expire(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_7', 'livemode' => false], $this->context());

        $result = $this->service->expire($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::EXPIRED, $result->status);
        $this->assertSame('sub_incomplete_7', $result->provider_subscription_id);
    }

    public function test_incomplete_preserves_provider_identity_through_to_expiry(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_8', 'livemode' => false], $this->context());
        $result = $this->service->expire($subscription, $this->context());

        $this->assertNotNull($result->provider_subscription_id);
        $this->assertSame('sub_incomplete_8', $result->provider_subscription_id);
    }

    public function test_stale_incomplete_event_is_rejected(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $newer = $this->context(['occurred_at' => CarbonImmutable::now()]);
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_9', 'livemode' => false], $newer);

        $older = $this->context(['occurred_at' => CarbonImmutable::now()->subHour()]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->markIncomplete($subscription, ['id' => 'sub_incomplete_9', 'livemode' => false], $older);
    }

    // ─── Activation ──────────────────────────────────────────────────────

    public function test_activates_only_from_an_allowed_state(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $result = $this->service->activate($subscription, $this->providerSubscriptionData(), $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertNotNull($result->activated_at);
        $this->assertNotNull($result->current_period_starts_at);
        $this->assertNotNull($result->current_period_ends_at);
    }

    public function test_activation_requires_normalized_provider_subscription_identity(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['id' => null]), $this->context());
    }

    public function test_activation_requires_matching_livemode(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['livemode' => true]), $this->context());
    }

    public function test_activation_requires_coherent_period_dates(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['current_period_start' => null]), $this->context());
    }

    public function test_duplicate_activation_is_safe(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $data = $this->providerSubscriptionData();

        $first = $this->service->activate($subscription, $data, $this->context());
        $second = $this->service->activate($subscription, $data, $this->context());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'subscription.activated')->count());
    }

    public function test_conflicting_activation_details_are_rejected(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());
        $this->service->activate($subscription, $this->providerSubscriptionData(['id' => 'sub_original']), $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['id' => 'sub_different']), $this->context());
    }

    public function test_checkout_controller_never_calls_activate_directly(): void
    {
        // Updated for Slice C2 (First Subscription Checkout): CheckoutController
        // now exists, but it only ever calls CheckoutSessionService::startCheckout(),
        // which itself only ever calls createDraftSubscription()/markPendingPayment() —
        // never activate(). A browser redirect back from Stripe Checkout carries
        // no normalized provider subscription data (real ID, real period dates),
        // so there is nothing a redirect-handling controller could pass to
        // activate() without a genuine, verified webhook first. Confirmed by
        // reading the controller's source rather than asserting non-existence.
        $source = file_get_contents(app_path('Http/Controllers/Api/CheckoutController.php'));
        $this->assertStringNotContainsString('->activate(', $source);
        $this->assertStringNotContainsString('lifecycleService', $source);
    }

    // ─── Payment problems ────────────────────────────────────────────────

    private function activeSubscription(): Subscription
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        return $this->service->activate($subscription, $this->providerSubscriptionData(), $this->context());
    }

    public function test_active_can_become_past_due(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->markPastDue($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::PAST_DUE, $result->status);
    }

    public function test_past_due_can_return_to_active(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->markPastDue($subscription, $this->context());

        $result = $this->service->restoreToActive($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function test_grace_period_dates_are_coherent(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->markPastDue($subscription, $this->context());
        $graceEndsAt = CarbonImmutable::now()->addDays(7);

        $result = $this->service->startGracePeriod($subscription, $graceEndsAt, $this->context());

        $this->assertSame(SubscriptionStatus::PAST_DUE, $result->status);
        $this->assertSame($graceEndsAt->timestamp, $result->grace_period_ends_at->timestamp);
    }

    public function test_grace_period_requires_past_due_state(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->startGracePeriod($subscription, CarbonImmutable::now()->addDays(7), $this->context());
    }

    public function test_stale_past_due_event_cannot_roll_back_a_newer_active_state(): void
    {
        $subscription = $this->activeSubscription();

        $newerContext = $this->context(['occurred_at' => CarbonImmutable::now()]);
        $this->service->markPastDue($subscription, $newerContext);
        $this->service->restoreToActive($subscription, $this->context(['occurred_at' => CarbonImmutable::now()->addSecond()]));

        $staleContext = $this->context(['occurred_at' => $newerContext->occurredAt->subMinute()]);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->markPastDue($subscription, $staleContext);
    }

    public function test_repeated_past_due_event_is_idempotent(): void
    {
        $subscription = $this->activeSubscription();

        $this->service->markPastDue($subscription, $this->context());
        $count1 = \App\Models\ActivityLog::where('action', 'subscription.past_due')->count();

        $this->service->markPastDue($subscription, $this->context());
        $count2 = \App\Models\ActivityLog::where('action', 'subscription.past_due')->count();

        $this->assertSame(1, $count1);
        $this->assertSame(1, $count2);
    }

    // ─── Suspension ──────────────────────────────────────────────────────

    public function test_suspension_scheduling_is_valid_only_from_allowed_states(): void
    {
        $subscription = $this->draft();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context());
    }

    public function test_suspended_transition_is_explicit_and_requires_a_reason(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->suspend($subscription, '', $this->context());
    }

    public function test_suspended_can_be_restored_through_an_approved_transition(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->suspend($subscription, 'compliance hold', $this->context());

        $result = $this->service->restoreToActive($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertNull($result->suspension_reason);
        $this->assertNull($result->suspended_at);
    }

    public function test_direct_arbitrary_status_mutation_is_unavailable(): void
    {
        $this->assertFalse(method_exists(SubscriptionLifecycleService::class, 'updateStatus'));
        $this->assertFalse(method_exists(SubscriptionLifecycleService::class, 'setStatus'));
    }

    public function test_scheduled_suspension_records_intent_without_changing_status(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->scheduleSuspension($subscription, 'repeated payment failures', $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertSame('repeated payment failures', $result->pending_suspension_reason);
        $this->assertNotNull($result->pending_suspension_effective_at);
    }

    // ─── Cancellation ────────────────────────────────────────────────────

    public function test_schedules_cancellation_at_period_end_without_marking_immediately_cancelled(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->scheduleCancellation($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertTrue($result->cancel_at_period_end);
    }

    public function test_immediate_cancellation_requires_explicit_reason(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->cancelImmediately($subscription, '', $this->context());
    }

    public function test_immediate_cancellation_with_reason_cancels_now(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->cancelImmediately($subscription, 'customer request', $this->context());

        $this->assertSame(SubscriptionStatus::CANCELLED, $result->status);
        $this->assertNotNull($result->cancelled_at);
        $this->assertNotNull($result->ended_at);
    }

    public function test_confirmation_of_scheduled_cancellation_is_idempotent(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleCancellation($subscription, $this->context());

        $first = $this->service->confirmCancellation($subscription, $this->context());
        $second = $this->service->confirmCancellation($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::CANCELLED, $first->status);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'subscription.cancelled')->count());
    }

    public function test_confirming_cancellation_without_a_schedule_conflicts(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->confirmCancellation($subscription, $this->context());
    }

    public function test_expiry_after_effective_cancellation_is_coherent(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->cancelImmediately($subscription, 'non-renewal', $this->context());

        $result = $this->service->expire($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::EXPIRED, $result->status);
        $this->assertNotNull($result->ended_at);
    }

    public function test_historical_commercial_data_remains_intact_after_cancellation(): void
    {
        $subscription = $this->activeSubscription();
        $planCode = $subscription->plan_code_snapshot;
        $unitAmount = $subscription->unit_amount;

        $this->service->cancelImmediately($subscription, 'closing account', $this->context());
        $subscription->refresh();

        $this->assertSame($planCode, $subscription->plan_code_snapshot);
        $this->assertSame($unitAmount, $subscription->unit_amount);
    }

    // ─── Invalid transitions ─────────────────────────────────────────────

    public function test_draft_cannot_jump_arbitrarily_to_suspended(): void
    {
        $subscription = $this->draft();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->suspend($subscription, 'no reason', $this->context());
    }

    public function test_cancelled_cannot_silently_return_to_active(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->cancelImmediately($subscription, 'done', $this->context());

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->restoreToActive($subscription, $this->context());
    }

    public function test_expired_cannot_be_modified_without_an_explicit_reactivation_path(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->cancelImmediately($subscription, 'done', $this->context());
        $this->service->expire($subscription, $this->context());

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->restoreToActive($subscription, $this->context());
    }

    public function test_provider_mode_mismatch_fails(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['livemode' => true]), $this->context());
    }

    public function test_provider_identity_mismatch_fails(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['id' => 'sub_someone_else']), $this->context());
    }

    public function test_missing_required_dates_fail(): void
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        $this->expectException(SubscriptionActivationException::class);
        $this->service->activate($subscription, $this->providerSubscriptionData(['current_period_end' => null]), $this->context());
    }

    public function test_stale_or_contradictory_events_fail(): void
    {
        $subscription = $this->activeSubscription();
        $now = CarbonImmutable::now();

        $this->service->markPastDue($subscription, $this->context(['occurred_at' => $now]));

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->markUnpaid($subscription, $this->context(['occurred_at' => $now->subMinute()]));
    }

    // ─── Concurrency ─────────────────────────────────────────────────────

    public function test_row_locking_prevents_conflicting_simultaneous_transitions(): void
    {
        // A true concurrent-process race can't be exercised in a single
        // synchronous test process, and the test database (sqlite) has no
        // row-level locking at all — SELECT ... FOR UPDATE is silently a
        // no-op on sqlite's query grammar (confirmed: it never appears in
        // the query log here, unlike on the real MySQL dev database this
        // service actually runs against). What CAN be verified in-process
        // is that every transition method routes through the same private
        // lock() helper, and that lock() actually calls lockForUpdate() —
        // asserted directly against the source rather than the emitted SQL,
        // since the SQL itself is platform-dependent.
        $source = file_get_contents(app_path('Services/Billing/SubscriptionLifecycleService.php'));

        $this->assertMatchesRegularExpression('/private function lock\(Subscription \$subscription\): Subscription\s*\{\s*return Subscription::query\(\)->whereKey\(\$subscription->id\)->lockForUpdate\(\)->firstOrFail\(\);/', $source);

        // And that every transition method acting on an EXISTING
        // subscription reaches that lock — either directly, or via the
        // shared transition() helper (which itself calls lock()).
        $reflection = new \ReflectionClass(SubscriptionLifecycleService::class);
        $publicTransitionMethods = collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->reject(fn (\ReflectionMethod $m) => $m->isConstructor() || in_array($m->getName(), [
                'createDraftSubscription',
                'hasConflictingSubscription',
                // G4B.2 — these also create a brand NEW row (nothing
                // existing to lockForUpdate() yet), protected instead by
                // the same per-organisation Cache::lock() pattern
                // createDraftSubscription() already uses — see
                // assignNonStripeSubscription()'s own docblock.
                'assignManualSubscription',
                'assignComplimentarySubscription',
            ], true))
            ->pluck('name');

        foreach ($publicTransitionMethods as $methodName) {
            $methodSource = $this->extractMethodSource($source, $methodName);
            $this->assertTrue(
                str_contains($methodSource, '$this->lock(')
                    || str_contains($methodSource, '$this->transition(')
                    || str_contains($methodSource, '$this->preparePlanChange(')
                    // G4B.2 — terminateManualOrComplimentarySubscription()
                    // delegates entirely to the existing cancelImmediately(),
                    // which itself routes through transition()/lock() — a
                    // real, safe delegation, not a new locking mechanism.
                    || str_contains($methodSource, '$this->cancelImmediately('),
                "{$methodName}() does not appear to lock the subscription row before mutating it."
            );
        }
    }

    private function extractMethodSource(string $classSource, string $methodName): string
    {
        $start = strpos($classSource, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate method {$methodName}() in source for structural inspection.");

        // Grab a generous window past the method signature — enough to
        // contain its body for a simple substring check, without needing a
        // full brace-matching parser for this structural sanity check.
        return substr($classSource, $start, 2000);
    }

    public function test_duplicate_provider_like_events_do_not_duplicate_activity_log_entries(): void
    {
        $subscription = $this->activeSubscription();

        $this->service->markPastDue($subscription, $this->context());
        $this->service->markPastDue($subscription, $this->context());
        $this->service->markPastDue($subscription, $this->context());

        $this->assertSame(1, \App\Models\ActivityLog::where('action', 'subscription.past_due')->count());
    }

    public function test_competing_state_changes_raise_a_domain_conflict_rather_than_silently_applying(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->suspend($subscription, 'fraud review', $this->context());

        // A "renewal" event arriving after a suspension is not a legal
        // transition from suspended directly to past_due.
        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->markPastDue($subscription, $this->context());
    }

    // ─── Plan changes (preparation only) ────────────────────────────────

    public function test_schedule_upgrade_records_pending_plan_without_touching_current_plan(): void
    {
        $subscription = $this->activeSubscription();
        $originalPlanId = $subscription->pricing_plan_id;

        $newPlan = $this->plan(['name' => 'Enterprise']);
        $newMapping = $this->mapping($newPlan);

        $result = $this->service->scheduleUpgrade($subscription, $newPlan, $newMapping, 'monthly', $this->context());

        $this->assertSame($originalPlanId, $result->pricing_plan_id);
        $this->assertSame($newPlan->id, $result->pending_pricing_plan_id);
        $this->assertNotNull($result->plan_change_effective_at);
    }

    public function test_schedule_downgrade_defaults_effective_date_to_current_period_end(): void
    {
        $subscription = $this->activeSubscription();
        $newPlan = $this->plan(['name' => 'Essential']);
        $newMapping = $this->mapping($newPlan);

        $result = $this->service->scheduleDowngrade($subscription, $newPlan, $newMapping, 'monthly', $this->context());

        $this->assertTrue($result->plan_change_effective_at->equalTo($subscription->current_period_ends_at));
        $this->assertSame($newPlan->id, $result->pending_pricing_plan_id);
    }

    public function test_plan_change_preparation_requires_active_subscription(): void
    {
        $subscription = $this->draft();
        $newPlan = $this->plan();
        $newMapping = $this->mapping($newPlan);

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->scheduleUpgrade($subscription, $newPlan, $newMapping, 'monthly', $this->context());
    }

    // ─── Provider reconciliation (narrow) ───────────────────────────────

    public function test_record_provider_state_syncs_period_dates_without_a_status_change(): void
    {
        $subscription = $this->activeSubscription();
        $newPeriodEnd = now()->addMonths(2)->timestamp;

        $result = $this->service->recordProviderState($subscription, $this->providerSubscriptionData([
            'status' => 'active',
            'current_period_end' => $newPeriodEnd,
        ]), $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertSame($newPeriodEnd, $result->current_period_ends_at->timestamp);
    }

    public function test_record_provider_state_throws_on_a_status_mismatch_rather_than_guessing(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->recordProviderState($subscription, $this->providerSubscriptionData(['status' => 'past_due']), $this->context());
    }

    // ─── Regression guard ────────────────────────────────────────────────

    public function test_transition_map_still_matches_what_this_service_relies_on(): void
    {
        $this->assertTrue(\App\Support\Billing\SubscriptionTransitions::canTransition(SubscriptionStatus::DRAFT, SubscriptionStatus::TRIALING));
        $this->assertTrue(\App\Support\Billing\SubscriptionTransitions::canTransition(SubscriptionStatus::ACTIVE, SubscriptionStatus::SUSPENDED));
        $this->assertFalse(\App\Support\Billing\SubscriptionTransitions::canTransition(SubscriptionStatus::EXPIRED, SubscriptionStatus::ACTIVE));
    }
}
