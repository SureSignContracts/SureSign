<?php

namespace App\Jobs;

use App\Models\AppointmentExternalSync;
use App\Services\Calendar\AppointmentCalendarSyncService;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage 4B.1 — dispatched only via App\Services\Calendar\AppointmentCalendarSyncService::queueForAppointment(),
 * itself only called from
 * App\Services\Consultancy\ConsultancyPaymentConversionService::convert()'s
 * DB::afterCommit() (see that method's own docblock). Runs on the
 * dedicated `google-integrations` queue — never billing-webhooks,
 * consultancy-payments, or default.
 *
 * **Idempotent/retry-safe by construction**: every classified outcome
 * (success, retry_pending, failed, manual_review, disconnected, cancelled)
 * is persisted on the AppointmentExternalSync row by
 * AppointmentCalendarSyncService::attempt() itself, which then returns
 * normally — this job's own $tries/backoff are reserved for an
 * UNCLASSIFIED failure escaping that service (a genuine infrastructure
 * problem: a database error, an unexpected bug), never for an ordinary
 * classified provider failure. A classified failure never rethrows here.
 *
 * **Stale-job aware**: if the row's Appointment became ineligible
 * (Appointment::isEligibleForExternalSync() === false) before this job
 * ran, or if another worker already claimed/finished the row, the service
 * exits safely as a no-op — this job never assumes it is the only
 * dispatch for a given sync row.
 *
 * Stage 4B.2 (Google Meet Conference Generation) added no new job class —
 * this same job now also handles the synced-Calendar/pending-Meet
 * recheck case, routing to a different service method for it.
 */
class SyncAppointmentCalendarEventJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $timeout = 60;

    public function __construct(private readonly int $appointmentExternalSyncId)
    {
        $this->onQueue('google-integrations');
    }

    public function handle(AppointmentCalendarSyncService $syncService): void
    {
        $sync = AppointmentExternalSync::find($this->appointmentExternalSyncId);
        if (!$sync) {
            // The row itself is gone (should not happen — no code path
            // deletes an AppointmentExternalSync row) — exit safely rather
            // than throwing for something that can never be retried away.
            Log::warning('SyncAppointmentCalendarEventJob: AppointmentExternalSync row not found — exiting.', [
                'appointment_external_sync_id' => $this->appointmentExternalSyncId,
            ]);

            return;
        }

        // Stage 4B.2 — a synced-Calendar/pending-Meet row is not claimable
        // by attempt()/process() at all (SYNCED isn't an AUTO_CLAIMABLE
        // Calendar state) and needs a narrower, Meet-only recheck instead
        // — see AppointmentCalendarSyncService::refreshPendingMeet()'s own
        // docblock for why this never touches Calendar state.
        if ($sync->state === CalendarSyncState::SYNCED && $sync->meeting_state === MeetConferenceState::PENDING) {
            $syncService->refreshPendingMeet($sync);

            return;
        }

        $syncService->attempt($sync);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SyncAppointmentCalendarEventJob failed permanently after all attempts — an unclassified/infrastructure failure, not an ordinary provider error.', [
            'appointment_external_sync_id' => $this->appointmentExternalSyncId,
            'exception_class' => get_class($e),
        ]);
    }
}
