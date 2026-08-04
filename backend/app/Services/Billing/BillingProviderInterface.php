<?php

namespace App\Services\Billing;

use App\Support\Billing\OneOffCheckoutRequest;

/**
 * The one boundary between SureSign's billing services and whatever payment
 * provider is actually configured. Implementations (StripeBillingProvider,
 * FakeBillingProvider) may create/retrieve provider objects and verify
 * webhook signatures — nothing more.
 *
 * An implementation of this interface must NEVER decide:
 *   - whether an organisation has platform access
 *   - whether a subscription should be manually suspended
 *   - which features are entitled
 *   - whether a lifecycle transition is valid
 *   - when a customer notification should be sent
 * Those all belong to SureSign's own services (SubscriptionService,
 * SubscriptionAccessService, EntitlementService — Phase 5+), which are the
 * only things that call an implementation of this interface, never the
 * other way around.
 *
 * Every method returns/accepts plain associative arrays rather than
 * provider-specific SDK objects, so nothing outside the provider
 * implementation itself ever touches a \Stripe\* class directly — matching
 * how ClaudeAiProvider/EmailNotificationService already keep their vendor
 * SDK usage contained to one class.
 */
interface BillingProviderInterface
{
    /**
     * Consultancy Live Booking Upgrade, Stage 3 — one-off (`mode: payment`)
     * Checkout using Stripe's inline `price_data`, for a commercial item
     * with no pre-registered Product/Price (a Consultancy service's price
     * is a plain admin-editable value, not a versioned Stripe Price —
     * see App\Support\Billing\OneOffCheckoutRequest's own docblock for why
     * this is additive to, not a replacement for, createCheckoutSession()
     * above). Automatic tax is always disabled — no approved tax policy
     * exists yet (see App\Support\Consultancy\ConsultancyTaxTreatment).
     * Payment methods are deliberately restricted to card (Apple Pay/
     * Google Pay ride on the card payment method type automatically) —
     * no delayed-notification or bank-transfer methods.
     *
     * @return array{id: string, url: string, expires_at: ?int, status: string, livemode: bool}
     */
    public function createOneOffCheckoutSession(OneOffCheckoutRequest $request): array;

    /**
     * Whether this provider instance is currently configured for the
     * provider's live/production mode, with no network call — derived from
     * local configuration (StripeBillingProvider) or an explicit test
     * fixture (FakeBillingProvider). Services use this to refuse using a
     * mapping/customer whose stored `livemode` doesn't match the
     * environment actually running right now.
     */
    public function isLivemode(): bool;

    /**
     * @param array{email?: string, name?: string, organization_id: int, metadata?: array} $attributes
     * @return array{id: string, email: ?string, name: ?string, livemode: bool}
     */
    public function createCustomer(array $attributes): array;

    /**
     * @return array{id: string, email: ?string, name: ?string, livemode: bool}|null
     */
    public function retrieveCustomer(string $providerCustomerId): ?array;

    /**
     * Updates a subset of a provider customer's own fields (e.g. email,
     * name). Must not be used to update anything provider-managed the
     * caller doesn't own (payment methods, tax settings) — see
     * BillingCustomerService for which fields SureSign is allowed to push.
     *
     * @param array{email?: string, name?: string, metadata?: array} $attributes
     * @return array{id: string, email: ?string, name: ?string, livemode: bool}
     */
    public function updateCustomer(string $providerCustomerId, array $attributes): array;

    /**
     * `subscription_metadata`, when provided, is attached to Stripe's own
     * `subscription_data.metadata` — i.e. it ends up on the resulting
     * Stripe SUBSCRIPTION object itself, not just the Checkout Session.
     * This is what lets a future `customer.subscription.*` webhook event
     * carry trusted SureSign identifiers directly, without requiring the
     * webhook processor to infer correlation via a BillingCustomer mapping.
     * Deliberately a SEPARATE key from `metadata` (which stays
     * session-level only) since the two Stripe objects have independent
     * metadata dictionaries — an implementation may pass the same array
     * for both, but must never conflate the two Stripe API fields.
     *
     * @param array{
     *     customer_id: string,
     *     price_id: string,
     *     quantity: int,
     *     success_url: string,
     *     cancel_url: string,
     *     metadata?: array,
     *     subscription_metadata?: array,
     *     idempotency_key: string,
     * } $params
     * @return array{id: string, url: string, expires_at: ?int, status: string, livemode: bool}
     */
    public function createCheckoutSession(array $params): array;

    /**
     * @return array{id: string, status: string, customer_id: ?string, livemode: bool}|null
     */
    public function retrieveCheckoutSession(string $providerCheckoutSessionId): ?array;

    /**
     * Phase E4 — explicitly invalidates an in-flight Checkout Session at
     * the provider so a customer-initiated "Cancel Pending Checkout"
     * genuinely closes the Stripe-hosted page (not just SureSign's own
     * local record) — closes the residual window where a customer could
     * otherwise still complete payment via an old browser tab/bookmark
     * after cancelling locally. Best-effort from the caller's perspective:
     * CheckoutSessionService::cancelPendingCheckout() still proceeds with
     * the LOCAL cancellation even if this call fails (SureSign remains
     * authoritative for its own commercial state; this is cleanup, not a
     * precondition). Triggers a real `checkout.session.expired` webhook,
     * processed by the existing, unchanged `WebhookEventProcessor::processCheckoutExpired()`.
     *
     * @return array{id: string, status: string, customer_id: ?string, livemode: bool}
     */
    public function expireCheckoutSession(string $providerCheckoutSessionId): array;

