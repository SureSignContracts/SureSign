<?php

namespace App\Services\Geocoding;

/**
 * Global Address UX V3 — the provider-agnostic orchestration layer between
 * `LocationSuggestionController` and whichever `LocationSuggestionProviderInterface`
 * is bound (Geoapify, today). Mirrors `ProjectGeocodingService`'s role and
 * shape for the deterministic geocoder, but is a genuinely separate class —
 * this service must never be added as a second method on
 * `ProjectGeocodingService`, since the two have different failure
 * contracts (this one degrades to an empty list; that one throws through
 * to an atomic caller).
 *
 * Owns exactly one provider-agnostic decision: whether the query is even
 * worth asking a provider about at all (minimum length) — never opens a
 * database connection, never touches User/Organization, never persists
 * anything.
 */
class CitySuggestionService
{
    /** Mirrors this app's other short-query search conventions — long enough to be a meaningful locality fragment, short enough to still feel instant. */
    public const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private readonly LocationSuggestionProviderInterface $provider,
    ) {
    }

    /**
     * @return array<int, array{name: string, region: ?string, country: ?string}>
     *   Always an array — a too-short query, and any provider/system
     *   failure, both resolve to an empty list here rather than an
     *   exception. This is what makes City autocomplete "optional
     *   assistance only": nothing upstream of this method needs to know
     *   whether a real request was even attempted.
     */
    public function suggest(string $query, ?string $countryCode, int $limit = 8): array
    {
        $trimmed = trim($query);
        if (mb_strlen($trimmed) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        try {
            return $this->provider->suggestCities($trimmed, $countryCode, $limit);
        } catch (GeocodingProviderException) {
            // Already logged by the provider itself — this is the one
            // place a provider/system failure is deliberately swallowed,
            // because city suggestions are optional assistance and must
            // never block onboarding/settings or surface a fatal error.
            return [];
        }
    }
}
