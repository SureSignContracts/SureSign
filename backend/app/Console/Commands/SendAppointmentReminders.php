<?php

namespace App\Console\Commands;

use App\Jobs\SendAppointmentEmailJob;
use App\Models\Appointment;
use App\Models\AppointmentReminderSend;
use App\Models\SuresignSetting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Appointments Phase 4 reminder dispatcher.
 *
 * Scheduled every 15 minutes (routes/console.php), but the due-check is
 * deliberately NOT tied to a fixed 15-minute wall-clock slice — a reminder
 * for offset O is simply "due" once `now() >= starts_at - O` and hasn't
 * already been claimed. That means a late-running tick (a slow deploy, a
 * missed cron minute, a temporary outage) just catches up on the next run
 * instead of silently skipping appointments that fell in the gap — there
 * is no window to "miss". The upper bound (`starts_at <= now() + O`,
 * algebraically the same inequality) combined with the lower bound
 * (`starts_at > now()`, i.e. the appointment hasn't happened yet) is what
 * keeps this from re-sending a wildly stale reminder after a long outage:
 * once an appointment's start time has passed, it simply stops being a
 * candidate for ANY offset, regardless of how late a reminder for it would
 * otherwise be.
 *
 * The database-level unique constraint on appointment_reminder_sends
 * (appointment_id, offset_minutes, schedule_version) — not this command's
 * own logic — is what makes it safe to run this command concurrently
 * (overlapping ticks, multiple workers) or repeatedly without ever
 * double-sending: claimReminderSend() below deliberately attempts the
 * INSERT first and only sends if that insert succeeds, mirroring
 * SendDeadlineReminders::claimReminderSend()'s exact pattern.
 *
 * Reminder emails are sent via the queued SendAppointmentEmailJob, not
 * synchronously in-process — unlike SendDeadlineReminders' direct-send
 * precedent. That precedent doesn't transfer cleanly here: once this
 * command's claimReminderSend() succeeds, the (appointment_id,
 * offset_minutes, schedule_version) unique constraint means that exact
 * reminder slot can never be claimed again — so a crash or thrown
 * exception between claim and send would previously strand that reminder
 * unsent forever, with no retry possible. Dispatching through
 * SendAppointmentEmailJob (tries=3, backoff=[30, 120]) gives a claimed-but-
 * not-yet-sent reminder genuine retry coverage, at the cost of the claimed
 * row sitting at 'pending' for a few extra seconds until the queue worker
 * picks it up — an acceptable tradeoff for a reminder, not a real-time
 * transaction. The job updates the row to 'sent'/'failed' itself (via the
 * reminder_send_id passed in context) once it has actually attempted
 * delivery.
 */
class SendAppointmentReminders extends Command
{
    protected $signature   = 'suresign:send-appointment-reminders';
    protected $description = 'Send due appointment reminder emails (24h/1h before by default, configurable)';

    private const ACTIVE_STATUSES = ['requested', 'pending_confirmation', 'confirmed'];

    public function handle(): int
    {
        $settings = SuresignSetting::instance();

        if (!$settings->appointment_reminders_enabled) {
            $this->info('Appointment reminders are disabled — nothing to do.');
            return self::SUCCESS;
        }

        $offsets = $settings->appointment_reminder_offsets_minutes ?: SuresignSetting::DEFAULT_APPOINTMENT_REMINDER_OFFSETS_MINUTES;
        $now = Carbon::now();
        $claimed = 0;

        foreach ($offsets as $offsetMinutes) {
            $offsetMinutes = (int) $offsetMinutes;
            if ($offsetMinutes <= 0) {
                continue;
            }

            Appointment::whereIn('status', self::ACTIVE_STATUSES)
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $now->copy()->addMinutes($offsetMinutes))
                ->whereNotNull('assigned_user_id') // reminders need a real recipient context; unassigned appointments have none to remind on behalf of yet
                ->chunkById(100, function ($appointments) use ($offsetMinutes, &$claimed) {
                    foreach ($appointments as $appointment) {
                        $send = $this->claimReminderSend($appointment, $offsetMinutes);
                        if (!$send) {
                            continue; // already claimed for this appointment/offset/schedule_version
                        }
                        $claimed++;

                        SendAppointmentEmailJob::dispatch($appointment->id, 'reminder', [
                            'offset_minutes'   => $offsetMinutes,
                            'reminder_send_id' => $send->id,
                        ])->afterCommit();
                    }
                });
        }

        $this->info("Reminders claimed and queued: {$claimed}.");

        return self::SUCCESS;
    }

    /**
     * Attempts to atomically claim this (appointment, offset, schedule_version)
     * reminder slot. Returns the created row on success, null if it was
     * already claimed (by this or a concurrent run) — the unique
     * constraint, not this check, is the actual guarantee.
     */
    private function claimReminderSend(Appointment $appointment, int $offsetMinutes): ?AppointmentReminderSend
    {
        try {
            return AppointmentReminderSend::create([
                'appointment_id'   => $appointment->id,
                'offset_minutes'   => $offsetMinutes,
                'schedule_version' => $appointment->schedule_version,
                'scheduled_for'    => $appointment->starts_at->copy()->subMinutes($offsetMinutes),
                'status'           => 'pending',
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }
}
