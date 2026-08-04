<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingWebhookEvent;
use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\Exceptions\WebhookModeMismatchException;
use App\Services\Billing\Exceptions\WebhookSecretNotConfiguredException;
use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The trusted boundary between Stripe and SureSign — verifies a webhook
 * delivery's signature, builds a normalized VerifiedWebhookEvent, and
 * durably persists it into the billing_webhook_events ledger EXACTLY once.
 *
 * This service does not interpret what an event means. It never calls
 * SubscriptionLifecycleService, never mutates a BillingCheckoutSession,
 * never activates a subscription, and never touches an invoice or payment
 * record — WebhookEventProcessor (invoked asynchronously via
 * ProcessBillingWebhookEventJob, dispatched below) is the only place that
 * happens. `TransitionContext::occurredAt` is sourced from this service's
 * persisted `provider_created_at`, never from anything created here —
 * this service creates no TransitionContext at all.
 *
 * A NEW verified event (the `created` outcome — see persist()) also
 * dispatches `ProcessBillingWebhookEventJob` after this method's own
 * transaction commits, so a worker never receives an event ID whose
 * ledger row isn't durably visible yet. A duplicate or payload-mismatch-
 * conflict delivery (reconcileDuplicate()) deliberately never dispatches a
 * second job — see ProcessBillingWebhookEventJob's docblock for why the
 * scheduled `billing:webhooks:recover` command, not a redispatch here, is
 * the single source of truth for anything that ends up stranded.
 */
