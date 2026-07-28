<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Services\Entitlements\SnapshotIntegrityClassifier;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotIntegrityClassifierTest extends TestCase
{
    use RefreshDatabase;

    private SnapshotIntegrityClassifier $classifier;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = $this->app->make(SnapshotIntegrityClassifier::class);
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
        ], $overrides));
    }

    public function test_a_status_featuregate_never_consults_a_snapshot_for_is_not_applicable(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::CANCELLED]);

        $this->assertSame(SnapshotIntegrityClassification::NOT_APPLICABLE, $this->classifier->classify($subscription));
    }

    public function test_a_subscription_with_a_current_snapshot_is_present(): void
    {
        $subscription = $this->subscription();
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $this->org->id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [],
            'effective_from' => CarbonImmutable::now()->subMinute(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_PRESENT, $this->classifier->classify($subscription));
    }

    public function test_a_subscription_predating_the_boundary_is_legacy(): void
    {
        $subscription = $this->subscription(['starts_at' => '2026-01-01 00:00:00']);

        $this->assertSame(SnapshotIntegrityClassification::LEGACY_PRE_SNAPSHOT, $this->classifier->classify($subscription));
    }

    public function test_a_subscription_with_no_starts_at_is_ambiguous(): void
    {
        $subscription = $this->subscription(['starts_at' => null]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS, $this->classifier->classify($subscription));
    }

    public function test_a_modern_active_subscription_with_a_known_plan_is_recoverable(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE, $this->classifier->classify($subscription));
        $this->assertTrue($this->classifier->isRecoverable($subscription));
    }

    public function test_a_modern_active_subscription_with_an_unrecognised_plan_code_is_ambiguous(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => 'some_legacy_plan_code',
        ]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS, $this->classifier->classify($subscription));
        $this->assertFalse($this->classifier->isRecoverable($subscription));
    }

    public function test_a_modern_active_subscription_with_no_plan_code_at_all_is_ambiguous(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => null,
        ]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS, $this->classifier->classify($subscription));
    }

    public function test_a_modern_trialing_subscription_is_always_recoverable_given_a_starts_at(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::TRIALING,
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => null,
        ]);

        $this->assertSame(SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE, $this->classifier->classify($subscription));
    }

    public function test_recovery_plan_for_active_uses_activation_source_transition(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $plan = $this->classifier->recoveryPlan($subscription);

        $this->assertSame('activation', $plan['lifecycle_reason']);
        $this->assertSame('subscription.activated', $plan['source_transition']);
    }

    public function test_recovery_plan_for_trialing_uses_trial_start_source_transition(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::TRIALING,
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => null,
        ]);

        $plan = $this->classifier->recoveryPlan($subscription);

        $this->assertSame('trial_start', $plan['lifecycle_reason']);
        $this->assertSame('subscription.trial_started', $plan['source_transition']);
    }

    public function test_recovery_plan_is_null_when_not_recoverable(): void
    {
        $subscription = $this->subscription(['starts_at' => null]);

        $this->assertNull($this->classifier->recoveryPlan($subscription));
    }
}
