<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Services\Billing\Exceptions\CheckoutSessionLifecycleConflictException;
use App\Support\Billing\CheckoutSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The sole authoritative path for the two permitted post-creation
 * `BillingCheckoutSession` transitions — `markCompleted()`/`markExpired()`
 * — mirroring how SubscriptionLifecycleService is the sole authoritative
 * path for Subscription mutations. Only App\Services\Billing\WebhookEventProcessor
 * calls this class; CheckoutSessionService (session creation) never does,
 * and nothing ever mutates `billing_checkout_sessions.status`/`completed_at`
 * directly outside this class.
 *
 * `completed` and `expired` are treated as mutually exclusive terminal
 * states: a real Stripe Checkout Session only ever produces ONE of
 * `checkout.session.completed` or `checkout.session.expired` for its
 * lifetime, never both — so a request to apply the "other" terminal state
 * on top of an already-terminal session is never a legitimate ordering
 * question (which one is "newer") and is refused outright as a conflict,
 * regardless of the incoming event's timestamp. This is deliberately
 * simpler than adding a last-provider-event timestamp column: it fully
 * satisfies "no overwrite of a completed session by an older expired event"
 * and vice versa without a schema change (see this checkpoint's report on
 * why no migration was needed for Checkout Session ordering).
 */
class CheckoutSessionLifecycleService
{
    /**
     * Idempotent: a session already `completed` is returned unchanged. A
     * session currently `expired` cannot become `completed` — see class
     * docblock.
     */
    public function markCompleted(BillingCheckoutSession $session, CarbonImmutable $occurredAt, ?string $providerEventId): BillingCheckoutSession
    {
        return DB::transaction(function () use ($session, $occurredAt, $providerEventId) {
            $locked = $this->lock($session);

            if ($locked->status === CheckoutSessionStatus::COMPLETED) {
                return $locked;
            }

            if ($locked->status === CheckoutSessionStatus::EXPIRED) {
                throw new CheckoutSessionLifecycleConflictException(
                    "Checkout session {$locked->internal_reference} is already expired; refusing to mark it completed."
                );
            }

            $locked->status = CheckoutSessionStatus::COMPLETED;
            $locked->completed_at = $occurredAt;
            $locked->save();

            $this->log($locked, 'billing.checkout.completed', 'Checkout session completed', $providerEventId);

            return $locked;
        });
    }

    /**
     * Idempotent: a session already `expired` is returned unchanged. A
     * session currently `completed` cannot become `expired` — see class
     * docblock. The underlying subscription is never touched here — it
     * remains in its historical draft/pending state (see this checkpoint's
     * report on `checkout.session.expired` behaviour).
     */
    public function markExpired(BillingCheckoutSession $session, ?string $providerEventId): BillingCheckoutSession
    {
        return DB::transaction(function () use ($session, $providerEventId) {
            $locked = $this->lock($session);

            if ($locked->status === CheckoutSessionStatus::EXPIRED) {
                return $locked;
            }

            if ($locked->status === CheckoutSessionStatus::COMPLETED) {
                throw new CheckoutSessionLifecycleConflictException(
                    "Checkout session {$locked->internal_reference} is already completed; refusing to mark it expired."
                );
            }

            $locked->status = CheckoutSessionStatus::EXPIRED;
            $locked->save();

            $this->log($locked, 'billing.checkout.expired', 'Checkout session expired', $providerEventId);

            return $locked;
        });
    }

    private function lock(BillingCheckoutSession $session): BillingCheckoutSession
    {
        return BillingCheckoutSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
    }

    private function log(BillingCheckoutSession $session, string $action, string $description, ?string $providerEventId): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            subject: $session,
            organizationId: $session->organization_id,
            meta: array_filter([
                'checkout_reference' => $session->internal_reference,
                'provider_checkout_session_id' => $session->provider_checkout_session_id,
                'provider_event_id' => $providerEventId,
            ], fn ($value) => $value !== null),
        );
    }
}