class WebhookIngestionService
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly BillingProviderManager $providerManager,
        private readonly WebhookEventRoutingService $routingService,
    ) {
    }

    /**
     * @throws InvalidWebhookSignatureException
     * @throws \App\Services\Billing\Exceptions\MalformedWebhookEventException
     * @throws WebhookSecretNotConfiguredException
     * @throws WebhookModeMismatchException
     */
    public function ingest(string $rawBody, string $signatureHeader): WebhookIngestionResult
    {
        // 1. Resolve the application's OWN currently-configured mode —
        // never inferred from the incoming payload before verification.
        $applicationLivemode = $this->provider->isLivemode();
        $secret = $this->providerManager->resolveWebhookSecret($applicationLivemode);

        if ($secret === '') {
            $mode = $applicationLivemode ? 'live' : 'test';
            throw new WebhookSecretNotConfiguredException(
                "No webhook signing secret is configured for the application's current ({$mode}) mode."
            );
        }

        // 2. Verify the EXACT raw body against ONLY that one secret — never
        // attempted against the other mode's secret, never both in
        // sequence, never accepted on "whichever matches."
        $verifiedArray = $this->provider->verifyWebhookSignature($rawBody, $signatureHeader, $secret);

        // 3. Build the normalized envelope — throws on a malformed shape.
        $provider = $this->providerManager->configuredProvider();
        $verified = VerifiedWebhookEvent::fromVerified($provider, $rawBody, $verifiedArray);

        // 4. The verified event's OWN livemode must match the application's
        // resolved mode — an opposite-mode event is never persisted into
        // the active ledger.
        if ($verified->livemode !== $applicationLivemode) {
            $this->logConcise('billing.webhook.mode_mismatch', 'Rejected a webhook event whose livemode did not match the application\'s configured mode', [
                'provider_event_id' => $verified->providerEventId,
                'event_type' => $verified->eventType,
                'event_livemode' => $verified->livemode,
                'application_livemode' => $applicationLivemode,
            ]);

            throw new WebhookModeMismatchException(
                "Event {$verified->providerEventId} livemode ({$this->modeLabel($verified->livemode)}) "
                . "does not match the application's configured mode ({$this->modeLabel($applicationLivemode)})."
            );
        }

        // 5. Persist exactly once — the DB's own unique (provider,
        // provider_event_id) constraint is the concurrency-safety
        // boundary, not a check-then-create race.
        return $this->persist($verified);
    }

    private function persist(VerifiedWebhookEvent $verified): WebhookIngestionResult
    {
        try {
            return DB::transaction(function () use ($verified) {
                $event = BillingWebhookEvent::create([
                    'provider' => $verified->provider,
                    'provider_event_id' => $verified->providerEventId,
                    'event_type' => $verified->eventType,
                    'api_version' => $verified->apiVersion,
                    'livemode' => $verified->livemode,
                    'provider_created_at' => $verified->providerCreatedAt,
                    'processing_status' => WebhookProcessingStatus::RECEIVED,
                    'received_at' => $verified->receivedAt,
                    'payload_json' => $verified->payload,
                    'payload_hash' => $verified->payloadHash,
                ]);

                $this->logConcise('billing.webhook.received', "Received verified webhook event ({$verified->eventType})", [
                    'provider_event_id' => $verified->providerEventId,
                    'event_type' => $verified->eventType,
                ]);

                // Dispatched with ->afterCommit() specifically so the
                // worker can never receive an event ID whose ledger row
                // isn't visible yet to other connections — Laravel defers
                // the actual queue push until this transaction (the one
                // this whole persist() call runs inside) commits, and
                // drops the dispatch entirely if it rolls back instead.
                // This is the ONLY outcome that ever dispatches a
                // processing job — a duplicate/conflict delivery
                // (reconcileDuplicate() below) deliberately never does;
                // see ProcessBillingWebhookEventJob's docblock and
                // internal-docs/super-admin/subscription-billing.md for
                // why redispatching duplicates was rejected in favour of
                // the scheduled recovery command being the single source
                // of truth for anything that ends up stranded.
                $this->dispatchForProcessing($event);

                return WebhookIngestionResult::created($event);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->reconcileDuplicate($verified);
        }
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 3 — delegates the routing
     * DECISION to WebhookEventRoutingService (shared with
     * RecoverBillingWebhookEvents, so a stranded Consultancy event is
     * routed identically on recovery as on first ingestion), but keeps the
     * ->afterCommit() dispatch semantics here — this is still the only
     * place a NEW event's processing job is ever dispatched.
     */
    private function dispatchForProcessing(BillingWebhookEvent $event): void
    {
        $jobClass = $this->routingService->jobClassFor($event);
        $jobClass::dispatch($event->id)->afterCommit();
    }

    /**
     * A row for this (provider, provider_event_id) already exists — either
     * a genuine redelivery, or a concurrent request that won the race. The
     * DB row, locked here, is the single source of truth for what happens
     * next; nothing about this path ever creates a second row.
     */
    private function reconcileDuplicate(VerifiedWebhookEvent $verified): WebhookIngestionResult
    {
        return DB::transaction(function () use ($verified) {
            $existing = BillingWebhookEvent::query()
                ->where('provider', $verified->provider)
                ->where('provider_event_id', $verified->providerEventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($existing->payload_hash === $verified->payloadHash) {
                $this->logConcise('billing.webhook.duplicate', "Duplicate verified webhook event ignored ({$verified->eventType})", [
                    'provider_event_id' => $verified->providerEventId,
                ]);

                return WebhookIngestionResult::duplicate($existing);
            }

            // Payload mismatch — never overwrite the original payload,
            // hash, received_at, attempt_count, processed_at, failed_at, or
            // failure_message. Only promote processing_status to
            // `conflict` when the original row hasn't been processed yet
            // (status still `received`) — anything further along already
            // represents real historical work that must never be
            // overwritten by a later, conflicting delivery.
            if ($existing->processing_status === WebhookProcessingStatus::RECEIVED) {
                $existing->update(['processing_status' => WebhookProcessingStatus::CONFLICT]);
            }

            $this->logConcise('billing.webhook.conflict', "Duplicate provider event ID with a mismatched payload hash ({$verified->eventType}) — requires manual review", [
                'provider_event_id' => $verified->providerEventId,
                'event_type' => $verified->eventType,
                'existing_status' => $existing->processing_status,
            ]);

            Log::warning('Billing webhook conflict: duplicate provider_event_id with mismatched payload hash', [
                'provider' => $verified->provider,
                'provider_event_id' => $verified->providerEventId,
                'existing_status' => $existing->processing_status,
            ]);

            return WebhookIngestionResult::conflict($existing->fresh());
        });
    }

    /**
     * Concise operational events only — never a raw payload, never a
     * signature header, never a secret. See ActivityLog::record()'s own
     * "never let audit logging break the main flow" guarantee.
     */
    private function logConcise(string $action, string $description, array $meta): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            meta: $meta,
        );
    }

    private function modeLabel(bool $livemode): string
    {
        return $livemode ? 'live' : 'test';
    }
}
