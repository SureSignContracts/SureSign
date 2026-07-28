<?php

namespace App\Services\Billing;

use App\Models\BillingWebhookEvent;

/**
 * What WebhookIngestionService::ingest() decided to do with a verified
 * event — the controller reads only `httpStatus` to build its response; it
 * never re-derives HTTP status logic itself.
 */
final class WebhookIngestionResult
{
    public const CREATED = 'created';
    public const DUPLICATE = 'duplicate';
    public const CONFLICT = 'conflict';

    private function __construct(
        public readonly string $outcome,
        public readonly int $httpStatus,
        public readonly ?BillingWebhookEvent $event,
    ) {
    }

    public static function created(BillingWebhookEvent $event): self
    {
        return new self(self::CREATED, 200, $event);
    }

    public static function duplicate(BillingWebhookEvent $event): self
    {
        return new self(self::DUPLICATE, 200, $event);
    }

    /**
     * Always 200 — per the approved policy, a payload-mismatch conflict is
     * durably recorded (see WebhookIngestionService) and acknowledged
     * rather than left to retry forever.
     */
    public static function conflict(BillingWebhookEvent $event): self
    {
        return new self(self::CONFLICT, 200, $event);
    }
}
