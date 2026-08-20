<?php

namespace App\Services\Geocoding;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Global Address UX V3 — the sole Geoapify Address Autocomplete
 * (`/v1/geocode/autocomplete`) HTTP client, deliberately separate from
 * `GeoapifyGeocodingProvider` (Forward Geocoding, `/v1/geocode/search`) —
 * a different Geoapify endpoint with different semantics, sharing only
 * configuration (`services.geoapify.*`) and the same defensive HTTP
 * conventions. Never called by, and never calls, `ProjectGeocodingService`
 * or `GeoapifyGeocodingProvider` — the deterministic Project geocoder is
 * completely untouched by this class's existence.
 *
 * Live-verified against Geoapify's own current Address Autocomplete API
 * docs before this was written (endpoint, `text`/`filter`/`limit`/`format`/
 * `apiKey` request parameters, and the `results`/`result_type`/`city`/
 * `state`/`country` response shape). Same `format=json` requirement
 * `GeoapifyGeocodingProvider` already had to add explicitly — Geoapify's
 * real default (undocumented-until-tested) response is a GeoJSON
 * FeatureCollection, not flat JSON, regardless of endpoint. Also
 * live-smoke-tested against the real API (query "Calap", country
 * `PH`) — confirmed `city`-typed results, the `city`/`state`/`country`
 * fields, and `filter=countrycode:xx` all behave exactly as documented,
 * returning "Calapan" / "Oriental Mindoro" as the top match.
 *
 * V3 closeout: that same smoke test also proved folding a Region string
 * into the free-text query actively HURTS relevance (Geoapify matched
 * "Oriental" as a street-name token instead of using "Oriental Mindoro" as
 * a province disambiguator) — so, rather than keep an unused `$region`
 * parameter implying filtering that doesn't happen, this class (and the
 * whole interface/service/controller chain above it) no longer accepts
 * Region at all. Country IS a real, verified `filter=countrycode:xx`
 * value below; Region is not reintroduced here speculatively — a future
 * phase can add a genuinely provider-verified mechanism if Geoapify offers
 * one, with its own fresh live check.
 *
 * `type=city` is deliberately NOT sent as a request parameter — it only
 * accepts one value, and would silently exclude a real town/village/
 * municipality Geoapify classifies as `locality` rather than `city`.
 * Instead, exactly like `GeoapifyGeocodingProvider::ACCEPTED_RESULT_TYPES`,
 * the accepted-result policy is enforced in PHP against the response's own
 * `result_type` field — `city` and `locality` are accepted, everything
 * else (street/amenity/building/postcode/state/country) is discarded here,
 * never left for the caller to filter. `locality` covering "not quite a
 * named city" localities (town/village/municipality) is Geoapify's own
 * documented vocabulary, not an invented category — but has not been
 * exercised against every real-world example in this codebase's own live
 * smoke test, so treat it as a reasonable, safe-by-construction default
 * rather than an exhaustively proven one: a genuine locality Geoapify
 * tags with some other value would simply be filtered out (never a wrong
 * result, never a crash), not silently miscategorized.
 */
class GeoapifyLocationSuggestionProvider implements LocationSuggestionProviderInterface
{
    private const ENDPOINT = '/v1/geocode/autocomplete';

    private const ACCEPTED_RESULT_TYPES = ['city', 'locality'];

    public function suggestCities(string $query, ?string $countryCode, int $limit): array
    {
        $apiKey = config('services.geoapify.api_key');
        if (empty($apiKey)) {
            throw new GeocodingProviderException('The location suggestion service is not configured.');
        }

        $baseUrl = rtrim(config('services.geoapify.base_url', 'https://api.geoapify.com'), '/');

        $params = [
            'text'   => $query,
            'limit'  => $limit,
            'format' => 'json',
            'apiKey' => $apiKey,
        ];
        // Country IS a hard filter — an ISO alpha-2 code is always
        // well-formed by construction (validated by the controller before
        // this is ever called), unlike a free-text region.
        if ($countryCode !== null && $countryCode !== '') {
            $params['filter'] = 'countrycode:' . strtolower($countryCode);
        }

        try {
            $response = Http::timeout(6)->get($baseUrl . self::ENDPOINT, $params);
        } catch (ConnectionException $e) {
            Log::warning('[Geoapify] Connection failure during city autocomplete', ['error' => $e->getMessage()]);
            throw new GeocodingProviderException('The location suggestion service is currently unavailable.');
        }

        if ($response->failed()) {
            $status = $response->status();
            // Sanitized logging only — never the request URL (carries
            // apiKey as a query parameter) and never the response body.
            Log::warning('[Geoapify] City autocomplete request failed', ['status' => $status]);

            throw new GeocodingProviderException(match (true) {
                in_array($status, [401, 403], true) => 'The location suggestion service rejected its configured credentials.',
                $status === 429 => 'The location suggestion service is currently rate-limited.',
                $status >= 500 => 'The location suggestion service is currently unavailable.',
                default => 'The location suggestion service returned an error.',
            });
        }

        $data = $response->json();
        if (!is_array($data) || !array_key_exists('results', $data) || !is_array($data['results'])) {
            Log::warning('[Geoapify] City autocomplete returned an unexpected response shape');
            throw new GeocodingProviderException('The location suggestion service returned an unexpected response.');
        }

        $suggestions = [];
        foreach ($data['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $normalized = $this->tryNormalize($result);
            if ($normalized !== null) {
                $suggestions[] = $normalized;
            }
        }

        return $suggestions;
    }

    /**
     * Returns a normalized suggestion only if this candidate's
     * `result_type` is locality-level — null otherwise (silently skipped,
     * never an exception; one bad/irrelevant candidate must never fail the
     * whole suggestion list).
     */
    private function tryNormalize(array $result): ?array
    {
        $resultType = $result['result_type'] ?? null;
        if (!is_string($resultType) || !in_array($resultType, self::ACCEPTED_RESULT_TYPES, true)) {
            return null;
        }

        // `city` is the documented field for the locality's own name —
        // `name` is used as a fallback for a `locality` result where Geoapify
        // doesn't populate `city` distinctly. Never `formatted`/`address_line1`
        // (a full address string) — this must stay just the locality name,
        // matching what a user would type into a City field themselves.
        $name = $result['city'] ?? $result['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return null;
        }

        $state = $result['state'] ?? null;
        $country = $result['country'] ?? null;

        return [
            'name' => $name,
            'region' => is_string($state) && $state !== '' ? $state : null,
            'country' => is_string($country) && $country !== '' ? $country : null,
        ];
    }
}
