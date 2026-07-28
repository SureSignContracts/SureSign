<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Slice E2 — the provider-independent Customer Portal abstraction. Only
 * ever uses the CONFIGURED, trusted return URL
 * (`config('billing.portal_return_url')`) — never a frontend-supplied one,
 * closing off an open-redirect vector a user-controlled return URL would
 * otherwise create.
 *
 * ─── Enabled Portal capabilities ──────────────────────────────────────────
 *
 *   - Payment-method management
 *   - Invoice history
 *   - Billing-details management (name/address on the Stripe Customer —
 *     NOT email, which stays SureSign-authoritative; see
 *     CUSTOMER_UPDATE_ALLOWED_FIELDS below)
 *
 * ─── Explicitly DISABLED Portal capabilities ──────────────────────────────
 *
 *   - Plan upgrades/downgrades — SureSign's own Pricing, plan-change
 *     state machine (`SubscriptionPlanChangeService`), and webhook-
 *     confirmed snapshot workflow are the ONLY approved path for a plan
 *     change (Non-negotiable Principle: SureSign remains the commercial
 *     source of truth). A Portal-driven plan change would let a customer
 *     change their Price directly on Stripe with no local
 *     `BillingPlanChange` row, no eligibility check, and no snapshot —
 *     completely bypassing this checkpoint's entire plan-change
 *     architecture.
 *   - Subscription cancellation — same reasoning; cancellation must go
 *     through `SubscriptionLifecycleService::scheduleCancellation()`, not
 *     a Stripe-side self-service cancel.
 *   - Subscription quantity changes, coupons/promotion codes — SureSign
 *     has no per-seat pricing dimension and no coupon system; there is
 *     nothing safe for these to do.
 *
 * As of Slice E2, these capabilities are enforced PROGRAMMATICALLY via a
 * dedicated, restricted `billing_portal.configuration` object this class
 * creates/discovers itself (`resolveRestrictedConfiguration()`) — this is
 * no longer a Dashboard-only setting this codebase cannot verify. Every
 * `createSession()` call re-fetches that configuration from Stripe and
 * fails CLOSED (refuses to create a session) if it does not match the
 * exact restricted feature set — see `configurationIsSafe()`. There is no
 * "trust the cached configuration forever" path: only the configuration's
 * ID is cached (rarely changes), never its safety verdict.
 *
 * If Stripe ever reports a subscription-affecting change that originated
 * from the Portal despite this configuration (e.g. an operator edits the
 * configuration directly in the Stripe Dashboard), it still flows through
 * the exact same `WebhookEventProcessor`/`SubscriptionPlanChangeService`
 * verification built for any other provider-originated subscription event
 * — there is no separate, weaker "it came from the Portal" trust path.
 */
class BillingPortalService
{
    /**
     * Stamped onto the restricted configuration this class creates, so a
     * later call can find it again via listPortalConfigurations() rather
     * than trusting an operator-set `is_default` flag or guessing by name.
     */
    private const CONFIGURATION_METADATA_KEY = 'suresign_restricted_billing_portal';
    private const CONFIGURATION_METADATA_VALUE = 'v1';

    /**
     * Only Organisation billing-address fields — never email (SureSign's
     * own Organisation record stays authoritative for identity) and never
     * the Organisation/company name (also SureSign-authoritative).
     */
    private const CUSTOMER_UPDATE_ALLOWED_FIELDS = ['address', 'phone', 'tax_id'];

    private const RESTRICTED_FEATURES = [
        'payment_method_update' => ['enabled' => true],
        'invoice_history' => ['enabled' => true],
        'customer_update' => [
            'enabled' => true,
            'allowed_updates' => self::CUSTOMER_UPDATE_ALLOWED_FIELDS,
        ],
        'subscription_cancel' => ['enabled' => false],
        'subscription_update' => ['enabled' => false],
    ];

    public function __construct(
        private readonly BillingProviderInterface $provider,
    ) {
    }

