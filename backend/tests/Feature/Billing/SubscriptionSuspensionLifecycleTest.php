<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription Suspension Completion checkpoint — covers the full
 * scheduleSuspension()/rescheduleSuspension()/cancelScheduledSuspension()/
 * suspend()/restoreToActive() lifecycle, including immediate vs. future
 * suspension, effective-date boundaries, and idempotency.
 */
class SubscriptionSuspensionLifecycleTest extends TestCase
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

    private function context(array $overrides = []): TransitionContext
    {
        return TransitionContext::make(array_merge([
            'source' => TransitionSource::SUPER_ADMIN,
            'actor_user_id' => $this->actor->id,
        ], $overrides));
    }

    private function draft(): Subscription
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

    private function activeSubscription(): Subscription
    {
        $subscription = $this->draft();
        $this->service->markPendingPayment($subscription, $this->context());

        return $this->service->activate($subscription, [
            'id' => 'sub_fake_' . random_int(1, 1000000),
            'status' => 'active',
            'customer_id' => 'cus_fake_1',
            'cancel_at_period_end' => false,
            'current_period_start' => now()->subDay()->timestamp,
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => null,
            'livemode' => false,
        ], $this->context());
    }

    // ─── scheduleSuspension() ────────────────────────────────────────────

    public function test_scheduling_a_suspension_does_not_change_status(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertSame('non-payment', $result->pending_suspension_reason);
        $this->assertNotNull($result->pending_suspension_effective_at);
    }

    public function test_immediate_suspension_request_defaults_effective_at_to_now(): void
    {
        $subscription = $this->activeSubscription();
        $before = CarbonImmutable::now()->subSecond();

        $result = $this->service->scheduleSuspension($subscription, 'non-payment', $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status, 'An immediate request is still only pending — never applied synchronously.');
        $this->assertTrue($result->pending_suspension_effective_at->greaterThanOrEqualTo($before));
    }

    public function test_cannot_schedule_a_suspension_from_a_state_that_cannot_reach_suspended(): void
    {
        $subscription = $this->draft();

        $this->expectException(InvalidSubscriptionTransitionException::class);
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context());
    }

    public function test_cannot_schedule_a_second_suspension_while_one_is_already_pending(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'first reason', $this->context());

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->scheduleSuspension($subscription, 'second reason', $this->context());
    }

    // ─── rescheduleSuspension() ──────────────────────────────────────────

    public function test_reschedules_a_pending_suspension_to_a_new_date(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $newDate = CarbonImmutable::now()->addDays(10);
        $result = $this->service->rescheduleSuspension($subscription, $newDate, $this->context());

        $this->assertSame($newDate->format('Y-m-d H:i:s'), $result->pending_suspension_effective_at->format('Y-m-d H:i:s'));
        $this->assertSame('non-payment', $result->pending_suspension_reason);
    }

    public function test_rescheduling_can_also_change_the_reason(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $result = $this->service->rescheduleSuspension($subscription, CarbonImmutable::now()->addDays(10), $this->context(), 'updated reason');

        $this->assertSame('updated reason', $result->pending_suspension_reason);
    }

    public function test_rescheduling_without_a_pending_suspension_conflicts(): void
    {
        $subscription = $this->activeSubscription();

        $this->expectException(SubscriptionLifecycleConflictException::class);
        $this->service->rescheduleSuspension($subscription, CarbonImmutable::now()->addDays(3), $this->context());
    }

    // ─── cancelScheduledSuspension() ─────────────────────────────────────

    public function test_cancels_a_pending_suspension_without_changing_status(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $result = $this->service->cancelScheduledSuspension($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertNull($result->pending_suspension_reason);
        $this->assertNull($result->pending_suspension_effective_at);
    }

    public function test_cancelling_with_nothing_pending_is_a_safe_no_op(): void
    {
        $subscription = $this->activeSubscription();

        $result = $this->service->cancelScheduledSuspension($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
    }

    public function test_repeated_cancellation_does_not_duplicate_audit_entries(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $this->service->cancelScheduledSuspension($subscription, $this->context());
        $this->service->cancelScheduledSuspension($subscription, $this->context());

        $this->assertSame(1, ActivityLog::where('action', 'subscription.suspension_cancelled')->count());
    }

    public function test_an_already_effective_suspension_cannot_be_cancelled_as_if_it_never_happened(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->suspend($subscription, 'non-payment', $this->context());

        // Nothing pending — cancelling is a no-op, and the subscription
        // remains genuinely suspended (not silently un-suspended).
        $result = $this->service->cancelScheduledSuspension($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::SUSPENDED, $result->status);
    }

    // ─── suspend() / immediate ───────────────────────────────────────────

    public function test_suspend_clears_any_pending_suspension_fields(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->scheduleSuspension($subscription, 'non-payment', $this->context(), CarbonImmutable::now()->addDays(3));

        $result = $this->service->suspend($subscription, 'non-payment', $this->context());

        $this->assertSame(SubscriptionStatus::SUSPENDED, $result->status);
        $this->assertNull($result->pending_suspension_reason);
        $this->assertNull($result->pending_suspension_effective_at);
    }

    // ─── resume (restoreToActive) ─────────────────────────────────────────

    public function test_resume_restores_to_active_and_clears_suspension_fields(): void
    {
        $subscription = $this->activeSubscription();
        $this->service->suspend($subscription, 'non-payment', $this->context());

        $result = $this->service->restoreToActive($subscription, $this->context());

        $this->assertSame(SubscriptionStatus::ACTIVE, $result->status);
        $this->assertNull($result->suspended_at);
        $this->assertNull($result->suspension_reason);
    }

    public function test_no_separate_resume_method_exists(): void
    {
        $this->assertFalse(method_exists(SubscriptionLifecycleService::class, 'resume'), 'restoreToActive() is the resume path — see its docblock.');
    }
}
