<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Central authority for appointment status transitions, reassignment, and
 * rescheduling. Reschedule is deliberately NOT a status — it's an event
 * that updates starts_at/ends_at while leaving the current status alone
 * (approved architecture decision; see the Phase 1 gate report).
 */
class AppointmentWorkflowService
{
    /**
     * Allowed status transitions. Any pair not listed here is rejected.
     */
    public const TRANSITIONS = [
        'requested'             => ['pending_confirmation', 'confirmed', 'declined', 'cancelled'],
        'pending_confirmation'  => ['confirmed', 'declined', 'cancelled'],
        'confirmed'             => ['cancelled', 'completed', 'no_show'],
        'declined'              => [],
        'cancelled'             => [],
        'completed'             => [],
        'no_show'               => [],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws \InvalidArgumentException if the transition isn't allowed
     */
    public function transition(Appointment $appointment, string $toStatus, ?User $actor, array $meta = []): Appointment
    {
        $from = $appointment->status;

        if ($from === $toStatus) {
            throw new \InvalidArgumentException("Appointment is already {$toStatus}.");
        }

        if (!$this->canTransition($from, $toStatus)) {
            throw new \InvalidArgumentException("Cannot transition an appointment from {$from} to {$toStatus}.");
        }

        $updates = ['status' => $toStatus];
        if ($toStatus === 'cancelled') {
            $updates['cancelled_at'] = Carbon::now();
            if (isset($meta['reason'])) $updates['cancellation_reason'] = $meta['reason'];
        }
        if ($toStatus === 'declined' && isset($meta['reason'])) {
            $updates['cancellation_reason'] = $meta['reason'];
        }
        if ($toStatus === 'completed') {
            $updates['completed_at'] = Carbon::now();
            if (isset($meta['notes'])) $updates['completion_notes'] = $meta['notes'];
        }

        $appointment->update($updates);

        ActivityLog::record(
            "appointment.{$toStatus}",
            "Appointment {$appointment->reference} moved from {$from} to {$toStatus}.",
            $actor,
            $appointment,
            array_merge(['from' => $from, 'to' => $toStatus], $meta),
            $appointment->project_id,
            $appointment->organization_id,
        );

        return $appointment->refresh();
    }

    /**
     * Reschedule — an event, not a status change. Times are assumed
     * already conflict-checked by AppointmentSchedulingService.
     *
     * Also, in the same update (and therefore the same DB transaction the
     * caller is already running this inside):
     *   - schedule_version is bumped, which (a) makes this appointment's
     *     reminders "due again" against the new time — appointment_reminder_sends'
     *     unique key includes schedule_version, so the old version's send
     *     rows simply stop matching, with no row deletion needed — and
     *     (b) is reused directly as the ICS SEQUENCE number, so calendar
     *     clients treat a re-sent invite as an update rather than a
     *     duplicate.
     *   - public_token is rotated, so any cancel/reschedule links already
     *     sent in a previous email become invalid — the next confirmation
     *     email (sent by the caller after this returns) carries fresh
     *     links built from the new token.
     */
    public function reschedule(Appointment $appointment, Carbon $startsAt, Carbon $endsAt, string $timezone, ?User $actor, ?string $reason = null): Appointment
    {
        $previousStart = $appointment->starts_at;
        $previousEnd   = $appointment->ends_at;

        $appointment->update([
            'starts_at'        => $startsAt,
            'ends_at'          => $endsAt,
            'booking_timezone' => $timezone,
            'reschedule_reason' => $reason,
            'schedule_version'  => $appointment->schedule_version + 1,
            'public_token'      => Str::random(48),
        ]);

        ActivityLog::record(
            'appointment.rescheduled',
            "Appointment {$appointment->reference} rescheduled.",
            $actor,
            $appointment,
            [
                'previous_starts_at' => $previousStart?->toJSON(),
                'previous_ends_at'   => $previousEnd?->toJSON(),
                'starts_at'          => $startsAt->toJSON(),
                'ends_at'            => $endsAt->toJSON(),
                'timezone'           => $timezone,
                'reason'             => $reason,
            ],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return $appointment->refresh();
    }

    public function assign(Appointment $appointment, ?int $userId, ?User $actor): Appointment
    {
        $previous = $appointment->assigned_user_id;

        $appointment->update(['assigned_user_id' => $userId]);

        ActivityLog::record(
            'appointment.assigned',
            "Appointment {$appointment->reference} assignment changed.",
            $actor,
            $appointment,
            ['previous_assigned_user_id' => $previous, 'assigned_user_id' => $userId],
            $appointment->project_id,
            $appointment->organization_id,
        );

        return $appointment->refresh();
    }
}
