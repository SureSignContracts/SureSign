<?php

namespace App\Services\Geocoding;

use App\Support\Geocoding\GeocodingOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Contract-Assisted Project Location, Phase 2 — the sole Geoapify Forward
 * Geocoding HTTP client. Locked, approved provider for this phase (see
 * GeocodingProviderInterface's docblock) — never falls back to another
 * provider, never invents a candidate provider itself.
 *
 * Live-verified against Geoapify's own current API documentation before
 * this was written (endpoint, `text`/`apiKey`/`limit` request parameters,
 * and the `results`/`rank.confidence`/`result_type`/`rank.match_type`
 * response shape all confirmed current as of this implementation — no
 * material drift from what this class assumes).
 *
 * The acceptance policy (confidence threshold + accepted result types) is
 * deliberately encapsulated here, not in `ProjectGeocodingService` — it's
 * expressed entirely in Geoapify's own response vocabulary
 * (`rank.confidence`, `result_type`), so a future different provider would
 * need its own equivalent policy in its own class, not a shared one that
 * assumes Geoapify's semantics. `ProjectGeocodingService` only owns the
 * provider-agnostic "is this location specific enough to attempt at all"
 * gate (Part 9).
 */
class GeoapifyGeocodingProvider implements GeocodingProviderInterface
{
    private const ENDPOINT = '/v1/geocode/search';

    /**
     * Conservative, deterministic acceptance policy (Part 10) — a
     * building/amenity/street result type only, never a coarse
     * city/state/country/postcode-only result accepted as a construction
     * Project's exact site. `rank.confidence` threshold is config-driven
     * (services.geoapify.min_confidence, default 0.95) so it can be tuned
     * without a code change if real-world testing shows it's miscalibrated
     * — the policy itself (which result types are ever acceptable) is not
     * config-driven, since weakening that silently would defeat the whole
     * "no pin is better than wrong pin" safety principle this exists for.
     */
    private const ACCEPTED_RESULT_TYPES = ['building', 'amenity', 'street'];

    public function geocode(array $components): GeocodingOutcome
    {
        $apiKey = config('services.geoapify.api_key');
        if (empty($apiKey)) {
            // Explicit "not configured" failure, never a silent no-match —
            // see GeocodingProviderInterface/Part 38. An environment with
            // no key configured must not let Apply appear to "try and
            // fail" a real geocode; it never attempted one.
            throw new GeocodingProviderException('The map location service is not configured.');
        }

        $text = $this->buildQueryText($components);

        $baseUrl = rtrim(config('services.geoapify.base_url', 'https://api.geoapify.com'), '/');

        try {
            $response = Http::timeout(10)->get($baseUrl . self::ENDPOINT, [
                'text'   => $text,
                'limit'  => 3,
                // Live-verified against a real Geoapify API key/live smoke
                // test (Part 39): omitting `format` returns a GeoJSON
                // FeatureCollection (`type`/`features[].properties.*`), NOT
                // the flat `{"results":[...]}` shape this class (and the
                // documentation initially checked) assumed — Geoapify's
                // true default response format is GeoJSON, not flat JSON,
                // despite "json" being one of the documented `format`
                // values. Explicit here so the parsing below can rely on
                // `results`/`rank`/`result_type` exactly as documented.
                'format' => 'json',
                'apiKey' => $apiKey,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('[Geoapify] Connection failure during forward geocoding', ['error' => $e->getMessage()]);
            throw new GeocodingProviderException('The map location service is currently unavailable.');
        }

        if ($response->failed()) {
            $status = $response->status();
            // Sanitized logging only — never the request URL (it carries
            // apiKey as a query parameter) and never the response body,
            // which could itself echo the query string back.
            Log::warning('[Geoapify] Forward geocoding request failed', ['status' => $status]);

            throw new GeocodingProviderException(match (true) {
                in_array($status, [401, 403], true) => 'The map location service rejected its configured credentials.',
                $status === 429 => 'The map location service is currently rate-limited.',
                $status >= 500 => 'The map location service is currently unavailable.',
                default => 'The map location service returned an error.',
            });
        }

        $data = $response->json();
        if (!is_array($data) || !array_key_exists('results', $data) || !is_array($data['results'])) {
            // A 200 response that doesn't match the documented shape at
            // all — never silently treated as "no candidates" (Part 11).
            Log::warning('[Geoapify] Forward geocoding returned an unexpected response shape');
            throw new GeocodingProviderException('The map location service returned an unexpected response.');
        }

        foreach ($data['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $outcome = $this->tryAcceptCandidate($result);
            if ($outcome !== null) {
                return $outcome;
            }
        }

        // Zero results, or every candidate failed the reliable-match
        // policy/coordinate validation — a genuine, successful "we asked,
        // nothing was reliable enough" outcome, never an exception.
        return GeocodingOutcome::noReliableMatch();
    }

    /**
     * Builds the free-form query text from non-empty components only
     * (Part 6) — a cleaned "25 Riverside Road, Manchester, M3 4AB, United
     * Kingdom" style string, preferred over Geoapify's own structured
     * address parameters (housenumber/street/etc.) because the confirmed
     * `address_line` field is not guaranteed to have those separated.
     */
    private function buildQueryText(array $components): string
    {
        return implode(', ', array_filter([
            $components['address'] ?? null,
            $components['city'] ?? null,
            $components['state'] ?? null,
            $components['postcode'] ?? null,
            $components['country'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Returns a matched GeocodingOutcome only if this one candidate passes
     * every part of the reliable-match policy AND its coordinates are
     * valid — null otherwise (never an exception; a candidate simply
     * failing the policy falls through to the next candidate, or to
     * no-reliable-match if none qualify).
     */
    private function tryAcceptCandidate(array $result): ?GeocodingOutcome
    {
        $resultType = $result['result_type'] ?? null;
        if (!is_string($resultType) || !in_array($resultType, self::ACCEPTED_RESULT_TYPES, true)) {
            return null;
        }

        $confidence = $result['rank']['confidence'] ?? null;
        $minConfidence = (float) config('services.geoapify.min_confidence', 0.95);
        if (!is_numeric($confidence) || (float) $confidence < $minConfidence) {
            return null;
        }

        $latitude = $result['lat'] ?? null;
        $longitude = $result['lon'] ?? null;
        if (!$this->isValidLatitude($latitude) || !$this->isValidLongitude($longitude)) {
            return null;
        }

        $matchType = $result['rank']['match_type'] ?? null;

        return GeocodingOutcome::matched(
            (float) $latitude,
            (float) $longitude,
            (float) $confidence,
            $resultType,
            is_string($matchType) ? $matchType : null,
        );
    }

    /** Never trust a provider value blindly (Part 11) — reject null/NaN/non-numeric/out-of-range. */
    private function isValidLatitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -90 && (float) $value <= 90;
    }

    private function isValidLongitude(mixed $value): bool
    {
        return is_numeric($value) && (float) $value >= -180 && (float) $value <= 180;
    }
}
