<?php

namespace App\Services\Billing;

use App\Support\Billing\BillingProviders;
use InvalidArgumentException;

/**
 * Resolves the configured provider name and guards against an unsupported
 * one — a stable, provider-agnostic entry point for anything that needs to
 * know "which provider, and is it valid" without necessarily needing a
 * live client (e.g. a future Stripe Setup status page). The actual
 * BillingProviderInterface implementation used at runtime is resolved via
 * the container (bound in BillingServiceProvider), not by this class
 * constructing one directly — that's what makes the testing/fake swap a
 * one-line service-provider binding rather than something scattered
 * through call sites.
 */
class BillingProviderManager
{
    public function configuredProvider(): string
    {
        $provider = config('billing.provider', BillingProviders::STRIPE);

        if (!BillingProviders::isSupported($provider)) {
            throw new InvalidArgumentException("Unsupported billing provider configured: {$provider}");
        }

        return $provider;
    }

    public function isEnabled(): bool
    {
        return (bool) config('billing.enabled', false);
    }

    /**
     * Selects the ONE webhook signing secret matching the given mode —
     * never both, never inferred from an incoming payload. The caller
     * (WebhookIngestionService) always resolves the application's OWN
     * currently-configured mode first (via
     * BillingProviderInterface::isLivemode(), the same source every other
     * billing service already trusts) and passes it in here; this method
     * never guesses which mode is "active" itself. Returns an empty string
     * if the corresponding secret isn't configured — the caller is
     * responsible for treating that as a configuration failure, never as
     * "try the other one instead."
     */
    public function resolveWebhookSecret(bool $livemode): string
    {
        return (string) config($livemode ? 'billing.stripe.webhook_secret_live' : 'billing.stripe.webhook_secret_test', '');
    }
}
