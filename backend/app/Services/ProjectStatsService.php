<?php

namespace App\Services;

use App\Models\AdjudicationCase;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\Variation;

class ProjectStatsService
{
    public static function getStats(Project $project): array
    {
        return [
            'open_rfis'                  => Rfi::where('project_id', $project->id)
                ->whereNotIn('status', ['closed', 'responded'])
                ->count(),
            'pending_variations'         => Variation::where('project_id', $project->id)
                ->whereIn('status', ['pending', 'submitted'])
                ->count(),
            'payment_apps'               => PaymentApplication::where('project_id', $project->id)->count(),
            'open_snagging'              => Snag::where('project_id', $project->id)
                ->where('status', '!=', 'closed')
                ->count(),
            'active_adjudication_cases'  => AdjudicationCase::where('project_id', $project->id)
                ->whereNotIn('status', ['closed', 'archived'])
                ->count(),
            'documents_count'            => \App\Models\Document::where('project_id', $project->id)->count(),
            'contract_value'             => $project->contract_value ?? 0,
            'total_certified'            => PaymentApplication::where('project_id', $project->id)
                ->whereNotNull('certified_amount')
                ->sum('certified_amount'),
        ];
    }
}
