<?php

namespace App\Services\Google;

/**
 * Google Integration Foundation, Stage 4A — the ONE seam between this
 * codebase and any real Google HTTP call, covering BOTH the OAuth token
 * lifecycle and the Calendar API read used by
 * App\Services\Calendar\GoogleCalendarProvider::testConnection(). Every
 * other Google service (GoogleOAuthService, GoogleTokenRefreshService,
 * GoogleCalendarProvider) depends on THIS interface, never on
 * `\Google\Client`/`\Google\Service\Calendar` directly — mirroring exactly
 * how every Billing service depends on BillingProviderInterface, never on
 * `\Stripe\StripeClient` directly.
 *
 * This is a slightly lower-level seam than BillingProviderInterface's
 * business-shaped methods (createCheckoutSession() etc.) because Google's
 * flow has a genuine OAuth exchange step Stripe's server-side API-key
 * model doesn't — the token lifecycle itself (not just "create an object
 * on the provider") is what must be mockable here.
 */
interface GoogleApiClientInterface
{
    public function buildAuthorizationUrl(string $state, array $scopes): string;

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope: string, id_token?: string}
     * @throws \RuntimeException on a rejected/invalid authorization code
     */
    public function exchangeAuthorizationCode(string $code): array;

    /**
     * Decodes and verifies a Google ID token (signature + issuer/audience),
     * returning its claims — never trusted unverified.
     *
     * @return array{sub?: string, email?: string}
     */
    public function decodeIdToken(string $idToken): array;

    /**
     * @return array{access_token: string, expires_in: int}
     * @throws \RuntimeException if Google rejects the refresh token
     */
    public function refreshAccessToken(string $refreshToken): array;

    /**
     * Best-effort — callers must not treat a thrown exception here as
     * fatal (see GoogleConnectionService::disconnect()).
     */
    public function revokeToken(string $token): void;

    /**
     * Lists at most $maxResults events on the connected account's primary
     * calendar — a real, lightweight, non-destructive read used only for
     * the connection-health test call. Never creates/updates/deletes
     * anything.
     *
     * @throws \RuntimeException on any API failure (network, auth, permissions, not found)
     */
    public function listPrimaryCalendarEvents(string $accessToken, int $maxResults): array;

    /**
     * Stage 4B.1 — inserts one event on the connected account's primary
     * calendar. This is the only method in this interface that mutates
     * anything at Google. $sendUpdates is always passed explicitly by the
     * caller (App\Services\Calendar\GoogleCalendarProvider always passes
     * 'none' for Stage 4B.1 — see that class's own docblock) — never
     * defaulted here.
     *
     * @param  array  $eventBody  A Calendar API Events resource body
     *                            (summary/description/start/end/attendees/extendedProperties).
     * @return array The raw Calendar API Event resource Google returned
     *               (must contain at least 'id').
     * @throws \Google\Service\Exception on a definitive HTTP error response from Google.
     * @throws \GuzzleHttp\Exception\ConnectException|\Throwable on a transport-level
     *         failure with no HTTP response at all (timeout, connection reset, DNS).
     */
    public function insertPrimaryCalendarEvent(string $accessToken, array $eventBody, string $sendUpdates): array;

    /**
     * Stage 4B.1 — the reconciliation read: lists every event on the
     * primary calendar whose `extendedProperties.private[$key] === $value`
     * (Google Calendar API's `privateExtendedProperty` list filter). Read-
     * only — never creates/updates/deletes, and never triggers an
     * attendee notification.
     *
     * @return array<int, array> Each element is a raw Calendar API Event
     *                           resource (at least 'id'). Empty array = no match.
     * @throws \Google\Service\Exception on a definitive HTTP error response from Google.
     * @throws \GuzzleHttp\Exception\ConnectException|\Throwable on a transport-level failure.
     */
    public function listPrimaryCalendarEventsByPrivateProperty(string $accessToken, string $key, string $value): array;
}
