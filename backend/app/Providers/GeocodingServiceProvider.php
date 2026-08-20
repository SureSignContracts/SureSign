<?php

namespace App\Providers;

use App\Services\Geocoding\GeoapifyGeocodingProvider;
use App\Services\Geocoding\GeoapifyLocationSuggestionProvider;
use App\Services\Geocoding\GeocodingProviderInterface;
use App\Services\Geocoding\LocationSuggestionProviderInterface;
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
 *
 * Global Address UX V3 also binds LocationSuggestionProviderInterface to
 * GeoapifyLocationSuggestionProvider here — same provider (Geoapify), same
 * "no fake swap" testing convention, but a deliberately separate interface
 * binding from GeocodingProviderInterface above (see that interface's own
 * docblock for why City autocomplete and Forward Geocoding stay two
 * unrelated seams even though they share a vendor).
 */
class GeocodingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocodingProviderInterface::class, GeoapifyGeocodingProvider::class);
        $this->app->singleton(LocationSuggestionProviderInterface::class, GeoapifyLocationSuggestionProvider::class);
    }
}
