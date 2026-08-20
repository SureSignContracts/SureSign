<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Geocoding\CitySuggestionService;
use Illuminate\Http\Request;

/**
 * Global Address UX V3 — the one HTTP surface for `CitySuggestionService`.
 * Authenticated only (this app's normal `auth:sanctum` group,
 * `throttle:60,1` — see this method's own comment) — no organisation/
 * tenant data is ever read or returned here, so there is nothing to scope
 * by organisation; every authenticated user gets the same suggestion
 * behaviour.
 *
 * This is a suggestion service only — GET, no side effects, never
 * persists anything, never touches User/Organization/Project. A
 * misconfigured/unavailable/rate-limited provider always resolves to a
 * plain `{"data": []}` — never a 5xx — since city suggestions must never
 * block onboarding/settings, and `CitySuggestionService` has already done
 * the swallowing; this controller only shapes the request/response.
 *
 * V3 closeout: `region` is deliberately NOT part of this contract. It was
 * accepted here and passed through to the provider in an earlier version,
 * but never actually filtered anything — a live smoke test proved naive
 * region-based query shaping hurts result relevance (see
 * `GeoapifyLocationSuggestionProvider`'s docblock), so it was removed
 * rather than left as a parameter that implies filtering it doesn't
 * perform. A caller submitting `region` today gets no error (it's simply
 * not a recognised field), but it has never had any effect on the
 * response. `CityAutocomplete`'s own `region` prop is a purely frontend UI
 * concern (clearing stale suggestions when Region changes) and is never
 * sent to this endpoint.
 */
class LocationSuggestionController extends Controller
{
    public function __construct(
        private readonly CitySuggestionService $citySuggestionService,
    ) {
    }

    public function cities(Request $request)
    {
        $validated = $request->validate([
            'query'        => 'required|string|max:100',
            // Not validated against countryRegionData's own list — this is
            // a plain ISO alpha-2 shape check, deliberately not a
            // frontend-library-dependent contract (see this feature's
            // brief: "backend validation must not depend on a
            // frontend-only country library").
            'country_code' => 'nullable|string|regex:/^[A-Za-z]{2}$/',
        ]);

        $suggestions = $this->citySuggestionService->suggest(
            $validated['query'],
            $validated['country_code'] ?? null,
        );

        return response()->json(['data' => $suggestions]);
    }
}
