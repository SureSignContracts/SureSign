<?php

namespace App\Services\Geocoding;

/**
 * Global Address UX V3 — the boundary between `CitySuggestionService` and
 * whatever autocomplete/suggestion provider is actually configured.
 * Deliberately a SEPARATE interface from `GeocodingProviderInterface`, not
 * a second method on it — Forward Geocoding (deterministic Project pin
 * from a full confirmed address) and city autocomplete (optional
 * suggestions from partial human input) are different concerns with
 * different failure semantics, even though both happen to call Geoapify
 * today. A future different autocomplete provider would only need to
 * implement this interface; it would never touch
 * `GeocodingProviderInterface`/`ProjectGeocodingService`.
 *
 * V3 closeout: no `$region` parameter exists here. An earlier version
 * accepted one and folded it into the query text — a live smoke test
 * proved that actively hurts relevance (see `GeoapifyLocationSuggestionProvider`'s
 * docblock) — and removing a parameter no implementation actually used was
 * judged safer than keeping a speculative, unused one on a public API
 * contract. Region-based invalidation of stale suggestions when the
 * user's selected Region changes is a purely frontend UI concern
 * (`CityAutocomplete`'s own `region` prop) and never reaches this
 * interface or the backend at all. A future, genuinely provider-verified
 * use of Region as a search filter should add it back deliberately, with
 * its own live verification — not by reintroducing this exact parameter
 * unverified.
 */
interface LocationSuggestionProviderInterface
{
    /**
     * @return array<int, array{name: string, region: ?string, country: ?string}>
     *   Normalized locality-level suggestions only (city/town/village/
     *   municipality-equivalent) — never a street, building, POI, or
     *   amenity result. Always returns an array, even on zero matches.
     * @throws GeocodingProviderException on a genuine provider/system
     *   failure (missing key, auth rejection, rate limit, timeout, 5xx,
     *   malformed response) — never returned as a fake empty result, so
     *   the caller can distinguish "nothing matched" from "the provider
     *   couldn't be asked" for logging purposes. `CitySuggestionService`
     *   is responsible for turning either outcome into the same
     *   customer-facing "no suggestions" UX — this interface itself does
     *   not swallow failures.
     */
    public function suggestCities(string $query, ?string $countryCode, int $limit): array;
}
