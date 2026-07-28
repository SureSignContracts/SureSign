<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Rules\SafeUrl;
use App\Services\Billing\Exceptions\CheckoutSessionLifecycleConflictException;
use App\Services\Billing\Exceptions\CheckoutValidationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Support\Billing\BillingReferenceType;
use App\Support\Billing\CheckoutSessionStatus;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Thin orchestration layer preparing and creating a provider Checkout
 * Session — nothing more. This service owns none of the rules it
 * orchestrates:
 *
 *   - BillingCustomerService resolves/owns the Organisation-to-provider-
 *     Customer relationship.
 *   - PlanPriceMappingService resolves/owns the plan-to-provider-Price
 *     mapping.
 *   - SubscriptionLifecycleService owns the draft subscription and is the
 *     SOLE authoritative source of the "does this organisation already
 *     have a commercially conflicting subscription" rule — this class
 *     never re-implements or duplicates that check; it calls
 *     `createDraftSubscription()` and lets
 *     `SubscriptionLifecycleConflictException` propagate untouched if the
 *     organisation isn't eligible for a new one.
 *
 * This service NEVER activates a subscription, NEVER interprets a webhook
 * event, and NEVER enforces application access. Its responsibility ends
 * the moment the Checkout Session is created and persisted locally — a
 * completed provider Checkout Session still waits for a verified webhook
 * (a later, not-yet-built checkpoint) before anything commercial changes.
 */
