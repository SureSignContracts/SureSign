<?php

namespace App\Console\Commands;

use App\Models\DeadlineReminderRun;
use App\Models\DeadlineReminderSend;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Services\EmailNotificationService;
use App\Services\TimezoneResolver;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Batch 7 worldwide scheduler.
 *
 * Email-only (in-app deadline reminders for these same four fields are
 * generated separately — idempotently, org-wide, via NotificationEngineService
 * on the hourly calendar:sync schedule — see the class-level history this
 * class already carried since Batch 1/4).
 *
 * Architecture (see Batch 7 report for the full design rationale):
 *   - The Laravel scheduler invokes this command hourly, in UTC — the
 *     infrastructure layer stays UTC-only, exactly as every prior batch
 *     established. There is no per-organisation crontab entry and no
 *     rewriting of the schedule.
 *   - Each run is a lightweight DISPATCHER: it inspects every active
 *     organisation's own current local hour and only actually processes
 *     an organisation once its local hour has reached the configured
 *     reminder hour (`suresign.deadline_reminder_local_hour`, default 8),
 *     using `>=` rather than `===` — see processOrganization() for why.
 *   - A durable per-organisation, per-local-date checkpoint
 *     (DeadlineReminderRun) — not a cache-only marker — records whether
 *     that organisation's pass for that local day is done, survives
 *     restarts/multiple replicas, and lets an incomplete run resume
 *     rather than being silently marked done.
 *   - A durable, uniquely-constrained per-reminder record
 *     (DeadlineReminderSend) stops any individual email from ever being
 *     sent twice, independent of the organisation-level checkpoint.
 */
class SendDeadlineReminders extends Command
{
    private const COMMAND_KEY = 'send-deadline-reminders';

    protected $signature   = 'suresign:send-deadline-reminders';
    protected $description = 'Dispatch email reminders for upcoming payment deadlines, once per organisation per organisation-local day (in-app reminders are owned by NotificationEngineService)';

