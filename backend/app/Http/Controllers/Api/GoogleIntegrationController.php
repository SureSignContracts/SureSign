<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Calendar\CalendarProviderInterface;
use App\Services\Google\GoogleConnectionService;
use App\Services\Google\GoogleHealthService;
use App\Services\Google\GoogleIntegrationReadinessService;
use App\Services\Google\GoogleOAuthService;
use App\Support\Google\GoogleConnectionPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Google Integration Foundation, Stage 4A — the platform-level Admin
 * surface for the Google OAuth connection. Deliberately NOT under any
 * Consultancy route prefix — this connection is owned by the platform,
 * not any one module (see
 * internal-docs/super-admin/google-integration.md's ownership section).
 *
 * `diagnostics()` is read-only and available to Super Admin OR Admin
 * (matches the ai-telemetry/ai-credits read-only precedent — both roles
 * are platform-wide, not customer-org scoped, in this codebase's role
 * model). Every mutating action (connect/callback/disconnect/
 * test-connection) is Super Admin ONLY, mirroring the AI Credits
 * grant/adjust/expire and subscription-assignment precedent for
 * high-consequence platform actions — see routes/api.php's registration
 * of this controller for the exact middleware split.
 *
 * Stage 4A deliberately implements ONLY connection lifecycle + diagnostics
 * — no calendar event/Meet-link endpoint exists anywhere in this
 * controller (Stage 4B's own addition, once Consultancy is a real
 * caller).
 */
class GoogleIntegrationController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauthService,
        private readonly GoogleConnectionService $connectionService,
        private readonly GoogleHealthService $healthService,
        private readonly GoogleIntegrationReadinessService $readinessService,
        private readonly CalendarProviderInterface $calendarProvider,
    ) {
    }

    /**
     * Diagnostics for the Admin Google Integration page — connection
     * identity, health state, and readiness. Never triggers a live Google
     * call itself (see GoogleHealthService's own docblock) — use
     * testConnection() for that.
     */
    public function diagnostics(Request $request)
    {
        $connection = $this->connectionService->current();
        $health = $this->healthService->currentHealth();

        return response()->json([
            'connection'  => GoogleConnectionPresenter::diagnostics($connection),
            'health'      => ['state' => $health['state'], 'missing_scopes' => $health['missing_scopes']],
            'readiness'   => $this->readinessService->check(),
        ]);
    }

    /**
     * Builds the Google consent screen URL. The frontend navigates the
     * browser there directly; Google redirects back to the FRONTEND
     * redirect_uri configured in config('google.redirect_uri'), which then
     * calls callback() below with the returned code/state.
     */
    public function connect(Request $request)
    {
        $result = $this->oauthService->buildAuthorizationUrl($request->user());

        return response()->json(['url' => $result['url']]);
    }

    /**
     * Completes the OAuth exchange. Called by an authenticated frontend
     * page (not by Google directly) with the code/state Google returned to
     * the redirect_uri — see GoogleOAuthService's own docblock for the
     * full CSRF/replay protection this depends on.
     */
    public function callback(Request $request)
    {
        $validated = $request->validate([
            'code'  => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $connection = $this->oauthService->completeConnection($validated['code'], $validated['state'], $request->user());
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['code' => [$e->getMessage()]]);
        }

        return response()->json(['connection' => GoogleConnectionPresenter::diagnostics($connection)]);
    }

    public function disconnect(Request $request)
    {
        $connection = $this->connectionService->disconnect($request->user());

        return response()->json(['connection' => GoogleConnectionPresenter::diagnostics($connection)]);
    }

    /**
     * A real, lightweight, non-destructive live call to Google (see
     * CalendarProviderInterface::testConnection()'s own docblock) —
     * deliberately a distinct action from diagnostics(), which never
     * makes a live call.
     */
    public function testConnection(Request $request)
    {
        $result = $this->calendarProvider->testConnection();

        return response()->json($result);
    }
}
