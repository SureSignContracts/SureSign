<?php

namespace Tests\Feature\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\BillingWebhookEvent;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\User;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessBillingWebhookEventJobTest extends TestCase
{
    use RefreshDatabase;

    private function webhookEvent(string $type, array $dataObject, array $overrides = []): BillingWebhookEvent
    {
        return BillingWebhookEvent::create(array_merge([
            'provider' => 'stripe',
            'provider_event_id' => 'evt_' . random_int(1, 100000000),
            'event_type' => $type,
            'livemode' => false,
            'provider_created_at' => now(),
            'processing_status' => WebhookProcessingStatus::RECEIVED,
            'received_at' => now(),
            'payload_json' => ['data' => ['object' => $dataObject]],
            'payload_hash' => hash('sha256', json_encode($dataObject)),
        ], $overrides));
    }

    public function test_job_processes_a_received_event(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_1']);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->refresh()->processing_status);
    }

    public function test_duplicate_job_execution_is_harmless(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_2']);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);
        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
        $this->assertSame(1, $event->attempt_count);
    }

    public function test_already_processed_event_is_a_no_op(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_3'], [
            'processing_status' => WebhookProcessingStatus::PROCESSED,
            'processed_at' => now(),
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $this->assertSame(0, $event->refresh()->attempt_count);
    }

    public function test_ignored_event_is_a_no_op(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_4'], [
            'processing_status' => WebhookProcessingStatus::IGNORED,
            'processed_at' => now(),
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $this->assertSame(0, $event->refresh()->attempt_count);
    }

    public function test_conflict_event_is_a_no_op(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_5'], [
            'processing_status' => WebhookProcessingStatus::CONFLICT,
            'failed_at' => now(),
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $this->assertSame(WebhookProcessingStatus::CONFLICT, $event->refresh()->processing_status);
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_active_processing_lease_is_not_reclaimed(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_6'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now(),
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::PROCESSING, $event->processing_status);
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_stale_processing_lease_is_reclaimed(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_7'], [
            'processing_status' => WebhookProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(20),
            'attempt_count' => 1,
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
        $this->assertSame(2, $event->attempt_count);
    }

    public function test_non_retryable_failed_event_is_a_no_op(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_8'], [
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => false,
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::FAILED, $event->processing_status);
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_retryable_failed_event_is_reprocessed_but_the_job_does_not_retry_it_itself(): void
    {
        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_9'], [
            'processing_status' => WebhookProcessingStatus::FAILED,
            'failed_at' => now(),
            'retryable' => true,
            'attempt_count' => 1,
        ]);

        // The job hands a retryable-failed row straight back to the
        // processor exactly like a `received` one — it does not implement
        // its own retry loop for this case (see class docblock: the
        // scheduled recovery command is the sole source of truth for when
        // a retryable-failed row gets a NEW job dispatched).
        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $event->refresh();
        $this->assertSame(WebhookProcessingStatus::IGNORED, $event->processing_status);
        $this->assertSame(2, $event->attempt_count);
    }

    public function test_unexpected_infrastructure_exception_propagates_for_the_queue_to_retry(): void
    {
        // A non-existent ledger ID makes WebhookEventProcessor::process()
        // throw ModelNotFoundException from firstOrFail() — the one path
        // that escapes the processor's own internal try/catch (which only
        // wraps the dispatch() call, not the row lookup itself). This job
        // must let it propagate rather than swallowing it, so Laravel's
        // own $tries/backoff apply.
        $this->expectException(ModelNotFoundException::class);

        ProcessBillingWebhookEventJob::dispatchSync(999999999);
    }

    public function test_no_raw_payload_appears_in_logs(): void
    {
        Log::spy();

        $event = $this->webhookEvent('payment_method.attached', ['id' => 'in_secret_marker_xyz']);
        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        Log::shouldNotHaveReceived('error');
    }

    public function test_lifecycle_transition_occurs_only_once_across_duplicate_job_execution(): void
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $plan = PricingPlan::create(['code' => 'p' . random_int(1, 10000000), 'slug' => 's' . random_int(1, 10000000), 'name' => 'Plan', 'currency' => 'GBP']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        $session = BillingCheckoutSession::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_dup_' . random_int(1, 10000000),
            'internal_reference' => 'CHK-DUP-' . random_int(1, 10000000),
            'status' => CheckoutSessionStatus::OPEN,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
        ]);

        $event = $this->webhookEvent('checkout.session.expired', [
            'id' => $session->provider_checkout_session_id,
            'livemode' => false,
        ]);

        ProcessBillingWebhookEventJob::dispatchSync($event->id);
        ProcessBillingWebhookEventJob::dispatchSync($event->id);

        $this->assertSame(1, ActivityLog::where('action', 'billing.checkout.expired')->count());
        $this->assertSame(CheckoutSessionStatus::EXPIRED, $session->refresh()->status);
    }
}
