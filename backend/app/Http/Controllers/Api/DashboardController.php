<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\Variation;
use App\Models\PaymentApplication;
use App\Models\Document;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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
                'documents_this_month'   => Document::whereIn('project_id', $projectIds)->whereMonth('created_at', now()->month)->count(),
                'payment_apps_pending'   => PaymentApplication::whereIn('project_id', $projectIds)->where('status', 'submitted')->count(),
            ],
            'recent_projects' => $projectQuery->with('creator:id,name')->latest()->limit(5)->get(),
            'recent_rfis'     => Rfi::whereIn('project_id', $projectIds)->with('project:id,name')->latest()->limit(5)->get(['id','project_id','rfi_number','subject','status','date_raised']),
            'recent_documents'=> Document::whereIn('project_id', $projectIds)->with('project:id,name')->latest()->limit(5)->get(['id','project_id','title','type','status','created_at']),
        ]);
    }
}
