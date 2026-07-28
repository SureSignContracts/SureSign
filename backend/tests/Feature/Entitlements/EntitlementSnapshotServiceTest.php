<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Services\Entitlements\EntitlementSnapshotService;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EntitlementSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementSnapshotService $snapshots;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshots = $this->app->make(EntitlementSnapshotService::class);
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

    public function test_creates_a_snapshot_capturing_the_plans_full_entitlement_set(): void
    {
        $subscription = $this->subscription();
        $effectiveFrom = CarbonImmutable::now();

        $snapshot = $this->snapshots->snapshotForActivation($subscription, $effectiveFrom);

        $this->assertSame($subscription->id, $snapshot->subscription_id);
        $this->assertSame($this->org->id, $snapshot->organization_id);
        $this->assertSame(PlanEntitlements::PROFESSIONAL, $snapshot->plan_code_snapshot);
        $this->assertSame('activation', $snapshot->lifecycle_reason);
        $this->assertSame('subscription.activated', $snapshot->source_transition);
        $this->assertSame($effectiveFrom->format('Y-m-d H:i:s'), $snapshot->effective_from->format('Y-m-d H:i:s'));

        $expected = PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL);
        $this->assertSame($expected[Feature::MAX_ACTIVE_PROJECTS]->value, $snapshot->entitlements_json[Feature::MAX_ACTIVE_PROJECTS]['value']);
        $this->assertSame($expected[Feature::ADVANCED_REPORTING]->value, $snapshot->entitlements_json[Feature::ADVANCED_REPORTING]['value']);
    }

    public function test_trial_start_snapshot_uses_the_dedicated_trial_profile_not_the_plan_default(): void
    {
        $subscription = $this->subscription(['plan_code_snapshot' => null]);

        $snapshot = $this->snapshots->snapshotForTrialStart($subscription, CarbonImmutable::now());

        $trialProfile = PlanEntitlements::trialProfile();
        $this->assertSame($trialProfile[Feature::AI_ANALYSES_PER_MONTH]->value, $snapshot->entitlements_json[Feature::AI_ANALYSES_PER_MONTH]['value']);
        $this->assertSame('trial_start', $snapshot->lifecycle_reason);
    }

    public function test_repeated_calls_for_the_same_event_reuse_the_existing_snapshot(): void
    {
        $subscription = $this->subscription();
        $effectiveFrom = CarbonImmutable::now();

        $first = $this->snapshots->snapshotForActivation($subscription, $effectiveFrom);
        $second = $this->snapshots->snapshotForActivation($subscription, $effectiveFrom);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());
    }

    public function test_a_different_effective_date_creates_a_new_snapshot_not_a_duplicate(): void
    {
        $subscription = $this->subscription();

        $this->snapshots->snapshotForActivation($subscription, CarbonImmutable::now());
        $this->snapshots->snapshotForUpgrade($subscription, CarbonImmutable::now()->addMonth());

        $this->assertSame(2, SubscriptionEntitlementSnapshot::count());
    }

    public function test_snapshots_are_immutable(): void
    {
        $subscription = $this->subscription();
        $snapshot = $this->snapshots->snapshotForActivation($subscription, CarbonImmutable::now());

        $this->expectException(LogicException::class);
        $snapshot->lifecycle_reason = 'tampered';
        $snapshot->save();
    }
}