    /**
     * @return array{url: string}
     */
    public function createPortalSession(string $providerCustomerId, string $returnUrl, ?string $configurationId = null): array;

    /**
     * Creates a Stripe Billing Portal Configuration — Slice E2's restricted
     * Portal capability policy is built entirely from this method's
     * `$attributes['features']`, never from the Stripe Dashboard's default
     * configuration. See BillingPortalService for the exact feature set.
     *
     * @param array{
     *     name?: string,
     *     business_profile?: array{headline?: string},
     *     default_return_url?: string,
     *     metadata?: array<string, string>,
     *     features: array<string, mixed>,
     * } $attributes
     * @return array{id: string, name: ?string, active: bool, is_default: bool, livemode: bool, metadata: array<string, string>, features: array<string, mixed>}
     */
    public function createPortalConfiguration(array $attributes): array;

    /**
     * @param array{active?: bool, limit?: int} $filters
     * @return array<int, array{id: string, name: ?string, active: bool, is_default: bool, livemode: bool, metadata: array<string, string>, features: array<string, mixed>}>
     */
    public function listPortalConfigurations(array $filters = []): array;

    /**
     * @return array{id: string, name: ?string, active: bool, is_default: bool, livemode: bool, metadata: array<string, string>, features: array<string, mixed>}
     */
    public function retrievePortalConfiguration(string $id): array;

    /**
     * current_period_start/current_period_end are read from the
     * subscription's primary item, not the subscription object itself —
     * see StripeBillingProvider::normalizeSubscription()'s docblock for why.
     * Callers (SubscriptionLifecycleService) only ever see this normalized
     * shape, never a raw provider object.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool}|null
     */
    public function retrieveSubscription(string $providerSubscriptionId): ?array;

    /**
     * ARCHITECTURE AUDIT WARNING (Billing Architecture Audit + Slice E1
     * checkpoint): `$atPeriodEnd = false` here calls Stripe's DELETE
     * subscription endpoint — genuine, immediate, irreversible
     * cancellation — it is NOT "undo a scheduled cancellation" despite
     * how the parameter name reads at a call site. Confirmed unused by
     * any domain service as of this checkpoint (only exercised by this
     * class's own unit test) — left in place for a possible future
     * immediate-cancellation feature, but do NOT use it to implement
     * "resume"/"undo" for a period-end-scheduled cancellation. Use
     * `scheduleCancellationAtPeriodEnd()`/`resumeSubscription()` below for
     * that — both always perform a plain subscription UPDATE, never a
     * delete.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool}
     */
    public function cancelSubscription(string $providerSubscriptionId, bool $atPeriodEnd = true): array;

    /**
     * Schedules cancellation at the current billing period's end — always
     * a subscription UPDATE (`cancel_at_period_end: true`), never a
     * delete. The counterpart `resumeSubscription()` below undoes this
     * (also an update, never a delete) — see this interface's warning on
     * `cancelSubscription()` for why that method must never be used for
     * either half of this pair. `$idempotencyKey` must be stable per
     * logical local request, exactly like `updateSubscriptionPrice()`'s
     * own contract.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool}
     */
    public function scheduleCancellationAtPeriodEnd(string $providerSubscriptionId, string $idempotencyKey): array;

    /**
     * Undoes a period-end-scheduled cancellation (`cancel_at_period_end:
     * false`) — a plain subscription update, never a delete, never a new
     * subscription. See `scheduleCancellationAtPeriodEnd()`'s docblock.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool}
     */
    public function resumeSubscription(string $providerSubscriptionId, string $idempotencyKey): array;

