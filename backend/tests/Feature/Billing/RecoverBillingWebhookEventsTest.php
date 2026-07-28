<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Models\BillingWebhookEvent;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * These tests fake the queue (unlike WebhookDispatchAfterCommitTest) —
 * RecoverBillingWebhookEvents dispatches via plain
 * `ProcessBillingWebhookEventJob::dispatch()` (no ->afterCommit(), since
 * it never runs inside the ledger row's own creating transaction), so
 * there is no transactional-deferral subtlety for Queue::fake() to miss
 * here; it correctly records whether a dispatch DECISION was made, which
 * is exactly what this command's own responsibility is — see
 * App\Console\Commands\RecoverBillingWebhookEvents's docblock ("discovers
 * ... and dispatches", never executes lifecycle logic itself). Whether a
 * dispatched job then behaves correctly is covered separately by
 * ProcessBillingWebhookEventJobTest.
 */
class RecoverBillingWebhookEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([ProcessBillingWebhookEventJob::class]);
    }

    private function webhookEvent(array $overrides = []): BillingWebhookEvent
    {
        return BillingWebhookEvent::create(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => 'invoice.paid',
            'livemode' => false,
            'provider_created_at' => now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => now(),
            'payload_json' => ['data' => ['object' => ['id' => 'x']]],
            'payload_hash' => hash('sha256', 'x'),
        ], $overrides));
    }

    public function test_dry_run_performs_no_dispatch(): void
    {
        $this->webhookEvent(['received_at' => now()->subMinutes(10)]);

        $this->artisan('billing:webhooks:recover', ['--dry-run' => true])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_old_received_rows_are_dispatched(): void
    {
        $event = $this->webhookEvent(['received_at' => now()->subMinutes(10)]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_fresh_received_rows_are_skipped(): void
    {
        $this->webhookEvent(['received_at' => now()->subSeconds(30)]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_stale_processing_rows_are_dispatched(): void
    {
        $this->webhookEvent([
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(20),
        ]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_active_processing_rows_are_skipped(): void
    {
        $this->webhookEvent([
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(2),
        ]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_retryable_failed_rows_are_dispatched(): void
    {
        $this->webhookEvent([
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => true,
        ]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_non_retryable_failed_rows_are_skipped(): void
    {
        $this->webhookEvent([
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => false,
        ]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_processed_rows_are_skipped(): void
    {
        $this->webhookEvent(['processing_status' => WebhookProcessingStatus::PROCESSED, 'processed_at' => now()]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_ignored_rows_are_skipped(): void
    {
        $this->webhookEvent(['processing_status' => WebhookProcessingStatus::IGNORED, 'processed_at' => now()]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_conflict_rows_are_skipped(): void
    {
        $this->webhookEvent(['processing_status' => WebhookProcessingStatus::CONFLICT, 'failed_at' => now()]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_limit_is_respected(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->webhookEvent(['received_at' => now()->subMinutes(10)]);
        }

        $this->artisan('billing:webhooks:recover', ['--limit' => 2])->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 2);
    }

    public function test_provider_filter_is_respected(): void
    {
        $this->webhookEvent(['received_at' => now()->subMinutes(10), 'provider' => 'stripe']);
        $this->webhookEvent(['received_at' => now()->subMinutes(10), 'provider' => 'other']);

        $this->artisan('billing:webhooks:recover', ['--provider' => 'other'])->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_event_id_targeting_dispatches_a_single_recoverable_event(): void
    {
        $event = $this->webhookEvent(['received_at' => now()->subMinutes(10)]);

        $this->artisan('billing:webhooks:recover', ['--event-id' => (string) $event->id])->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 1);
    }

    public function test_event_id_targeting_refuses_a_conflict_row(): void
    {
        $event = $this->webhookEvent(['processing_status' => WebhookProcessingStatus::CONFLICT, 'failed_at' => now()]);

        $this->artisan('billing:webhooks:recover', ['--event-id' => (string) $event->id])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_event_id_targeting_refuses_a_non_retryable_failure(): void
    {
        $event = $this->webhookEvent(['processing_status' => WebhookProcessingStatus::FAILED, 'failed_at' => now(), 'retryable' => false]);

        $this->artisan('billing:webhooks:recover', ['--event-id' => (string) $event->id])->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_event_id_targeting_unknown_id_fails(): void
    {
        $this->artisan('billing:webhooks:recover', ['--event-id' => '999999999'])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_concurrent_invocations_do_not_crash_and_remain_safe(): void
    {
        // Dispatching duplicate (harmless) jobs across two runs is
        // explicitly accepted by design (see command docblock) —
        // WebhookEventProcessor's own row lock is the correctness
        // boundary, not this command. This test proves running the
        // command twice back-to-back is itself safe (no exception, no
        // double-counting beyond what's expected).
        $this->webhookEvent(['received_at' => now()->subMinutes(10)]);

        $this->artisan('billing:webhooks:recover')->assertSuccessful();
        $this->artisan('billing:webhooks:recover')->assertSuccessful();

        Queue::assertPushed(ProcessBillingWebhookEventJob::class, 2);
    }
}
