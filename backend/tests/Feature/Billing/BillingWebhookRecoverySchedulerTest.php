<?php

namespace Tests\Feature\Billing;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Inspects the ACTUAL registered Illuminate\Console\Scheduling\Event for
 * `billing:webhooks:recover` — resolved from the real Schedule container
 * instance built from routes/console.php, never a source-string grep — so
 * this fails if the registration is ever accidentally removed, its cadence
 * changed without updating this test, or overlap protection dropped.
 */
class BillingWebhookRecoverySchedulerTest extends TestCase
{
    private function event()
    {
        $schedule = $this->app->make(Schedule::class);

        return collect($schedule->events())->first(
            fn ($event) => str_contains($event->command, 'billing:webhooks:recover')
        );
    }

    public function test_recovery_command_is_registered(): void
    {
        $this->assertNotNull($this->event(), 'billing:webhooks:recover is not registered in the scheduler.');
    }

    public function test_cadence_is_every_five_minutes(): void
    {
        $this->assertSame('*/5 * * * *', $this->event()->expression);
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
        // Matches every other scheduled command in this codebase (none use
        // onOneServer()) — this app's scheduler deployment is
        // single-instance; see routes/console.php's comment on this
        // command for why onOneServer() would be new, unproven
        // infrastructure rather than a correctness requirement.
        $this->assertFalse($this->event()->onOneServer);
    }
}
