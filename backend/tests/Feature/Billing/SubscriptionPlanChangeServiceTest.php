<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingPlanChange;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Models\User;
use App\Services\Billing\Exceptions\PlanChangeNotSupportedException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\SubscriptionPlanChangeService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\PlanChangeType;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLifecycleService $lifecycle;
    private SubscriptionPlanChangeService $planChanges;
    private FakeBillingProvider $fake;
    private Organization $org;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lifecycle = $this->app->make(SubscriptionLifecycleService::class);
        $this->planChanges = $this->app->make(SubscriptionPlanChangeService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

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

    private function plan(array $overrides = []): PricingPlan
    {
        return PricingPlan::create(array_merge([
            'code' => 'plan-' . random_int(1, 1000000),
            'slug' => 'plan-' . random_int(1, 1000000),
            'name' => 'Plan',
            'monthly_price' => 49.99,
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
            'unit_amount' => 4999,
            'is_active' => true,
            'livemode' => false,
        ], $overrides));
    }

    private function activeSubscription(array $overrides = []): Subscription
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);

        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
        $this->lifecycle->markPendingPayment($subscription, $this->context());

        $providerId = 'sub_fake_' . random_int(1, 1000000);

        $activated = $this->lifecycle->activate($subscription, [
            'id' => $providerId,
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ], $this->context());

        // activate() itself never calls the provider (it's fed a normalized
        // array, as if from a webhook) — seed the fake provider's own
        // record separately so send()/updateSubscriptionPrice() has
        // something to find.
        $this->fake->seedSubscription($providerId, [
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'price_id' => $mapping->provider_price_id,
        ]);

        if ($overrides) {
            $activated->update($overrides);
            $activated->refresh();
        }

        return $activated;
    }

    // ─── Eligibility ─────────────────────────────────────────────────────

    public function test_requesting_an_upgrade_requires_active_status(): void
    {
        $org2 = Organization::create(['name' => 'B', 'slug' => 'b-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $draft = $this->lifecycle->createDraftSubscription($org2, $plan, $mapping, 'monthly', $this->context());

        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $this->expectException(\App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException::class);
        $this->planChanges->requestUpgrade($draft, $target, $targetMapping, $this->context());
    }

    public function test_past_due_fails_safe_requiring_payment_recovery(): void
    {
        $subscription = $this->activeSubscription();
        $this->lifecycle->markPastDue($subscription, $this->context());
        $subscription->refresh();

        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
    }

    public function test_trialing_plan_changes_are_explicitly_deferred_not_guessed(): void
    {
        $plan = $this->plan();
        $mapping = $this->mapping($plan);
        $subscription = $this->lifecycle->createDraftSubscription($this->org, $plan, $mapping, 'monthly', $this->context());
        $trialing = $this->lifecycle->startTrial($subscription, CarbonImmutable::now()->addDays(14), $this->context());

        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $this->expectException(PlanChangeNotSupportedException::class);
        $this->planChanges->requestUpgrade($trialing, $target, $targetMapping, $this->context());
    }

    public function test_pending_cancellation_rejects_a_new_plan_change(): void
    {
        $subscription = $this->activeSubscription();
        $this->lifecycle->scheduleCancellation($subscription, $this->context());
        $subscription->refresh();

        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
    }

    // ─── Immediate upgrade ───────────────────────────────────────────────

    public function test_immediate_upgrade_creates_a_requested_plan_change_with_no_early_snapshot(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $this->assertSame(PlanChangeState::REQUESTED, $planChange->state);
        $this->assertSame(PlanChangeType::UPGRADE, $planChange->change_type);
        $this->assertNotNull($planChange->idempotency_key);
        // Activation itself already created one snapshot (checkpoint 14) —
        // requesting the plan change must not add an early second one.
        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());

        $subscription->refresh();
        $this->assertSame($target->id, $subscription->pending_pricing_plan_id);
    }

    public function test_send_calls_the_provider_and_marks_sent(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $sent = $this->planChanges->send($planChange);

        $this->assertSame(PlanChangeState::SENT, $sent->state);
        $this->assertNotNull($sent->sent_at);
        $this->assertSame(1, $sent->attempt_count);
        $this->assertSame($targetMapping->provider_price_id, $this->fake->subscriptions[$subscription->provider_subscription_id]['price_id']);
    }

    public function test_send_is_idempotent_and_never_double_calls_the_provider_for_the_same_key(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $first = $this->planChanges->send($planChange);
        $second = $this->planChanges->send($first);

        // Second call is a no-op (state already SENT) — attempt_count
        // never increments a second time.
        $this->assertSame(1, $second->attempt_count);
    }

    public function test_confirm_from_provider_applies_the_plan_and_creates_exactly_one_snapshot(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
        $this->planChanges->send($planChange);

        $confirmed = $this->planChanges->confirmFromProvider($planChange->fresh(), $this->context());

        $this->assertSame(PlanChangeState::APPLIED, $confirmed->state);
        $this->assertNotNull($confirmed->applied_at);

        $subscription->refresh();
        $this->assertSame($target->id, $subscription->pricing_plan_id);
        $this->assertNull($subscription->pending_pricing_plan_id);
        // Activation created one snapshot; confirming the upgrade creates
        // exactly one more — never a duplicate on top of that.
        $this->assertSame(2, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_confirm_from_provider_is_idempotent_on_duplicate_webhook_redelivery(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
        $this->planChanges->send($planChange->fresh());

        $this->planChanges->confirmFromProvider($planChange->fresh(), $this->context());
        $this->planChanges->confirmFromProvider($planChange->fresh(), $this->context());

        $this->assertSame(2, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    // ─── Downgrade ───────────────────────────────────────────────────────

    public function test_downgrade_is_always_scheduled_for_period_end_regardless_of_caller_context(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        // Caller passes an effective_at — must be ignored by policy.
        $contextWithEffectiveAt = $this->context(['effective_at' => CarbonImmutable::now()->addHour()]);

        $planChange = $this->planChanges->requestDowngrade($subscription, $target, $targetMapping, $contextWithEffectiveAt);

        $this->assertSame(PlanChangeType::DOWNGRADE, $planChange->change_type);
        $this->assertSame(
            $subscription->fresh()->current_period_ends_at->format('Y-m-d H:i:s'),
            $planChange->requested_effective_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_downgrade_does_not_reduce_access_before_the_effective_boundary(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);

        $this->planChanges->requestDowngrade($subscription, $target, $targetMapping, $this->context());

        $subscription->refresh();
        $this->assertSame($subscription->plan_code_snapshot, $subscription->plan_code_snapshot); // unchanged plan
        $this->assertNotSame($target->id, $subscription->pricing_plan_id);
    }

    // ─── Cancellation and replacement ────────────────────────────────────

    public function test_cancel_pending_clears_the_request_without_changing_plan(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $cancelled = $this->planChanges->cancelPending($subscription, $this->context());

        $this->assertSame($planChange->id, $cancelled->id);
        $this->assertSame(PlanChangeState::CANCELLED, $cancelled->state);

        $subscription->refresh();
        $this->assertNull($subscription->pending_pricing_plan_id);
    }

    /**
     * Stage 7 (Slice D) — once send() has run, updateSubscriptionPrice()
     * has already changed the price at Stripe itself (a direct synchronous
     * write, not a staged one), so there is no safe local-only
     * "cancellation" left — the confirming webhook is already on its way.
     */
    public function test_cancel_pending_after_send_is_rejected(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
        $this->planChanges->send($planChange);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->planChanges->cancelPending($subscription, $this->context());
    }

    public function test_cancel_pending_with_nothing_pending_is_a_safe_no_op(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->planChanges->cancelPending($subscription, $this->context());

        $this->assertNull($result);
    }

    public function test_a_second_request_without_supersede_conflicts(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $another = $this->plan();
        $anotherMapping = $this->mapping($another);

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->planChanges->requestUpgrade($subscription->fresh(), $another, $anotherMapping, $this->context());
    }

    public function test_replacing_a_pending_downgrade_with_an_upgrade_marks_the_old_one_superseded(): void
    {
        $subscription = $this->activeSubscription();
        $downgradeTarget = $this->plan();
        $downgradeMapping = $this->mapping($downgradeTarget);
        $original = $this->planChanges->requestDowngrade($subscription, $downgradeTarget, $downgradeMapping, $this->context());

        $upgradeTarget = $this->plan();
        $upgradeMapping = $this->mapping($upgradeTarget);
        $replacement = $this->planChanges->requestUpgrade($subscription->fresh(), $upgradeTarget, $upgradeMapping, $this->context(), supersede: true);

        $this->assertSame(PlanChangeState::SUPERSEDED, $original->fresh()->state);
        $this->assertNotNull($original->fresh()->superseded_at);
        $this->assertSame(PlanChangeState::REQUESTED, $replacement->state);
        $this->assertNotSame($original->id, $replacement->id);
    }

    public function test_an_applied_plan_change_cannot_be_cancelled_retroactively(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
        $this->planChanges->send($planChange->fresh());
        $this->planChanges->confirmFromProvider($planChange->fresh(), $this->context());

        // Nothing pending anymore — cancelPending() is a safe no-op, never
        // undoes the already-applied change.
        $result = $this->planChanges->cancelPending($subscription->fresh(), $this->context());

        $this->assertNull($result);
        $this->assertSame($target->id, $subscription->fresh()->pricing_plan_id);
    }

    // ─── Structural failure ──────────────────────────────────────────────

    public function test_unexpected_subscription_item_structure_marks_the_plan_change_terminally_failed(): void
    {
        $subscription = $this->activeSubscription();
        $this->fake->subscriptions[$subscription->provider_subscription_id]['item_count'] = 2;

        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());

        $result = $this->planChanges->send($planChange);

        $this->assertSame(PlanChangeState::FAILED, $result->state);
        $this->assertSame('unexpected_item_structure', $result->failure_code);
    }

    // ─── Audit trail ─────────────────────────────────────────────────────

    public function test_plan_change_lifecycle_events_are_audited(): void
    {
        $subscription = $this->activeSubscription();
        $target = $this->plan();
        $targetMapping = $this->mapping($target);
        $planChange = $this->planChanges->requestUpgrade($subscription, $target, $targetMapping, $this->context());
        $this->planChanges->send($planChange->fresh());
        $this->planChanges->confirmFromProvider($planChange->fresh(), $this->context());

        $this->assertGreaterThanOrEqual(1, ActivityLog::where('action', 'subscription.plan_change_scheduled')->count());
        $this->assertGreaterThanOrEqual(1, ActivityLog::where('action', 'subscription.plan_change_applied')->count());
    }
}
