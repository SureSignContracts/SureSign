<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionEntitlementSnapshot;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Entitlements\PlanEntitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckSubscriptionEntitlementIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $overrides = []): Subscription
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);

        return Subscription::create(array_merge([
            'organization_id' => $org->id,
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

    public function test_default_run_is_non_destructive(): void
    {
        $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);

        $this->artisan('billing:subscriptions:check-integrity')->assertExitCode(0);

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }

    public function test_dry_run_with_repair_writes_nothing(): void
    {
        $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);

        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }

    public function test_repair_mode_creates_a_snapshot(): void
    {
        $subscription = $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true])
            ->assertExitCode(0);

        $this->assertSame(1, SubscriptionEntitlementSnapshot::where('subscription_id', $subscription->id)->count());
    }

    public function test_targeted_subscription_option_only_checks_one_record(): void
    {
        $target = $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::ESSENTIAL]);
        $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL]);

        $this->artisan('billing:subscriptions:check-integrity', ['--subscription' => $target->id, '--repair' => true])
            ->assertExitCode(0);

        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());
    }

    public function test_repeat_execution_is_safe(): void
    {
        $this->subscription([
            'starts_at' => CarbonImmutable::now(),
            'activated_at' => CarbonImmutable::now(),
            'plan_code_snapshot' => PlanEntitlements::ESSENTIAL,
        ]);

        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true])->assertExitCode(0);
        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true])->assertExitCode(0);

        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());
    }

    public function test_healthy_records_are_left_unchanged(): void
    {
        $subscription = $this->subscription(['starts_at' => '2026-01-01 00:00:00']);
        SubscriptionEntitlementSnapshot::create([
            'subscription_id' => $subscription->id,
            'organization_id' => $subscription->organization_id,
            'plan_code_snapshot' => PlanEntitlements::PROFESSIONAL,
            'entitlements_json' => [],
            'effective_from' => CarbonImmutable::now()->subMinute(),
            'lifecycle_reason' => 'activation',
            'source_transition' => 'subscription.activated',
        ]);

        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true])->assertExitCode(0);

        $this->assertSame(1, SubscriptionEntitlementSnapshot::count());
    }

    public function test_ambiguous_records_are_left_unchanged(): void
    {
        $this->subscription(['starts_at' => CarbonImmutable::now(), 'plan_code_snapshot' => null]);

        $this->artisan('billing:subscriptions:check-integrity', ['--repair' => true])->assertExitCode(0);

        $this->assertSame(0, SubscriptionEntitlementSnapshot::count());
    }
}
