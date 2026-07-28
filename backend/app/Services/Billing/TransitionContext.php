<?php

namespace App\Services\Billing;

use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Structured, normalized context accompanying every commercially
 * significant subscription transition — never a bare string or a
 * caller-assembled array. Deliberately immutable and deliberately free of
 * anything Stripe-specific beyond plain scalar identifiers: no raw
 * provider payload, no \Stripe\* object ever travels through this class.
 *
 * `occurredAt` is when the underlying event actually happened (e.g. the
 * instant Stripe recorded a status change) — used for stale-event
 * rejection. `effectiveAt` is when the resulting local state should be
 * considered to have taken effect commercially (usually the same instant,
 * but not always — e.g. a scheduled cancellation's effective date is in
 * the future relative to when the schedule was requested).
 */
final class TransitionContext
{
    private function __construct(
        public readonly string $source,
        public readonly ?string $reason,
        public readonly ?int $actorUserId,
        public readonly ?string $provider,
        public readonly ?string $providerEventId,
        public readonly ?string $providerSubscriptionId,
        public readonly CarbonImmutable $occurredAt,
        public readonly ?CarbonImmutable $effectiveAt,
        public readonly array $metadata,
        public readonly ?string $correlationReference,
    ) {
    }

    /**
     * @param array{
     *     source: string,
     *     reason?: ?string,
     *     actor_user_id?: ?int,
     *     provider?: ?string,
     *     provider_event_id?: ?string,
     *     provider_subscription_id?: ?string,
     *     occurred_at?: ?CarbonImmutable,
     *     effective_at?: ?CarbonImmutable,
     *     metadata?: array,
     *     correlation_reference?: ?string,
     * } $attributes
     */
    public static function make(array $attributes): self
    {
        $source = $attributes['source'] ?? null;

        if (!is_string($source) || !TransitionSource::isValid($source)) {
            throw new InvalidArgumentException(
                'TransitionContext requires a valid source from App\Support\Billing\TransitionSource, got: '
                . var_export($source, true)
            );
        }

        return new self(
            source: $source,
            reason: $attributes['reason'] ?? null,
            actorUserId: $attributes['actor_user_id'] ?? null,
            provider: $attributes['provider'] ?? null,
            providerEventId: $attributes['provider_event_id'] ?? null,
            providerSubscriptionId: $attributes['provider_subscription_id'] ?? null,
            occurredAt: $attributes['occurred_at'] ?? CarbonImmutable::now(),
            effectiveAt: $attributes['effective_at'] ?? null,
            metadata: $attributes['metadata'] ?? [],
            correlationReference: $attributes['correlation_reference'] ?? null,
        );
    }

    /**
     * Concise, non-sensitive metadata suitable for ActivityLog — never a
     * raw provider payload (see the class docblock).
     */
    public function toLogMetadata(): array
    {
        return array_filter([
            'source' => $this->source,
            'reason' => $this->reason,
            'actor_user_id' => $this->actorUserId,
            'provider' => $this->provider,
            'provider_event_id' => $this->providerEventId,
            'provider_subscription_id' => $this->providerSubscriptionId,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'effective_at' => $this->effectiveAt?->toIso8601String(),
            'correlation_reference' => $this->correlationReference,
        ], fn ($value) => $value !== null);
    }
}
