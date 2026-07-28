<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionAutomationService;
use App\Support\Billing\AutomationOutcome;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Subscription Commercial State Automation checkpoint (extended by the
 * Subscription Suspension Completion and Stripe Test Mode Integration
 * checkpoints) — the single scheduled processing command for every
 * automated commercial transition (grace period start/expiry, trial
 * expiry, scheduled cancellation, scheduled suspension, and now sending
 * due plan-change Price updates to the provider). All lifecycle logic
 * lives in `SubscriptionAutomationService`/`SubscriptionLifecycleService`/
 * `SubscriptionPlanChangeService`; this command is pure orchestration.
 *
 * Deliberately ONE command, not one per transition category (per this
 * checkpoint's brief: "avoid unnecessary scheduler fragmentation").
 */
class ProcessSubscriptionAutomation extends Command
{
    protected $signature = 'billing:subscriptions:process-automation
        {--limit=200 : Maximum subscriptions to consider PER CATEGORY per run}
        {--dry-run : Report what is due without executing any transition}';

    protected $description = 'Execute due grace-period, trial-expiry, scheduled-cancellation, scheduled-suspension, and plan-change-send transitions';

    public function handle(SubscriptionAutomationService $automation): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 200));
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            return $this->reportDryRun($automation);
        }

        $run = $automation->processDue($limit);

        foreach ($run['results'] as $result) {
            $this->line(sprintf(
                '[%s] subscription %d: %s%s',
                $result->category,
                $result->subscriptionId,
                $result->outcome,
                $result->newStatus ? " ({$result->previousStatus} -> {$result->newStatus})" : '',
            ));
        }

        $this->info(sprintf(
            'Discovered %d, transitioned %d, no longer applicable %d, conflicted %d, terminal failures %d.',
            $run['counters']['discovered'],
            $run['counters'][AutomationOutcome::TRANSITIONED],
            $run['counters'][AutomationOutcome::NO_LONGER_APPLICABLE],
            $run['counters'][AutomationOutcome::CONFLICTED],
            $run['counters'][AutomationOutcome::TERMINAL_FAILURE],
        ));

        $this->line("Scheduled suspensions still in the future: {$run['scheduled_suspensions_future']}.");
        $this->line("Plan changes scheduled but not yet due: {$run['plan_changes_pending_future']}.");

        Log::info('billing:subscriptions:process-automation completed', [
            'counters' => $run['counters'],
            'scheduled_suspensions_future' => $run['scheduled_suspensions_future'],
            'plan_changes_pending_future' => $run['plan_changes_pending_future'],
        ]);

        return $run['counters'][AutomationOutcome::TERMINAL_FAILURE] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function reportDryRun(SubscriptionAutomationService $automation): int
    {
        $this->info('Dry run — no transitions executed. Discovery counts only:');
        $this->line('  grace_start due: ' . Subscription::query()->where('status', SubscriptionStatus::PAST_DUE)->whereNull('grace_period_ends_at')->count());
        $this->line('  grace_expiry due: ' . Subscription::query()->where('status', SubscriptionStatus::PAST_DUE)->whereNotNull('grace_period_ends_at')->where('grace_period_ends_at', '<=', now())->count());
        $this->line('  trial_expiry due: ' . Subscription::query()->where('status', SubscriptionStatus::TRIALING)->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now())->count());
        $this->line('  scheduled_cancellation due: ' . Subscription::query()->where('status', SubscriptionStatus::ACTIVE)->where('cancel_at_period_end', true)->whereNotNull('current_period_ends_at')->where('current_period_ends_at', '<=', now())->count());
        $this->line('  scheduled_suspension due: ' . $automation->countScheduledSuspensions(due: true));
        $this->line('  scheduled_suspension future: ' . $automation->countScheduledSuspensions(due: false));
        $this->line('  plan_change_send due: ' . $automation->countPendingPlanChanges(due: true));
        $this->line('  plan_change_send future: ' . $automation->countPendingPlanChanges(due: false));

        return self::SUCCESS;
    }
}
