<?php

namespace App\Services\Geocoding;

use App\Support\Geocoding\GeocodingOutcome;

/**
 * Contract-Assisted Project Location, Phase 2 — the provider-agnostic
 * orchestration layer between `ProjectContractSetupSyncService` and
 * whichever `GeocodingProviderInterface` is bound (Geoapify, for this
 * phase). Owns exactly one decision that has nothing to do with Geoapify's
 * own response shape: whether a confirmed textual location is specific
 * enough to justify calling a geocoder at all (Part 9) — the
 * confidence/result-type acceptance policy itself lives in
 * `GeoapifyGeocodingProvider`, since that's expressed in Geoapify's own
 * vocabulary and would need re-deriving for any future different provider.
 *
 * Never opens a database transaction, never touches `Project` directly,
 * never catches `GeocodingProviderException` — a provider/system failure
 * propagates straight through to the caller unchanged, which is exactly
 * what makes `ProjectContractSetupSyncService::apply()`'s atomicity
 * guarantee work: nothing has been written yet at the point this throws.
 */
class ProjectGeocodingService
{
    public function __construct(
        private readonly GeocodingProviderInterface $provider,
    ) {
    }

    /**
     * @param array{address: ?string, city: ?string, state: ?string, postcode: ?string, country: ?string} $components
     *   Already-confirmed, already-normalized components — see
     *   ProjectContractSetupSyncService::projectLocationSuggestion().
     * @throws GeocodingProviderException on provider/system failure.
     */
    public function resolve(array $components): GeocodingOutcome
    {
        if (!$this->isSufficientlySpecific($components)) {
            // Too coarse to justify an automatic pin (Part 9) — a bare
            // city/region/country ("Manchester", "North London", "New
            // South Wales") is real, useful textual information but never
            // precise enough for a construction-site map pin. Treated
            // identically to a genuine no-reliable-match result, but
            // without spending an external request finding that out —
            // Geoapify is never called for this case at all.
            return GeocodingOutcome::noReliableMatch();
        }

        return $this->provider->geocode($components);
    }

    /**
     * V1 minimum-specificity rule (Part 9): a meaningful `address` (street/
     * building level text) is required before attempting automatic
     * geocoding at all. city/state/postcode/country alone are never
     * sufficient, regardless of how many of them are present.
     */
    private function isSufficientlySpecific(array $components): bool
    {
        $address = $components['address'] ?? null;

        return is_string($address) && trim($address) !== '';
    }
}
