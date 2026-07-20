<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Commercial\CommercialOverviewService;
use Illuminate\Http\Request;

/**
 * Global Commercial — organisation-wide commercial monitoring and triage.
 *
 * Read-only. Every actionable item returned deep-links into the existing
 * Project Workspace; this controller never creates, edits, certifies, or
 * issues a commercial record. Tenant isolation is enforced entirely via
 * CommercialOverviewService::build()'s use of
 * CommercialAggregationService::scopedProjectIds() — the same org-scoped,
 * Admin-narrowed rule ReportController and DashboardController already use.
 */
class CommercialOverviewController extends Controller
{
    public function __construct(private CommercialOverviewService $overview) {}

    /**
     * GET /commercial/overview
     */
    public function overview(Request $request)
    {
        return response()->json($this->overview->build($request->user()));
    }
}
