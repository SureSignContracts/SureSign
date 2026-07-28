<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Intelligence\SubscriptionIntelligenceService;
use Illuminate\Http\Request;

/**
 * Phase G3 — the Subscription Intelligence Centre's single read-only
 * endpoint. Thin by design: every field is assembled by
 * `SubscriptionIntelligenceService`, scoped to the authenticated user's
 * own organisation — no organisation id is ever accepted from the caller
 * (Stage 14), matching `BillingController`'s existing tenant-isolation
 * convention exactly.
 */
class SubscriptionIntelligenceController extends Controller
{
    public function __construct(private readonly SubscriptionIntelligenceService $intelligence)
    {
    }

    /**
     * GET /billing/intelligence
     */
    public function index(Request $request)
    {
        return response()->json(['data' => $this->intelligence->intelligenceFor($request->user())]);
    }
}
