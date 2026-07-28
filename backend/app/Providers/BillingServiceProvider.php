<?php

namespace App\Providers;

use App\Services\Billing\BillingProviderInterface;
use App\Services\Billing\FakeBillingProvider;
use App\Services\Billing\StripeBillingProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds BillingProviderInterface to the fake implementation in the testing
 * environment, and to the real Stripe adapter everywhere else. This is the
 * ONLY place that decision is made — explicit, environment-based, and
 * fixed at boot time, never driven by a request header, query parameter, or
 * any other frontend-controlled value. Automated tests therefore always
 * exercise FakeBillingProvider and never construct a real Stripe client,
 * regardless of what billing.stripe.secret happens to contain.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BillingProviderInterface::class, function ($app) {
            if ($app->environment('testing')) {
                return new FakeBillingProvider();
            }

            return new StripeBillingProvider();
        });

        // Also bind the concrete fake so tests can type-hint it directly
        // (e.g. to call seedSubscription()) without re-resolving/casting
        // the interface.
        $this->app->singleton(FakeBillingProvider::class, function ($app) {
            return $app->make(BillingProviderInterface::class);
        });
    }
}
