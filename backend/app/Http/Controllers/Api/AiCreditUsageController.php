<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Intelligence\AiCreditUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase G4C.3E — the customer-facing "Monthly AI Usage" presentation
 * endpoint. Thin by design, mirroring SubscriptionIntelligenceController
 * exactly: every field is assembled by AiCreditUsageService, scoped to the
 * authenticated user's own organisation — no organisation id is ever
 * accepted from the caller. No `role:` middleware restricts this route;
 * safety comes entirely from never accepting a caller-supplied id, so it
 * is safe regardless of which role calls it.
 *
 * Never returns a raw allowance or raw used-credit figure — see
 * AiCreditUsageService's own docblock.
 */
class AiCreditUsageController extends Controller
{
    public function __construct(private readonly AiCreditUsageService $usage)
    {
    }

    /**
     * GET /billing/ai-credit-usage
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->usage->usageFor($request->user()));
    }
}
