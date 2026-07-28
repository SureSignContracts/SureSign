<?php

namespace Tests\Feature\Entitlements;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Services\Entitlements\FeatureGate;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\Feature;
use App\Support\Entitlements\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subscription Commercial State Automation checkpoint, Part 9 — FeatureGate
 * snapshot-resolution plumbing. Confirms the resolution order (current
 * snapshot first, live PlanEntitlements only as a documented compatibility
 * fallback when no snapshot exists) and the fail-safe rule for an
 * inconsistent snapshot (never falls back to live in that case).
 */
class FeatureGateSnapshotResolutionTest extends TestCase
{
    use RefreshDatabase;

    private FeatureGate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gate = $this->app->make(FeatureGate::class);
    }

    private function org(): Organization
    {
        return Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
    }

    private function subscription(Organization $organization, array $overrides = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $organization->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-TEST-' . random_int(1, 10000000),
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            // See FeatureGateTest's identical fixture note — predates the
            // snapshot-support boundary, representing the legacy
            // compatibility-fallback case deliberately.
            'starts_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }

    public function test_resolves_from_the_current_snapshot_when_one_exists_rather_than_recomputing_live(): void
    {
        $organization = $this->org();
        // Plan default for Professional is 25, but the snapshot deliberately
        // records a different frozen value — proves FeatureGate reads the
        // snapshot rather than recomputing PlanEntitlements::forPlanCode()
        // live, which would return 25 regardless.
        $subscription = $this->subscription($organization);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'pricing_plan_id' => null,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [
                Feature::MAX_ACTIVE_PROJECTS => ['value_type' => 'integer', 'value' => 999, 'is_unlimited' => false, 'unit' => 'projects', 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->subMinute(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        $limit = $this->gate->limit($organization, Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame(999, $limit->value);
    }

    public function test_falls_back_to_live_plan_entitlements_when_no_snapshot_exists(): void
    {
        $organization = $this->org();
        $this->subscription($organization);

        $limit = $this->gate->limit($organization, Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame(PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL)[Feature::MAX_ACTIVE_PROJECTS]->value, $limit->value);
    }

    public function test_a_snapshot_missing_the_requested_key_fails_safe_rather_than_falling_back_to_live(): void
    {
        $organization = $this->org();
        $subscription = $this->subscription($organization);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            // Deliberately does NOT include ADVANCED_REPORTING — simulates
            // an inconsistent/corrupt snapshot.
            'entitlements_json' => [
                Feature::MAX_ACTIVE_PROJECTS => ['value_type' => 'integer', 'value' => 25, 'is_unlimited' => false, 'unit' => 'projects', 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->subMinute(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        // Live PlanEntitlements for Professional WOULD grant this — proving
        // the false result below is the fail-safe path, not a coincidence.
        $this->assertTrue(PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL)[Feature::ADVANCED_REPORTING]->asBoolean());

        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_a_snapshot_not_yet_effective_is_ignored_in_favour_of_live_fallback(): void
    {
        $organization = $this->org();
        $subscription = $this->subscription($organization);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [
                Feature::MAX_ACTIVE_PROJECTS => ['value_type' => 'integer', 'value' => 999, 'is_unlimited' => false, 'unit' => 'projects', 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->addMonth(),
            'lifecycle_reason' => 'upgrade_applied',
            'source_transition' => 'subscription.plan_change_applied',
        ]);

        $limit = $this->gate->limit($organization, Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame(25, $limit->value);
    }

    public function test_the_most_recent_effective_snapshot_wins_when_more_than_one_exists(): void
    {
        $organization = $this->org();
        $subscription = $this->subscription($organization);

        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
            'entitlements_json' => [
                Feature::MAX_ACTIVE_PROJECTS => ['value_type' => 'integer', 'value' => 5, 'is_unlimited' => false, 'unit' => 'projects', 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->subMonth(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [
                Feature::MAX_ACTIVE_PROJECTS => ['value_type' => 'integer', 'value' => 25, 'is_unlimited' => false, 'unit' => 'projects', 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->subDay(),
            'lifecycle_reason' => 'upgrade_applied',
            'source_transition' => 'subscription.plan_change_applied',
        ]);

        $limit = $this->gate->limit($organization, Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame(25, $limit->value);
    }

    // ─── Snapshot Integrity & Commercial Automation Hardening checkpoint ──

    public function test_a_modern_subscription_missing_its_required_snapshot_fails_safe_instead_of_falling_back_live(): void
    {
        $organization = $this->org();
        // starts_at is "now" — after the snapshot-support boundary — with
        // no snapshot recorded: a genuine integrity problem, not a legacy
        // compatibility case.
        $this->subscription($organization, ['starts_at' => CarbonImmutable::now()]);

        // Live PlanEntitlements WOULD grant this — proving the false
        // result below is the fail-safe path.
        $this->assertTrue(PlanEntitlements::forPlanCode(PlanEntitlements::PROFESSIONAL)[Feature::ADVANCED_REPORTING]->asBoolean());

        $this->assertFalse($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_a_genuinely_legacy_subscription_still_uses_the_live_fallback(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['starts_at' => '2026-01-01 00:00:00']);

        $this->assertTrue($this->gate->allows($organization, Feature::ADVANCED_REPORTING));
    }

    public function test_explain_reports_snapshot_as_the_resolution_path_when_one_exists(): void
    {
        $organization = $this->org();
        $subscription = $this->subscription($organization, ['starts_at' => CarbonImmutable::now()]);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $organization->id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [
                Feature::ADVANCED_REPORTING => ['value_type' => 'boolean', 'value' => true, 'is_unlimited' => false, 'unit' => null, 'source' => 'plan_default'],
            ],
            'effective_from' => CarbonImmutable::now()->subMinute(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame('snapshot', $decision->resolutionPath);
    }

    public function test_explain_reports_legacy_live_plan_fallback_for_a_genuinely_legacy_subscription(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['starts_at' => '2026-01-01 00:00:00']);

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame('legacy_live_plan_fallback', $decision->resolutionPath);
    }

    public function test_explain_reports_missing_required_snapshot_for_a_modern_subscription_without_one(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['starts_at' => CarbonImmutable::now()]);

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame('missing_required_snapshot', $decision->resolutionPath);
    }

    public function test_explain_reports_not_entitled_by_access_mode_for_a_restricted_subscription(): void
    {
        $organization = $this->org();
        $this->subscription($organization, ['status' => SubscriptionStatus::SUSPENDED]);

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame('not_entitled_by_access_mode', $decision->resolutionPath);
    }

    public function test_explain_reports_no_subscription_when_none_exists(): void
    {
        $organization = $this->org();

        $decision = $this->gate->explain($organization, Feature::ADVANCED_REPORTING);

        $this->assertSame('no_subscription', $decision->resolutionPath);
    }
}
