<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\Variation;
use App\Models\PaymentApplication;
use App\Models\Document;
use App\Services\Dashboard\OrganisationDashboardService;
use App\Services\TimezoneResolver;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private OrganisationDashboardService $organisationDashboard) {}

    /**
     * GET /dashboard/action-centre — Phase 2's Organisation Action Centre:
     * organisation-wide Needs Attention queue, Portfolio Health, Commercial
     * Snapshot, and Recent Activity. Kept as a separate endpoint from
     * index() below (which still backs the pre-Phase-2 stat cards) rather
     * than changing index()'s existing response shape underneath any other
     * consumer that might depend on it.
     */
    public function actionCentre(Request $request)
    {
        return response()->json($this->organisationDashboard->build($request->user()));
    }

    public function index(Request $request)
    {
        $user  = $request->user();
        $orgId = $user->organization_id;

        $projectQuery = Project::where('organization_id', $orgId);
        if ($user->hasRole('Admin')) {
            $projectQuery->whereHas('users', fn($q) => $q->where('user_id', $user->id));
        }

        $projectIds = $projectQuery->pluck('id');

        return response()->json([
            'stats' => [
                'total_projects'         => $projectQuery->count(),
                'active_projects'        => (clone $projectQuery)->where('status', 'active')->count(),
                'open_rfis'              => Rfi::whereIn('project_id', $projectIds)->where('status', 'open')->count(),
                'pending_variations'     => Variation::whereIn('project_id', $projectIds)->where('status', 'pending')->count(),
                // "This month" is this user's own business month, not the
                // server's UTC month — only the month source changed here;
                // the pre-existing whereMonth()-without-whereYear() shape
                // (matches any past year's same month too) is untouched, out
                // of scope for a timezone-only batch.
                'documents_this_month'   => Document::whereIn('project_id', $projectIds)->whereMonth('created_at', TimezoneResolver::now($user)->month)->count(),
                'payment_apps_pending'   => PaymentApplication::whereIn('project_id', $projectIds)->where('status', 'submitted')->count(),
            ],
            'recent_projects' => $projectQuery->with('creator:id,name')->latest()->limit(5)->get(),
            'recent_rfis'     => Rfi::whereIn('project_id', $projectIds)->with('project:id,name')->latest()->limit(5)->get(['id','project_id','rfi_number','subject','status','date_raised']),
            'recent_documents'=> Document::whereIn('project_id', $projectIds)->with('project:id,name')->latest()->limit(5)->get(['id','project_id','title','type','status','created_at']),
        ]);
    }
}