class CheckoutSessionService
{
    private const SUPPORTED_INTERVALS = ['monthly', 'annual'];

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly BillingCustomerService $billingCustomerService,
        private readonly PlanPriceMappingService $planPriceMappingService,
        private readonly SubscriptionLifecycleService $lifecycleService,
        private readonly BillingReferenceService $referenceService,
        private readonly CheckoutSessionLifecycleService $checkoutLifecycleService,
    ) {
    }

    /**
     * A currently-reusable OPEN/CREATED, unexpired Checkout Session already
     * in flight for this exact organisation/plan/interval, if any — read-only,
     * never calls the provider. Callers (e.g. a Checkout controller) should
     * check this FIRST: a genuine double-click/two-tab/retry against an
     * already-`pending_payment` draft subscription would otherwise surface
     * as `SubscriptionLifecycleConflictException` from startCheckout() below
     * (correct for a genuinely conflicting DIFFERENT plan/subscription, but
     * unhelpful for the exact-same in-flight attempt this method exists to
     * detect). Deliberately does not consider currency/amount — those are
     * always server-resolved from the same plan/interval, so they cannot
     * differ between the original attempt and a retry.
     */
    public function findReusableCheckoutForPlan(Organization $organization, PricingPlan $plan, string $billingInterval): ?BillingCheckoutSession
    {
        return BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('pricing_plan_id', $plan->id)
            ->where('billing_interval', $billingInterval)
            ->whereIn('status', [CheckoutSessionStatus::CREATED, CheckoutSessionStatus::OPEN])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();
    }

    /**
     * Prepares and creates (or safely reuses) a provider Checkout Session
     * for an organisation/plan/interval — the full orchestration sequence
     * described in the class docblock. Idempotent and concurrency-safe:
     * see reuse/locking notes on resolveOrCreateCheckoutSession() below.
     *
     * @throws CheckoutValidationException
     * @throws \App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException
     */
    public function startCheckout(
        Organization $organization,
        PricingPlan $plan,
        string $billingInterval,
        string $currency,
        User $actor,
        string $successUrl,
        string $cancelUrl,
        ?string $correlationReference = null,
    ): BillingCheckoutSession {
        $this->assertPlanIsSellable($plan);
        $this->assertSupportedInterval($billingInterval);
        $currency = $this->normalizeCurrency($currency);

        // Only when there is no correlationReference — that path has its
        // OWN idempotent-reuse guarantee (createDraftSubscription() finds
        // and reuses the exact same subscription by reference, regardless
        // of that subscription's checkout session's own expiry) and must
        // never be pre-empted by this self-heal step. CheckoutController
        // (the real, only production caller) never passes one.
        if ($correlationReference === null) {
            $this->expireStaleAbandonedPendingCheckout($organization, $actor);
        }

        $this->assertSafeUrl($successUrl);
        $this->assertSafeUrl($cancelUrl);

        $priceMapping = $this->planPriceMappingService->resolveActivePrice($plan, $billingInterval, $currency);

        if ($priceMapping === null) {
            throw new CheckoutValidationException(
                "No active provider price mapping exists for plan \"{$plan->name}\" ({$billingInterval}, {$currency})."
            );
        }

        // Resolving/creating the BillingCustomer is the first provider-
        // reaching call — every commercial validation above happens
        // first, so an invalid request never even resolves a customer.
        $billingCustomer = $this->billingCustomerService->getOrCreate($organization, $actor);

        // SubscriptionLifecycleService remains the sole authority on the
        // conflicting-subscription invariant — its exception, if any,
        // propagates completely untouched.
        $context = TransitionContext::make([
            'source' => TransitionSource::CHECKOUT,
            'actor_user_id' => $actor->id,
        ]);

        $subscription = $this->lifecycleService->createDraftSubscription(
            $organization,
            $plan,
            $priceMapping,
            $billingInterval,
            $context,
            $correlationReference,
            $billingCustomer->id,
        );

        return $this->resolveOrCreateCheckoutSession($organization, $subscription, $plan, $priceMapping, $billingCustomer, $actor, $successUrl, $cancelUrl, $correlationReference, $context);
    }

    /**
     * Phase E4 — "do not trap the customer" self-heal. If the
     * organisation's most recent LIVE subscription is stuck in
     * `pending_payment` with no still-resumable (created/open, unexpired)
     * Checkout Session left, it is safe to auto-expire it before this
     * class attempts to start a fresh Checkout — nothing was ever
     * charged, and `SubscriptionLifecycleService::hasConflictingSubscription()`
     * would otherwise block a customer who simply closed an old Checkout
     * tab and never returned to it. Deliberately a no-op whenever a
     * still-resumable session exists — that case must surface
     * `startCheckout()`'s existing `SubscriptionLifecycleConflictException`
     * unchanged, so the frontend can prompt the customer to explicitly
     * continue or cancel it (see `cancelPendingCheckout()` below) rather
     * than this class silently discarding a still-valid attempt.
     */
    private function expireStaleAbandonedPendingCheckout(Organization $organization, User $actor): void
    {
        $pending = $organization->subscriptions()
            ->where('status', SubscriptionStatus::PENDING_PAYMENT)
            ->latest('id')
            ->first();

        if ($pending === null) {
            return;
        }

        $stillResumable = $pending->checkoutSessions()
            ->whereIn('status', [CheckoutSessionStatus::CREATED, CheckoutSessionStatus::OPEN])
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if ($stillResumable) {
            return;
        }

        $this->lifecycleService->expire($pending, TransitionContext::make([
            'source' => TransitionSource::CHECKOUT,
            'actor_user_id' => $actor->id,
        ]));
    }

    /**
     * Phase E4 — the explicit "Cancel Pending Checkout" customer action.
     * Only ever valid for a subscription currently `pending_payment`
     * (never touches an active/past_due/etc. subscription — those go
     * through SubscriptionCancellationService instead, which schedules a
     * period-end cancellation, a completely different commercial
     * operation). Best-effort invalidates the Stripe-side Checkout
     * Session (closing the residual "still-open browser tab" risk) and
     * ALWAYS marks the local session expired synchronously — this cannot
     * wait for the (real, still-fired) `checkout.session.expired` webhook,
     * since `findReusableCheckoutForPlan()` must stop offering this
     * session as reusable the instant this method returns, not once the
     * webhook eventually arrives. Then immediately cancels the
     * subscription itself via the existing, unchanged
     * `SubscriptionLifecycleService::cancelImmediately()` (a valid
     * `pending_payment -> cancelled` transition per
     * `SubscriptionTransitions::MAP`) — freeing the organisation to start
     * a new Checkout right away, without waiting on any webhook.
     *
     * @throws SubscriptionLifecycleConflictException if the subscription is not currently pending_payment
     */
    public function cancelPendingCheckout(Subscription $subscription, User $actor): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::PENDING_PAYMENT) {
            throw new SubscriptionLifecycleConflictException(
                "Subscription {$subscription->internal_reference} is not awaiting payment — nothing to cancel."
            );
        }

        $session = $subscription->checkoutSessions()
            ->whereIn('status', [CheckoutSessionStatus::CREATED, CheckoutSessionStatus::OPEN])
            ->latest('id')
            ->first();

        if ($session !== null) {
            try {
                $this->provider->expireCheckoutSession($session->provider_checkout_session_id);
            } catch (ApiErrorException $e) {
                // Best-effort only — SureSign's own local cancellation
                // below is what actually protects the customer/organisation;
                // a Stripe-side hiccup here must never block it. Logged for
                // operator visibility only.
                Log::warning('Stripe API error expiring a Checkout Session during customer-initiated cancellation', [
                    'organization_id' => $subscription->organization_id,
                    'subscription_id' => $subscription->id,
                    'checkout_session_id' => $session->id,
                    'stripe_error_type' => $e->getStripeCode(),
                ]);
            }

            try {
                $this->checkoutLifecycleService->markExpired($session, null);
            } catch (CheckoutSessionLifecycleConflictException) {
                // Already completed by a race with a genuine payment — leave
                // it alone; cancelImmediately() below will still refuse to
                // proceed if the subscription itself has since moved past
                // pending_payment (e.g. a concurrent webhook activated it).
            }
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::CUSTOMER_BILLING_ACTION,
            'actor_user_id' => $actor->id,
        ]);

        return $this->lifecycleService->cancelImmediately(
            $subscription->fresh(),
            'Customer cancelled pending checkout before completing payment',
            $context,
        );
    }

    /**
     * Reuses an existing OPEN, unexpired, commercially-matching checkout
     * session for this draft subscription, or creates a new provider
     * Checkout Session (and supersedes the old local record historically —
     * never deleting it) when none is reusable.
     *
     * Reuse criteria (ALL must hold): status is created/open, not expired,
     * same organisation, same subscription, same pricing plan, same
     * provider, same livemode, same billing interval/currency/amount. A
     * previously completed session is NEVER reusable — completion means
     * the provider-side flow already finished; a new attempt needs a new
     * session, not a reopened old one. Provider retrieval is NOT required
     * to decide reusability: an OPEN local record's own recorded expiry is
     * sufficient (Stripe Checkout Sessions do not silently change status
     * on their own before expiry outside of completion, which would have
     * already been reconciled by a future webhook checkpoint) — this
     * keeps the reuse decision fast and provider-call-free in the common
     * case, consistent with idempotent-reuse patterns already established
     * elsewhere (PlanPriceMappingService::syncPlanPrice()).
     */
    private function resolveOrCreateCheckoutSession(
        Organization $organization,
        Subscription $subscription,
        PricingPlan $plan,
        PricingPlanProviderPrice $priceMapping,
        BillingCustomer $billingCustomer,
        User $actor,
        string $successUrl,
        string $cancelUrl,
        ?string $correlationReference,
        TransitionContext $context,
    ): BillingCheckoutSession {
        $lock = Cache::lock("checkout-session:{$subscription->id}", 10);

        return $lock->block(5, function () use ($organization, $subscription, $plan, $priceMapping, $billingCustomer, $actor, $successUrl, $cancelUrl, $correlationReference, $context) {
            $reusable = BillingCheckoutSession::query()
                ->where('subscription_id', $subscription->id)
                ->whereIn('status', [CheckoutSessionStatus::CREATED, CheckoutSessionStatus::OPEN])
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->where('organization_id', $organization->id)
                ->where('pricing_plan_id', $plan->id)
                ->where('provider', $priceMapping->provider)
                ->where('billing_interval', $subscription->billing_interval)
                ->where('currency', $subscription->currency)
                ->where('amount', $subscription->unit_amount)
                ->latest('id')
                ->first();

            if ($reusable !== null) {
                // markPendingPayment() is a safe no-op if the subscription
                // is already pending_payment from the original attempt —
                // the ONLY place provider_checkout_session_id is written
                // is inside SubscriptionLifecycleService, never a direct
                // field mutation here.
                $this->lifecycleService->markPendingPayment($subscription, $context, $reusable->provider_checkout_session_id);

                return $reusable;
            }

            $priorAttempts = BillingCheckoutSession::where('subscription_id', $subscription->id)->count();

            return DB::transaction(function () use ($organization, $subscription, $plan, $priceMapping, $billingCustomer, $actor, $successUrl, $cancelUrl, $correlationReference, $priorAttempts, $context) {
                $idempotencyKey = "checkout:{$subscription->internal_reference}:{$priorAttempts}";

                // Generated BEFORE the provider call (not inline inside
                // BillingCheckoutSession::create() as before) specifically so
                // the identical reference can also be included in
                // `subscription_data.metadata` below — a stable,
                // human-readable identifier a future webhook processor can
                // use to correlate a `customer.subscription.*` event back to
                // the exact Checkout attempt that produced it, without
                // requiring the local row to exist yet at the moment Stripe
                // is called.
                $checkoutReference = $this->referenceService->generate(BillingReferenceType::CHECKOUT);

                $providerSession = $this->provider->createCheckoutSession([
                    'customer_id' => $billingCustomer->provider_customer_id,
                    'price_id' => $priceMapping->provider_price_id,
                    'quantity' => 1,
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'metadata' => $this->checkoutMetadata($organization, $subscription, $billingCustomer, $plan, $priceMapping, $correlationReference, $checkoutReference),
                    // Propagated onto the Stripe SUBSCRIPTION object itself
                    // (not just the Checkout Session) — see
                    // StripeBillingProvider::createCheckoutSession()'s
                    // docblock. This is what lets WebhookEventProcessor
                    // correlate `customer.subscription.created` via trusted
                    // metadata as its primary path, rather than falling back
                    // to a BillingCustomer-mapping inference (see
                    // internal-docs/super-admin/subscription-billing.md).
                    // Deliberately the SAME identifier set as session-level
                    // `metadata` above — every value here is a stable
                    // internal ID/reference, never a price, card detail, or
                    // secret.
                    'subscription_metadata' => $this->checkoutMetadata($organization, $subscription, $billingCustomer, $plan, $priceMapping, $correlationReference, $checkoutReference),
                    'idempotency_key' => $idempotencyKey,
                ]);

                if (empty($providerSession['id']) || empty($providerSession['url'])) {
                    throw new CheckoutValidationException('Provider response was missing a required checkout session identifier or URL.');
                }

                $session = BillingCheckoutSession::create([
                    'organization_id' => $organization->id,
                    'subscription_id' => $subscription->id,
                    'pricing_plan_id' => $plan->id,
                    'initiated_by_user_id' => $actor->id,
                    'provider' => $priceMapping->provider,
                    'provider_checkout_session_id' => $providerSession['id'],
                    'checkout_url' => $providerSession['url'],
                    'internal_reference' => $checkoutReference,
                    'status' => $providerSession['status'] ?? CheckoutSessionStatus::OPEN,
                    'billing_interval' => $subscription->billing_interval,
                    'currency' => $subscription->currency,
                    'amount' => $subscription->unit_amount,
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'expires_at' => !empty($providerSession['expires_at']) ? CarbonImmutable::createFromTimestampUTC($providerSession['expires_at']) : null,
                    'metadata_json' => [
                        'billing_customer_id' => $billingCustomer->id,
                        'pricing_plan_provider_price_id' => $priceMapping->id,
                        'correlation_reference' => $correlationReference,
                    ],
                ]);

                // The ONLY write to the subscription itself — routed
                // entirely through SubscriptionLifecycleService, never a
                // direct field assignment. draft -> pending_payment is the
                // correct commercial reading of "a checkout session now
                // exists and the customer is being sent to pay."
                $this->lifecycleService->markPendingPayment($subscription, $context, $providerSession['id']);

                ActivityLog::record(
                    action: $priorAttempts > 0 ? 'checkout.recreated' : 'checkout.created',
                    description: $priorAttempts > 0
                        ? "Recreated checkout session for \"{$organization->name}\" ({$plan->name})"
                        : "Created checkout session for \"{$organization->name}\" ({$plan->name})",
                    user: $actor,
                    subject: $session,
                    organizationId: $organization->id,
                    meta: [
                        'subscription_reference' => $subscription->internal_reference,
                        'checkout_reference' => $session->internal_reference,
                        'provider_checkout_session_id' => $providerSession['id'],
                        'pricing_plan_id' => $plan->id,
                        'billing_interval' => $subscription->billing_interval,
                        'attempt' => $priorAttempts + 1,
                    ],
                );

                return $session;
            });
        });
    }

    /**
     * Stable, reconciliation-only identifiers — never entitlement
     * payloads, raw commercial JSON, pricing calculations, user emails, or
     * anything sensitive. Used BOTH as the Checkout Session's own
     * session-level `metadata` AND (identically) as the resulting Stripe
     * Subscription's `subscription_data.metadata` — see
     * resolveOrCreateCheckoutSession() above. `suresign_organization_id`
     * intentionally keeps this codebase's existing American spelling
     * (matching every other `organization_id` column/field already in use)
     * rather than introducing a second, differently-spelled key for the
     * same concept elsewhere in the metadata dictionary.
     */
    private function checkoutMetadata(
        Organization $organization,
        Subscription $subscription,
        BillingCustomer $billingCustomer,
        PricingPlan $plan,
        PricingPlanProviderPrice $priceMapping,
        ?string $correlationReference,
        ?string $checkoutReference = null,
    ): array {
        return array_filter([
            'suresign_organization_id' => (string) $organization->id,
            'suresign_subscription_id' => (string) $subscription->id,
            'suresign_subscription_reference' => $subscription->internal_reference,
            'suresign_checkout_session_id' => $checkoutReference,
            'suresign_billing_customer_id' => (string) $billingCustomer->id,
            'suresign_pricing_plan_id' => (string) $plan->id,
            'suresign_provider_price_mapping_id' => (string) $priceMapping->id,
            'suresign_billing_interval' => $subscription->billing_interval,
            'suresign_livemode' => $subscription->livemode ? 'true' : 'false',
            'suresign_correlation_reference' => $correlationReference,
        ], fn ($value) => $value !== null);
    }

    private function assertPlanIsSellable(PricingPlan $plan): void
    {
        if ($plan->status !== 'active') {
            throw new CheckoutValidationException("Pricing plan \"{$plan->name}\" is not currently available for sale.");
        }
    }

    private function assertSupportedInterval(string $billingInterval): void
    {
        if (!in_array($billingInterval, self::SUPPORTED_INTERVALS, true)) {
            throw new CheckoutValidationException("Unsupported billing interval: {$billingInterval}");
        }
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (strlen($currency) !== 3) {
            throw new CheckoutValidationException("Invalid currency code: {$currency}");
        }

        return $currency;
    }

    /**
     * Same rule App\Rules\SafeUrl enforces (relative path or https://),
     * PLUS one relaxation scoped to this method alone: http:// is also
     * accepted, but only in local/testing environments (same environment-
     * gated pattern as App\Support\Billing\BillingConfigGuard) — Stripe
     * Checkout's success_url/cancel_url must be a fully-qualified absolute
     * URL, and local dev's frontend has no TLS certificate. Never loosened
     * on the shared SafeUrl rule itself, which Pricing Management's own
     * CTA/link field validation also depends on for a strict guarantee
     * even under local testing.
     */
    private function assertSafeUrl(string $url): void
    {
        if ($url !== '' && app()->environment(['local', 'testing']) && preg_match('#^http://[^\s]+$#i', $url)) {
            return;
        }

        $failed = false;

        (new SafeUrl())->validate('url', $url, function () use (&$failed) {
            $failed = true;
        });

        if ($failed) {
            throw new CheckoutValidationException("Unsafe redirect URL: {$url}");
        }
    }
}
