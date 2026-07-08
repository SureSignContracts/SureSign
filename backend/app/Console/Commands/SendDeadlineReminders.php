<?php

namespace App\Console\Commands;

use App\Models\PaymentApplication;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendDeadlineReminders extends Command
{
    protected $signature   = 'suresign:send-deadline-reminders';
    protected $description = 'Send email and in-app reminders for upcoming payment deadlines';

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
                    ->with('contract.project', 'createdBy', 'organization')
                    ->get();

                foreach ($apps as $app) {
                    $label      = $config['label'];
                    $daysText   = $daysAhead === 0 ? 'today' : "in {$daysAhead} day" . ($daysAhead > 1 ? 's' : '');
                    $appRef     = "PA #{$app->application_number}";
                    $contractTitle = $app->contract?->title ?? "Contract #{$app->contract_id}";

                    $emailSubject = "{$label} {$daysText} — {$appRef}";
                    $emailBody    = "{$label} for {$appRef} ({$contractTitle}) is due {$daysText} on {$targetDate}.";

                    // Email reminder
                    EmailNotificationService::send('deadline.reminder', $emailSubject, $emailBody, [], $app->organization);

                    // In-app notification for the PA creator (if resolved)
                    $user = $app->createdBy ?? null;
                    if ($user) {
                        NotificationService::send(
                            $user,
                            NotificationService::PAYMENT_DEADLINE_APPROACHING,
                            $emailSubject,
                            $emailBody,
                            [
                                'payment_application_id' => $app->id,
                                'contract_id'            => $app->contract_id,
                                'field'                  => $field,
                                'deadline_date'          => $targetDate,
                                'days_ahead'             => $daysAhead,
                            ]
                        );
                    }

                    $sent++;
                }
            }
        }

        $this->info("Deadline reminders sent: {$sent}.");

        return Command::SUCCESS;
    }
}
