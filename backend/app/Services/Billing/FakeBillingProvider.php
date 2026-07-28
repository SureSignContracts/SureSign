<?php

namespace App\Services\Billing;

use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\Exceptions\UnexpectedSubscriptionItemStructureException;

/**
 * In-memory fake used by the automated test suite and bound whenever
 * app()->environment('testing') is true (see BillingServiceProvider) — no
 * automated test may ever construct a real Stripe client. Deterministic,
 * no network calls, no randomness (this class must stay compatible with
 * workflow/test environments that forbid Date::now()/uniqid()-style
 * non-determinism, so callers pass in any IDs/timestamps they need rather
 * than this class generating its own).
 *
 * Binding is explicit (a service-provider check of the environment), never
 * driven by a frontend-controlled value — see BillingProviderManager.
 */
class FakeBillingProvider implements BillingProviderInterface
{
    /** @var array<string, array> */
    public array $customers = [];

    /** @var array<string, array> */
    public array $checkoutSessions = [];

    /** @var array<string, array> */
    public array $subscriptions = [];

    /** @var array<string, array> */
    public array $invoices = [];

    /** @var array<string, array> */
    public array $products = [];

    /** @var array<string, array> */
    public array $prices = [];

    /** @var array<string, array> */
    public array $portalConfigurations = [];

    /** @var array<string, array> keyed by idempotency key — proves a retried call never re-mutates */
    public array $idempotentResults = [];

    private int $sequence = 0;

    /**
     * Stamped onto every object this instance creates — defaults to false
     * (Test Mode), matching a real Stripe test-mode key. Tests simulating a
     * livemode mismatch construct/configure a second fake instance (or flip
     * this) rather than the provider ever guessing — see
     * BillingCustomerServiceTest/PlanPriceMappingServiceTest.
     */
    public bool $livemode = false;

    public function isLivemode(): bool
    {
        return $this->livemode;
    }

    public function createCustomer(array $attributes): array
    {
        $id = 'cus_fake_' . (++$this->sequence);

        $customer = [
            'id' => $id,
            'email' => $attributes['email'] ?? null,
            'name' => $attributes['name'] ?? null,
            'livemode' => $this->livemode,
        ];

        $this->customers[$id] = $customer;

        return $customer;
    }

    public function retrieveCustomer(string $providerCustomerId): ?array
    {
        return $this->customers[$providerCustomerId] ?? null;
    }

    public function updateCustomer(string $providerCustomerId, array $attributes): array
    {
        $customer = $this->customers[$providerCustomerId]
            ?? throw new \RuntimeException("Unknown fake customer: {$providerCustomerId}");

        foreach (['email', 'name'] as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $customer[$field] = $attributes[$field];
            }
        }

        $this->customers[$providerCustomerId] = $customer;

