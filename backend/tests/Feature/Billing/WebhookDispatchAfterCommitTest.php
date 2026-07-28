<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Models\BillingWebhookEvent;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves WebhookIngestionService's `dispatch(...)->afterCommit()` pattern
 * genuinely defers until the surrounding transaction commits, and is
 * genuinely discarded on rollback — using the REAL `sync` queue connection
 * (this suite's `QUEUE_CONNECTION=sync` per phpunit.xml), never
 * `Queue::fake()`. `Queue::fake()` cannot prove this: `QueueFake::push()`
 * records a job as pushed immediately regardless of `afterCommit()`,
 * because the fake bypasses the real connection logic that registers a
 * `DB::afterCommit()` callback — it does not simulate transactional
 * deferral at all. Only a real queue connection (here, `sync`, which
 * executes a job inline the moment it's actually pushed) can prove the
 * rollback-safety property this checkpoint requires.
 *
 * Each test uses a pre-committed `unsupported.event` ledger row (outside
 * the transaction under test) so the row's own `attempt_count`/
 * `processing_status` — untouched by the inner (possibly rolled-back)
 * transaction itself — becomes the observable proof of whether the job
 * actually ran.
 */
class WebhookDispatchAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    private function committedEvent(): BillingWebhookEvent
    {
        return BillingWebhookEvent::create([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_commit_' . random_int(1, 10000000),
            'event_type' => 'unsupported.event',
            'livemode' => false,
            'provider_created_at' => now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => now(),
            'payload_json' => ['data' => ['object' => ['id' => 'x']]],
            'payload_hash' => hash('sha256', 'x'),
        ]);
    }

    public function test_dispatch_after_commit_does_not_run_if_the_transaction_rolls_back(): void
    {
        $event = $this->committedEvent();

        try {
            DB::transaction(function () use ($event) {
                ProcessBillingWebhookEventJob::dispatch($event->id)->afterCommit();

                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::RECEIVED, $event->processing_status);
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_dispatch_after_commit_runs_once_the_transaction_commits(): void
    {
        $event = $this->committedEvent();

        DB::transaction(function () use ($event) {
            ProcessBillingWebhookEventJob::dispatch($event->id)->afterCommit();
        });

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
        $this->assertSame(1, $event->attempt_count);
    }
}
