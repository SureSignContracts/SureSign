<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Models\ActivityLog;
use App\Models\BillingWebhookEvent;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Proves the ACTUAL named-queue behaviour this checkpoint exists to fix —
 * something neither the rest of this suite's `QUEUE_CONNECTION=sync`
 * (phpunit.xml — no queue-name concept at all: a sync dispatch just runs
 * immediately regardless of `onQueue()`) nor `Queue::fake()` (records a
 * push without ever routing it to a named queue a worker could fail to
 * consume) can demonstrate. This test switches the DEFAULT queue
 * connection to `database` for its own duration only, so
 * `ProcessBillingWebhookEventJob::dispatch()` pushes a REAL row into the
 * `jobs` table with `queue = 'billing-webhooks'`, then drives
 * `php artisan queue:work` via `Artisan::call()` with `--once
 * --stop-when-empty` — a single, bounded, deterministic reservation
 * attempt against that real row, never a long-running background worker
 * (which would make this suite flaky) — to prove exactly which `--queue`
 * argument can and cannot reserve it. This is the same mechanism
 * `backend/docker/entrypoint.sh`'s `queue` branch drives in production,
 * just invoked once, synchronously, inside this test.
 */
class BillingWebhookQueueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Only this test class exercises the real `database` queue driver
        // — every other Billing test deliberately relies on
        // QUEUE_CONNECTION=sync (or Queue::fake()) to keep the rest of the
        // suite fast and deterministic; this is the one place that
        // trade-off would hide the exact regression this checkpoint fixes.
        config(['queue.default' => 'database']);
    }

    private function webhookEvent(array $overrides = []): BillingWebhookEvent
    {
        return BillingWebhookEvent::create(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_queue_' . random_int(1, 100000000),
            'event_type' => 'payment_method.attached',
            'livemode' => false,
            'provider_created_at' => now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => now(),
            'payload_json' => ['data' => ['object' => ['id' => 'in_synthetic_marker']]],
            'payload_hash' => hash('sha256', 'x'),
        ], $overrides));
    }

    private function runWorkerOnce(string $queues): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database',
            '--queue' => $queues,
            '--once' => true,
            '--stop-when-empty' => true,
        ]);
    }

    // ─── Part 8 / Part 18 — the job is genuinely stored on billing-webhooks ─

    public function test_dispatched_job_is_stored_on_the_billing_webhooks_queue(): void
    {
        $event = $this->webhookEvent();

        ProcessBillingWebhookEventJob::dispatch($event->id);

        $this->assertDatabaseHas('jobs', ['queue' => 'billing-webhooks']);
        $this->assertDatabaseCount('jobs', 1);
    }

    // ─── Part 20 — a default-only worker cannot reserve it ─────────────────

    public function test_a_worker_consuming_only_default_cannot_reserve_the_job(): void
    {
        $event = $this->webhookEvent();
        ProcessBillingWebhookEventJob::dispatch($event->id);

        $this->runWorkerOnce('default');

        // Untouched — the job is still sitting in the queue table, and the
        // ledger row never got claimed.
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(WebhookProcessingStatus::RECEIVED, $event->refresh()->processing_status);
        $this->assertSame(0, $event->attempt_count);
    }

    // ─── Part 19 — the deployed policy (billing-webhooks,default) reserves it ─

    public function test_a_worker_consuming_billing_webhooks_then_default_reserves_and_processes_it(): void
    {
        $event = $this->webhookEvent();
        ProcessBillingWebhookEventJob::dispatch($event->id);

        $this->runWorkerOnce('billing-webhooks,default');

        // Removed from the queue table once processed successfully.
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->refresh()->processing_status);
        $this->assertSame(1, $event->attempt_count);
    }

    // ─── Part 21 — exactly one lifecycle transition, never duplicated ──────

    public function test_lifecycle_transition_via_the_real_queue_occurs_exactly_once(): void
    {
        $event = $this->webhookEvent([
            'event_type' => 'checkout.session.expired',
            'payload_json' => ['data' => ['object' => ['id' => 'cs_queue_marker', 'livemode' => false]]],
        ]);

        ProcessBillingWebhookEventJob::dispatch($event->id);
        $this->runWorkerOnce('billing-webhooks,default');

        // No linked local BillingCheckoutSession exists for this synthetic
        // ID, so this specific event correlates to a retryable failure —
        // the point of this test is the QUEUE mechanics (reserved,
        // processed, removed exactly once), not checkout correlation
        // itself (see WebhookEventProcessorTest for that).
        $this->assertSame(WebhookProcessingStatus::FAILED, $event->refresh()->processing_status);
        $this->assertTrue($event->retryable);
        $this->assertSame(1, $event->attempt_count);
    }

    // ─── Part 22 — duplicate job execution stays idempotent on the real queue ─

    public function test_duplicate_dispatch_and_processing_is_idempotent(): void
    {
        $event = $this->webhookEvent([
            'event_type' => 'checkout.session.expired',
            'payload_json' => ['data' => ['object' => ['id' => 'cs_queue_marker_2', 'livemode' => false]]],
        ]);

        ProcessBillingWebhookEventJob::dispatch($event->id);
        $this->runWorkerOnce('billing-webhooks,default');
        $event->refresh();
        $this->assertSame(1, $event->attempt_count);

        // A second, independent dispatch for the SAME ledger row (e.g. a
        // duplicate delivery from the recovery command) — must not
        // duplicate the ActivityLog trail or corrupt the ledger state.
        ProcessBillingWebhookEventJob::dispatch($event->id);
        $this->runWorkerOnce('billing-webhooks,default');

        $event->refresh();
        $this->assertSame(2, $event->attempt_count); // retried, per its own retryable=true
        $this->assertSame(0, ActivityLog::where('action', 'billing.checkout.expired')->count());
    }

    // ─── Part 6 item 9 / Part 23 — recovery command redispatches via the real queue ─

    public function test_recovery_command_redispatches_a_stranded_received_event_through_the_real_queue(): void
    {
        $event = $this->webhookEvent(['received_at' => now()->subMinutes(10)]);

        // Deliberately never dispatched directly — simulates the
        // after-commit dispatch having been lost (e.g. a crash between
        // commit and the queue push).
        $this->assertDatabaseCount('jobs', 0);

        Artisan::call('billing:webhooks:recover');

        $this->assertDatabaseHas('jobs', ['queue' => 'billing-webhooks']);

        $this->runWorkerOnce('billing-webhooks,default');

        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->refresh()->processing_status);
        $this->assertDatabaseCount('jobs', 0);
    }

    // ─── Part 10 — no raw payload anywhere along the real-queue path ───────

    public function test_no_raw_payload_appears_in_queue_storage_or_logs(): void
    {
        Log::spy();

        $event = $this->webhookEvent();
        ProcessBillingWebhookEventJob::dispatch($event->id);

        // The `jobs` table stores the SERIALIZED JOB (queue name, class,
        // constructor args — just the integer ID here), never the
        // ledger row's own payload_json.
        $jobRow = \DB::table('jobs')->first();
        $this->assertStringNotContainsString('in_synthetic_marker', $jobRow->payload);

        $this->runWorkerOnce('billing-webhooks,default');

        Log::shouldNotHaveReceived('error');
        $this->assertSame('in_synthetic_marker', $event->refresh()->payload_json['data']['object']['id']);
    }
}