    /**
     * Stripe Test Mode Integration checkpoint — updates the subscription's
     * SINGLE recurring item to a new Price. Throws
     * `UnexpectedSubscriptionItemStructureException` if the provider
     * subscription does not have exactly one item (Part 11's explicit
     * invariant check — SureSign never does per-seat/multi-item billing).
     * `$prorationBehavior` is passed straight through to Stripe
     * ('create_prorations'|'none') — this class never calculates a
     * prorated amount itself. `$idempotencyKey` must be stable per logical
     * local operation (see `SubscriptionPlanChangeService`) so a retried
     * call can never apply the same Price change twice.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool}
     * @throws \App\Services\Billing\Exceptions\UnexpectedSubscriptionItemStructureException
     */
    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, string $prorationBehavior, string $idempotencyKey): array;

    /**
     * @return array<string, mixed>|null Normalized invoice data, or null if not found.
     */
    public function retrieveInvoice(string $providerInvoiceId): ?array;

    /**
     * Creates a provider Product — the catalogue-level container a Price
     * attaches to. Product metadata is for reconciliation only; SureSign's
     * own pricing_plans row remains the authoritative plan name/description
     * (see PlanPriceMappingService).
     *
     * @param array{name: string, metadata?: array} $attributes
     * @return array{id: string, name: string, livemode: bool}
     */
    public function createProduct(array $attributes): array;

    /**
     * @return array{id: string, name: string, livemode: bool}|null
     */
    public function retrieveProduct(string $providerProductId): ?array;

    /**
     * Creates a provider Price. Prices are immutable for amount/currency —
     * this method only ever creates a new Price object, never mutates one;
     * see PlanPriceMappingService for the supersession policy.
     *
     * @param array{
     *     product_id: string,
     *     unit_amount: int,
     *     currency: string,
     *     recurring_interval: string,
     *     metadata?: array,
     *     idempotency_key: string,
     * } $attributes
     * @return array{id: string, product_id: string, unit_amount: int, currency: string, active: bool, livemode: bool}
     */
    public function createPrice(array $attributes): array;

    /**
     * @return array{id: string, product_id: string, unit_amount: int, currency: string, active: bool, livemode: bool}|null
     */
    public function retrievePrice(string $providerPriceId): ?array;

    /**
     * Marks a Price inactive so it can no longer be selected for a NEW
     * Checkout Session, without deleting it — any existing subscription
     * already referencing this Price by ID is entirely unaffected. This is
     * the provider-level half of "superseding" a mapping; the local
     * pricing_plan_provider_prices row's own is_active flag is the other
     * half (see PlanPriceMappingService).
     *
     * @return array{id: string, active: bool}
     */
    public function deactivatePrice(string $providerPriceId): array;

    /**
     * Verifies a webhook payload's signature and returns the decoded event
     * as a plain array. Must throw on an invalid/missing signature —
     * callers never proceed to process an unverified payload.
     *
     * @throws \App\Services\Billing\Exceptions\InvalidWebhookSignatureException
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $webhookSecret): array;

    /**
     * Normalizes a `data.object` payload from an already-VERIFIED
     * `customer.subscription.*` webhook event into the same
     * provider-independent shape `retrieveSubscription()` returns (plus a
     * few extra fields a webhook processor also needs for correlation —
     * `price_id`/`product_id`/`metadata`/`cancelled_at`/`ended_at`), so
     * App\Services\Billing\WebhookEventProcessor never touches a raw Stripe
     * array shape directly. Deliberately takes a plain array (the webhook
     * payload has already been decoded/verified by this point), never a
     * \Stripe\Event or \Stripe\Subscription object.
     *
     * current_period_start/current_period_end are read from the
     * subscription's primary item, not the subscription object itself —
     * see StripeBillingProvider::normalizeSubscriptionArray()'s docblock,
     * which is also what retrieveSubscription() delegates to, so this exact
     * period-field logic is never duplicated between the two call paths.
     *
     * @return array{id: string, status: string, customer_id: ?string, cancel_at_period_end: bool, current_period_start: ?int, current_period_end: ?int, trial_end: ?int, livemode: bool, price_id: ?string, product_id: ?string, cancelled_at: ?int, ended_at: ?int, metadata: array}
     */
    public function normalizeSubscriptionFromWebhookPayload(array $subscriptionObject): array;

    /**
     * Normalizes a `data.object` payload from an already-VERIFIED
     * `checkout.session.*` webhook event into a provider-independent shape.
     * Same "plain array in, plain array out" boundary as
     * normalizeSubscriptionFromWebhookPayload() above.
     *
     * @return array{id: string, status: ?string, customer_id: ?string, subscription_id: ?string, livemode: bool, amount_total: ?int, currency: ?string, metadata: array}
     */
    public function normalizeCheckoutSessionFromWebhookPayload(array $checkoutSessionObject): array;

    /**
     * Normalizes a `data.object` payload from an already-VERIFIED
     * `invoice.paid`/`invoice.payment_failed` webhook event — Stripe Test
     * Mode Integration checkpoint, Part 18/19's invoice/payment-history
     * foundation. Same "plain array in, plain array out" boundary as the
     * other `normalize*FromWebhookPayload()` methods — `InvoiceSyncService`
     * never touches a raw Stripe array shape directly.
     *
     * `number` is Stripe's OWN invoice number (the one printed on the
     * hosted invoice page/PDF) — a pure passthrough, never generated
     * locally; distinct from `BillingInvoice::$invoice_number`, which is
     * SureSign's own internal correlation reference (see
     * `BillingReferenceService`). Phase E3 finance-readiness review: never
     * conflate the two in a UI label — an accountant reconciling against
     * the actual Stripe document needs this field, not SureSign's own.
     *
     * @return array{id: string, number: ?string, status: string, customer_id: ?string, subscription_id: ?string, livemode: bool, currency: string, subtotal: ?int, tax: ?int, total: ?int, amount_due: ?int, amount_paid: ?int, amount_remaining: ?int, hosted_invoice_url: ?string, invoice_pdf: ?string, billing_reason: ?string, period_start: ?int, period_end: ?int, due_date: ?int, payment_intent_id: ?string, metadata: array}
     */
    public function normalizeInvoiceFromWebhookPayload(array $invoiceObject): array;
}
