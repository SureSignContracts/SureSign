<?php

namespace App\Services\Billing;

use App\Services\Billing\Exceptions\MalformedWebhookEventException;
use Carbon\CarbonImmutable;

/**
 * An immutable, provider-independent representation of a Stripe webhook
 * event that has ALREADY passed signature verification — this class
 * represents a verified provider FACT, never a business command. It must
 * only ever be constructed via `fromVerified()`, which requires the exact
 * raw request body that was successfully verified — there is no public
 * constructor, and no code path lets application logic fabricate one from
 * unverified request data.
 *
 * Deliberately contains no lifecycle decision of any kind — no status
 * transition, no entitlement, no access decision. It is the durable,
 * trusted INPUT a future event-processing checkpoint will read from the
 * ledger and interpret; this class and WebhookIngestionService never
 * interpret it themselves.
 */
final class VerifiedWebhookEvent
{
    private function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $eventType,
        public readonly bool $livemode,
        public readonly CarbonImmutable $providerCreatedAt,
        public readonly ?string $apiVersion,
        public readonly ?string $requestId,
        public readonly ?string $accountId,
        public readonly array $payload,
        public readonly string $payloadHash,
        public readonly CarbonImmutable $receivedAt,
    ) {
    }

    /**
     * The only factory — requires the exact raw request body that passed
     * signature verification (for hashing, per this checkpoint's explicit
     * "hash the exact raw request body" requirement) and the provider's
     * own verified event array (never a raw \Stripe\Event object — the
     * provider adapter already normalizes to a plain array before this
     * point).
     *
     * @throws MalformedWebhookEventException
     */
    public static function fromVerified(string $provider, string $rawBody, array $verifiedEventArray): self
    {
        $providerEventId = $verifiedEventArray['id'] ?? null;
        $eventType = $verifiedEventArray['type'] ?? null;
        $created = $verifiedEventArray['created'] ?? null;

        if (!is_string($providerEventId) || $providerEventId === '') {
            throw new MalformedWebhookEventException('Verified event was missing a required "id".');
        }

        if (!is_string($eventType) || $eventType === '') {
            throw new MalformedWebhookEventException('Verified event was missing a required "type".');
        }

        if (!is_int($created)) {
            throw new MalformedWebhookEventException('Verified event was missing a required "created" timestamp.');
        }

        return new self(
            provider: $provider,
            providerEventId: $providerEventId,
            eventType: $eventType,
            livemode: (bool) ($verifiedEventArray['livemode'] ?? false),
            providerCreatedAt: CarbonImmutable::createFromTimestampUTC($created),
            apiVersion: $verifiedEventArray['api_version'] ?? null,
            requestId: $verifiedEventArray['request']['id'] ?? null,
            accountId: $verifiedEventArray['account'] ?? null,
            payload: $verifiedEventArray,
            payloadHash: hash('sha256', $rawBody),
            receivedAt: CarbonImmutable::now(),
        );
    }
}