    public function handle(): int
    {
        $configuredHour = (int) config('suresign.deadline_reminder_local_hour', 8);
        $processed = 0;
        $skipped   = 0;

        Organization::where('is_active', true)
            ->chunkById(50, function ($organizations) use ($configuredHour, &$processed, &$skipped) {
                foreach ($organizations as $organization) {
                    if ($this->processOrganization($organization, $configuredHour)) {
                        $processed++;
                    } else {
                        $skipped++;
                    }
                }
            });

        $this->info("Organisations processed: {$processed}, skipped/not-yet-eligible: {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Returns true if this organisation was actually (re)processed this
     * tick, false if it was skipped (not yet in its local reminder window,
     * already completed for today, or lock contention with another
     * concurrent tick/replica).
     */
    private function processOrganization(Organization $organization, int $configuredHour): bool
    {
        $timezone = TimezoneResolver::effectiveTimezone(null, $organization);
        $localNow = TimezoneResolver::now(null, $organization);

        // Eligibility uses >=, not ===, deliberately: this is what makes a
        // late-running tick (Phase 3), a spring-forward local hour that
        // never occurred that day (Phase 12 — the first tick at or after
        // the configured hour catches it), and simply resuming a crashed
        // run all fall out of the SAME one rule, with no DST special-casing
        // needed. The per-local-date checkpoint below is what stops this
        // from reprocessing on every subsequent tick for the rest of the
        // day, and stops a fall-back repeated local hour from double-firing.
        if ($localNow->hour < $configuredHour) {
            return false;
        }

        $localDate = $localNow->toDateString();

        // Distributed lock (Phase 13): the `database` cache driver's
        // cache_locks table gives a real atomic lock across replicas/
        // workers — not just this-process safety — with zero new
        // infrastructure. Short TTL: this only needs to protect the brief
        // claim-or-resume decision below, not the whole reminder pass.
        $lock = Cache::lock("deadline-reminders:{$organization->id}:{$localDate}", 30);

        if (!$lock->get()) {
            Log::info('deadline-reminders: lock contention, deferring to a later tick', [
                'organization_id' => $organization->id,
            ]);
            return false;
        }

        try {
            // Looked up via whereDate(), not a plain `where('local_date', ...)`
            // exact-string match: Eloquent's `date` cast writes the column
            // using a full "Y-m-d H:i:s" formatted string on every INSERT
            // (it relies on the DB column's own DATE type to truncate it —
            // MySQL does this silently, sqlite does not, storing it
            // verbatim). A bare string match against that stored value is
            // therefore unreliable — whereDate() wraps the column in a
            // portable SQL DATE() comparison instead (the same technique
            // already used elsewhere in this codebase, e.g.
            // PaymentApplication::whereDate() below).
            $run = DeadlineReminderRun::where('organization_id', $organization->id)
                ->where('command_key', self::COMMAND_KEY)
                ->whereDate('local_date', $localDate)
                ->first();

            if (!$run) {
                // The lock above already makes this section exclusive
                // across replicas in the normal case; this catch is
                // defense-in-depth for the rare case the lock TTL is
                // exceeded (a very slow previous tick) — the unique
                // constraint on (organization_id, command_key, local_date)
                // is the actual guarantee, not this try/catch.
                try {
                    $run = DeadlineReminderRun::create([
                        'organization_id' => $organization->id, 'command_key' => self::COMMAND_KEY,
                        'local_date' => $localDate, 'timezone' => $timezone, 'started_at' => now(),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    $run = DeadlineReminderRun::where('organization_id', $organization->id)
                        ->where('command_key', self::COMMAND_KEY)
                        ->whereDate('local_date', $localDate)
                        ->firstOrFail();
                }
            }

            if ($run->isComplete()) {
                return false;
            }

            [$evaluated, $sent] = $this->sendRemindersForOrganization($organization, $localNow->toDate());

            // Only marked complete once the work has actually succeeded —
            // an exception below skips this update entirely, leaving the
            // run resumable on the next tick (Phase 4/17: never mark a run
            // complete before its work succeeds).
            //
            // Batch 7.2: increment() issues an atomic `SET col = col + ?`
            // rather than reading `$run->reminders_evaluated` in PHP and
            // writing back a computed sum — the latter would lose an
            // update under the (rare, lock-TTL-expiry) scenario where two
            // processes are genuinely concurrent for the same run (see the
            // Batch 7.2 report). This only protects the observability
            // counters, not correctness: individual reminder dedup is
            // already guaranteed by deadline_reminder_sends' own unique
            // constraint regardless of this.
            $run->increment('reminders_evaluated', $evaluated);
            $run->increment('emails_sent', $sent);
            $run->update(['completed_at' => now()]);

            Log::info('deadline-reminders: organisation processed', [
                'organization_id' => $organization->id,
                'timezone'        => $timezone,
                'local_date'      => $localDate,
                'reminders_evaluated' => $evaluated,
                'emails_sent'         => $sent,
            ]);

            return true;
        } catch (\Throwable $e) {
            // Deliberately never crashes the whole command — one
            // organisation's failure (e.g. a corrupt row, a transient DB
            // error) must not block every other organisation in the same
            // chunk (Phase 14).
            if (isset($run)) {
                $run->update([
                    'failed_at'       => now(),
                    'failure_message' => Str::limit($e->getMessage(), 250),
                ]);
            }
            Log::error('deadline-reminders: organisation processing failed', [
                'organization_id' => $organization->id,
                'error'           => $e->getMessage(),
            ]);
            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * Pure evaluation for one organisation against its own local today —
     * never touches global now()/today() internally. Returns
     * [remindersEvaluated, emailsSent].
     */
    private function sendRemindersForOrganization(Organization $organization, \DateTimeInterface $localToday): array
    {
        $localToday = \Carbon\Carbon::instance($localToday)->startOfDay();
        $excludedStatuses = ['cancelled', 'paid'];

        // [field => [label, days_ahead[]]]
        // pay_less_notice_deadline: 5, 2, 0 (same day)
        // due_date (payment due date):  5, 0 (same day)
        // payment_notice_deadline: 3
        // final_date_for_payment: 3
        $schedule = [
            'pay_less_notice_deadline' => ['label' => 'Pay Less Notice Deadline', 'days' => [5, 2, 0]],
            'due_date'                 => ['label' => 'Payment Due Date',        'days' => [5, 0]],
            'payment_notice_deadline'  => ['label' => 'Payment Notice Deadline', 'days' => [3]],
            'final_date_for_payment'   => ['label' => 'Final Date for Payment',  'days' => [3]],
        ];

        $evaluated = 0;
        $sent      = 0;

        foreach ($schedule as $field => $config) {
            foreach ($config['days'] as $daysAhead) {
                $targetDate = $localToday->copy()->addDays($daysAhead)->toDateString();

                $apps = PaymentApplication::where('organization_id', $organization->id)
                    ->whereDate($field, $targetDate)
                    ->whereNotIn('status', $excludedStatuses)
                    ->with('contract')
                    ->get();

                foreach ($apps as $app) {
                    $evaluated++;

                    if (!$this->claimReminderSend($organization, $app, $field, $daysAhead, $targetDate)) {
                        continue; // already sent for this exact (source, field, offset, deadline) — skip, not a resend
                    }

                    $label      = $config['label'];
                    $daysText   = $daysAhead === 0 ? 'today' : "in {$daysAhead} day" . ($daysAhead > 1 ? 's' : '');
                    $appRef     = "PA #{$app->application_number}";
                    $contractTitle = $app->contract?->title ?? "Contract #{$app->contract_id}";

                    // $targetDate is a DATE-only value throughout — never
                    // converted through any timezone, only compared against
                    // the organisation-local calendar date above.
                    $emailSubject = "{$label} {$daysText} — {$appRef}";
                    $emailBody    = "{$label} for {$appRef} ({$contractTitle}) is due {$daysText} on {$targetDate}.";

                    EmailNotificationService::send('deadline.reminder', $emailSubject, $emailBody, [], $organization);
                    $sent++;
                }
            }
        }

        return [$evaluated, $sent];
    }

    /**
     * Atomically claims this exact reminder identity — the database's own
     * unique constraint is what actually prevents a duplicate send under
     * retries/overlapping workers (Phase 13), not this method's own logic.
     * Returns false (do not send) if this exact reminder was already sent.
     */
    private function claimReminderSend(Organization $organization, PaymentApplication $app, string $field, int $daysAhead, string $targetDate): bool
    {
        try {
            DeadlineReminderSend::create([
                'organization_id'         => $organization->id,
                'source_type'             => 'payment_application',
                'source_id'               => $app->id,
                'reminder_field'          => $field,
                'reminder_offset_days'    => $daysAhead,
                'effective_deadline_date' => $targetDate,
            ]);
            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
