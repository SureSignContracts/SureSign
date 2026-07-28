<?php

namespace Tests\Feature\Entitlements;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Services\Entitlements\EntitlementSnapshotIntegrityService;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use App\Support\Entitlements\SnapshotIntegrityClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementSnapshotIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementSnapshotIntegrityService $integrity;
    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->integrity = $this->app->make(EntitlementSnapshotIntegrityService::class);
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

    public function test_check_without_repair_writes_nothing(): void
    {
        $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $result = $this->integrity->check(repair: false);

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
        $this->assertSame(1, $result['counters'][SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_RECOVERABLE]);
    }

    public function test_repair_creates_a_snapshot_for_a_recoverable_subscription(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $result = $this->integrity->check(repair: true);

        $this->assertSame(1, $result['counters']['repaired']);
        $snapshot = SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('activation', $snapshot->lifecycle_reason);
        $expected = PlanEntitlements::forPlanCode(PlanEntitlements::ESSENTIAL);
        $this->assertSame($expected[Feature::MAX_ACTIVE_PROJECTS]->value, $snapshot->entitlements_json[Feature::MAX_ACTIVE_PROJECTS]['value']);
    }

    public function test_repair_never_touches_an_ambiguous_subscription(): void
    {
        $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => null,
        ]);

        $result = $this->integrity->check(repair: true);

        $this->assertSame(0, $result['counters']['repaired']);
        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
        $this->assertSame(1, $result['counters'][SnapshotIntegrityClassification::EXPECTED_SNAPSHOT_MISSING_AMBIGUOUS]);
    }

    public function test_repair_is_idempotent_and_reuses_the_first_snapshot(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $first = $this->integrity->repair($subscription);
        $second = $this->integrity->repair($subscription);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());
    }

    public function test_repair_returns_null_and_writes_nothing_for_a_legacy_subscription(): void
    {
        $subscription = $this->subscription(['starts_at' => '2026-01-01 00:00:00']);

        $result = $this->integrity->repair($subscription);

        $this->assertNull($result);
        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }

    public function test_repair_records_an_activity_log_entry(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $this->integrity->repair($subscription);

        $this->assertSame(1, ActivityLog::where('action', 'subscription.entitlement_snapshot_repaired')->count());
    }

    public function test_check_can_target_a_single_subscription(): void
    {
        $target = $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);
        $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $result = $this->integrity->check(subscriptionId: $target->id);

        $this->assertSame(1, $result['counters']['scanned']);
        $this->assertSame($target->id, $result['records'][0]['subscription_id']);
    }
}
