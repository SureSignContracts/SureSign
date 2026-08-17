<?php

namespace App\Support\Geocoding;

/**
 * Contract-Assisted Project Location, Phase 2 — the internal, provider-
 * agnostic result of one geocoding attempt. `GeoapifyGeocodingProvider` is
 * responsible for translating Geoapify's own HTTP/JSON shape into exactly
 * this — `ProjectGeocodingService`/`ProjectContractSetupSyncService` never
 * see a raw Geoapify field name.
 *
 * Only ever constructed via `matched()`/`noReliableMatch()` — never directly
 * — so a `MATCHED` instance can never exist without valid, range-checked
 * coordinates already attached.
 *
 * A provider/system failure (auth, timeout, rate limit, 5xx, malformed
 * response) is never represented here at all — it's always a
 * `GeocodingProviderException` thrown instead of returned, so a caller can
 * never accidentally treat "the service was unavailable" as "no address
 * candidate was reliable enough."
 */
final class GeocodingOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?float $confidence = null,
        public readonly ?string $resultType = null,
        public readonly ?string $matchType = null,
    ) {
    }

    public static function matched(float $latitude, float $longitude, ?float $confidence, ?string $resultType, ?string $matchType): self
    {
        return new self(GeocodingMatchStatus::MATCHED, $latitude, $longitude, $confidence, $resultType, $matchType);
    }

    public static function noReliableMatch(): self
    {
        return new self(GeocodingMatchStatus::NO_RELIABLE_MATCH);
    }

    public function isMatched(): bool
    {
        return $this->status === GeocodingMatchStatus::MATCHED;
    }
}
