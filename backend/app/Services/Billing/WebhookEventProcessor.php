<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingWebhookEvent;
use App\Models\Subscription;
use App\Services\Billing\Exceptions\CheckoutSessionLifecycleConflictException;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionActivationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\SubscriptionStatusMapper;
use App\Support\Billing\SubscriptionTransitions;
use App\Support\Billing\TransitionSource;
use App\Support\Billing\WebhookProcessingErrorCode;
use App\Support\Billing\WebhookProcessingStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Interprets an already-verified, already-persisted `billing_webhook_events`
 * ledger row and applies the (narrow, explicitly supported) local effects a
 * Checkout/subscription lifecycle event implies. This is the ONLY place a
 * webhook event's business meaning is decided — the HTTP controller and
 * WebhookIngestionService never do, and never will (see their own
 * docblocks). This class never accepts a raw HTTP body, a Stripe signature,
 * or an unverified array — only a persisted `BillingWebhookEvent` (or its
 * ID), which by construction has already passed WebhookIngestionService's
 * signature/livemode verification.
 *
 * ─── Claim matrix (see assessClaimability()) ─────────────────────────────
 *
 *   received   → claimable
 *   processing → NOT claimable UNLESS its claim lease has expired (see
 *                isAbandonedClaim()/PROCESSING_LEASE_MINUTES) — an
 *                abandoned claim becomes reclaimable; a genuinely
 *                still-running one cannot be double-claimed regardless
 *                (see isAbandonedClaim()'s own docblock for why)
 *   processed  → NOT claimable — returns an idempotent "already processed" result
 *   ignored    → NOT claimable — returns an idempotent "already ignored" result
 *   conflict   → NEVER claimable — requires manual investigation (see below)
 *   failed     → claimable ONLY if `retryable = true`; a non-retryable
 *                failure is treated like conflict (manual review required)
 *
 * ─── Claiming and locking strategy ────────────────────────────────────────
 *
 * A SINGLE database transaction: `SELECT ... FOR UPDATE` the ledger row,
 * decide claimability, promote to `processing` + increment `attempt_count`,
 * run the explicit handler, persist the final outcome, commit. This is safe
 * specifically because nothing in this class (or the services it calls —
 * SubscriptionLifecycleService, CheckoutSessionLifecycleService) ever makes
 * an external provider API call: every input needed is already sitting in
 * `payload_json`, and every correlation/mutation is a local database
 * operation. A two-phase claim/finalize split exists specifically to keep
 * lock duration short when a provider call sits in between; since none
 * occurs here, keeping one transaction open for the whole operation is
 * simpler and equally safe. A concurrent second call to process() the same
 * row blocks on the row lock until the first commits, then observes the
 * now-terminal status and returns the same idempotent result — so two
 * processors can never both invoke a business action for one event.
 *
 * ─── Explicit dispatch only ───────────────────────────────────────────────
 *
 * `dispatch()` is a plain match() over `event_type` — no reflection, no
 * dynamic method construction, no class name taken from the payload.
 * Supported events: `checkout.session.completed`, `checkout.session.expired`,
 * `customer.subscription.created`, `customer.subscription.updated`,
 * `customer.subscription.deleted`. Every other valid, verified event type
 * (`invoice.*`, `payment_intent.*`, `charge.*`, `refund.*`, `customer.*`,
 * `entitlements.*`, etc.) is deliberately `ignored`, never `failed` — an
 * unsupported event is not an error.
 *
 * ─── Livemode isolation ───────────────────────────────────────────────────
 *
 * A ledger row's own `livemode` always already matches the application's
 * configured mode (WebhookIngestionService guarantees this before a row is
 * ever persisted). This class additionally requires the CORRELATED local
 * record's own `livemode` to match — a Subscription/BillingCheckoutSession
 * whose `livemode` disagrees is never mutated; the event becomes `conflict`
 * (an unsafe local correlation), never a silent no-op and never a guess.
 *
 * ─── Stale-event handling ─────────────────────────────────────────────────
 *
 * Before calling any subscription lifecycle transition, this class compares
 * the event's own `provider_created_at` against
 * `subscriptions.last_transition_occurred_at` itself (the exact comparison
 * SubscriptionLifecycleService::assertNotStale() also performs internally)
 * and short-circuits to `ignored` ("safely obsolete") without ever calling
 * the lifecycle service, rather than letting the shared
 * SubscriptionLifecycleConflictException surface and trying to distinguish
 * "stale" from a genuine identity conflict by matching its message text.
 * Any SubscriptionLifecycleConflictException that still reaches this class
 * after that pre-check therefore represents a genuine conflict, not
 * staleness, and is mapped to `conflict` accordingly.
 *
 * ─── Correlation (strengthened in the Subscription Event Hardening
 * checkpoint) ──────────────────────────────────────────────────────────────
 *
 * Checkout events correlate via `billing_checkout_sessions.provider_checkout_session_id`
 * (never a success-URL query parameter), then validate: livemode,
 * organisation, the session's own `suresign_subscription_id` metadata
 * against the linked subscription, the linked subscription's
 * `BillingCustomer.provider_customer_id` against the event's `customer_id`,
 * pricing plan, billing interval, and amount/currency (a surrogate for
 * provider-Price validation — see validateCheckoutCorrelation()'s docblock
 * for why the actual Price ID isn't available without an extra,
 * out-of-scope Stripe API call) — any mismatch is `conflict`, never a
 * best-effort match.
 *
 * Subscription events correlate in this exact order (see
 * correlateForCreated()/correlateForUpdateOrDelete()):
 *   1. `subscriptions.provider_subscription_id` — set once this
 *      subscription has ever been linked before.
 *   2. Trusted Checkout metadata (`suresign_subscription_id`) — now the
 *      PRIMARY path for a genuinely new subscription, since
 *      `CheckoutSessionService` propagates `subscription_data.metadata`
 *      onto the Stripe Subscription object itself (added this checkpoint;
 *      see `CheckoutSessionService::checkoutMetadata()`).
 *   3. `BillingCustomer` mapping (`provider_customer_id` + `livemode`,
 *      requiring EXACTLY ONE matching local subscription) — now the
 *      EXCEPTIONAL fallback, reached only for a Checkout Session created
 *      BEFORE this checkpoint shipped (no metadata was ever attached to
 *      its resulting subscription), or a provider subscription that never
 *      originated from SureSign's own Checkout at all.
 * Whichever step resolves a candidate, validateTrustedIdentifiers() then
 * independently re-validates every trusted identifier present in the
 * event's own metadata (organisation, subscription ID, livemode) against
 * that candidate — metadata strengthens correlation but never, by itself,
 * authorizes a transition; every identifier is still cross-checked against
 * local state.
 *
 * ─── Documented lifecycle gaps (mapped to `conflict`, never guessed) ─────
 *
 * `SubscriptionLifecycleService::markIncomplete()` (added this checkpoint)
 * closes the `incomplete` gap — see its own docblock. One real gap
 * remains, confirmed by inspection of `SubscriptionLifecycleService`'s
 * public API: Stripe status `paused` — `SubscriptionTransitions::MAP`
 * never lists `PAUSED` as a valid destination from any status at all, and
 * no method sets it. This is a deliberate commercial-policy decision, not
 * an oversight: Stripe's `paused` only ever occurs when a trial ends
 * without a payment method attached, and SureSign's current commercial
 * model has no equivalent concept to map it onto (`suspended` is a
 * distinct, deliberate SureSign-only decision — see
 * `SubscriptionStatus`'s docblock — never something Stripe's own state
 * should silently trigger). This class continues routing `paused` to
 * `conflict` for manual review rather than inventing a mapping — see the
 * final report for the commercial decisions required before `paused`
 * could be supported.
 */
class WebhookEventProcessor
{
    /**
     * A `processing` row whose `processing_started_at` is older than this
     * many minutes is treated as an abandoned claim (the process that set
     * it never reached finalize()) and becomes reclaimable — see
     * assessClaimability(). Under this class's own single-transaction
     * design (see class docblock), a row genuinely CANNOT be left
     * durably at `processing` by a normal `process()` call crashing,
     * since the promotion to `processing` and the final outcome commit
     * together or not at all. This lease exists as a safety net for the
     * one scenario that design doesn't cover: a future caller (a queued
     * job, a differently-structured invocation) that doesn't preserve the
     * single-transaction invariant, or a genuinely orphaned database
     * session. 15 minutes is comfortably longer than any processing path
     * this class implements could plausibly take (no external API calls
     * ever occur mid-processing — see class docblock), so a legitimate
     * still-running claim is never prematurely reclaimed in practice.
     */
    // Public so App\Console\Commands\RecoverBillingWebhookEvents references
    // this exact value rather than duplicating the literal — one source
    // of truth for "how long is a processing claim valid."
    public const PROCESSING_LEASE_MINUTES = 15;

    private const SUPPORTED_EVENT_TYPES = [
        'checkout.session.completed',
        'checkout.session.expired',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly SubscriptionLifecycleService $lifecycleService,
        private readonly CheckoutSessionLifecycleService $checkoutLifecycleService,
        private readonly SubscriptionPlanChangeService $planChangeService,
        private readonly InvoiceSyncService $invoiceSyncService,
    ) {
    }

    public function process(BillingWebhookEvent|int $event): WebhookProcessingResult
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
                Log::error('Unhandled exception while processing a billing webhook event', [
                    'billing_webhook_event_id' => $locked->id,
                    'provider_event_id' => $locked->provider_event_id,
                    'event_type' => $locked->event_type,
                    'exception_class' => get_class($e),
                ]);

                $result = WebhookProcessingResult::failed(
                    $locked->id,
                    $locked->provider_event_id,
                    $locked->event_type,
                    'unexpected_exception',
                    true,
                    WebhookProcessingErrorCode::INTERNAL_ERROR,
                );
            }

            $this->finalize($locked, $result);

            return $result;
        });
    }

    // ─── Claiming ─────────────────────────────────────────────────────────

    private function assessClaimability(BillingWebhookEvent $event): ?WebhookProcessingResult
    {
        return match ($event->processing_status) {
            WebhookProcessingStatus::RECEIVED => null,
            WebhookProcessingStatus::FAILED => $event->retryable === true
                ? null
                : WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, WebhookProcessingStatus::FAILED, 'not_claimable_non_retryable_failure'),
            WebhookProcessingStatus::PROCESSING => $this->isAbandonedClaim($event)
                ? null
                : WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, WebhookProcessingStatus::PROCESSING, 'not_claimable_already_processing'),
            WebhookProcessingStatus::PROCESSED => WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, WebhookProcessingStatus::PROCESSED, 'already_processed'),
            WebhookProcessingStatus::IGNORED => WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, WebhookProcessingStatus::IGNORED, 'already_ignored'),
            WebhookProcessingStatus::CONFLICT => WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, WebhookProcessingStatus::CONFLICT, 'not_claimable_conflict_requires_manual_review'),
            default => WebhookProcessingResult::notClaimable($event->id, $event->provider_event_id, $event->event_type, $event->processing_status, 'not_claimable_unknown_status'),
        };
    }

    /**
     * A `processing` row is reclaimable once its lease has expired — see
     * the PROCESSING_LEASE_MINUTES docblock. `processing_started_at` being
     * null while the status is `processing` (should not happen for any row
     * this class itself wrote, but defensively treated as abandoned rather
     * than permanently stuck) also counts as abandoned.
     *
     * Double-claiming remains impossible regardless of this lease: the
     * caller has already acquired `SELECT ... FOR UPDATE` on this exact
     * row (see process()) before this method is ever consulted, so a
     * genuinely still-running processor holding that lock would block this
     * call entirely until its own transaction ends — this method only ever
     * runs once the lock has actually been acquired, i.e. once any prior
     * claimant's transaction has already concluded one way or another.
     */
    private function isAbandonedClaim(BillingWebhookEvent $event): bool
    {
        if ($event->processing_started_at === null) {
            return true;
        }

        return CarbonImmutable::instance($event->processing_started_at)
            ->addMinutes(self::PROCESSING_LEASE_MINUTES)
            ->isPast();
    }

    // ─── Explicit dispatch ────────────────────────────────────────────────

    private function dispatch(BillingWebhookEvent $event): WebhookProcessingResult
    {
        if ($event->provider_created_at === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_provider_created_at', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        if (!in_array($event->event_type, self::SUPPORTED_EVENT_TYPES, true)) {
            return WebhookProcessingResult::ignored($event->id, $event->provider_event_id, $event->event_type, 'ignored_unsupported_event_type');
        }

        $payloadObject = $event->payload_json['data']['object'] ?? null;

        if (!is_array($payloadObject)) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_data_object', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        // `provider_created_at` is cast to Illuminate\Support\Carbon by the
        // model — every downstream signature (TransitionContext,
        // CheckoutSessionLifecycleService) is strictly typed against
        // Carbon\CarbonImmutable (matching TransitionContext's own
        // immutability guarantee), so it is normalized to that type exactly
        // once here rather than at each call site.
        $occurredAt = CarbonImmutable::instance($event->provider_created_at);

        return match ($event->event_type) {
            'checkout.session.completed' => $this->processCheckoutCompleted($event, $payloadObject, $occurredAt),
            'checkout.session.expired' => $this->processCheckoutExpired($event, $payloadObject, $occurredAt),
            'customer.subscription.created' => $this->processSubscriptionCreated($event, $payloadObject, $occurredAt),
            'customer.subscription.updated' => $this->processSubscriptionUpdated($event, $payloadObject, $occurredAt),
            'customer.subscription.deleted' => $this->processSubscriptionDeleted($event, $payloadObject, $occurredAt),
            'invoice.paid' => $this->processInvoicePaid($event, $payloadObject, $occurredAt),
            'invoice.payment_failed' => $this->processInvoicePaymentFailed($event, $payloadObject, $occurredAt),
        };
    }

    // ─── checkout.session.completed ──────────────────────────────────────

    private function processCheckoutCompleted(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeCheckoutSessionFromWebhookPayload($payloadObject);

        $checkoutSession = BillingCheckoutSession::query()
            ->where('provider', $event->provider)
            ->where('provider_checkout_session_id', $normalized['id'])
            ->first();

        if ($checkoutSession === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_session_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        $subscription = $checkoutSession->subscription;

        if ($subscription === null) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_session_has_no_linked_subscription', WebhookProcessingErrorCode::MISSING_LOCAL_SUBSCRIPTION,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        $mismatch = $this->validateCheckoutCorrelation($event, $normalized, $checkoutSession, $subscription, checkCommercials: true);
        if ($mismatch !== null) {
            return $mismatch;
        }

        try {
            $this->checkoutLifecycleService->markCompleted($checkoutSession, $occurredAt, $event->provider_event_id);
        } catch (CheckoutSessionLifecycleConflictException $e) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_session_transition_rejected', WebhookProcessingErrorCode::UNSUPPORTED_TRANSITION,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        // Deliberately does NOT activate the subscription — see class
        // docblock / this checkpoint's report on why Checkout completion
        // alone never implies successful payment. If Stripe already
        // reports a provider subscription ID here, the forthcoming
        // customer.subscription.created/updated event is what actually
        // establishes/activates it via SubscriptionLifecycleService.
        return WebhookProcessingResult::processed(
            $event->id, $event->provider_event_id, $event->event_type,
            'checkout_marked_completed',
            ['checkout_session_id' => $checkoutSession->id, 'subscription_id' => $subscription->id],
        );
    }

    // ─── checkout.session.expired ────────────────────────────────────────

    private function processCheckoutExpired(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeCheckoutSessionFromWebhookPayload($payloadObject);

        $checkoutSession = BillingCheckoutSession::query()
            ->where('provider', $event->provider)
            ->where('provider_checkout_session_id', $normalized['id'])
            ->first();

        if ($checkoutSession === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_session_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        $subscription = $checkoutSession->subscription;

        if ($subscription !== null) {
            $mismatch = $this->validateCheckoutCorrelation($event, $normalized, $checkoutSession, $subscription, checkCommercials: false);
            if ($mismatch !== null) {
                return $mismatch;
            }
        }

        try {
            $this->checkoutLifecycleService->markExpired($checkoutSession, $event->provider_event_id);
        } catch (CheckoutSessionLifecycleConflictException $e) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_session_transition_rejected', WebhookProcessingErrorCode::UNSUPPORTED_TRANSITION,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        // The linked subscription (if any) is left completely untouched —
        // it remains in its historical draft/pending_payment state. A new
        // Checkout attempt creates a new session; nothing here cancels or
        // expires the subscription itself.
        return WebhookProcessingResult::processed(
            $event->id, $event->provider_event_id, $event->event_type,
            'checkout_marked_expired',
            ['checkout_session_id' => $checkoutSession->id],
        );
    }

    private function validateCheckoutCorrelation(
        BillingWebhookEvent $event,
        array $normalized,
        BillingCheckoutSession $checkoutSession,
        Subscription $subscription,
        bool $checkCommercials,
    ): ?WebhookProcessingResult {
        if ($subscription->livemode !== $event->livemode) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        $metadata = $normalized['metadata'] ?? [];

        if ((string) $checkoutSession->organization_id !== (string) ($metadata['suresign_organization_id'] ?? null)) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_organisation_mismatch', WebhookProcessingErrorCode::ORGANISATION_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        // Session-level metadata's suresign_subscription_id — set by
        // CheckoutSessionService::checkoutMetadata() — must agree with the
        // subscription the local BillingCheckoutSession row is ALREADY
        // linked to. Never silently trust one source over the other.
        if (isset($metadata['suresign_subscription_id']) && (string) $subscription->id !== (string) $metadata['suresign_subscription_id']) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_subscription_metadata_mismatch', WebhookProcessingErrorCode::AMBIGUOUS_CORRELATION,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        // Provider customer — the Checkout Session's own `customer` must
        // match the BillingCustomer already linked to this subscription.
        // Stripe's Checkout Session payload always carries a plain
        // customer ID string (never expanded) for our created sessions.
        $billingCustomer = $subscription->billingCustomer;

        if ($billingCustomer !== null && $normalized['customer_id'] !== null && $billingCustomer->provider_customer_id !== $normalized['customer_id']) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_provider_customer_mismatch', WebhookProcessingErrorCode::ORGANISATION_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        if (!$checkCommercials) {
            return null;
        }

        if ((string) $checkoutSession->pricing_plan_id !== (string) ($metadata['suresign_pricing_plan_id'] ?? null)) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_pricing_plan_mismatch', WebhookProcessingErrorCode::COMMERCIAL_SNAPSHOT_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        if (($metadata['suresign_billing_interval'] ?? null) !== $checkoutSession->billing_interval) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_billing_interval_mismatch', WebhookProcessingErrorCode::COMMERCIAL_SNAPSHOT_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        if ($normalized['amount_total'] !== null && (int) $normalized['amount_total'] !== (int) $checkoutSession->amount) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_amount_mismatch', WebhookProcessingErrorCode::COMMERCIAL_SNAPSHOT_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        if ($normalized['currency'] !== null && $normalized['currency'] !== $checkoutSession->currency) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'checkout_currency_mismatch', WebhookProcessingErrorCode::COMMERCIAL_SNAPSHOT_MISMATCH,
                ['checkout_session_id' => $checkoutSession->id],
            );
        }

        return null;
    }

    // ─── customer.subscription.created ───────────────────────────────────

    private function processSubscriptionCreated(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeSubscriptionFromWebhookPayload($payloadObject);

        if (empty($normalized['id'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_provider_subscription_id', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        $correlated = $this->correlateForCreated($event, $normalized);
        if ($correlated instanceof WebhookProcessingResult) {
            return $correlated;
        }

        $subscription = $correlated;

        $trustedIdentifierMismatch = $this->validateTrustedIdentifiers($event, $subscription, $normalized);
        if ($trustedIdentifierMismatch !== null) {
            return $trustedIdentifierMismatch;
        }

        $commercialMismatch = $this->validateCommercialSnapshot($event, $subscription, $normalized);
        if ($commercialMismatch !== null) {
            return $commercialMismatch;
        }

        if ($this->isStale($subscription, $occurredAt)) {
            return WebhookProcessingResult::ignored(
                $event->id, $event->provider_event_id, $event->event_type,
                'stale_event_ignored', ['subscription_id' => $subscription->id],
            );
        }

        if (!SubscriptionStatusMapper::isKnownStripeStatus($normalized['status'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'unrecognised_provider_status', false, WebhookProcessingErrorCode::UNRECOGNISED_PROVIDER_STATUS,
                ['subscription_id' => $subscription->id],
            );
        }

        $mapped = SubscriptionStatusMapper::fromStripeStatus($normalized['status']);
        $context = $this->buildContext($event, $occurredAt, $normalized['id']);

        try {
            if ($mapped === SubscriptionStatus::ACTIVE) {
                $this->lifecycleService->activate($subscription, $normalized, $context);

                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_activated', ['subscription_id' => $subscription->id],
                );
            }

            if ($mapped === SubscriptionStatus::TRIALING && $subscription->status === SubscriptionStatus::DRAFT) {
                if (empty($normalized['trial_end'])) {
                    return WebhookProcessingResult::failed(
                        $event->id, $event->provider_event_id, $event->event_type,
                        'missing_trial_end', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
                        ['subscription_id' => $subscription->id],
                    );
                }

                $this->lifecycleService->startTrial($subscription, CarbonImmutable::createFromTimestampUTC($normalized['trial_end']), $context);

                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_trial_started', ['subscription_id' => $subscription->id],
                );
            }

            if ($mapped === SubscriptionStatus::INCOMPLETE) {
                $this->lifecycleService->markIncomplete($subscription, $normalized, $context);

                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_marked_incomplete', ['subscription_id' => $subscription->id],
                );
            }
        } catch (SubscriptionActivationException|SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            return $this->conflictFromLifecycleException($event, $e, ['subscription_id' => $subscription->id]);
        }

        // Documented gap — see class docblock: `paused` (and
        // any other raw status a brand-new subscription is not expected to
        // start in) have no safe named lifecycle method to reach from here.
        return WebhookProcessingResult::conflict(
            $event->id, $event->provider_event_id, $event->event_type,
            'unsupported_initial_provider_status:' . $normalized['status'], WebhookProcessingErrorCode::UNSUPPORTED_TRANSITION,
            ['subscription_id' => $subscription->id],
        );
    }

    /**
     * Correlation order, exactly as required (see class docblock's
     * "Correlation" section):
     *
     *   1. `provider_subscription_id` — set once this subscription has
     *      ever been linked before (redelivery / a later event for an
     *      already-known subscription).
     *   2. Trusted Checkout metadata (`suresign_subscription_id`, present
     *      on every subscription created via a Checkout Session that
     *      propagated `subscription_data.metadata` — see
     *      `CheckoutSessionService::checkoutMetadata()`). This is now the
     *      PRIMARY path for a genuinely brand-new subscription, since
     *      `provider_subscription_id` is by definition not yet populated
     *      for one.
     *   3. `BillingCustomer` mapping — the EXCEPTIONAL fallback, reached
     *      only when metadata is absent (a Checkout Session created before
     *      this checkpoint shipped, or a provider-side subscription never
     *      originated from SureSign's own Checkout at all).
     *
     * Metadata NEVER authorizes a transition by itself — whichever
     * candidate this method returns is still independently re-validated by
     * validateTrustedIdentifiers() (organisation/subscription-id/livemode)
     * immediately after, and by validateCommercialSnapshot() (provider
     * Price) before any lifecycle call.
     *
     * @return Subscription|WebhookProcessingResult
     */
    private function correlateForCreated(BillingWebhookEvent $event, array $normalized)
    {
        // 1. provider_subscription_id
        $existing = Subscription::query()
            ->where('provider', $event->provider)
            ->where('provider_subscription_id', $normalized['id'])
            ->first();

        if ($existing !== null) {
            if ($existing->livemode !== $event->livemode) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                    ['subscription_id' => $existing->id],
                );
            }

            return $existing;
        }

        // 2. Trusted metadata
        $metadataSubscriptionId = $normalized['metadata']['suresign_subscription_id'] ?? null;

        if (is_string($metadataSubscriptionId) && $metadataSubscriptionId !== '' && ctype_digit($metadataSubscriptionId)) {
            $byMetadata = Subscription::query()->find((int) $metadataSubscriptionId);

            if ($byMetadata === null) {
                // Trusted metadata identifying a subscription that does not
                // exist locally at all is never "not yet visible" (the
                // metadata was generated FROM that exact local row at
                // Checkout time — it cannot legitimately predate it) — a
                // genuine integrity problem, not a retryable delay.
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'metadata_subscription_not_found_locally', WebhookProcessingErrorCode::MISSING_LOCAL_SUBSCRIPTION,
                );
            }

            if ($byMetadata->livemode !== $event->livemode) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                    ['subscription_id' => $byMetadata->id],
                );
            }

            if ($byMetadata->provider_subscription_id !== null && $byMetadata->provider_subscription_id !== $normalized['id']) {
                // Never attach a provider subscription to a different
                // local subscription merely because metadata "looks"
                // plausible — the local row is already linked elsewhere.
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'metadata_subscription_already_linked_elsewhere', WebhookProcessingErrorCode::PROVIDER_IDENTITY_CONFLICT,
                    ['subscription_id' => $byMetadata->id],
                );
            }

            return $byMetadata;
        }

        // 3. BillingCustomer fallback — exceptional path (see docblock).
        $billingCustomer = BillingCustomer::query()
            ->where('provider', $event->provider)
            ->where('provider_customer_id', $normalized['customer_id'])
            ->where('livemode', $event->livemode)
            ->first();

        if ($billingCustomer === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'billing_customer_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        $candidates = Subscription::query()
            ->where('billing_customer_id', $billingCustomer->id)
            ->where('livemode', $event->livemode)
            ->where('status', SubscriptionStatus::PENDING_PAYMENT)
            ->get();

        if ($candidates->count() === 0) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'pending_subscription_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        if ($candidates->count() > 1) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'ambiguous_pending_subscription_correlation', WebhookProcessingErrorCode::AMBIGUOUS_CORRELATION,
            );
        }

        return $candidates->first();
    }

    // ─── customer.subscription.updated ───────────────────────────────────

    private function processSubscriptionUpdated(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeSubscriptionFromWebhookPayload($payloadObject);

        if (empty($normalized['id'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_provider_subscription_id', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        $correlated = $this->correlateForUpdateOrDelete($event, $normalized);
        if ($correlated instanceof WebhookProcessingResult) {
            return $correlated;
        }

        $subscription = $correlated;

        $trustedIdentifierMismatch = $this->validateTrustedIdentifiers($event, $subscription, $normalized);
        if ($trustedIdentifierMismatch !== null) {
            return $trustedIdentifierMismatch;
        }

        $commercialMismatch = $this->validateCommercialSnapshot($event, $subscription, $normalized);
        if ($commercialMismatch !== null) {
            return $commercialMismatch;
        }

        if ($this->isStale($subscription, $occurredAt)) {
            return WebhookProcessingResult::ignored(
                $event->id, $event->provider_event_id, $event->event_type,
                'stale_event_ignored', ['subscription_id' => $subscription->id],
            );
        }

        if (!SubscriptionStatusMapper::isKnownStripeStatus($normalized['status'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'unrecognised_provider_status', false, WebhookProcessingErrorCode::UNRECOGNISED_PROVIDER_STATUS,
                ['subscription_id' => $subscription->id],
            );
        }

        $mapped = SubscriptionStatusMapper::fromStripeStatus($normalized['status']);
        $context = $this->buildContext($event, $occurredAt, $normalized['id']);
        $affected = ['subscription_id' => $subscription->id];

        try {
            if ($mapped === $subscription->status) {
                // A pure refresh — status hasn't changed, but period dates /
                // cancel_at_period_end may have (e.g. Stripe recording a
                // scheduled cancel_at_period_end on an otherwise still-ACTIVE
                // subscription), or (Stripe Test Mode Integration checkpoint)
                // the reported Price may confirm a pending plan change.
                $cancelAtPeriodEndBefore = $subscription->cancel_at_period_end;

                $this->lifecycleService->recordProviderState($subscription, $normalized, $context);

                // Billing Architecture Audit + Slice E1 checkpoint —
                // recordProviderState() already syncs cancel_at_period_end
                // unconditionally (correct, no behaviour change here); this
                // only gives the resulting audit/processing action a
                // specific, human-legible label instead of the generic
                // "provider state recorded" whenever that flag is what
                // actually changed, so cancellation scheduling/undo has its
                // own visible confirmation in the log — never creates a
                // snapshot, never touches status.
                $cancelAtPeriodEndAfter = $normalized['cancel_at_period_end'] ?? $cancelAtPeriodEndBefore;
                $cancellationAction = match (true) {
                    $cancelAtPeriodEndAfter && !$cancelAtPeriodEndBefore => 'subscription_cancellation_confirmed_by_provider',
                    !$cancelAtPeriodEndAfter && $cancelAtPeriodEndBefore => 'subscription_cancellation_undo_confirmed_by_provider',
                    default => null,
                };

                $planChangeAction = $this->reconcilePlanChangeIfPending($subscription, $normalized, $context);

                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    $planChangeAction ?? $cancellationAction ?? 'subscription_provider_state_recorded', $affected,
                );
            }

            $action = match ($mapped) {
                SubscriptionStatus::ACTIVE => $this->applyActiveTransition($subscription, $normalized, $context),
                SubscriptionStatus::PAST_DUE => $this->applyPastDueTransition($subscription, $context),
                SubscriptionStatus::UNPAID => $this->applyUnpaidTransition($subscription, $context),
                SubscriptionStatus::CANCELLED => $this->applyCancelledTransition($subscription, $normalized['status'], $context),
                SubscriptionStatus::TRIALING => $this->applyTrialingTransition($subscription, $normalized, $context),
                SubscriptionStatus::INCOMPLETE => $this->applyIncompleteTransition($subscription, $normalized, $context),
                SubscriptionStatus::EXPIRED => $this->applyExpiredTransition($subscription, $context),
                default => null,
            };

            if ($action === null) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'unsupported_updated_provider_status:' . $normalized['status'], WebhookProcessingErrorCode::UNSUPPORTED_TRANSITION,
                    $affected,
                );
            }

            return WebhookProcessingResult::processed($event->id, $event->provider_event_id, $event->event_type, $action, $affected);
        } catch (SubscriptionActivationException|SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            return $this->conflictFromLifecycleException($event, $e, $affected);
        }
    }

    /**
     * Stripe Test Mode Integration checkpoint, Part 16 — reconciles a
     * pending `BillingPlanChange` (state `sent`/`confirmed`) against the
     * Price a verified webhook actually reports. Only ever called from the
     * "pure refresh" branch above (status unchanged) — a plan change never
     * changes `status`, so this is the only path it can be confirmed from.
     *
     * Reported Price matches the pending change's target → confirm and
     * apply (Part 16's "provider confirmed" flow). Reported Price does
     * NOT match → provider drift: never silently touched, never mapped by
     * amount/name — logged and left for `billing:stripe:reconcile` to
     * surface explicitly (Part 23's "unknown Price" / "conflict" policy).
     * No pending change at all → nothing to do, returns null.
     */
    private function reconcilePlanChangeIfPending(Subscription $subscription, array $normalized, TransitionContext $context): ?string
    {
        $pending = $this->planChangeService->pendingFor($subscription);

        if ($pending === null || !in_array($pending->state, [\App\Support\Billing\PlanChangeState::SENT, \App\Support\Billing\PlanChangeState::CONFIRMED], true)) {
            return null;
        }

        $reportedPriceId = $normalized['price_id'] ?? null;
        $targetPriceId = $pending->targetPriceMapping?->provider_price_id;

        if ($reportedPriceId === null || $targetPriceId === null || $reportedPriceId !== $targetPriceId) {
            Log::warning('WebhookEventProcessor: provider Price does not match the pending plan change target — provider drift, not silently applied.', [
                'subscription_id' => $subscription->id,
                'plan_change_id' => $pending->id,
                'reported_price_id' => $reportedPriceId,
                'expected_price_id' => $targetPriceId,
            ]);

            return 'plan_change_provider_drift_detected';
        }

        $this->planChangeService->confirmFromProvider($pending, $context);

        return 'plan_change_confirmed_and_applied';
    }

    private function applyActiveTransition(Subscription $subscription, array $normalized, TransitionContext $context): ?string
    {
        if (in_array($subscription->status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::UNPAID, SubscriptionStatus::SUSPENDED], true)) {
            $this->lifecycleService->restoreToActive($subscription, $context);

            return 'subscription_restored_to_active';
        }

        if (in_array($subscription->status, [SubscriptionStatus::PENDING_PAYMENT, SubscriptionStatus::INCOMPLETE, SubscriptionStatus::TRIALING], true)) {
            $this->lifecycleService->activate($subscription, $normalized, $context);

            return 'subscription_activated';
        }

        return null;
    }

    private function applyPastDueTransition(Subscription $subscription, TransitionContext $context): ?string
    {
        if ($subscription->status !== SubscriptionStatus::ACTIVE) {
            return null;
        }

        $this->lifecycleService->markPastDue($subscription, $context);

        return 'subscription_marked_past_due';
    }

    private function applyUnpaidTransition(Subscription $subscription, TransitionContext $context): ?string
    {
        if ($subscription->status !== SubscriptionStatus::PAST_DUE) {
            return null;
        }

        $this->lifecycleService->markUnpaid($subscription, $context);

        return 'subscription_marked_unpaid';
    }

    private function applyCancelledTransition(Subscription $subscription, string $rawStatus, TransitionContext $context): ?string
    {
        if ($subscription->status === SubscriptionStatus::ACTIVE && $subscription->cancel_at_period_end) {
            $this->lifecycleService->confirmCancellation($subscription, $context);

            return 'subscription_cancellation_confirmed';
        }

        if (!SubscriptionTransitions::canTransition($subscription->status, SubscriptionStatus::CANCELLED)) {
            return null;
        }

        $this->lifecycleService->cancelImmediately($subscription, "Stripe reported the subscription as \"{$rawStatus}\"", $context);

        return 'subscription_cancelled_immediately';
    }

    private function applyTrialingTransition(Subscription $subscription, array $normalized, TransitionContext $context): ?string
    {
        if ($subscription->status !== SubscriptionStatus::DRAFT || empty($normalized['trial_end'])) {
            return null;
        }

        $this->lifecycleService->startTrial($subscription, CarbonImmutable::createFromTimestampUTC($normalized['trial_end']), $context);

        return 'subscription_trial_started';
    }

    /**
     * Valid only from `pending_payment` — matches
     * `SubscriptionTransitions::MAP`. A Stripe `incomplete` update arriving
     * for a subscription in any other local status indicates a
     * correlation problem, not a legitimate lifecycle path; this returns
     * null (→ conflict) rather than guessing.
     */
    private function applyIncompleteTransition(Subscription $subscription, array $normalized, TransitionContext $context): ?string
    {
        if ($subscription->status !== SubscriptionStatus::PENDING_PAYMENT) {
            return null;
        }

        $this->lifecycleService->markIncomplete($subscription, $normalized, $context);

        return 'subscription_marked_incomplete';
    }

    /**
     * Reached when Stripe reports `incomplete_expired` (mapped to
     * `SubscriptionStatus::EXPIRED` — see SubscriptionStatusMapper's
     * docblock). Valid from `pending_payment` or `incomplete` per
     * `SubscriptionTransitions::MAP`; any other current status is a
     * correlation problem, not guessed.
     */
    private function applyExpiredTransition(Subscription $subscription, TransitionContext $context): ?string
    {
        if (!SubscriptionTransitions::canTransition($subscription->status, SubscriptionStatus::EXPIRED)) {
            return null;
        }

        $this->lifecycleService->expire($subscription, $context);

        return 'subscription_expired';
    }

    // ─── customer.subscription.deleted ───────────────────────────────────

    private function processSubscriptionDeleted(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeSubscriptionFromWebhookPayload($payloadObject);

        if (empty($normalized['id'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_provider_subscription_id', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        $correlated = $this->correlateForUpdateOrDelete($event, $normalized);
        if ($correlated instanceof WebhookProcessingResult) {
            return $correlated;
        }

        $subscription = $correlated;
        $affected = ['subscription_id' => $subscription->id];

        $trustedIdentifierMismatch = $this->validateTrustedIdentifiers($event, $subscription, $normalized);
        if ($trustedIdentifierMismatch !== null) {
            return $trustedIdentifierMismatch;
        }

        if ($this->isStale($subscription, $occurredAt)) {
            return WebhookProcessingResult::ignored(
                $event->id, $event->provider_event_id, $event->event_type,
                'stale_event_ignored', $affected,
            );
        }

        $context = $this->buildContext($event, $occurredAt, $normalized['id']);

        try {
            if ($subscription->status === SubscriptionStatus::CANCELLED) {
                // Idempotent — already cancelled (e.g. a previously-processed
                // customer.subscription.updated already applied it).
                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_already_cancelled', $affected,
                );
            }

            if ($subscription->status === SubscriptionStatus::ACTIVE && $subscription->cancel_at_period_end) {
                $this->lifecycleService->confirmCancellation($subscription, $context);

                return WebhookProcessingResult::processed(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_cancellation_confirmed', $affected,
                );
            }

            $this->lifecycleService->cancelImmediately($subscription, 'Stripe reported the subscription as deleted', $context);

            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'subscription_cancelled_immediately', $affected,
            );
        } catch (SubscriptionActivationException|SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            return $this->conflictFromLifecycleException($event, $e, $affected);
        }
    }

    // ─── invoice.paid / invoice.payment_failed ───────────────────────────

    /**
     * Stripe Test Mode Integration checkpoint, Part 17/18/19 — persists the
     * invoice/payment record (`InvoiceSyncService`, idempotent via existing
     * unique provider-id constraints) and, if the subscription is currently
     * `past_due`/`unpaid`, calls the existing `restoreToActive()` recovery
     * path. A `suspended` subscription is deliberately NOT auto-restored by
     * a paid invoice — suspension is "a deliberate business decision,
     * separate from raw payment failure" (existing `suspend()` docblock);
     * recovering from it always requires an explicit operator action,
     * never an automatic side effect of a payment event.
     */
    private function processInvoicePaid(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeInvoiceFromWebhookPayload($payloadObject);

        $correlated = $this->correlateInvoiceSubscription($event, $normalized);
        if ($correlated instanceof WebhookProcessingResult) {
            return $correlated;
        }

        $subscription = $correlated;
        $affected = ['subscription_id' => $subscription->id, 'provider_invoice_id' => $normalized['id']];

        $this->invoiceSyncService->syncFromWebhook($normalized, $subscription);

        if (!in_array($subscription->status, [SubscriptionStatus::PAST_DUE, SubscriptionStatus::UNPAID], true)) {
            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced', $affected,
            );
        }

        if ($this->isStale($subscription, $occurredAt)) {
            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced_stale_recovery_ignored', $affected,
            );
        }

        try {
            $this->lifecycleService->restoreToActive($subscription, $this->buildContext($event, $occurredAt, $normalized['subscription_id']));

            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced_and_subscription_restored', $affected,
            );
        } catch (SubscriptionActivationException|SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            return $this->conflictFromLifecycleException($event, $e, $affected);
        }
    }

    /**
     * Only `active` transitions to `past_due` on a payment failure — an
     * already `past_due`/`unpaid`/`suspended` subscription's repeated
     * failure is a safe no-op past this point (the invoice is still
     * synced regardless, so the failure is always visible in
     * `billing_invoices`).
     */
    private function processInvoicePaymentFailed(BillingWebhookEvent $event, array $payloadObject, CarbonImmutable $occurredAt): WebhookProcessingResult
    {
        $normalized = $this->provider->normalizeInvoiceFromWebhookPayload($payloadObject);

        $correlated = $this->correlateInvoiceSubscription($event, $normalized);
        if ($correlated instanceof WebhookProcessingResult) {
            return $correlated;
        }

        $subscription = $correlated;
        $affected = ['subscription_id' => $subscription->id, 'provider_invoice_id' => $normalized['id']];

        $this->invoiceSyncService->syncFromWebhook($normalized, $subscription);

        if ($subscription->status !== SubscriptionStatus::ACTIVE) {
            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced', $affected,
            );
        }

        if ($this->isStale($subscription, $occurredAt)) {
            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced_stale_transition_ignored', $affected,
            );
        }

        try {
            $this->lifecycleService->markPastDue($subscription, $this->buildContext($event, $occurredAt, $normalized['subscription_id']));

            return WebhookProcessingResult::processed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_synced_and_subscription_marked_past_due', $affected,
            );
        } catch (SubscriptionActivationException|SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            return $this->conflictFromLifecycleException($event, $e, $affected);
        }
    }

    /**
     * Invoice correlation is narrower than subscription correlation
     * (Part 16): an invoice always names its subscription directly
     * (`normalized['subscription_id']`) — no trusted-metadata fallback is
     * needed or attempted, since Stripe invoices don't carry SureSign's
     * own metadata the way a Checkout Session/Subscription does.
     *
     * @return Subscription|WebhookProcessingResult
     */
    private function correlateInvoiceSubscription(BillingWebhookEvent $event, array $normalized)
    {
        if (empty($normalized['id']) || empty($normalized['subscription_id'])) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'missing_provider_invoice_or_subscription_id', false, WebhookProcessingErrorCode::MALFORMED_PAYLOAD,
            );
        }

        $subscription = Subscription::query()
            ->where('provider', $event->provider)
            ->where('provider_subscription_id', $normalized['subscription_id'])
            ->first();

        if ($subscription === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_subscription_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        if ($subscription->livemode !== $event->livemode) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'invoice_subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                ['subscription_id' => $subscription->id],
            );
        }

        return $subscription;
    }

    // ─── Shared correlation/validation helpers ────────────────────────────

    /**
     * Same three-step order as correlateForCreated() — see that method's
     * docblock. For `.updated`/`.deleted` the metadata fallback additionally
     * covers genuine Stripe out-of-order delivery (an update/delete arriving
     * before `.created` has ever been processed), on top of the
     * pre-checkpoint-6 Checkout Sessions that never got trusted metadata.
     *
     * @return Subscription|WebhookProcessingResult
     */
    private function correlateForUpdateOrDelete(BillingWebhookEvent $event, array $normalized)
    {
        // 1. provider_subscription_id
        $existing = Subscription::query()
            ->where('provider', $event->provider)
            ->where('provider_subscription_id', $normalized['id'])
            ->first();

        if ($existing !== null) {
            if ($existing->livemode !== $event->livemode) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                    ['subscription_id' => $existing->id],
                );
            }

            return $existing;
        }

        // 2. Trusted metadata
        $metadataSubscriptionId = $normalized['metadata']['suresign_subscription_id'] ?? null;

        if (is_string($metadataSubscriptionId) && $metadataSubscriptionId !== '' && ctype_digit($metadataSubscriptionId)) {
            $byMetadata = Subscription::query()->find((int) $metadataSubscriptionId);

            if ($byMetadata === null) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'metadata_subscription_not_found_locally', WebhookProcessingErrorCode::MISSING_LOCAL_SUBSCRIPTION,
                );
            }

            if ($byMetadata->livemode !== $event->livemode) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                    ['subscription_id' => $byMetadata->id],
                );
            }

            if ($byMetadata->provider_subscription_id !== null && $byMetadata->provider_subscription_id !== $normalized['id']) {
                return WebhookProcessingResult::conflict(
                    $event->id, $event->provider_event_id, $event->event_type,
                    'metadata_subscription_already_linked_elsewhere', WebhookProcessingErrorCode::PROVIDER_IDENTITY_CONFLICT,
                    ['subscription_id' => $byMetadata->id],
                );
            }

            return $byMetadata;
        }

        // 3. BillingCustomer fallback — exceptional path (see docblock).
        // Out-of-order delivery: created hasn't been processed yet. Same
        // BillingCustomer-mapping fallback as correlateForCreated(), scoped
        // to statuses that plausibly haven't been linked to a provider
        // subscription ID yet.
        $billingCustomer = BillingCustomer::query()
            ->where('provider', $event->provider)
            ->where('provider_customer_id', $normalized['customer_id'])
            ->where('livemode', $event->livemode)
            ->first();

        if ($billingCustomer === null) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'billing_customer_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        $candidates = Subscription::query()
            ->where('billing_customer_id', $billingCustomer->id)
            ->where('livemode', $event->livemode)
            ->whereIn('status', [SubscriptionStatus::PENDING_PAYMENT, SubscriptionStatus::INCOMPLETE])
            ->get();

        if ($candidates->count() === 0) {
            return WebhookProcessingResult::failed(
                $event->id, $event->provider_event_id, $event->event_type,
                'subscription_not_found_locally', true, WebhookProcessingErrorCode::CORRELATION_NOT_FOUND,
            );
        }

        if ($candidates->count() > 1) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'ambiguous_subscription_correlation', WebhookProcessingErrorCode::AMBIGUOUS_CORRELATION,
            );
        }

        return $candidates->first();
    }

    /**
     * The independent-validation half of "metadata strengthens correlation
     * but never replaces it" — run immediately after correlateForCreated()/
     * correlateForUpdateOrDelete() resolve a candidate, REGARDLESS of which
     * of the three correlation steps actually found it. Every trusted
     * identifier present in the event's own metadata must agree with the
     * resolved local record; any disagreement is `conflict`, never a
     * silent pick of "whichever source looks more convenient."
     */
    private function validateTrustedIdentifiers(BillingWebhookEvent $event, Subscription $subscription, array $normalized): ?WebhookProcessingResult
    {
        $metadata = $normalized['metadata'] ?? [];

        if (isset($metadata['suresign_organization_id']) && (string) $subscription->organization_id !== (string) $metadata['suresign_organization_id']) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'trusted_metadata_organisation_mismatch', WebhookProcessingErrorCode::ORGANISATION_MISMATCH,
                ['subscription_id' => $subscription->id],
            );
        }

        if (isset($metadata['suresign_subscription_id']) && $metadata['suresign_subscription_id'] !== '' && (string) $subscription->id !== (string) $metadata['suresign_subscription_id']) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'trusted_metadata_subscription_id_mismatch', WebhookProcessingErrorCode::AMBIGUOUS_CORRELATION,
                ['subscription_id' => $subscription->id],
            );
        }

        if (($normalized['livemode'] ?? null) !== null && $normalized['livemode'] !== $subscription->livemode) {
            return WebhookProcessingResult::conflict(
                $event->id, $event->provider_event_id, $event->event_type,
                'subscription_livemode_mismatch', WebhookProcessingErrorCode::LIVEMODE_MISMATCH,
                ['subscription_id' => $subscription->id],
            );
        }

        return null;
    }

    /**
     * Defends against a subscription/updated event reporting a provider
     * Price different from the one SureSign currently has on record
     * (`subscriptions.provider_price_id`) — an unexpected plan/Price
     * change is `conflict`, never silently applied.
     *
     * ONE deliberate exception (Stripe Test Mode Integration checkpoint,
     * Part 16): if the reported Price is exactly the TARGET of a pending
     * `BillingPlanChange` (`sent`/`confirmed`) for this subscription, this
     * is not a mismatch to reject — it's the confirmation
     * `reconcilePlanChangeIfPending()` is waiting for. Any other mismatch
     * (no pending change, or a price matching neither the stored one nor
     * a pending target) still conflicts exactly as before — this is
     * itself the "unknown Price"/provider-drift safeguard (Part 23):
     * never silently overwritten, never mapped by amount/name, always
     * surfaced for manual reconciliation.
     */
    private function validateCommercialSnapshot(BillingWebhookEvent $event, Subscription $subscription, array $normalized): ?WebhookProcessingResult
    {
        if ($subscription->provider_price_id === null || empty($normalized['price_id']) || $subscription->provider_price_id === $normalized['price_id']) {
            return null;
        }

        $pendingPlanChange = $this->planChangeService->pendingFor($subscription);
        $expectedTargetPriceId = $pendingPlanChange?->targetPriceMapping?->provider_price_id;

        if ($expectedTargetPriceId !== null && $expectedTargetPriceId === $normalized['price_id']) {
            return null;
        }

        return WebhookProcessingResult::conflict(
            $event->id, $event->provider_event_id, $event->event_type,
            'subscription_price_mismatch', WebhookProcessingErrorCode::COMMERCIAL_SNAPSHOT_MISMATCH,
            ['subscription_id' => $subscription->id],
        );
    }

    private function isStale(Subscription $subscription, CarbonImmutable $occurredAt): bool
    {
        return $subscription->last_transition_occurred_at !== null
            && $occurredAt->lt($subscription->last_transition_occurred_at);
    }

    private function buildContext(BillingWebhookEvent $event, CarbonImmutable $occurredAt, ?string $providerSubscriptionId): TransitionContext
    {
        return TransitionContext::make([
            'source' => TransitionSource::VERIFIED_WEBHOOK,
            'provider' => $event->provider,
            'provider_event_id' => $event->provider_event_id,
            'provider_subscription_id' => $providerSubscriptionId,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function conflictFromLifecycleException(BillingWebhookEvent $event, \Throwable $e, array $affected): WebhookProcessingResult
    {
        $errorCode = match (true) {
            $e instanceof SubscriptionActivationException => WebhookProcessingErrorCode::PROVIDER_IDENTITY_CONFLICT,
            $e instanceof InvalidSubscriptionTransitionException => WebhookProcessingErrorCode::UNSUPPORTED_TRANSITION,
            default => WebhookProcessingErrorCode::LIFECYCLE_REJECTED,
        };

        return WebhookProcessingResult::conflict(
            $event->id, $event->provider_event_id, $event->event_type,
            'lifecycle_transition_rejected', $errorCode, $affected,
        );
    }

    // ─── Finalization ─────────────────────────────────────────────────────

    private function finalize(BillingWebhookEvent $event, WebhookProcessingResult $result): void
    {
        $now = CarbonImmutable::now();

        match ($result->status) {
            WebhookProcessingStatus::PROCESSED => $event->update([
                'processing_status' => WebhookProcessingStatus::PROCESSED,
                'processed_at' => $now,
                'processing_started_at' => null,
                'failed_at' => null,
                'failure_message' => null,
                'retryable' => null,
            ]),
            WebhookProcessingStatus::IGNORED => $event->update([
                'processing_status' => WebhookProcessingStatus::IGNORED,
                'processed_at' => $now,
                'processing_started_at' => null,
            ]),
            WebhookProcessingStatus::FAILED => $event->update([
                'processing_status' => WebhookProcessingStatus::FAILED,
                'failed_at' => $now,
                'processing_started_at' => null,
                'failure_message' => $this->sanitizeFailureMessage($result),
                'retryable' => $result->retryable ?? false,
            ]),
            WebhookProcessingStatus::CONFLICT => $event->update([
                'processing_status' => WebhookProcessingStatus::CONFLICT,
                'failed_at' => $now,
                'processing_started_at' => null,
                'failure_message' => $this->sanitizeFailureMessage($result),
                'retryable' => false,
            ]),
            default => null,
        };

        $this->logOutcome($event, $result);
    }

    private function sanitizeFailureMessage(WebhookProcessingResult $result): string
    {
        // Stable identifiers only — never a raw exception message, never a
        // stack trace, never payload content. See class docblock.
        return mb_substr("{$result->action} ({$result->errorCode})", 0, 250);
    }

    private function logOutcome(BillingWebhookEvent $event, WebhookProcessingResult $result): void
    {
        $action = match ($result->status) {
            WebhookProcessingStatus::PROCESSED => 'billing.webhook.processed',
            WebhookProcessingStatus::IGNORED => 'billing.webhook.ignored',
            WebhookProcessingStatus::FAILED => 'billing.webhook.failed',
            WebhookProcessingStatus::CONFLICT => 'billing.webhook.conflict',
            default => null,
        };

        if ($action === null) {
            return;
        }

        ActivityLog::record(
            action: $action,
            description: "Webhook event {$event->event_type} ({$event->provider_event_id}): {$result->action}",
            subject: $event,
            meta: array_filter([
                'provider_event_id' => $event->provider_event_id,
                'event_type' => $event->event_type,
                'processing_action' => $result->action,
                'error_code' => $result->errorCode,
                'affected_records' => $result->affectedRecords ?: null,
            ], fn ($value) => $value !== null),
        );

        if ($result->status === WebhookProcessingStatus::CONFLICT) {
            Log::warning('Billing webhook processing conflict — requires manual review', [
                'billing_webhook_event_id' => $event->id,
                'provider_event_id' => $event->provider_event_id,
                'event_type' => $event->event_type,
                'error_code' => $result->errorCode,
            ]);
        }
    }
}
