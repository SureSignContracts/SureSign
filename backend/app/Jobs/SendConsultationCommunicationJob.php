<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\AppointmentReminderSend;
use App\Services\Consultancy\ConsultationCommunicationService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * sends one Consultancy booking/Meet-lifecycle communication. Mirrors
 * SendAppointmentEmailJob/SendConsultationEmailJob's exact contract:
 * dispatched only ->afterCommit() by the caller, re-fetches by id rather
 * than risking stale serialized state, and a delivery failure here must
 * never surface as a failure of the triggering write (which already
 * committed). Runs on the existing `default` queue — deliberately NOT
 * `billing-webhooks`/`consultancy-payments`/`google-integrations`, per the
 * approved architecture (customer communications must never compete with
 * or be delayed by payment/Google work, nor delay them in turn).
 *
 * Idempotency is owned entirely by
 * App\Services\Consultancy\ConsultationCommunicationService's DB unique
 * constraint — this job may be safely retried or dispatched twice by a
 * racing caller with no duplicate-send risk.
 */
class SendConsultationCommunicationJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    /**
     * @param string $kind    'booking_confirmed' | 'meeting_link_ready' |
     *                        'booking_rescheduled' | 'booking_cancelled' |
     *                        'meeting_reminder' | 'consultation_followup' |
     *                        'summary_published'
     * @param array  $context 'meeting_reminder' only —
     *                        ['offset_minutes' => 1440, 'reminder_send_id' => 123],
     *                        mirroring SendAppointmentEmailJob's own
     *                        reminder context exactly. reminder_send_id lets
     *                        this job update the claimed AppointmentReminderSend
     *                        row's status once the send is attempted.
     */
    public function __construct(
        public readonly int $appointmentId,
        public readonly string $kind,
        public readonly array $context = [],
    ) {
    }

    public function handle(ConsultationCommunicationService $service): void
    {
        $appointment = Appointment::find($this->appointmentId);
        if (!$appointment) {
            return;
        }

        $sent = match ($this->kind) {
            'booking_confirmed'     => $service->sendBookingConfirmed($appointment),
            'meeting_link_ready'    => $service->sendMeetingLinkReady($appointment),
            'booking_rescheduled'   => $service->sendBookingRescheduled($appointment),
            'booking_cancelled'     => $service->sendBookingCancelled($appointment),
            'meeting_reminder'      => $service->sendMeetingReminder($appointment, $this->context['offset_minutes']),
            'consultation_followup' => $service->sendConsultationFollowUp($appointment),
            'summary_published'     => $service->sendSummaryPublished($appointment),
            default => false,
        };

        if ($this->kind === 'meeting_reminder') {
            $this->markReminderSend($sent ? 'sent' : 'failed', $sent ? null : 'EmailNotificationService reported delivery failure — see its own logs.');
        }

        if (!$sent) {
            Log::info("SendConsultationCommunicationJob: no email sent for appointment {$this->appointmentId} (kind={$this->kind}) — either already delivered (idempotency) or delivery failed (see EmailNotificationService logs).");
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->kind === 'meeting_reminder') {
            $this->markReminderSend('failed', $exception->getMessage());
        }

        Log::error("SendConsultationCommunicationJob failed for appointment {$this->appointmentId} (kind={$this->kind})", [
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Mirrors SendAppointmentEmailJob::markReminderSend() exactly — the row
     * was already claimed (inserted as 'pending') by SendAppointmentReminders
     * before dispatch, so this never creates a row, only updates one.
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
