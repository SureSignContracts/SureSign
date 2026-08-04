<?php

namespace App\Console\Commands;

use App\Jobs\SyncAppointmentCalendarEventJob;
use App\Models\AppointmentExternalSync;
use App\Support\Google\CalendarSyncState;
use App\Support\Google\MeetConferenceState;
use Illuminate\Console\Command;

/**
 * Stage 4B.1 — dispatches App\Jobs\SyncAppointmentCalendarEventJob for
 * every AppointmentExternalSync row that needs recovery: due
 * `retry_pending` rows, `disconnected` rows (re-attempted every tick —
 * cheap, since App\Services\Calendar\AppointmentCalendarSyncService checks
 * readiness before ever calling Google), abandoned `processing` rows
 * (lease expired — see CalendarSyncState::PROCESSING_LEASE_MINUTES), and
 * any row with `outcome_uncertain = true` regardless of its current state
 * (a belt-and-braces guarantee that no uncertain row is ever silently
 * stranded, even if some future code path leaves one in an unexpected
 * state).
 *
 * Never dispatches for `synced`, `cancelled`, `failed`, or `manual_review`
 * (unless also outcome_uncertain) — those require an explicit Admin
 * action, never an automatic sweep (see AppointmentCalendarSyncService's
 * ADMIN_CLAIMABLE vs AUTO_CLAIMABLE state sets).
 *
 * Stage 4B.2 (Google Meet Conference Generation) added exactly ONE new
 * category — Calendar `synced` rows whose `meeting_state` is still
 * `pending` — dispatched at the SAME 5-minute cadence, never a separate
 * high-frequency Meet-polling loop (Google conference generation is
 * usually near-instant; this is a safety net, not a tight poll, and
 * respects Google's own API quotas exactly as the rest of this command
 * already does).
 *
 * Never creates a duplicate Calendar event itself — this command only
 * DISPATCHES jobs; AppointmentCalendarSyncService::attempt()'s own
 * row-locked claim and reconcile-before-create algorithm are the actual
 * correctness boundary, exactly mirroring
 * App\Console\Commands\RecoverBillingWebhookEvents's identical
 * "dispatch is not a mutation" reasoning. The pending-Meet category is
 * even simpler: App\Services\Calendar\AppointmentCalendarSyncService::refreshPendingMeet()
 * never creates anything at all, only re-reads and re-applies Google's
 * own conference status for an already-known event.
 */
class ReconcileAppointmentCalendarSyncs extends Command
{
    private const DEFAULT_LIMIT = 200;

    protected $signature = 'appointments:calendar-sync:reconcile
        {--limit=200 : Maximum number of rows to recover PER CATEGORY}
        {--dry-run : Report what would be dispatched without dispatching anything}';

    protected $description = 'Dispatch recovery jobs for due retry_pending, disconnected, abandoned-processing, outcome-uncertain, and pending-Meet Appointment Calendar sync rows';

    public function handle(): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: self::DEFAULT_LIMIT));
        $dryRun = (bool) $this->option('dry-run');

        $due = $this->recoverCategory('due retry_pending', $this->dueRetryQuery(), $limit, $dryRun);
        $disconnected = $this->recoverCategory('disconnected', $this->disconnectedQuery(), $limit, $dryRun);
        $abandoned = $this->recoverCategory('abandoned processing', $this->abandonedProcessingQuery(), $limit, $dryRun);
        $uncertain = $this->recoverCategory('outcome uncertain', $this->outcomeUncertainQuery(), $limit, $dryRun);
        $pendingMeet = $this->recoverCategory('Meet pending', $this->pendingMeetQuery(), $limit, $dryRun);

        $total = $due + $disconnected + $abandoned + $uncertain + $pendingMeet;

        $this->info(sprintf(
            '%s %d row(s): %d due retry_pending, %d disconnected, %d abandoned processing, %d outcome uncertain, %d Meet pending.',
            $dryRun ? 'Would recover' : 'Recovered',
            $total,
            $due,
            $disconnected,
            $abandoned,
            $uncertain,
            $pendingMeet,
        ));

        return self::SUCCESS;
    }

    private function dueRetryQuery()
    {
        return AppointmentExternalSync::where('state', CalendarSyncState::RETRY_PENDING)
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()));
    }

    private function disconnectedQuery()
    {
        return AppointmentExternalSync::where('state', CalendarSyncState::DISCONNECTED);
    }

    private function abandonedProcessingQuery()
    {
        return AppointmentExternalSync::where('state', CalendarSyncState::PROCESSING)
            ->where(fn ($q) => $q->whereNull('processing_started_at')
                ->orWhere('processing_started_at', '<', now()->subMinutes(CalendarSyncState::PROCESSING_LEASE_MINUTES)));
    }

    private function outcomeUncertainQuery()
    {
        return AppointmentExternalSync::where('outcome_uncertain', true)
            ->whereNotIn('state', [CalendarSyncState::SYNCED, CalendarSyncState::CANCELLED]);
    }

    private function pendingMeetQuery()
    {
        return AppointmentExternalSync::where('state', CalendarSyncState::SYNCED)
            ->where('meeting_state', MeetConferenceState::PENDING);
    }

    private function recoverCategory(string $label, $query, int $limit, bool $dryRun): int
    {
        $rows = $query->limit($limit)->get();

        if ($dryRun) {
            if ($rows->isNotEmpty()) {
                $this->line("Would dispatch for {$rows->count()} {$label} row(s): " . $rows->pluck('id')->implode(', '));
            }

            return $rows->count();
        }

        foreach ($rows as $row) {
            SyncAppointmentCalendarEventJob::dispatch($row->id)->onQueue('google-integrations');
        }

        return $rows->count();
    }
}
