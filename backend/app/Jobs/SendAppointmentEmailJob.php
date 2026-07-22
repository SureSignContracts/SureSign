<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentReminderSend;
use App\Services\AppointmentEmailService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends one attendee-facing appointment email. Always dispatched with
 * ->afterCommit() by the caller, so a queued email is never processed
 * before the triggering appointment mutation has actually committed
 * (and never dispatched at all if that transaction rolls back).
 *
 * $appointmentId (not the model) is passed deliberately — the job may run
 * anywhere from seconds to minutes after dispatch, so it re-fetches the
 * current row rather than risking stale serialized state.
 *
 * Failure here (a bad email address, Brevo being down, etc.) must never
 * surface as a failure of the appointment mutation itself — that mutation
 * already committed by the time this runs. EmailNotificationService
 * itself already swallows and logs delivery failures, so nothing here is
 * expected to throw in the ordinary case, but $tries/backoff are
 * still set defensively for genuine transient failures (e.g. Brevo
 * rate-limiting).
 */
class SendAppointmentEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries   = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    /**
     * @param string $kind 'created' | 'transition' | 'reschedule' | 'reminder'
     * @param array  $context kind-specific extra data, e.g. ['to_status' => 'confirmed'] or
     *                        ['offset_minutes' => 1440, 'reminder_send_id' => 123] for 'reminder' —
     *                        reminder_send_id lets this job update the claimed
     *                        AppointmentReminderSend row's status once the send is attempted,
     *                        rather than SendAppointmentReminders updating it synchronously.
     */
    public function __construct(
        public readonly int $appointmentId,
        public readonly string $kind,
        public readonly array $context = [],
    ) {
    }

    public function handle(AppointmentEmailService $emailService): void
    {
        $appointment = Appointment::find($this->appointmentId);
        if (!$appointment) {
            return;
        }

        $sent = match ($this->kind) {
            'created'    => $emailService->sendForCreation($appointment),
            'transition' => $emailService->sendForTransition($appointment, $this->context['to_status']),
            'reschedule' => $emailService->sendForReschedule($appointment),
            'reminder'   => $emailService->sendReminder($appointment, $this->context['offset_minutes']),
            default      => false,
        };

        if ($this->kind === 'reminder') {
            $this->markReminderSend($sent ? 'sent' : 'failed', $sent ? null : 'EmailNotificationService reported delivery failure — see its own logs.');
        }

        if (!$sent) {
            Log::info("SendAppointmentEmailJob: no email sent for appointment {$this->appointmentId} (kind={$this->kind}) — either not applicable for this status or delivery failed (see EmailNotificationService logs).");
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->kind === 'reminder') {
            $this->markReminderSend('failed', $exception->getMessage());
        }

        Log::error("SendAppointmentEmailJob failed for appointment {$this->appointmentId} (kind={$this->kind})", [
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Marks the AppointmentReminderSend row this job was dispatched for as
     * sent/failed. Only relevant for kind='reminder' — the row was already
     * claimed (inserted as 'pending') by SendAppointmentReminders before
     * dispatch, so this never creates a row, only updates one.
     */
    private function markReminderSend(string $status, ?string $failureMessage): void
    {
        $sendId = $this->context['reminder_send_id'] ?? null;
        if (!$sendId) {
            return;
        }

        AppointmentReminderSend::where('id', $sendId)->update([
            'status'          => $status,
            'sent_at'         => $status === 'sent' ? Carbon::now() : null,
            'failure_message' => $failureMessage,
        ]);
    }
}
