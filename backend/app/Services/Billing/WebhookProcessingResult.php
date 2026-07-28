<?php

namespace App\Services\Billing;

use App\Support\Billing\WebhookProcessingStatus;

/**
 * What WebhookEventProcessor::process() decided about a single ledger row —
 * never the full webhook payload (see class docblock on
 * WebhookEventProcessor for why). `action` is a short, stable label for
 * which handler ran and what it did (e.g. "activated_subscription",
 * "checkout_marked_completed", "ignored_unsupported_event") — for
 * operator/log readability, not machine dispatch.
 */
final class WebhookProcessingResult
{
    private function __construct(
        public readonly int $webhookEventId,
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly string $status,
        public readonly string $action,
        public readonly array $affectedRecords,
        public readonly ?bool $retryable,
        public readonly ?string $errorCode,
    ) {
    }

    public static function processed(int $webhookEventId, string $providerEventId, string $eventType, string $action, array $affectedRecords = []): self
    {
        return new self($webhookEventId, $providerEventId, $eventType, WebhookProcessingStatus::PROCESSED, $action, $affectedRecords, null, null);
    }

    public static function ignored(int $webhookEventId, string $providerEventId, string $eventType, string $action, array $affectedRecords = []): self
    {
        return new self($webhookEventId, $providerEventId, $eventType, WebhookProcessingStatus::IGNORED, $action, $affectedRecords, null, null);
    }

    public static function failed(int $webhookEventId, string $providerEventId, string $eventType, string $action, bool $retryable, string $errorCode, array $affectedRecords = []): self
    {
        return new self($webhookEventId, $providerEventId, $eventType, WebhookProcessingStatus::FAILED, $action, $affectedRecords, $retryable, $errorCode);
    }

    public static function conflict(int $webhookEventId, string $providerEventId, string $eventType, string $action, string $errorCode, array $affectedRecords = []): self
    {
        return new self($webhookEventId, $providerEventId, $eventType, WebhookProcessingStatus::CONFLICT, $action, $affectedRecords, false, $errorCode);
    }

    /**
     * The ledger row was not claimable at all (already `processing`,
     * already terminal, or a non-retryable `failed`/`conflict` row) — no
     * business action was invoked. `status` reflects the row's EXISTING
     * processing_status, unchanged by this call.
     */
    public static function notClaimable(int $webhookEventId, string $providerEventId, string $eventType, string $existingStatus, string $action): self
    {
        return new self($webhookEventId, $providerEventId, $eventType, $existingStatus, $action, [], null, null);
    }
}
