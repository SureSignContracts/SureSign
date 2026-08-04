<?php

namespace App\Jobs;

use App\Services\Consultancy\ConsultancyWebhookEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — pure orchestration, mirrors
 * App\Jobs\ProcessBillingWebhookEventJob exactly, but invokes
 * ConsultancyWebhookEventProcessor instead. Deliberately a SEPARATE job on
 * a SEPARATE queue (`consultancy-payments`) — never mixed with subscription
 * webhook processing, and never competing with it for worker time.
 *
 * Same idempotent-by-construction contract as the Billing job: re-running
 * this for the same event ID is always safe, since the processor's own
 * row-locked claim matrix decides whether a business action runs.
 */
class ProcessConsultancyWebhookEventJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60];
    public int $timeout = 30;

    public function __construct(private readonly int $billingWebhookEventId)
    {
        $this->onQueue('consultancy-payments');
    }

    public function handle(ConsultancyWebhookEventProcessor $processor): void
    {
        try {
            $processor->process($this->billingWebhookEventId);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception dispatching a Consultancy webhook event to the processor', [
                'billing_webhook_event_id' => $this->billingWebhookEventId,
                'attempt' => $this->attempts(),
                'exception_class' => get_class($e),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Consultancy webhook processing job failed permanently after all attempts', [
            'billing_webhook_event_id' => $this->billingWebhookEventId,
            'exception_class' => get_class($e),
        ]);
    }
}