        return $customer;
    }

    public function createCheckoutSession(array $params): array
    {
        $id = 'cs_fake_' . (++$this->sequence);

        // expires_at is deliberately never computed internally (e.g. via
        // now()->addHours(24)) — this class stays wall-clock-free; a
        // caller that needs a specific expiry passes it explicitly via
        // $params['expires_at'] (a unix timestamp), exactly like the real
        // Stripe adapter receives one back from the real API.
        $session = [
            'id' => $id,
            'url' => "https://checkout.stripe.test/fake/{$id}",
            'expires_at' => $params['expires_at'] ?? null,
            'status' => 'open',
            'customer_id' => $params['customer_id'],
            'livemode' => $this->livemode,
            // Stored for test introspection only — mirrors
            // StripeBillingProvider's real `subscription_data.metadata`
            // wiring so a test can assert what would have been stamped onto
            // the resulting Stripe Subscription object.
            'subscription_metadata' => $params['subscription_metadata'] ?? [],
        ];

        $this->checkoutSessions[$id] = $session;

        return $session;
    }

    public function retrieveCheckoutSession(string $providerCheckoutSessionId): ?array
    {
        return $this->checkoutSessions[$providerCheckoutSessionId] ?? null;
    }

    public function expireCheckoutSession(string $providerCheckoutSessionId): array
    {
        $session = $this->checkoutSessions[$providerCheckoutSessionId]
            ?? throw new \RuntimeException("Unknown fake checkout session: {$providerCheckoutSessionId}");

        $session['status'] = 'expired';
        $this->checkoutSessions[$providerCheckoutSessionId] = $session;

        return $session;
    }

    public function createPortalSession(string $providerCustomerId, string $returnUrl, ?string $configurationId = null): array
    {
        return ['url' => "https://billing.stripe.test/fake/portal?customer={$providerCustomerId}&return={$returnUrl}&configuration={$configurationId}"];
    }

    public function createPortalConfiguration(array $attributes): array
    {
        $id = 'bpc_fake_' . (++$this->sequence);

        $configuration = [
            'id' => $id,
            'name' => $attributes['name'] ?? null,
            'active' => true,
            'is_default' => false,
            'livemode' => $this->livemode,
            'metadata' => $attributes['metadata'] ?? [],
            'features' => $this->normalizePortalConfigurationFeatures($attributes['features']),
        ];

        $this->portalConfigurations[$id] = $configuration;

        return $configuration;
    }

    public function listPortalConfigurations(array $filters = []): array
    {
        $configurations = array_values($this->portalConfigurations);

        if (array_key_exists('active', $filters)) {
            $configurations = array_values(array_filter($configurations, fn ($c) => $c['active'] === $filters['active']));
        }

        return $configurations;
    }

    public function retrievePortalConfiguration(string $id): array
    {
        return $this->portalConfigurations[$id]
            ?? throw new \RuntimeException("Unknown fake portal configuration: {$id}");
    }

    /**
     * Mirrors StripeBillingProvider::normalizePortalConfiguration()'s exact
     * flat shape — BillingPortalService's drift check must see the same
     * shape regardless of which provider is bound. Accepts the raw
     * Stripe-param-shaped `features` array (as passed into
     * createPortalConfiguration()) — a test that wants to simulate drift
     * mutates `$this->portalConfigurations[$id]['features']` directly
     * (already in this normalized shape), never this method's input shape.
     */
    private function normalizePortalConfigurationFeatures(array $features): array
    {
        return [
            'payment_method_update' => (bool) ($features['payment_method_update']['enabled'] ?? false),
            'invoice_history' => (bool) ($features['invoice_history']['enabled'] ?? false),
            'customer_update' => (bool) ($features['customer_update']['enabled'] ?? false),
            'customer_update_allowed_fields' => $features['customer_update']['allowed_updates'] ?? [],
            'subscription_cancel' => (bool) ($features['subscription_cancel']['enabled'] ?? false),
            'subscription_update' => (bool) ($features['subscription_update']['enabled'] ?? false),
        ];
    }

    public function retrieveSubscription(string $providerSubscriptionId): ?array
    {
        return $this->subscriptions[$providerSubscriptionId] ?? null;
    }

    public function cancelSubscription(string $providerSubscriptionId, bool $atPeriodEnd = true): array
    {
        $subscription = $this->subscriptions[$providerSubscriptionId]
            ?? throw new \RuntimeException("Unknown fake subscription: {$providerSubscriptionId}");

        $subscription['cancel_at_period_end'] = $atPeriodEnd;
        $subscription['status'] = $atPeriodEnd ? $subscription['status'] : 'canceled';

        $this->subscriptions[$providerSubscriptionId] = $subscription;

        return $subscription;
    }

    public function scheduleCancellationAtPeriodEnd(string $providerSubscriptionId, string $idempotencyKey): array
    {
        return $this->setCancelAtPeriodEnd($providerSubscriptionId, true, $idempotencyKey);
    }

    public function resumeSubscription(string $providerSubscriptionId, string $idempotencyKey): array
    {
        return $this->setCancelAtPeriodEnd($providerSubscriptionId, false, $idempotencyKey);
    }

    /**
     * Same idempotency contract as updateSubscriptionPrice() below — a
     * retried call with the same key returns the already-recorded result
     * without re-mutating state.
     */
    private function setCancelAtPeriodEnd(string $providerSubscriptionId, bool $cancelAtPeriodEnd, string $idempotencyKey): array
    {
        if (isset($this->idempotentResults[$idempotencyKey])) {
            return $this->idempotentResults[$idempotencyKey];
        }

        $subscription = $this->subscriptions[$providerSubscriptionId]
            ?? throw new \RuntimeException("Unknown fake subscription: {$providerSubscriptionId}");

        $subscription['cancel_at_period_end'] = $cancelAtPeriodEnd;
        $this->subscriptions[$providerSubscriptionId] = $subscription;
        $this->idempotentResults[$idempotencyKey] = $subscription;

        return $subscription;
    }

    /**
     * Mirrors the real adapter's idempotency contract: a second call with
     * the same `$idempotencyKey` returns the already-recorded result
     * without re-mutating `$this->subscriptions` — so a test can prove a
     * retried plan-change send is genuinely safe, not just "happens to
     * look the same."
     */
    public function updateSubscriptionPrice(string $providerSubscriptionId, string $newPriceId, string $prorationBehavior, string $idempotencyKey): array
    {
        if (isset($this->idempotentResults[$idempotencyKey])) {
            return $this->idempotentResults[$idempotencyKey];
        }

        $subscription = $this->subscriptions[$providerSubscriptionId]
            ?? throw new \RuntimeException("Unknown fake subscription: {$providerSubscriptionId}");

        $itemCount = $subscription['item_count'] ?? 1;

        if ($itemCount !== 1) {
            throw new UnexpectedSubscriptionItemStructureException(
                "Subscription {$providerSubscriptionId} has {$itemCount} items — expected exactly 1."
            );
        }

        $subscription['price_id'] = $newPriceId;
        $this->subscriptions[$providerSubscriptionId] = $subscription;
        $this->idempotentResults[$idempotencyKey] = $subscription;

        return $subscription;
    }

    public function retrieveInvoice(string $providerInvoiceId): ?array
    {
        return $this->invoices[$providerInvoiceId] ?? null;
    }

    public function createProduct(array $attributes): array
    {
        $id = 'prod_fake_' . (++$this->sequence);

        $product = [
            'id' => $id,
            'name' => $attributes['name'],
            'livemode' => $this->livemode,
        ];

        $this->products[$id] = $product;

        return $product;
    }

    public function retrieveProduct(string $providerProductId): ?array
    {
        return $this->products[$providerProductId] ?? null;
    }

    public function createPrice(array $attributes): array
    {
        $id = 'price_fake_' . (++$this->sequence);

        $price = [
            'id' => $id,
            'product_id' => $attributes['product_id'],
            'unit_amount' => $attributes['unit_amount'],
            'currency' => $attributes['currency'],
            'active' => true,
            'livemode' => $this->livemode,
        ];

        $this->prices[$id] = $price;

        return $price;
    }

    public function retrievePrice(string $providerPriceId): ?array
    {
        return $this->prices[$providerPriceId] ?? null;
    }

    public function deactivatePrice(string $providerPriceId): array
    {
        $price = $this->prices[$providerPriceId]
            ?? throw new \RuntimeException("Unknown fake price: {$providerPriceId}");

        $price['active'] = false;
        $this->prices[$providerPriceId] = $price;

        return ['id' => $price['id'], 'active' => false];
    }

    /**
     * Fake signature verification: valid only when $signature exactly
     * equals the configured webhook secret prefixed with "valid:" — a
     * deliberately simple, deterministic stand-in for
     * \Stripe\Webhook::constructEvent(). Real signature-verification
     * behavior is tested separately, directly against the SDK, using real
     * signed fixture payloads (see StripeBillingProviderWebhookSignatureTest)
     * — this fake only needs to let billing-service tests simulate
     * "signature ok" / "signature bad" without any Stripe involvement.
     */
    public function verifyWebhookSignature(string $payload, string $signature, string $webhookSecret): array
    {
        if ($signature !== "valid:{$webhookSecret}") {
            throw new InvalidWebhookSignatureException('Fake webhook signature verification failed.');
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new InvalidWebhookSignatureException('Fake webhook payload was not valid JSON.');
        }

        return $decoded;
    }

    /**
     * Test helper — seeds a fake subscription record directly, bypassing
     * createCheckoutSession(), for tests that need retrieveSubscription()
     * to return something specific.
     */
    public function seedSubscription(string $providerSubscriptionId, array $data): void
    {
        $this->subscriptions[$providerSubscriptionId] = array_merge(['id' => $providerSubscriptionId], $data);
    }

    /**
     * Deliberately mirrors StripeBillingProvider::normalizeSubscriptionArray()'s
     * exact field layout (including reading the period fields from
     * items.data[0], never the top level) — tests exercising
     * WebhookEventProcessor build fixture payloads matching real Stripe
     * webhook JSON shape, and this fake must interpret that shape
     * identically to the real adapter, not a simplified stand-in shape.
     */
    public function normalizeSubscriptionFromWebhookPayload(array $subscriptionObject): array
    {
        $items = $subscriptionObject['items']['data'] ?? [];
        $primaryItem = $items[0] ?? null;
        $price = $primaryItem['price'] ?? null;

        return [
            'id' => $subscriptionObject['id'],
            'status' => $subscriptionObject['status'],
            'customer_id' => is_array($subscriptionObject['customer'] ?? null) ? ($subscriptionObject['customer']['id'] ?? null) : ($subscriptionObject['customer'] ?? null),
            'cancel_at_period_end' => (bool) ($subscriptionObject['cancel_at_period_end'] ?? false),
            'current_period_start' => $primaryItem['current_period_start'] ?? null,
            'current_period_end' => $primaryItem['current_period_end'] ?? null,
            'trial_end' => $subscriptionObject['trial_end'] ?? null,
            'livemode' => (bool) ($subscriptionObject['livemode'] ?? false),
            'price_id' => is_array($price) ? ($price['id'] ?? null) : null,
            'product_id' => is_array($price) ? (is_array($price['product'] ?? null) ? ($price['product']['id'] ?? null) : ($price['product'] ?? null)) : null,
            'cancelled_at' => $subscriptionObject['canceled_at'] ?? null,
            'ended_at' => $subscriptionObject['ended_at'] ?? null,
            'metadata' => is_array($subscriptionObject['metadata'] ?? null) ? $subscriptionObject['metadata'] : [],
        ];
    }

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
        ];
    }

    /**
     * Mirrors StripeBillingProvider::normalizeInvoiceFromWebhookPayload()'s
     * exact field layout — see that method's docblock.
     */
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
}
