<?php

namespace App\Services\Google;

use App\Models\ActivityLog;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Support\Google\GoogleScopes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Google Integration Foundation, Stage 4A — owns the authorization-URL/
 * callback exchange, via GoogleApiClientInterface (never `\Google\Client`
 * directly — see that interface's own docblock for why this seam exists
 * and how it's tested without live Google communication).
 *
 * Requests `offline` access (so a refresh_token is issued) and
 * `prompt=consent` (so a refresh_token is issued even on a RE-connection —
 * Google only returns one on the very first consent for a given
 * client+account pair otherwise, which would silently break reconnection)
 * — both set unconditionally in GoogleClientAdapter's base client, not
 * decided per-call here. Scopes requested are exactly
 * App\Support\Google\GoogleScopes::REQUIRED plus `openid`/`email` — the
 * minimum needed to also identify WHO connected (Google's ID token
 * `sub`/`email` claims), which the approved diagnostics requirement
 * ("connected account", "connected email") makes necessary. This is a
 * deliberate, narrow addition beyond the single calendar scope, not scope
 * creep — `openid`/`email` grant no calendar/file/contact access of any
 * kind.
 *
 * OAuth CSRF protection: `buildAuthorizationUrl()` generates a random
 * `state` value and stores it in the cache (not the PHP session — this
 * API is a Bearer-token SPA backend, not cookie-session-based, so a value
 * that survives the full-page navigation to Google and back without
 * depending on session affinity is required) for a short, bounded TTL,
 * tied to the initiating Super Admin's user ID. `completeConnection()`
 * consumes that state with `Cache::pull()` — an atomic get-and-delete — so
 * a replayed or duplicated callback (the same code/state submitted twice)
 * finds nothing on the second attempt and is rejected, never silently
 * reprocessed.
 */
class GoogleOAuthService
{
    private const STATE_TTL_MINUTES = 10;
    private const REQUESTED_SCOPES = [...GoogleScopes::REQUIRED, 'openid', 'email'];

    public function __construct(private readonly GoogleApiClientInterface $apiClient)
    {
    }

    /**
     * @return array{url: string, state: string}
     */
    public function buildAuthorizationUrl(User $actor): array
    {
        $state = Str::random(40);
        Cache::put($this->stateCacheKey($state), ['user_id' => $actor->id], now()->addMinutes(self::STATE_TTL_MINUTES));

        $url = $this->apiClient->buildAuthorizationUrl($state, self::REQUESTED_SCOPES);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * @throws \RuntimeException on invalid/expired/replayed state, or a rejected authorization code
     */
    public function completeConnection(string $code, string $state, User $actor): GoogleConnection
    {
        $stateData = Cache::pull($this->stateCacheKey($state));
        if (!$stateData) {
            throw new \RuntimeException('This connection link has expired or was already used. Please try connecting again.');
        }

        $token = $this->apiClient->exchangeAuthorizationCode($code);

        $claims = !empty($token['id_token']) ? $this->apiClient->decodeIdToken($token['id_token']) : [];
        $grantedScopes = isset($token['scope']) ? explode(' ', (string) $token['scope']) : GoogleScopes::REQUIRED;

        // A fresh reconnect always supersedes any previously connected row
        // — never two simultaneously 'connected' rows for the same
        // provider/purpose. The previous row is marked 'disconnected'
        // (never deleted), preserving it as connection history.
        GoogleConnection::where('provider', 'google')
            ->where('purpose', 'primary')
            ->where('status', 'connected')
            ->update(['status' => 'disconnected', 'disconnected_at' => now(), 'access_token' => null, 'refresh_token' => null]);

        $connection = GoogleConnection::create([
            'provider'          => 'google',
            'purpose'           => 'primary',
            'status'            => 'connected',
            'google_account_id' => $claims['sub'] ?? null,
            'connected_email'   => $claims['email'] ?? null,
            'access_token'      => $token['access_token'],
            // Google omits refresh_token on a repeat consent for the same
            // client+account UNLESS prompt=consent forced re-issuance
            // (which the adapter's base client always sets) — still
            // guarded here defensively rather than assumed.
            'refresh_token'     => $token['refresh_token'] ?? null,
            'token_expires_at'  => now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            'scopes'            => $grantedScopes,
            'connected_at'      => now(),
            'connected_by_user_id' => $actor->id,
        ]);

        ActivityLog::record(
            'google.connected',
            'Google account connected.',
            $actor,
            $connection,
            ['connected_email' => $connection->connected_email, 'scopes' => $grantedScopes],
        );

        return $connection;
    }

    private function stateCacheKey(string $state): string
    {
        return "google_oauth_state:{$state}";
    }
}
