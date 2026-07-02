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
        $pid = $project->id;

        return [
            'open_rfis'                 => Rfi::where('project_id', $pid)
                ->whereNotIn('status', ['closed', 'responded'])
                ->count(),

            // Variations split by lifecycle stage
            'pending_variations'        => Variation::where('project_id', $pid)
                ->whereIn('status', Variation::IN_PROGRESS_STATUSES)
                ->count(),
            'approved_variations'       => Variation::where('project_id', $pid)
                ->where('status', Variation::STATUS_APPROVED)
                ->count(),
            'approved_variations_value' => (float) Variation::where('project_id', $pid)
                ->where('status', Variation::STATUS_APPROVED)
                ->sum('agreed_amount'),

            'payment_apps'              => PaymentApplication::where('project_id', $pid)->count(),

            'open_snagging'             => Snag::where('project_id', $pid)
                ->where('status', '!=', 'closed')
                ->count(),

            'active_adjudication_cases' => AdjudicationCase::where('project_id', $pid)
                ->whereNotIn('status', ['closed', 'archived'])
                ->count(),

            'documents_count'           => \App\Models\Document::where('project_id', $pid)->count(),

            'contract_value'            => $project->contract_value ?? 0,

            'total_certified'           => (float) PaymentApplication::where('project_id', $pid)
                ->whereNotNull('certified_amount')
                ->sum('certified_amount'),
        ];
    }
}
