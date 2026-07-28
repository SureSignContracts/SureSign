<?php

namespace App\Providers;

use App\Services\Entitlements\EntitlementOverrideRepository;
use App\Services\Entitlements\NullEntitlementOverrideRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the entitlement override extension point (Part 8/9) to its
 * no-op implementation — the ONE place this decision is made, matching
 * `BillingServiceProvider`'s equivalent role for `BillingProviderInterface`.
 * A future checkpoint that builds real `subscription_overrides`
 * persistence only needs to change this one binding.
 */
class EntitlementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntitlementOverrideRepository::class, NullEntitlementOverrideRepository::class);
    }
}
