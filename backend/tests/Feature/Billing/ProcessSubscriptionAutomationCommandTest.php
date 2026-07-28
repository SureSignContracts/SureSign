<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\Subscription;
use App\Support\Billing\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSubscriptionAutomationCommandTest extends TestCase
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
            'status' => SubscriptionStatus::PAST_DUE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 2999,
        ], $overrides));
    }

    public function test_dry_run_does_not_execute_any_transition(): void
    {
        $subscription = $this->subscription(['grace_period_ends_at' => CarbonImmutable::now()->subHour()]);

        $this->artisan('billing:subscriptions:process-automation', ['--dry-run' => true])
            ->assertExitCode(0);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PAST_DUE, $subscription->status);
    }

    public function test_executes_due_transitions_and_exits_successfully(): void
    {
        $subscription = $this->subscription(['grace_period_ends_at' => CarbonImmutable::now()->subHour()]);

        $this->artisan('billing:subscriptions:process-automation')
            ->assertExitCode(0);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::UNPAID, $subscription->status);
    }

    public function test_a_second_run_is_a_safe_no_op(): void
    {
        $subscription = $this->subscription(['grace_period_ends_at' => CarbonImmutable::now()->subHour()]);

        $this->artisan('billing:subscriptions:process-automation')->assertExitCode(0);
        $this->artisan('billing:subscriptions:process-automation')->assertExitCode(0);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::UNPAID, $subscription->status);
    }
}
