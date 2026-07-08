<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\RetentionRelease;
use App\Models\Rfi;
use App\Models\Variation;
use Illuminate\Http\Request;

/**
 * Cross-project reporting — the org-wide counterpart to per-project
 * financial data already surfaced in ProjectController::dashboardIntelligence()
 * and the Commercial page. Reuses the exact same tenant-scoping rule as
 * DashboardController::index() rather than inventing a new one.
 */
class ReportController extends Controller
{
    private function scopedProjectIds(Request $request)
    {
        $user = $request->user();
        $projectQuery = Project::where('organization_id', $user->organization_id);

        if ($user->hasRole('Admin')) {
            $projectQuery->whereHas('users', fn($q) => $q->where('user_id', $user->id));
        }

        return $projectQuery->pluck('id');
    }

    /**
     * GET /reports/summary — org-wide financial + operational headline
     * figures, backing the Reports page's stat cards.
     */
    public function summary(Request $request)
    {
        $projectIds = $this->scopedProjectIds($request);

        $apps = PaymentApplication::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'cancelled');

        $certifiedToDate = (clone $apps)->whereNotNull('certified_amount')->sum('certified_amount');
        $paidToDate      = (clone $apps)->whereNotNull('paid_amount')->sum('paid_amount');
        $retentionHeld   = (clone $apps)->sum('less_retention')
            - RetentionRelease::whereIn('project_id', $projectIds)->sum('release_amount');

        $variations = Variation::whereIn('project_id', $projectIds);

        // Project.contract_value is unused in practice (always 0) — the real
        // figure lives on the main contract's contract_sum.
        $totalContractValue = Contract::whereIn('project_id', $projectIds)
            ->where('type', 'main_contract')
            ->sum('contract_sum');

        return response()->json([
            'total_contract_value'      => (float) $totalContractValue,
            'certified_to_date'         => (float) $certifiedToDate,
            'paid_to_date'              => (float) $paidToDate,
            'outstanding_balance'       => (float) ($certifiedToDate - $paidToDate),
            'retention_held'            => (float) max(0, $retentionHeld),
            'pending_variations'        => (clone $variations)->whereIn('status', Variation::IN_PROGRESS_STATUSES)->count(),
            'pending_variations_value'  => (float) (clone $variations)->whereIn('status', Variation::IN_PROGRESS_STATUSES)->sum('quoted_amount'),
            'approved_variations_value' => (float) (clone $variations)->where('status', Variation::STATUS_APPROVED)->sum('agreed_amount'),
            'open_rfis'                 => Rfi::whereIn('project_id', $projectIds)->where('status', 'open')->count(),
        ]);
    }

    /**
     * GET /reports/commercial-summary — per-project breakdown backing the
     * "Commercial Summary" report card's drill-down.
     */
    public function commercialSummary(Request $request)
    {
        $projectIds = $this->scopedProjectIds($request);

        $projects = Project::whereIn('id', $projectIds)->get(['id', 'name']);

        $rows = $projects->map(function (Project $project) {
            $apps = PaymentApplication::where('project_id', $project->id)->where('status', '!=', 'cancelled');

            $certified = (clone $apps)->whereNotNull('certified_amount')->sum('certified_amount');
            $paid      = (clone $apps)->whereNotNull('paid_amount')->sum('paid_amount');
            $retention = (clone $apps)->sum('less_retention')
                - RetentionRelease::where('project_id', $project->id)->sum('release_amount');

            $variations = Variation::where('project_id', $project->id);

            $contractValue = Contract::where('project_id', $project->id)
                ->where('type', 'main_contract')
                ->sum('contract_sum');

            return [
                'project_id'                => $project->id,
                'project_name'              => $project->name,
                'contract_value'            => (float) $contractValue,
                'certified_to_date'         => (float) $certified,
                'paid_to_date'              => (float) $paid,
                'outstanding_balance'       => (float) ($certified - $paid),
                'retention_held'            => (float) max(0, $retention),
                'approved_variations_value' => (float) (clone $variations)->where('status', Variation::STATUS_APPROVED)->sum('agreed_amount'),
                'pending_variations_value'  => (float) (clone $variations)->whereIn('status', Variation::IN_PROGRESS_STATUSES)->sum('quoted_amount'),
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }
}
