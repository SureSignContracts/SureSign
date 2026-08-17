<?php

namespace App\Support\Geocoding;

/**
 * Contract-Assisted Project Location, Phase 2 — the only two outcomes a
 * successful geocoding attempt can produce. Deliberately does NOT include a
 * third "failure" status here — a provider/system failure (auth, timeout,
 * rate limit, 5xx, malformed response) is always a
 * `GeocodingProviderException`, never a fake status value on this class.
 * Mixing "the provider genuinely found nothing reliable" with "we couldn't
 * even ask the provider" into one enum would make it too easy for a caller
 * to accidentally treat a real system failure as a harmless no-match — see
 * `GeocodingOutcome`'s own docblock.
 */
final class GeocodingMatchStatus
{
    public const MATCHED = 'matched';
    public const NO_RELIABLE_MATCH = 'no_reliable_match';
}
