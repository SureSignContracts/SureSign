<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Services\Billing\SubscriptionAutomationService;
use App\Support\Billing\AutomationOutcome;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription Commercial State Automation checkpoint — covers every
 * automated path (grace start/expiry, trial expiry, scheduled
 * cancellation), idempotent re-runs (recovery), and the deliberately
 * blocked observability-only paths (scheduled suspension, scheduled plan
 * changes). Uses frozen time throughout — no Stripe dependency.
 */
class SubscriptionAutomationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionAutomationService $automation;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->automation = $this->app->make(SubscriptionAutomationService::class);
        $this->org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    private function subscription(array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $this->org->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 10000000),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'last_transition_occurred_at' => CarbonImmutable::now(),
        ], $overrides));
    }

    // ─── Grace period start ──────────────────────────────────────────────

    public function test_starts_a_grace_period_for_a_past_due_subscription_with_none_recorded(): void
    {
        $anchor = CarbonImmutable::now()->subDay();
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'last_transition_occurred_at' => $anchor,
        ]);

        $results = $this->automation->processGracePeriodStarts();

        $this->assertCount(1, $results);
        $this->assertSame(AutomationOutcome::TRANSITIONED, $results[0]->outcome);

        $subscription->refresh();
        $this->assertNotNull($subscription->grace_period_ends_at);
        $this->assertSame(
            $anchor->addDays((int) config('billing.grace_period_days'))->format('Y-m-d H:i:s'),
            $subscription->grace_period_ends_at->format('Y-m-d H:i:s'),
        );
    }

    public function test_grace_period_start_is_idempotent_on_repeat_runs(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'last_transition_occurred_at' => CarbonImmutable::now()->subDay(),
        ]);

        $this->automation->processGracePeriodStarts();
        $second = $this->automation->processGracePeriodStarts();

        // Already has grace_period_ends_at recorded now — no longer
        // discovered as "due to start" at all.
        $this->assertCount(0, $second);
    }

    public function test_does_not_start_a_grace_period_for_a_subscription_that_already_has_one(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->addDays(3),
        ]);

        $results = $this->automation->processGracePeriodStarts();

        $this->assertCount(0, $results);
    }

    // ─── Grace period expiry ─────────────────────────────────────────────

    public function test_expires_a_grace_period_that_has_passed_by_marking_unpaid(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);

        $results = $this->automation->processGracePeriodExpiries();

        $this->assertCount(1, $results);
        $this->assertSame(AutomationOutcome::TRANSITIONED, $results[0]->outcome);
        $this->assertSame(SubscriptionStatus::UNPAID, $results[0]->newStatus);
    }

    public function test_does_not_expire_a_grace_period_still_in_the_future(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->addDay(),
        ]);

        $results = $this->automation->processGracePeriodExpiries();

        $this->assertCount(0, $results);
    }

    public function test_grace_period_expiry_is_idempotent_on_repeat_runs(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processGracePeriodExpiries();
        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::UNPAID, $subscription->status);

        // Second run: no longer past_due, so no longer discovered.
        $second = $this->automation->processGracePeriodExpiries();
        $this->assertCount(0, $second);
    }

    // ─── Trial expiry ────────────────────────────────────────────────────

    public function test_expires_a_lapsed_trial(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::TRIALING,
            'trial_ends_at' => CarbonImmutable::now()->subDay(),
            'plan_code_snapshot' => null,
        ]);

        $results = $this->automation->processTrialExpiries();

        $this->assertCount(1, $results);
        $this->assertSame(SubscriptionStatus::EXPIRED, $results[0]->newStatus);
    }

    public function test_does_not_expire_a_trial_still_running(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::TRIALING,
            'trial_ends_at' => CarbonImmutable::now()->addDay(),
            'plan_code_snapshot' => null,
        ]);

        $results = $this->automation->processTrialExpiries();

        $this->assertCount(0, $results);
    }

    // ─── Scheduled cancellation ──────────────────────────────────────────

    public function test_confirms_a_cancellation_whose_period_end_has_passed(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::ACTIVE,
            'cancel_at_period_end' => true,
            'current_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);

        $results = $this->automation->processScheduledCancellations();

        $this->assertCount(1, $results);
        $this->assertSame(SubscriptionStatus::CANCELLED, $results[0]->newStatus);
    }

    public function test_does_not_confirm_a_cancellation_before_its_effective_date(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::ACTIVE,
            'cancel_at_period_end' => true,
            'current_period_ends_at' => CarbonImmutable::now()->addDay(),
        ]);

        $results = $this->automation->processScheduledCancellations();

        $this->assertCount(0, $results);
    }

    public function test_scheduled_cancellation_is_idempotent_on_repeat_runs(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::ACTIVE,
            'cancel_at_period_end' => true,
            'current_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processScheduledCancellations();
        $second = $this->automation->processScheduledCancellations();

        $this->assertCount(0, $second);
    }

    // ─── Scheduled suspension (Subscription Suspension Completion checkpoint) ─

    public function test_suspends_a_subscription_whose_pending_suspension_is_due(): void
    {
        $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $results = $this->automation->processScheduledSuspensions();

        $this->assertCount(1, $results);
        $this->assertSame(AutomationOutcome::TRANSITIONED, $results[0]->outcome);
        $this->assertSame(SubscriptionStatus::SUSPENDED, $results[0]->newStatus);
    }

    public function test_scheduled_suspension_carries_over_the_pending_reason(): void
    {
        $subscription = $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processScheduledSuspensions();

        $subscription->refresh();
        $this->assertSame('Persistent non-payment', $subscription->suspension_reason);
        $this->assertNull($subscription->pending_suspension_reason);
        $this->assertNull($subscription->pending_suspension_effective_at);
    }

    public function test_does_not_suspend_before_the_effective_date(): void
    {
        $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->addHour(),
        ]);

        $results = $this->automation->processScheduledSuspensions();

        $this->assertCount(0, $results);
    }

    public function test_scheduled_suspension_is_idempotent_on_repeat_runs(): void
    {
        $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processScheduledSuspensions();
        $second = $this->automation->processScheduledSuspensions();

        $this->assertCount(0, $second);
    }

    public function test_discards_a_pending_suspension_that_is_no_longer_applicable(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::CANCELLED,
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $results = $this->automation->processScheduledSuspensions();

        $this->assertCount(1, $results);
        $this->assertSame(AutomationOutcome::NO_LONGER_APPLICABLE, $results[0]->outcome);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::CANCELLED, $subscription->status);
        $this->assertNull($subscription->pending_suspension_reason);
        $this->assertNull($subscription->pending_suspension_effective_at);
    }

    public function test_scheduled_suspension_does_not_create_an_entitlement_snapshot(): void
    {
        $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processScheduledSuspensions();

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }

    public function test_counts_scheduled_suspensions_split_by_due_versus_future(): void
    {
        $this->subscription(['pending_suspension_effective_at' => CarbonImmutable::now()->subHour()]);
        $this->subscription(['pending_suspension_effective_at' => CarbonImmutable::now()->addDay()]);

        $this->assertSame(1, $this->automation->countScheduledSuspensions(due: true));
        $this->assertSame(1, $this->automation->countScheduledSuspensions(due: false));
    }

    // ─── Plan-change observability (send automation covered in SubscriptionPlanChangeServiceTest) ─

    public function test_counts_pending_plan_changes_split_by_due_versus_future(): void
    {
        $dueSubscription = $this->subscription();
        $futureSubscription = $this->subscription();

        \App\Models\BillingPlanChange::create($this->planChangeAttributes($dueSubscription, [
            'requested_effective_at' => CarbonImmutable::now()->subHour(),
        ]));
        \App\Models\BillingPlanChange::create($this->planChangeAttributes($futureSubscription, [
            'requested_effective_at' => CarbonImmutable::now()->addDay(),
        ]));

        $this->assertSame(1, $this->automation->countPendingPlanChanges(due: true));
        $this->assertSame(1, $this->automation->countPendingPlanChanges(due: false));
    }

    private function planChangeAttributes(Subscription $subscription, array $overrides = []): array
    {
        $plan = \App\Models\PricingPlan::create([
            'code' => 'plan-' . random_int(1, 1000000),
            'slug' => 'plan-' . random_int(1, 1000000),
            'name' => 'Plan',
            'monthly_price' => 49.99,
            'currency' => 'GBP',
        ]);

        $mapping = \App\Models\PricingPlanProviderPrice::create([
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'provider_price_id' => 'price_fake_' . random_int(1, 1000000),
            'unit_amount' => 4999,
            'is_active' => true,
            'livemode' => false,
        ]);

        return array_merge([
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'target_pricing_plan_id' => $plan->id,
            'target_price_mapping_id' => $mapping->id,
            'change_type' => \App\Support\Billing\PlanChangeType::UPGRADE,
            'policy' => \App\Support\Billing\PlanChangePolicy::IMMEDIATE,
            'livemode' => false,
            'state' => \App\Support\Billing\PlanChangeState::REQUESTED,
            'requested_effective_at' => CarbonImmutable::now(),
            'requested_at' => CarbonImmutable::now(),
        ], $overrides);
    }

    // ─── Aggregate run ────────────────────────────────────────────────────

    public function test_process_due_runs_every_automated_category_and_tallies_counters(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'last_transition_occurred_at' => CarbonImmutable::now()->subDay(),
        ]);
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);
        $this->subscription([
            'status' => SubscriptionStatus::TRIALING,
            'trial_ends_at' => CarbonImmutable::now()->subDay(),
            'plan_code_snapshot' => null,
        ]);
        $this->subscription([
            'status' => SubscriptionStatus::ACTIVE,
            'cancel_at_period_end' => true,
            'current_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);
        $this->subscription([
            'pending_suspension_reason' => 'Persistent non-payment',
            'pending_suspension_effective_at' => CarbonImmutable::now()->subHour(),
        ]);

        $run = $this->automation->processDue();

        $this->assertSame(5, $run['counters']['discovered']);
        $this->assertSame(5, $run['counters'][AutomationOutcome::TRANSITIONED]);
        $this->assertSame(0, $run['counters'][AutomationOutcome::CONFLICTED]);
        $this->assertSame(0, $run['counters'][AutomationOutcome::TERMINAL_FAILURE]);
        $this->assertArrayHasKey('scheduled_suspensions_future', $run);
        $this->assertArrayHasKey('plan_changes_pending_future', $run);
    }

    // ─── No snapshot for grace/trial-expiry/cancellation transitions ────

    public function test_grace_expiry_trial_expiry_and_cancellation_do_not_create_entitlement_snapshots(): void
    {
        $this->subscription([
            'status' => SubscriptionStatus::PAST_DUE,
            'grace_period_ends_at' => CarbonImmutable::now()->subHour(),
        ]);

        $this->automation->processGracePeriodExpiries();

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }
}
