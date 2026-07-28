<?php

namespace App\Jobs;

use App\Services\Billing\WebhookEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pure orchestration — invokes WebhookEventProcessor::process() and nothing
 * else. Contains no normalization, correlation, lifecycle, or Checkout
 * Session logic; every domain decision belongs to WebhookEventProcessor (and
 * the lifecycle services it calls), never here. See
 * internal-docs/super-admin/subscription-billing.md's ownership table.
 *
 * Accepts only the persisted ledger row's ID — never the raw webhook body,
 * a Stripe object, or a normalized event array — the ledger row (already
 * verified by WebhookIngestionService) is the sole source of truth this job
 * hands to the processor. Re-fetching by ID (rather than serializing the
 * model) matches this codebase's existing convention for jobs whose target
 * row may change state between dispatch and execution (see
 * SendAppointmentEmailJob's own docblock for the same reasoning).
 *
 * Idempotent by construction: re-running this job for the same event ID
 * (a queue redelivery, a worker restart, or a duplicate dispatch from the
 * recovery command) is always safe — WebhookEventProcessor's own row-locked
 * claim matrix is the only thing that decides whether a business action
 * runs, never this job. This job itself performs no state check at all;
 * it delegates entirely.
 *
 * ─── Retry policy ─────────────────────────────────────────────────────────
 *
 * This job's $tries/$backoff cover ONLY a genuine infrastructure exception
 * escaping process() itself (a DB deadlock, a lost connection, a bad/
 * missing event ID) — WebhookEventProcessor already catches every
 * exception arising from normalization/correlation/lifecycle calls
 * internally and converts it into a terminal `failed` WebhookProcessingResult
 * (see its own class docblock), so process() normally never throws at all.
 * A domain-level `failed` result with `retryable = true` is deliberately
 * NOT retried by this job (Laravel-level retry and the scheduled recovery
 * command must never both be redispatching the same row — see
 * `billing:webhooks:recover`'s docblock for why the recovery command is
 * the single source of truth for that case). The job completes
 * successfully — no exception thrown — for every WebhookProcessingResult
 * outcome (processed/ignored/conflict/failed/not-claimable): a `conflict`
 * is a quarantined domain state, not a queue failure, and none of these
 * outcomes should ever appear in `failed_jobs`.
 *
 * No external provider API call ever occurs during processing, so this
 * job is expected to complete in well under a second in the normal case —
 * $timeout is set generously short accordingly, comfortably below the
 * database queue connection's `retry_after` (600s, see config/queue.php)
 * so a still-running job is never re-reserved and executed twice.
 */
class ProcessBillingWebhookEventJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $timeout = 30;

    public function __construct(private readonly int $billingWebhookEventId)
    {
        // Isolates billing webhook processing from slower jobs (AI
        // analysis, appointment emails) sharing the 'default' queue — see
        // internal-docs/super-admin/subscription-billing.md for the
        // required worker configuration (`--queue=billing-webhooks,default`).
        // Set via onQueue() rather than redeclaring the $queue property —
        // Queueable already declares it, and PHP treats a re-declared
        // trait property with a different type as a fatal incompatible
        // composition error.
        $this->onQueue('billing-webhooks');
    }

    public function handle(WebhookEventProcessor $processor): void
    {
        try {
            $processor->process($this->billingWebhookEventId);
        } catch (\Throwable $e) {
            // A genuine infrastructure exception escaped the processor's
            // own internal catch (see class docblock) — e.g. a missing
            // row, a deadlock, a lost DB connection. Never log the raw
            // payload/signature/secret; only stable identifiers. Re-thrown
            // so Laravel's own $tries/$backoff apply — this is the ONLY
            // path by which this job's queue-level retry is ever used.
            Log::error('Unhandled exception dispatching a billing webhook event to the processor', [
                'billing_webhook_event_id' => $this->billingWebhookEventId,
                'attempt' => $this->attempts(),
                'exception_class' => get_class($e),
            ]);

            throw $e;
        }

        // Every WebhookProcessingResult outcome — processed, ignored,
        // conflict, failed (retryable or not), or not-claimable — means
        // this job completed its one job: handing the row to the
        // processor. The ledger row's own finalized state is the durable
        // record of what actually happened; this job never inspects or
        // acts on the result itself (see class docblock).
    }

    /**
     * Reached only once $tries is exhausted on genuine infrastructure
     * exceptions (see handle()). The underlying ledger row is untouched by
     * this failure (any partial claim was rolled back with the failing
     * transaction — see WebhookEventProcessor's single-transaction
     * design), so it remains exactly as reclaimable as before this job
     * ever ran; the scheduled `billing:webhooks:recover` command's
     * stranded-`received`/stale-`processing` sweep is the ultimate safety
     * net beneath this job's own retries, not a duplicate one.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('Billing webhook processing job failed permanently after all attempts', [
            'billing_webhook_event_id' => $this->billingWebhookEventId,
            'exception_class' => get_class($e),
        ]);
    }
}
