<?php

namespace App\Console\Commands;

use App\Models\PaymentApplication;
use App\Services\EmailNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Email-only. In-app deadline reminders for these same four fields
 * (payment_notice_deadline, pay_less_notice_deadline, due_date,
 * final_date_for_payment) are already generated — idempotently, with
 * org-wide fan-out, priority escalation, and auto-resolution — by
 * NotificationEngineService via the hourly calendar:sync schedule. This
 * command used to also call NotificationService::send() with no
 * source_type/source_id, so it had no idempotency and created a fresh
 * duplicate in-app notification (to the PA creator only) every day a
 * deadline was approaching. That call has been removed; this command's
 * only remaining, non-duplicated responsibility is the 'deadline.reminder'
 * email, which NotificationEngineService does not send.
 */
class SendDeadlineReminders extends Command
{
    protected $signature   = 'suresign:send-deadline-reminders';
    protected $description = 'Send email reminders for upcoming payment deadlines (in-app reminders are owned by NotificationEngineService)';

    public function handle(): int
    {
        $today            = Carbon::today();
        $excludedStatuses = ['cancelled', 'paid'];

        // [field => [label, days_ahead[]]]
        // pay_less_notice_deadline: 5, 2, 0 (same day)
        // due_date (payment due date):  5, 0 (same day)
        // payment_notice_deadline: 3
        // final_date_for_payment: 3
        $schedule = [
            'pay_less_notice_deadline' => [
                'label'    => 'Pay Less Notice Deadline',
                'days'     => [5, 2, 0],
            ],
            'due_date' => [
                'label'    => 'Payment Due Date',
                'days'     => [5, 0],
            ],
            'payment_notice_deadline' => [
                'label'    => 'Payment Notice Deadline',
                'days'     => [3],
            ],
            'final_date_for_payment' => [
                'label'    => 'Final Date for Payment',
                'days'     => [3],
            ],
        ];

        $sent = 0;

        foreach ($schedule as $field => $config) {
            foreach ($config['days'] as $daysAhead) {
                $targetDate = $today->copy()->addDays($daysAhead)->toDateString();

                $apps = PaymentApplication::whereDate($field, $targetDate)
                    ->whereNotIn('status', $excludedStatuses)
                    ->with('contract.project', 'organization')
                    ->get();

                foreach ($apps as $app) {
                    $label      = $config['label'];
                    $daysText   = $daysAhead === 0 ? 'today' : "in {$daysAhead} day" . ($daysAhead > 1 ? 's' : '');
                    $appRef     = "PA #{$app->application_number}";
                    $contractTitle = $app->contract?->title ?? "Contract #{$app->contract_id}";

                    $emailSubject = "{$label} {$daysText} — {$appRef}";
                    $emailBody    = "{$label} for {$appRef} ({$contractTitle}) is due {$daysText} on {$targetDate}.";

                    // Email reminder — in-app is handled separately by NotificationEngineService.
                    EmailNotificationService::send('deadline.reminder', $emailSubject, $emailBody, [], $app->organization);

                    $sent++;
                }
            }
        }

        $this->info("Deadline reminders sent: {$sent}.");

        return Command::SUCCESS;
    }
}
