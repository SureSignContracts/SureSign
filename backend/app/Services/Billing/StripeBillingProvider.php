<?php

namespace App\Services\Billing;

use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\Exceptions\UnexpectedSubscriptionItemStructureException;
use App\Support\Billing\OneOffCheckoutRequest;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Thin adapter around \Stripe\StripeClient — the official stripe-php SDK
 * used directly (no Laravel Cashier, see the Phase 0 architecture
 * decision). Every method here does exactly what its BillingProviderInterface
 * docblock says and nothing more: no access decisions, no entitlement
 * logic, no notifications. Business logic for those lives in
 * SubscriptionService/SubscriptionAccessService/EntitlementService
 * (Phase 5+), which call this class, never the reverse.
 */
class StripeBillingProvider implements BillingProviderInterface
{
    private StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        $this->client = $client ?? new StripeClient(array_filter([
            'api_key' => config('billing.stripe.secret'),
            'stripe_version' => config('billing.stripe.api_version'),
        ]));
    }

    public function isLivemode(): bool
    {
        return str_starts_with((string) config('billing.stripe.secret'), 'sk_live_');
    }

    public function createCustomer(array $attributes): array
    {
        $customer = $this->client->customers->create(array_filter([
            'email' => $attributes['email'] ?? null,
            'name' => $attributes['name'] ?? null,
            'metadata' => $attributes['metadata'] ?? [],
        ]));

        return $this->normalizeCustomer($customer);
    }

    public function retrieveCustomer(string $providerCustomerId): ?array
    {
        $customer = $this->client->customers->retrieve($providerCustomerId);

        if (!empty($customer->deleted)) {
            return null;
        }

        return $this->normalizeCustomer($customer);
    }

    public function updateCustomer(string $providerCustomerId, array $attributes): array
    {
        $customer = $this->client->customers->update($providerCustomerId, array_filter([
            'email' => $attributes['email'] ?? null,
            'name' => $attributes['name'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
        ], fn ($value) => $value !== null));

        return $this->normalizeCustomer($customer);
    }

    /**
     * `subscription_data.metadata` is Stripe's own mechanism for stamping
     * metadata onto the Subscription object a `mode: subscription` Checkout
     * Session produces — distinct from the top-level `metadata`, which only
     * ever attaches to the Checkout Session object itself. See
     * BillingProviderInterface::createCheckoutSession()'s docblock.
     */
    public function createCheckoutSession(array $params): array
    {
        $session = $this->client->checkout->sessions->create(
            array_filter([
                'mode' => 'subscription',
                'customer' => $params['customer_id'],
                'line_items' => [[
                    'price' => $params['price_id'],
                    'quantity' => $params['quantity'] ?? 1,
                ]],
                'success_url' => $params['success_url'],
                'cancel_url' => $params['cancel_url'],
                'metadata' => $params['metadata'] ?? [],
                'subscription_data' => !empty($params['subscription_metadata'])
                    ? ['metadata' => $params['subscription_metadata']]
                    : null,
                // This Stripe account has Managed Payments enabled by
                // default, which requires every line-item Product to carry
                // a tax_code — SureSign has no approved tax policy or tax
                // logic of any kind yet (see CLAUDE.md exclusions), so this
                // explicitly opts the session out rather than fabricating
                // one. Revisit only alongside a deliberate tax-handling
                // decision, not as an incidental Checkout fix.
                'managed_payments' => ['enabled' => false],
            ], fn ($value) => $value !== null),
            ['idempotency_key' => $params['idempotency_key']]
        );

        return [
            'id' => $session->id,
            'url' => $session->url,
            'expires_at' => $session->expires_at,
            'status' => $session->status,
            'livemode' => (bool) $session->livemode,
        ];
    }

    /**
     * Consultancy Live Booking Upgrade, Stage 3 — see
     * BillingProviderInterface::createOneOffCheckoutSession()'s docblock.
     * `payment_method_types: ['card']` is deliberate and exhaustive for the
     * approved launch scope — Stripe Checkout automatically offers Apple
     * Pay/Google Pay under the 'card' type when the visitor's browser/
     * device supports it (no separate payment method type needed for
     * either wallet), and no other payment method type is ever added here,
     * so no delayed-notification or bank-transfer method can silently
     * become available. `automatic_tax` is always disabled — see
     * App\Support\Consultancy\ConsultancyTaxTreatment.
     */
    public function createOneOffCheckoutSession(OneOffCheckoutRequest $request): array
    {
        $session = $this->client->checkout->sessions->create(
            array_filter([
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => array_filter([
                        'currency' => strtolower($request->currency),
                        'unit_amount' => $request->amountMinorUnits,
                        'product_data' => array_filter([
                            'name' => $request->productName,
                            'description' => $request->productDescription,
                        ], fn ($value) => $value !== null),
                    ], fn ($value) => $value !== null),
                    'quantity' => 1,
                ]],
                'success_url' => $request->successUrl,
                'cancel_url' => $request->cancelUrl,
                'expires_at' => $request->expiresAt->getTimestamp(),
                'metadata' => $request->metadata,
                'automatic_tax' => ['enabled' => false],
            ], fn ($value) => $value !== null),
            ['idempotency_key' => $request->idempotencyKey]
        );

        return [
            'id' => $session->id,
            'url' => $session->url,
            'expires_at' => $session->expires_at,
            'status' => $session->status,
            'livemode' => (bool) $session->livemode,
        ];
    }

    public function retrieveCheckoutSession(string $providerCheckoutSessionId): ?array
    {
        $session = $this->client->checkout->sessions->retrieve($providerCheckoutSessionId);

        return [
            'id' => $session->id,
            'status' => $session->status,
            'customer_id' => $session->customer,
            'livemode' => (bool) $session->livemode,
        ];
    }

    public function expireCheckoutSession(string $providerCheckoutSessionId): array
    {
        $session = $this->client->checkout->sessions->expire($providerCheckoutSessionId);

        return [
            'id' => $session->id,
            'status' => $session->status,
            'customer_id' => $session->customer,
            'livemode' => (bool) $session->livemode,
        ];
    }

    public function createPortalSession(string $providerCustomerId, string $returnUrl, ?string $configurationId = null): array
    {
        $session = $this->client->billingPortal->sessions->create(array_filter([
            'customer' => $providerCustomerId,
            'return_url' => $returnUrl,
            'configuration' => $configurationId,
        ]));

        return ['url' => $session->url];
    }

    public function createPortalConfiguration(array $attributes): array
    {
        $configuration = $this->client->billingPortal->configurations->create(array_filter([
            'name' => $attributes['name'] ?? null,
            'business_profile' => $attributes['business_profile'] ?? null,
            'default_return_url' => $attributes['default_return_url'] ?? null,
            'metadata' => $attributes['metadata'] ?? [],
            'features' => $attributes['features'],
        ]));

        return $this->normalizePortalConfiguration($configuration);
    }

    public function listPortalConfigurations(array $filters = []): array
    {
        $configurations = $this->client->billingPortal->configurations->all(array_filter([
            'active' => $filters['active'] ?? null,
            'limit' => $filters['limit'] ?? 100,
        ]));

        return array_map(fn ($configuration) => $this->normalizePortalConfiguration($configuration), $configurations->data);
    }

    public function retrievePortalConfiguration(string $id): array
    {
        return $this->normalizePortalConfiguration($this->client->billingPortal->configurations->retrieve($id));
    }

    private function normalizePortalConfiguration($configuration): array
    {
        $features = $configuration->features;

        return [
            'id' => $configuration->id,
            'name' => $configuration->name,
            'active' => (bool) $configuration->active,
            'is_default' => (bool) $configuration->is_default,
            'livemode' => (bool) $configuration->livemode,
            'metadata' => $configuration->metadata ? $configuration->metadata->toArray() : [],
            'features' => [
                'payment_method_update' => (bool) ($features->payment_method_update->enabled ?? false),
                'invoice_history' => (bool) ($features->invoice_history->enabled ?? false),
                'customer_update' => (bool) ($features->customer_update->enabled ?? false),
                'customer_update_allowed_fields' => $features->customer_update->allowed_updates ?? [],
                'subscription_cancel' => (bool) ($features->subscription_cancel->enabled ?? false),
                'subscription_update' => (bool) ($features->subscription_update->enabled ?? false),
            ],
        ];
    }

    public function retrieveSubscription(string $providerSubscriptionId): ?array
    {
        $subscription = $this->client->subscriptions->retrieve($providerSubscriptionId);

        return $this->normalizeSubscription($subscription);
    }

    public function cancelSubscription(string $providerSubscriptionId, bool $atPeriodEnd = true): array
    {
        if ($atPeriodEnd) {
            $subscription = $this->client->subscriptions->update($providerSubscriptionId, [
                'cancel_at_period_end' => true,
            ]);
        } else {
            $subscription = $this->client->subscriptions->cancel($providerSubscriptionId);
        }

        return $this->normalizeSubscription($subscription);
    }

    public function scheduleCancellationAtPeriodEnd(string $providerSubscriptionId, string $idempotencyKey): array
    {
        $subscription = $this->client->subscriptions->update(
            $providerSubscriptionId,
            ['cancel_at_period_end' => true],
            ['idempotency_key' => $idempotencyKey],
        );

        return $this->normalizeSubscription($subscription);
    }

    public function resumeSubscription(string $providerSubscriptionId, string $idempotencyKey): array
    {
        $subscription = $this->client->subscriptions->update(
            $providerSubscriptionId,
            ['cancel_at_period_end' => false],
            ['idempotency_key' => $idempotencyKey],
        );

        return $this->normalizeSubscription($subscription);
    }

    /**
     * Retrieves first (never trusts a caller-supplied item ID — always
     * reads the CURRENT provider item structure immediately before
     * writing) so a stale local assumption about which item is primary can
     * never silently update the wrong one.
     */
    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, string $prorationBehavior, string $idempotencyKey): array
    {
        $subscription = $this->client->subscriptions->retrieve($providerSubscriptionId);
        $items = $subscription->items->data;

        if (count($items) !== 1) {
            throw new UnexpectedSubscriptionItemStructureException(
                "Subscription {$providerSubscriptionId} has " . count($items) . ' items — expected exactly 1 (SureSign has no per-seat/multi-item billing).'
            );
        }

        // Deliberately no 'billing_cycle_anchor' parameter — omitting it
        // preserves the existing anchor, matching the approved "do not
        // reset the billing cycle" policy without needing an explicit
        // value.
        $updated = $this->client->subscriptions->update(
            $providerSubscriptionId,
            [
                'items' => [['id' => $items[0]->id, 'price' => $newPriceId]],
                'proration_behavior' => $prorationBehavior,
            ],
            ['idempotency_key' => $idempotencyKey]
        );

        return $this->normalizeSubscription($updated);
    }

    public function retrieveInvoice(string $providerInvoiceId): ?array
    {
        $invoice = $this->client->invoices->retrieve($providerInvoiceId);

        return [
            'id' => $invoice->id,
            'status' => $invoice->status,
            'total' => $invoice->total,
            'amount_paid' => $invoice->amount_paid,
            'amount_due' => $invoice->amount_due,
            'amount_remaining' => $invoice->amount_remaining,
            'currency' => $invoice->currency,
            'hosted_invoice_url' => $invoice->hosted_invoice_url,
            'invoice_pdf' => $invoice->invoice_pdf,
        ];
    }

    public function createProduct(array $attributes): array
    {
        $product = $this->client->products->create(array_filter([
            'name' => $attributes['name'],
            'metadata' => $attributes['metadata'] ?? [],
        ]));

        return $this->normalizeProduct($product);
    }

    public function retrieveProduct(string $providerProductId): ?array
    {
        $product = $this->client->products->retrieve($providerProductId);

        if (!empty($product->deleted)) {
            return null;
        }

        return $this->normalizeProduct($product);
    }

    public function createPrice(array $attributes): array
    {
        $price = $this->client->prices->create(
            [
                'product' => $attributes['product_id'],
                'unit_amount' => $attributes['unit_amount'],
                'currency' => $attributes['currency'],
                'recurring' => ['interval' => $attributes['recurring_interval']],
                'metadata' => $attributes['metadata'] ?? [],
            ],
            ['idempotency_key' => $attributes['idempotency_key']]
        );

        return $this->normalizePrice($price);
    }

    public function retrievePrice(string $providerPriceId): ?array
    {
        $price = $this->client->prices->retrieve($providerPriceId);

        return $this->normalizePrice($price);
    }

    public function deactivatePrice(string $providerPriceId): array
    {
        $price = $this->client->prices->update($providerPriceId, ['active' => false]);

        return ['id' => $price->id, 'active' => $price->active];
    }

    public function verifyWebhookSignature(string $payload, string $signature, string $webhookSecret): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new InvalidWebhookSignatureException('Stripe webhook signature verification failed.', previous: $e);
        }

        return $event->toArray();
    }

    /**
     * `current_period_start`/`current_period_end` do NOT exist on the
     * top-level \Stripe\Subscription object in the installed SDK
     * (stripe-php v21.0.0) — confirmed by direct inspection of
     * vendor/stripe/stripe-php/lib/Subscription.php's @property docblock,
     * not assumed. Stripe moved billing-period tracking to the
     * subscription-item level (\Stripe\SubscriptionItem::$current_period_start/
     * $current_period_end). Since SureSign never does per-seat/multi-item
     * billing (see the confirmed no-seat-billing decision), a subscription
     * has exactly one primary item, and its period IS the subscription's
     * period for SureSign's purposes — read from items.data[0], not the
     * subscription object directly. This is deliberately isolated here, in
     * the provider adapter, so a future Stripe API/SDK change only ever
     * touches this one method — SubscriptionLifecycleService and every
     * other caller only ever see the normalized array below, never a raw
     * \Stripe\Subscription.
     *
     * This method operates on `$subscription->toArray()` rather than the
     * SDK object directly — the exact same field layout Stripe's raw
     * webhook JSON already uses — so normalizeSubscriptionFromWebhookPayload()
     * below can reuse this ONE implementation instead of duplicating the
     * period-field fix a second time for the webhook-processing checkpoint.
     */
    private function normalizeSubscription(\Stripe\Subscription $subscription): array
    {
        $narrow = $this->normalizeSubscriptionArray($subscription->toArray());

        return [
            'id' => $narrow['id'],
            'status' => $narrow['status'],
            'customer_id' => $narrow['customer_id'],
            'cancel_at_period_end' => $narrow['cancel_at_period_end'],
            'current_period_start' => $narrow['current_period_start'],
            'current_period_end' => $narrow['current_period_end'],
            'trial_end' => $narrow['trial_end'],
            'livemode' => $narrow['livemode'],
        ];
    }

    /**
     * Array-based counterpart to normalizeSubscription() above — same
     * period-field extraction (items.data[0].current_period_start/end),
     * operating on a plain decoded array (a verified webhook event's
     * `data.object`, or `\Stripe\Subscription::toArray()`) rather than an
     * SDK object. This is the ONE place that period-bug fix lives; both
     * normalizeSubscription() and normalizeSubscriptionFromWebhookPayload()
     * delegate to it rather than re-deriving the period fields separately.
     *
     * Includes a few extra fields (`price_id`/`product_id`/`metadata`/
     * `cancelled_at`/`ended_at`) beyond retrieveSubscription()'s narrower
     * shape — needed by App\Services\Billing\WebhookEventProcessor for
     * commercial-snapshot validation and correlation, harmless for callers
     * (like SubscriptionLifecycleService) that only read the narrower
     * subset of keys they expect.
     */
    public function normalizeSubscriptionArray(array $subscription): array
    {
        $items = $subscription['items']['data'] ?? [];
        $primaryItem = $items[0] ?? null;
        $price = $primaryItem['price'] ?? null;

        return [
            'id' => $subscription['id'],
            'status' => $subscription['status'],
            'customer_id' => is_array($subscription['customer'] ?? null) ? ($subscription['customer']['id'] ?? null) : ($subscription['customer'] ?? null),
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            'current_period_start' => $primaryItem['current_period_start'] ?? null,
            'current_period_end' => $primaryItem['current_period_end'] ?? null,
            'trial_end' => $subscription['trial_end'] ?? null,
            'livemode' => (bool) ($subscription['livemode'] ?? false),
            'price_id' => is_array($price) ? ($price['id'] ?? null) : null,
            'product_id' => is_array($price) ? (is_array($price['product'] ?? null) ? ($price['product']['id'] ?? null) : ($price['product'] ?? null)) : null,
            'cancelled_at' => $subscription['canceled_at'] ?? null,
            'ended_at' => $subscription['ended_at'] ?? null,
            'metadata' => is_array($subscription['metadata'] ?? null) ? $subscription['metadata'] : [],
        ];
    }

    public function normalizeSubscriptionFromWebhookPayload(array $subscriptionObject): array
    {
        return $this->normalizeSubscriptionArray($subscriptionObject);
    }

    /**
     * Same "plain array, provider-independent shape" boundary as
     * normalizeSubscriptionArray() above, for a verified
     * `checkout.session.*` event's `data.object`. `amount_total`/`currency`
     * are read directly from the Checkout Session object — Stripe includes
     * both at the top level for a completed session (currency is included
     * even before completion).
     */
    public function normalizeCheckoutSessionFromWebhookPayload(array $checkoutSessionObject): array
    {
        return [
            'id' => $checkoutSessionObject['id'],
            'status' => $checkoutSessionObject['status'] ?? null,
            'customer_id' => is_array($checkoutSessionObject['customer'] ?? null) ? ($checkoutSessionObject['customer']['id'] ?? null) : ($checkoutSessionObject['customer'] ?? null),
            'subscription_id' => is_array($checkoutSessionObject['subscription'] ?? null) ? ($checkoutSessionObject['subscription']['id'] ?? null) : ($checkoutSessionObject['subscription'] ?? null),
            'livemode' => (bool) ($checkoutSessionObject['livemode'] ?? false),
            'amount_total' => $checkoutSessionObject['amount_total'] ?? null,
            'currency' => isset($checkoutSessionObject['currency']) ? strtoupper($checkoutSessionObject['currency']) : null,
            'metadata' => is_array($checkoutSessionObject['metadata'] ?? null) ? $checkoutSessionObject['metadata'] : [],
            // Additive — Consultancy Live Booking Upgrade, Stage 3.
            // 'mode' distinguishes a subscription Checkout from a one-off
            // ('payment') Checkout; 'payment_status'/'payment_intent_id'
            // are what ConsultancyWebhookEventProcessor treats as
            // authoritative proof of a completed payment ('status' ===
            // 'complete' alone is not sufficient — see that class's
            // docblock).
            'mode' => $checkoutSessionObject['mode'] ?? null,
            'payment_status' => $checkoutSessionObject['payment_status'] ?? null,
            'payment_intent_id' => is_array($checkoutSessionObject['payment_intent'] ?? null) ? ($checkoutSessionObject['payment_intent']['id'] ?? null) : ($checkoutSessionObject['payment_intent'] ?? null),
        ];
    }

    public function normalizeInvoiceFromWebhookPayload(array $invoiceObject): array
    {
        return [
            'id' => $invoiceObject['id'],
            'number' => $invoiceObject['number'] ?? null,
            'status' => $invoiceObject['status'] ?? 'draft',
            'customer_id' => is_array($invoiceObject['customer'] ?? null) ? ($invoiceObject['customer']['id'] ?? null) : ($invoiceObject['customer'] ?? null),
            'subscription_id' => is_array($invoiceObject['subscription'] ?? null) ? ($invoiceObject['subscription']['id'] ?? null) : ($invoiceObject['subscription'] ?? null),
            'livemode' => (bool) ($invoiceObject['livemode'] ?? false),
            'currency' => strtoupper($invoiceObject['currency'] ?? 'gbp'),
            'subtotal' => $invoiceObject['subtotal'] ?? null,
            'tax' => $invoiceObject['tax'] ?? null,
            'total' => $invoiceObject['total'] ?? null,
            'amount_due' => $invoiceObject['amount_due'] ?? null,
            'amount_paid' => $invoiceObject['amount_paid'] ?? null,
            'amount_remaining' => $invoiceObject['amount_remaining'] ?? null,
            'hosted_invoice_url' => $invoiceObject['hosted_invoice_url'] ?? null,
            'invoice_pdf' => $invoiceObject['invoice_pdf'] ?? null,
            'billing_reason' => $invoiceObject['billing_reason'] ?? null,
            'period_start' => $invoiceObject['period_start'] ?? null,
            'period_end' => $invoiceObject['period_end'] ?? null,
            'due_date' => $invoiceObject['due_date'] ?? null,
            'payment_intent_id' => is_array($invoiceObject['payment_intent'] ?? null) ? ($invoiceObject['payment_intent']['id'] ?? null) : ($invoiceObject['payment_intent'] ?? null),
            'metadata' => is_array($invoiceObject['metadata'] ?? null) ? $invoiceObject['metadata'] : [],
        ];
    }

    private function normalizeCustomer(\Stripe\Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'email' => $customer->email,
            'name' => $customer->name,
            'livemode' => (bool) $customer->livemode,
        ];
    }

    private function normalizeProduct(\Stripe\Product $product): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'livemode' => (bool) $product->livemode,
        ];
    }

    private function normalizePrice(\Stripe\Price $price): array
    {
        return [
            'id' => $price->id,
            'product_id' => is_string($price->product) ? $price->product : $price->product->id,
            'unit_amount' => $price->unit_amount,
            'currency' => $price->currency,
            'active' => (bool) $price->active,
            'livemode' => (bool) $price->livemode,
        ];
    }
}
