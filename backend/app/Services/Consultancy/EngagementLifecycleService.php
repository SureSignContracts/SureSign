<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\ConsultationEnquiry;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The single authority for `consultation_enquiries.engagement_status` — a
 * Consultancy-owned operational lifecycle, deliberately separate from
 * `appointments.status` (the Appointments-owned scheduling lifecycle). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md
 * §2 for the full state-machine rationale.
 *
 * This service assumes its caller has already authorized the action —
 * exactly like AppointmentWorkflowService::transition()'s own contract. It
 * validates state-transition legality only, never a user's permission to
 * request it.
 */
class EngagementLifecycleService
{
    public const STATUSES = ['awaiting_consultant', 'awaiting_customer', 'completed', 'cancelled'];

    /**
     * The only two values a manual transition may move between — completed
     * and cancelled each have their own dedicated method (markCompleted(),
     * reopen(), syncFromAppointmentCancellation()) with their own triggers,
     * deliberately not reachable through this generic path.
     */
    private const MANUAL_TRANSITIONS = [
        'awaiting_consultant' => ['awaiting_customer'],
        'awaiting_customer'   => ['awaiting_consultant'],
    ];

    /**
     * Derives the correct initial engagement_status for a consultation
     * whose Appointment already has a real status — used by both the
     * Batch 1 backfill migration and (implicitly, by definition) new rows
     * created after this phase ships. A pure function so the migration and
     * this service can never define the rule differently.
     */
    public static function deriveInitialStatusFromAppointmentStatus(string $appointmentStatus): string
    {
        return match ($appointmentStatus) {
            'cancelled' => 'cancelled',
            'completed' => 'completed',
            default     => 'awaiting_consultant',
        };
    }

    /**
     * Manual awaiting_consultant <-> awaiting_customer only. Rejects any
     * other requested target explicitly — a manual attempt to reach
     * 'completed' or 'cancelled' through this method is a programming error,
     * not a permission problem, so it throws rather than silently no-oping.
     *
     * @throws \InvalidArgumentException
     */
    public function transitionManual(ConsultationEnquiry $enquiry, string $to, User $actor): ConsultationEnquiry
    {
        $from = $enquiry->engagement_status;

        if ($from === $to) {
            throw new \InvalidArgumentException("Engagement is already {$to}.");
        }

        $allowed = self::MANUAL_TRANSITIONS[$from] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new \InvalidArgumentException("Cannot manually transition an engagement from {$from} to {$to}.");
        }

        $enquiry->update(['engagement_status' => $to]);

        ActivityLog::record(
            'consultation.engagement_status_changed',
            "Consultation engagement status changed from {$from} to {$to}.",
            $actor,
            $enquiry->appointment,
            ['from' => $from, 'to' => $to, 'trigger' => 'manual'],
            $enquiry->appointment->project_id,
            $enquiry->appointment->organization_id,
        );

        return $enquiry->refresh();
    }

    /**
     * The one path to 'completed' — called either by the summary-publish
     * action (Batch 4) or an explicit manual "mark completed" admin action
     * (also Batch 4). Valid from either non-terminal state; rejected if
     * already completed or cancelled.
     *
     * @throws \InvalidArgumentException
     */
    public function markCompleted(ConsultationEnquiry $enquiry, User $actor, bool $viaSummaryPublish): ConsultationEnquiry
    {
        $from = $enquiry->engagement_status;

        if (!in_array($from, ['awaiting_consultant', 'awaiting_customer'], true)) {
            throw new \InvalidArgumentException("Cannot mark an engagement completed from {$from}.");
        }

        $enquiry->update(['engagement_status' => 'completed']);

        ActivityLog::record(
            'consultation.engagement_status_changed',
            "Consultation engagement marked completed.",
            $actor,
            $enquiry->appointment,
            ['from' => $from, 'to' => 'completed', 'trigger' => $viaSummaryPublish ? 'summary_publish' : 'manual_complete'],
            $enquiry->appointment->project_id,
            $enquiry->appointment->organization_id,
        );

        return $enquiry->refresh();
    }

    /**
     * completed -> awaiting_consultant only. Authorization (Super Admin
     * only) is enforced by the calling controller, not here — see this
     * class's own docblock. Logged as its own distinct action, never folded
     * into the generic 'consultation.engagement_status_changed' entry, so
     * a reopen is always easy to find in the activity trail.
     *
     * @throws \InvalidArgumentException
     */
    public function reopen(ConsultationEnquiry $enquiry, User $actor): ConsultationEnquiry
    {
        if ($enquiry->engagement_status !== 'completed') {
            throw new \InvalidArgumentException('Only a completed engagement can be reopened.');
        }

        $enquiry->update(['engagement_status' => 'awaiting_consultant']);

        ActivityLog::record(
            'consultation.engagement_reopened',
            'Consultation engagement reopened.',
            $actor,
            $enquiry->appointment,
            [],
            $enquiry->appointment->project_id,
            $enquiry->appointment->organization_id,
        );

        return $enquiry->refresh();
    }

    /**
     * The only path to 'cancelled' — called exclusively by
     * ConsultancyAppointmentObserver, never directly by a controller. No
     * actor: this is a system-triggered sync, not a user action in its own
     * right (mirrors the existing convention that a public-link Appointment
     * cancellation is itself logged with a null actor).
     *
     * Idempotent: a no-op if already cancelled (covers a theoretical double
     * fire of the observer). Defensively logs (never throws) if called
     * while 'completed' — this should be unreachable in practice, since an
     * Appointment already 'completed' has no further transition to
     * 'cancelled' in AppointmentWorkflowService::TRANSITIONS, but a warning
     * is cheap insurance against a future change to that map going unnoticed
     * here.
     */
    public function syncFromAppointmentCancellation(ConsultationEnquiry $enquiry): void
    {
        $from = $enquiry->engagement_status;

        if ($from === 'cancelled') {
            return;
        }

        if ($from === 'completed') {
            Log::warning('Consultancy: appointment cancelled while engagement already completed.', [
                'consultation_enquiry_id' => $enquiry->id,
                'appointment_id'          => $enquiry->appointment_id,
            ]);
            return;
        }

        $enquiry->update(['engagement_status' => 'cancelled']);

        ActivityLog::record(
            'consultation.engagement_status_changed',
            'Consultation engagement cancelled (appointment cancelled).',
            null,
            $enquiry->appointment,
            ['from' => $from, 'to' => 'cancelled', 'trigger' => 'appointment_cancelled'],
            $enquiry->appointment->project_id,
            $enquiry->appointment->organization_id,
        );
    }
}
