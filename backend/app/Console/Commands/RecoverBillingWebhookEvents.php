<?php

namespace App\Console\Commands;

use App\Models\BillingWebhookEvent;
use App\Services\Billing\WebhookEventProcessor;
use App\Services\Billing\WebhookEventRoutingService;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Discovers `billing_webhook_events` rows that need a processing job
 * (re)dispatched and dispatches one for each — never executes lifecycle
 * logic itself (see App\Services\Billing\WebhookEventProcessor, the only
 * place that happens). This command is the single, scheduled source of
 * truth for redispatching anything that ended up stranded — see
 * ProcessBillingWebhookEventJob's docblock for why WebhookIngestionService
 * deliberately never redispatches a duplicate/conflicting delivery itself,
 * and why this job's own queue-level retry never handles a domain-level
 * retryable failure.
 *
 * ─── What this command recovers ────────────────────────────────────────────
 *
 * 1. Stale `processing` rows — `processing_started_at` older than
 *    WebhookEventProcessor::PROCESSING_LEASE_MINUTES (15), or null. An
 *    ACTIVE processing lease (still within the window) is never touched —
 *    dispatching a job for it would be harmless (the processor's row lock
 *    would simply block until the real claimant finishes) but is
 *    deliberately skipped anyway to avoid needless queue churn.
 * 2. Retryable `failed` rows — `processing_status = failed AND
 *    retryable = true`. A non-retryable failure is NEVER touched — it
 *    requires manual investigation, exactly like a `conflict`.
 * 3. Stranded `received` rows older than RECEIVED_GRACE_MINUTES (2) —
 *    covers the rare case where WebhookIngestionService's own
 *    after-commit dispatch never reached the queue (e.g. a crash between
 *    commit and the queue push, or a queue outage). The grace period
 *    exists so a freshly-ingested event mid-flight through the NORMAL
 *    after-commit path is never redispatched while that's still simply in
 *    progress.
 *
 * Never touched: `processed`, `ignored`, `conflict`, non-retryable
 * `failed`, and an active `processing` lease — see
 * WebhookEventProcessor's claim matrix; this command's queries are a
 * strict subset of what that matrix already considers claimable/
 * reclaimable, so a dispatched job can never do anything unsafe even if
 * this command's own selection were somehow stale by the time the job
 * actually runs.
 *
 * ─── Concurrency ────────────────────────────────────────────────────────────
 *
 * No dispatch-tracking column was added (deliberately — see
 * internal-docs/super-admin/subscription-billing.md's "Recovery command
 * concurrency" section for the full reasoning): dispatching a job for a
 * row is NOT a mutation, and WebhookEventProcessor's own `SELECT ... FOR
 * UPDATE` claim is the actual correctness boundary regardless of how many
 * times a job is dispatched for the same row — a second dispatch for an
 * already-claimed-or-terminal row is always a safe, cheap no-op (proven
 * by WebhookEventProcessorTest's idempotency coverage). Two concurrent
 * runs of this command can therefore dispatch duplicate (harmless) jobs
 * for the same row in the worst case, but never duplicate a lifecycle
 * transition. Scheduler-level `withoutOverlapping()` (routes/console.php)
 * is the primary safeguard against that worst case even occurring, matching
 * this codebase's existing convention for every other scheduled command
 * (SendDeadlineReminders, SendAppointmentReminders — neither uses a
 * dispatch-tracking column either, relying on their own domain-level
 * idempotency instead, exactly as this command relies on
 * WebhookEventProcessor's).
 */
class RecoverBillingWebhookEvents extends Command
{
    private const RECEIVED_GRACE_MINUTES = 2;
    private const DEFAULT_LIMIT = 200;

    protected $signature = 'billing:webhooks:recover
        {--limit=200 : Maximum number of rows to recover PER CATEGORY}
        {--provider= : Only recover rows for this provider (e.g. stripe)}
        {--event-id= : Only consider this specific billing_webhook_events.id — for manual reprocessing}
        {--dry-run : Report what would be dispatched without dispatching anything}';

    protected $description = 'Dispatch processing jobs for stale-processing, retryable-failed, and stranded-received billing webhook events';

    public function handle(WebhookEventRoutingService $routingService): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: self::DEFAULT_LIMIT));
        $provider = $this->option('provider');
        $eventId = $this->option('event-id');
        $dryRun = (bool) $this->option('dry-run');

        if ($eventId !== null) {
            return $this->recoverSingleEvent((int) $eventId, $dryRun, $routingService);
        }

        $staleProcessing = $this->recoverCategory(
            'stale processing',
            $this->staleProcessingQuery($provider),
            $limit,
            $dryRun,
            $routingService,
        );

        $retryableFailed = $this->recoverCategory(
            'retryable failed',
            $this->retryableFailedQuery($provider),
            $limit,
            $dryRun,
            $routingService,
        );

        $strandedReceived = $this->recoverCategory(
            'stranded received',
            $this->strandedReceivedQuery($provider),
            $limit,
            $dryRun,
            $routingService,
        );

        $total = $staleProcessing + $retryableFailed + $strandedReceived;

        $this->info(sprintf(
            '%s %d event(s): %d stale processing, %d retryable failed, %d stranded received.',
            $dryRun ? 'Would recover' : 'Recovered',
            $total,
            $staleProcessing,
            $retryableFailed,
            $strandedReceived,
        ));

        return self::SUCCESS;
    }

    /**
     * Manual reprocessing path (Part 14) — targets exactly one ledger row
     * by ID. Deliberately narrow: never accepts raw payload, never resets
     * `processed`/`ignored` rows, never touches `conflict` (requires
     * deliberate investigation — no force option exists for it in this
     * checkpoint), never bypasses the processor's own claim matrix. This
     * only ever DISPATCHES a job for a row that already qualifies under
     * one of the three recovery categories above — it does not grant any
     * additional permission beyond what the scheduled sweep would
     * eventually do itself.
     */
    private function recoverSingleEvent(int $eventId, bool $dryRun, WebhookEventRoutingService $routingService): int
    {
        $event = BillingWebhookEvent::find($eventId);

        if ($event === null) {
            $this->error("No billing_webhook_events row with id {$eventId}.");

            return self::FAILURE;
        }

        if (!$this->isRecoverable($event)) {
            $this->warn(sprintf(
                'Event %d is not recoverable (processing_status=%s, retryable=%s) — conflicts and non-retryable failures require manual investigation, not reprocessing.',
                $event->id,
                $event->processing_status,
                var_export($event->retryable, true),
            ));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("Would dispatch a processing job for event {$eventId}.");

            return self::SUCCESS;
        }

        $jobClass = $routingService->jobClassFor($event);
        $jobClass::dispatch($event->id);
        $this->info("Dispatched a processing job for event {$eventId}.");

        return self::SUCCESS;
    }

    private function isRecoverable(BillingWebhookEvent $event): bool
    {
        if ($event->processing_status === WebhookProcessingStatus::RECEIVED) {
            return true;
        }

        if ($event->processing_status === WebhookProcessingStatus::PROCESSING) {
            return $event->processing_started_at === null
                || now()->diffInMinutes($event->processing_started_at, true) >= WebhookEventProcessor::PROCESSING_LEASE_MINUTES;
        }

        if ($event->processing_status === WebhookProcessingStatus::FAILED) {
            return $event->retryable === true;
        }

        return false;
    }

    private function recoverCategory(string $label, \Illuminate\Database\Eloquent\Builder $query, int $limit, bool $dryRun, WebhookEventRoutingService $routingService): int
    {
        $events = $query->limit($limit)->get();
        $ids = $events->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        if ($dryRun) {
            $this->line("[{$label}] would dispatch " . $ids->count() . ' event(s): ' . $ids->implode(', '));

            return $ids->count();
        }

        foreach ($events as $event) {
            $jobClass = $routingService->jobClassFor($event);
            $jobClass::dispatch($event->id);
        }

        Log::info("billing:webhooks:recover dispatched {$label} events", [
            'count' => $ids->count(),
            'billing_webhook_event_ids' => $ids->all(),
        ]);

        $this->line("[{$label}] dispatched " . $ids->count() . ' event(s).');

        return $ids->count();
    }

    private function staleProcessingQuery(?string $provider): \Illuminate\Database\Eloquent\Builder
    {
        return BillingWebhookEvent::query()
            ->where('processing_status', WebhookProcessingStatus::PROCESSING)
            ->where(function ($q) {
                $q->whereNull('processing_started_at')
                    ->orWhere('processing_started_at', '<=', now()->subMinutes(WebhookEventProcessor::PROCESSING_LEASE_MINUTES));
            })
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->oldest('id');
    }

    private function retryableFailedQuery(?string $provider): \Illuminate\Database\Eloquent\Builder
    {
        return BillingWebhookEvent::retryableFailed()
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->oldest('id');
    }

    private function strandedReceivedQuery(?string $provider): \Illuminate\Database\Eloquent\Builder
    {
        return BillingWebhookEvent::query()
            ->where('processing_status', WebhookProcessingStatus::RECEIVED)
            ->where('received_at', '<=', now()->subMinutes(self::RECEIVED_GRACE_MINUTES))
            ->when($provider, fn ($q) => $q->where('provider', $provider))
            ->oldest('id');
    }
}
