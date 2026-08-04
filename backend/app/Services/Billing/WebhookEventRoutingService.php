<?php

namespace App\Services\Billing;

use App\Jobs\ProcessBillingWebhookEventJob;
use App\Jobs\ProcessConsultancyWebhookEventJob;
use App\Models\BillingWebhookEvent;
use App\Models\ConsultancyPayment;

/**
 * The ONE place that decides which processing job a persisted, verified
 * `billing_webhook_events` row is dispatched to — subscription
 * (App\Services\Billing\WebhookEventProcessor) or Consultancy
 * (App\Services\Consultancy\ConsultancyWebhookEventProcessor). Used by both
 * WebhookIngestionService (first dispatch of a newly-created row) and
 * App\Console\Commands\RecoverBillingWebhookEvents (redispatch of a
 * stranded row) — a stranded Consultancy event must be routed identically
 * on recovery as it would have been on first ingestion; duplicating this
 * correlation check in two places would risk them silently drifting apart.
 *
 * A local-record CORRELATION check only (does a row already exist matching
 * this Stripe Checkout Session ID) — never an interpretation of what the
 * event means. See WebhookIngestionService's own docblock for the full
 * reasoning on why local correlation is preferred over trusting Stripe's
 * `mode` field or metadata alone.
 */
class WebhookEventRoutingService
{
    /**
     * Returns the job class to dispatch — never dispatches itself, since
     * callers need different dispatch semantics (WebhookIngestionService
     * needs ->afterCommit(); RecoverBillingWebhookEvents dispatches
     * immediately, since the row it's redispatching for was already
     * committed in a prior request).
     *
     * @return class-string
     */
    public function jobClassFor(BillingWebhookEvent $event): string
    {
        return $this->isConsultancyCheckoutEvent($event)
            ? ProcessConsultancyWebhookEventJob::class
            : ProcessBillingWebhookEventJob::class;
    }

    private function isConsultancyCheckoutEvent(BillingWebhookEvent $event): bool
    {
        if (!in_array($event->event_type, ['checkout.session.completed', 'checkout.session.expired'], true)) {
            return false;
        }

        $sessionId = $event->payload_json['data']['object']['id'] ?? null;
        if (!is_string($sessionId) || $sessionId === '') {
            return false;
        }

        return ConsultancyPayment::where('stripe_checkout_session_id', $sessionId)->exists();
    }
}
