<?php

namespace App\Services\Geocoding;

/**
 * Contract-Assisted Project Location, Phase 2 — thrown for every
 * provider/system failure: missing/invalid API key, authentication
 * rejection, rate limiting, provider 5xx, timeout/connection failure, or a
 * malformed successful response that can't be trusted. Never thrown for "no
 * reliable address candidate" — that's a normal, successful
 * `GeocodingOutcome::noReliableMatch()`, not an exception.
 *
 * The message is always a safe, generic, customer-facing sentence — never
 * the raw provider response, status code, or API key. Callers (the
 * controller) present this message directly; detailed diagnostics belong in
 * the log call the provider makes before throwing, not in this exception's
 * own message.
 */
class GeocodingProviderException extends \RuntimeException
{
}
