<?php

namespace App\Services\Consultancy;

use App\Models\ActivityLog;
use App\Models\BillingWebhookEvent;
use App\Models\ConsultancyPayment;
use App\Services\Billing\BillingProviderInterface;
use App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException;
use App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — interprets an already-
 * verified, already-persisted `billing_webhook_events` row that
 * WebhookIngestionService routed here (see that class's `dispatchForProcessing()`)
 * because it correlates to a local ConsultancyPayment. Deliberately a
 * SEPARATE processor from App\Services\Billing\WebhookEventProcessor —
 * that class's ~1,500 lines of subscription-specific correlation are never
 * touched or extended for Consultancy.
 *
 * Only two event types are supported — `checkout.session.completed` and
 * `checkout.session.expired` — matching the approved immediate-payment-
 * method launch scope (no async/delayed payment method events can ever
 * legitimately fire for a Consultancy Checkout Session, since none are
 * enabled — see StripeBillingProvider::createOneOffCheckoutSession()).
 *
 * `checkout.session.completed`'s `status`/`payment_status` fields are what
 * this class treats as authoritative proof of a genuinely completed
 * payment — NEVER Stripe metadata, and NEVER webhook arrival/event-created
 * timestamps. Per the approved expiry-race correction: Stripe does not
 * allow a Checkout Session to complete after its own `expires_at` — so
 * `status: complete` + `payment_status: paid` is, by construction, proof
 * payment completed within the aligned reservation/Checkout expiry window,
 * regardless of how late this webhook is actually delivered or processed.
 */
class ConsultancyWebhookEventProcessor
{
    public const PROCESSING_LEASE_MINUTES = 15;

    private const SUPPORTED_EVENT_TYPES = ['checkout.session.completed', 'checkout.session.expired'];

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly ConsultancyPaymentConversionService $conversionService,
    ) {
    }

    public function process(BillingWebhookEvent|int $event): array
    {
        $eventId = $event instanceof BillingWebhookEvent ? $event->id : $event;

        return DB::transaction(function () use ($eventId) {
            $locked = BillingWebhookEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();

            $notClaimable = $this->assessClaimability($locked);
            if ($notClaimable !== null) {
                return $notClaimable;
            }

            $locked->processing_status = WebhookProcessingStatus::PROCESSING;
            $locked->processing_started_at = CarbonImmutable::now();
            $locked->attempt_count += 1;
            $locked->save();

            try {
                $result = $this->dispatch($locked);
            } catch (\Throwable $e) {
                Log::error('Unhandled exception while processing a Consultancy webhook event', [
                    'billing_webhook_event_id' => $locked->id,
                    'event_type' => $locked->event_type,
                    'exception_class' => get_class($e),
                ]);
                $result = ['outcome' => WebhookProcessingStatus::FAILED, 'reason' => 'unexpected_exception', 'retryable' => true];
            }

            $this->finalize($locked, $result);

            return $result;
        });
    }

    private function assessClaimability(BillingWebhookEvent $event): ?array
    {
        return match ($event->processing_status) {
            WebhookProcessingStatus::RECEIVED => null,
            WebhookProcessingStatus::FAILED => $event->retryable === true
                ? null
                : ['outcome' => 'not_claimable', 'reason' => 'not_claimable_non_retryable_failure'],
            WebhookProcessingStatus::PROCESSING => $this->isAbandonedClaim($event)
                ? null
                : ['outcome' => 'not_claimable', 'reason' => 'not_claimable_already_processing'],
            WebhookProcessingStatus::PROCESSED => ['outcome' => 'not_claimable', 'reason' => 'already_processed'],
            WebhookProcessingStatus::IGNORED => ['outcome' => 'not_claimable', 'reason' => 'already_ignored'],
            WebhookProcessingStatus::CONFLICT => ['outcome' => 'not_claimable', 'reason' => 'not_claimable_conflict_requires_manual_review'],
            default => ['outcome' => 'not_claimable', 'reason' => 'not_claimable_unknown_status'],
        };
    }

    private function isAbandonedClaim(BillingWebhookEvent $event): bool
    {
        if ($event->processing_started_at === null) {
            return true;
        }

        return CarbonImmutable::instance($event->processing_started_at)
            ->addMinutes(self::PROCESSING_LEASE_MINUTES)
            ->isPast();
    }

    private function dispatch(BillingWebhookEvent $event): array
    {
        if (!in_array($event->event_type, self::SUPPORTED_EVENT_TYPES, true)) {
            return ['outcome' => WebhookProcessingStatus::IGNORED, 'reason' => 'ignored_unsupported_event_type'];
        }

        $payloadObject = $event->payload_json['data']['object'] ?? null;
        if (!is_array($payloadObject)) {
            return ['outcome' => WebhookProcessingStatus::FAILED, 'reason' => 'missing_data_object', 'retryable' => false];
        }

        $normalized = $this->provider->normalizeCheckoutSessionFromWebhookPayload($payloadObject);

        $payment = ConsultancyPayment::where('stripe_checkout_session_id', $normalized['id'])->first();
        if (!$payment) {
            // Should not happen — WebhookIngestionService only routes here
            // when a matching payment was found. A payment disappearing
            // between routing and processing (should be impossible given
            // restrictOnDelete()) is treated as retryable rather than
            // silently ignored.
            return ['outcome' => WebhookProcessingStatus::FAILED, 'reason' => 'consultancy_payment_not_found_locally', 'retryable' => true];
        }

        if ($payment->livemode !== $normalized['livemode']) {
            return ['outcome' => WebhookProcessingStatus::CONFLICT, 'reason' => 'livemode_mismatch'];
        }

        return match ($event->event_type) {
            'checkout.session.completed' => $this->processCompleted($event, $payment, $normalized),
            'checkout.session.expired' => $this->processExpired($payment),
        };
    }

    private function processCompleted(BillingWebhookEvent $event, ConsultancyPayment $payment, array $normalized): array
    {
        // Authoritative proof of a genuinely completed payment — see class
        // docblock. Anything else is not yet a confirmed payment.
        if (($normalized['status'] ?? null) !== 'complete' || ($normalized['payment_status'] ?? null) !== 'paid') {
            return ['outcome' => WebhookProcessingStatus::IGNORED, 'reason' => 'checkout_not_yet_confirmed_paid'];
        }

        if ($payment->status === 'converted') {
            return ['outcome' => WebhookProcessingStatus::PROCESSED, 'reason' => 'already_converted'];
        }

        if ($payment->status === 'checkout_open') {
            $payment->update([
                'status' => 'paid',
                'paid_at' => CarbonImmutable::now(),
                'stripe_payment_intent_id' => $normalized['payment_intent_id'] ?? $payment->stripe_payment_intent_id,
                'confirming_stripe_event_id' => $event->provider_event_id,
            ]);
        } elseif (!in_array($payment->status, ['paid', 'conversion_pending', 'manual_review'], true)) {
            return ['outcome' => WebhookProcessingStatus::CONFLICT, 'reason' => 'unexpected_payment_status:' . $payment->status];
        }

        try {
            $this->conversionService->convert($payment->fresh(), $event->provider_event_id);
        } catch (ConsultancyConversionRetryableException $e) {
            $payment->update(['status' => 'conversion_pending']);
            return ['outcome' => WebhookProcessingStatus::FAILED, 'reason' => 'conversion_pending_retry', 'retryable' => true];
        } catch (ConsultancyManualReviewRequiredException $e) {
            return ['outcome' => WebhookProcessingStatus::CONFLICT, 'reason' => 'manual_review_required:' . $e->getMessage()];
        }

        return ['outcome' => WebhookProcessingStatus::PROCESSED, 'reason' => 'payment_confirmed_and_converted'];
    }

    /**
     * A paid/converting/converted payment must NEVER be marked expired by
     * a late/out-of-order `checkout.session.expired` delivery — ignored,
     * not applied. Only a genuinely still-open Checkout is affected.
     */
    private function processExpired(ConsultancyPayment $payment): array
    {
        if (in_array($payment->status, ['paid', 'conversion_pending', 'converted', 'manual_review'], true)) {
            return ['outcome' => WebhookProcessingStatus::IGNORED, 'reason' => 'expired_event_after_payment_confirmed'];
        }

        if ($payment->status === 'checkout_open') {
            $payment->update(['status' => 'expired']);

            ActivityLog::record(
                'consultancy.payment_checkout_expired',
                'Consultancy Checkout Session expired without payment.',
                null,
                $payment,
                [],
            );
        }

        return ['outcome' => WebhookProcessingStatus::PROCESSED, 'reason' => 'checkout_marked_expired'];
    }

    private function finalize(BillingWebhookEvent $event, array $result): void
    {
        $outcome = $result['outcome'];

        if ($outcome === 'not_claimable') {
            return; // Row untouched — this call never actually claimed it.
        }

        $event->processing_status = $outcome;
        if ($outcome === WebhookProcessingStatus::FAILED) {
            $event->retryable = $result['retryable'] ?? false;
            $event->failed_at = CarbonImmutable::now();
            $event->failure_message = $result['reason'] ?? null;
        } else {
            $event->processed_at = CarbonImmutable::now();
        }
        $event->save();
    }
}
