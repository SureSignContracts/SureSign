<?php

namespace App\Services\Geocoding;

use App\Support\Geocoding\GeocodingOutcome;

/**
 * Contract-Assisted Project Location, Phase 2 — the one boundary between
 * `ProjectGeocodingService` and whatever geocoding provider is actually
 * configured, mirroring `App\Services\Billing\BillingProviderInterface`'s
 * role for Stripe. Only one implementation exists today
 * (`GeoapifyGeocodingProvider`) — Geoapify is the locked, approved provider
 * for this phase, not one of several — but the seam still means a future
 * provider swap would never need to change `ProjectGeocodingService` or
 * `ProjectContractSetupSyncService`.
 */
interface GeocodingProviderInterface
{
    /**
     * @param array{address: ?string, city: ?string, state: ?string, postcode: ?string, country: ?string} $components
     *   Already-confirmed, already-normalized textual location components —
     *   never raw Contract text, never a party address, never unrelated
     *   confirmed_data_json.
     * @throws GeocodingProviderException on any provider/system failure —
     *   never returned as a fake `GeocodingOutcome::noReliableMatch()`.
     */
    public function geocode(array $components): GeocodingOutcome;
}
