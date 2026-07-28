<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileStripeSubscriptionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_with_no_subscriptions(): void
    {
        $this->artisan('billing:stripe:reconcile')->assertExitCode(0);
    }

    public function test_command_is_not_registered_on_the_scheduler(): void
    {
        $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

        $event = collect($schedule->events())->first(
            fn ($event) => str_contains($event->command, 'billing:stripe:reconcile')
        );

        $this->assertNull($event, 'billing:stripe:reconcile is deliberately manual/on-demand, not scheduled.');
    }
}
