<?php

namespace App\Support\Billing;

/**
 * billing_webhook_events.processing_status allow-list — see
 * App\Services\Billing\WebhookIngestionService (this checkpoint) for how a
 * row is first created, and the future event-processing checkpoint for
 * how it moves beyond `received`.
 */
class WebhookProcessingStatus
{
    public const RECEIVED = 'received';
    public const PROCESSING = 'processing';
    public const PROCESSED = 'processed';
    public const IGNORED = 'ignored';
    public const FAILED = 'failed';

    /**
     * A verified delivery reused an existing provider_event_id but supplied
     * a different payload_hash — never an ordinary processing failure,
     * malformed payload, invalid signature, unsupported event type, or
     * transient provider error (those all stay `failed`/rejected-before-
     * persistence). A terminal, operational-review state: nothing in this
     * codebase automatically moves a row out of `conflict` — only a
     * deliberate future reconciliation action would.
     */
    public const CONFLICT = 'conflict';

    public const ALL = [
        self::RECEIVED,
        self::PROCESSING,
        self::PROCESSED,
        self::IGNORED,
        self::FAILED,
        self::CONFLICT,
    ];
}