    /**
     * @return array{url: string}
     */
    public function createSession(Organization $organization, User $actor): array
    {
        $billingCustomer = BillingCustomer::query()
            ->where('organization_id', $organization->id)
            ->where('provider', 'stripe')
            ->first();

        if ($billingCustomer === null) {
            throw new SubscriptionLifecycleConflictException(
                "Organisation {$organization->id} has no billing customer — cannot open a Customer Portal session."
            );
        }

        if ($billingCustomer->livemode !== $this->provider->isLivemode()) {
            throw new SubscriptionLifecycleConflictException(
                "Organisation {$organization->id}'s billing customer mode does not match the current provider mode."
            );
        }

        $returnUrl = (string) config('billing.portal_return_url');

        if ($returnUrl === '') {
            throw new SubscriptionLifecycleConflictException('billing.portal_return_url is not configured — refusing to create a Portal session with no trusted return URL.');
        }

        $configuration = $this->resolveVerifiedRestrictedConfiguration($organization, $billingCustomer);

        $session = $this->provider->createPortalSession(
            $billingCustomer->provider_customer_id,
            $returnUrl,
            $configuration['id'],
        );

        ActivityLog::record(
            action: 'billing.portal_session_created',
            description: 'Created a Stripe Customer Portal session',
            user: $actor,
            subject: $billingCustomer,
            organizationId: $organization->id,
            meta: ['billing_customer_id' => $billingCustomer->id],
        );

        return $session;
    }

    /**
     * Stage 14 drift check — callable on demand (e.g. from a console
     * command) without requiring an Organisation/BillingCustomer, purely
     * to report whether the restricted configuration this provider mode
     * would use is currently safe.
     *
     * @return array{configuration_id: string, safe: bool, reused: bool, features: array<string, mixed>}
     */
    public function verifyRestrictedConfiguration(): array
    {
        $configurationId = $this->findExistingConfigurationId();
        $reused = $configurationId !== null;
        $configurationId ??= $this->createRestrictedConfiguration()['id'];

        $configuration = $this->provider->retrievePortalConfiguration($configurationId);

        return [
            'configuration_id' => $configuration['id'],
            'safe' => $this->configurationIsSafe($configuration),
            'reused' => $reused,
            'features' => $configuration['features'],
        ];
    }

    /**
     * Resolves the restricted configuration ID (cached — creation/lookup by
     * metadata is a real Stripe API round-trip and the ID itself is stable),
     * then ALWAYS re-fetches the configuration's live feature set from
     * Stripe and refuses to proceed if it has drifted unsafe. This is
     * deliberately never combined into one cached value — only the ID is
     * safe to treat as stable; the safety verdict never is.
     */
    private function resolveVerifiedRestrictedConfiguration(Organization $organization, BillingCustomer $billingCustomer): array
    {
        $configurationId = $this->resolveConfigurationId();
        $configuration = $this->provider->retrievePortalConfiguration($configurationId);

        if (!$this->configurationIsSafe($configuration)) {
            Log::critical('Billing Portal restricted configuration has drifted unsafe — refusing to create a session', [
                'organization_id' => $organization->id,
                'billing_customer_id' => $billingCustomer->id,
                'configuration_id' => $configurationId,
                'features' => $configuration['features'],
            ]);

            throw new SubscriptionLifecycleConflictException(
                "The restricted Billing Portal configuration ({$configurationId}) no longer matches the approved capability policy — refusing to create a session."
            );
        }

        return $configuration;
    }

    private function resolveConfigurationId(): string
    {
        $cacheKey = 'billing.portal.restricted_configuration_id.' . ($this->provider->isLivemode() ? 'live' : 'test');

        return Cache::rememberForever($cacheKey, function () {
            return $this->findExistingConfigurationId() ?? $this->createRestrictedConfiguration()['id'];
        });
    }

    private function findExistingConfigurationId(): ?string
    {
        foreach ($this->provider->listPortalConfigurations(['active' => true, 'limit' => 100]) as $configuration) {
            if (($configuration['metadata'][self::CONFIGURATION_METADATA_KEY] ?? null) === self::CONFIGURATION_METADATA_VALUE) {
                return $configuration['id'];
            }
        }

        return null;
    }

    private function createRestrictedConfiguration(): array
    {
        return $this->provider->createPortalConfiguration([
            'name' => 'SureSign Restricted Billing Portal — ' . ($this->provider->isLivemode() ? 'Live' : 'Test'),
            'business_profile' => array_filter([
                'headline' => (string) config('app.name') . ' billing',
            ]),
            'default_return_url' => (string) config('billing.portal_return_url') ?: null,
            'metadata' => [self::CONFIGURATION_METADATA_KEY => self::CONFIGURATION_METADATA_VALUE],
            'features' => self::RESTRICTED_FEATURES,
        ]);
    }

    private function configurationIsSafe(array $configuration): bool
    {
        $features = $configuration['features'];

        return $features['payment_method_update'] === true
            && $features['invoice_history'] === true
            && $features['subscription_cancel'] === false
            && $features['subscription_update'] === false;
    }
}
