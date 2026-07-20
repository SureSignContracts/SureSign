<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiteAdmin\SiteAdministrationOverviewService;
use Illuminate\Http\Request;

/**
 * Site Admin — organisation-wide RFI/Site Instruction/Site Diary/Meeting/
 * EOT Request monitoring and browsing.
 *
 * Read-only. Every row deep-links into the existing Project Workspace;
 * this controller never creates, edits, or deletes a record — those
 * actions remain exclusively in the Project Workspace's own RFIs, Notices,
 * Site Reports and Meetings pages. Tenant isolation is enforced entirely
 * via SiteAdministrationOverviewService::build()'s use of
 * CommercialAggregationService::scopedProjectIds(), the same org-scoped,
 * Admin-narrowed rule every other Global module uses.
 */
class SiteAdministrationController extends Controller
{
    public function __construct(private SiteAdministrationOverviewService $overview) {}

    /**
     * GET /site-administration/overview
     */
    public function overview(Request $request)
    {
        return response()->json($this->overview->build($request->user()));
    }
}
