<?php

namespace Tests\Feature\Billing;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Mirrors BillingWebhookRecoverySchedulerTest's approach — inspects the
 * actual registered Illuminate\Console\Scheduling\Event rather than
 * grepping routes/console.php as a string.
 */
class SubscriptionAutomationSchedulerTest extends TestCase
{
    private function event()
    {
        $schedule = $this->app->make(Schedule::class);

        return collect($schedule->events())->first(
            fn ($event) => str_contains($event->command, 'billing:subscriptions:process-automation')
        );
    }

    public function test_automation_command_is_registered(): void
    {
        $this->assertNotNull($this->event(), 'billing:subscriptions:process-automation is not registered in the scheduler.');
    }

    public function test_cadence_is_hourly(): void
    {
        $this->assertSame('0 * * * *', $this->event()->expression);
    }

    public function test_overlap_protection_is_enabled(): void
    {
        $this->assertTrue($this->event()->withoutOverlapping);
    }

    public function test_runs_in_background(): void
    {
        $this->assertTrue($this->event()->runInBackground);
    }

    public function test_does_not_assume_a_multi_server_deployment(): void
    {
        $this->assertFalse($this->event()->onOneServer);
    }
}
