<?php

namespace App\Providers;

use App\Services\Geocoding\GeoapifyGeocodingProvider;
use App\Services\Geocoding\GeocodingProviderInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Binds GeocodingProviderInterface to GeoapifyGeocodingProvider — the one
 * locked, approved provider for this phase (see that interface's own
 * docblock). Deliberately no environment-based fake swap, unlike
 * BillingServiceProvider's Stripe/FakeBillingProvider split: automated
 * tests exercise the real GeoapifyGeocodingProvider and intercept its
 * outbound HTTP call with Http::fake() instead, so the actual
 * request-building and response-parsing logic is what gets tested, not a
 * hand-written stand-in that could drift from real behaviour.
 */
class GeocodingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocodingProviderInterface::class, GeoapifyGeocodingProvider::class);
    }
}
