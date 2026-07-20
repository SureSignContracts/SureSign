<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\Dashboard\OrganisationDashboardService;
use App\Services\TimezoneResolver;
use Illuminate\Support\Collection;

/**
 * Builds the Global Projects "Organisation Portfolio" payload — portfolio
 * discovery, comparison, search, filtering, and navigation across every
 * accessible project. Distinct from Dashboard (urgent org-wide triage),
 * Global Commercial (detailed live commercial monitoring), and Reports
 * (period-based export) — Projects never duplicates their full
 * functionality, only a compact per-project summary of each.
 *
 * Project attention reuses OrganisationDashboardService::collectAllItems()/
 * projectsRequiringAttention()/urgencySortKey() exactly — Dashboard and
 * Projects must always agree on which projects require attention, so
 * neither this class nor any caller re-implements that rule. Commercial
 * figures reuse CommercialAggregationService exactly as Global Commercial
 * and Reports do.
 */
class ProjectPortfolioService
{
    private const DEFAULT_PER_PAGE = 20;
    private const MAX_PER_PAGE = 50;

    private const VALID_SORTS = ['attention', 'name', 'activity', 'completion_date', 'outstanding'];

    public function __construct(
        private CommercialAggregationService $aggregation,
        private OrganisationDashboardService $dashboard,
    ) {}

    public function build(User $user, array $params): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);
        $allProjects = Project::whereIn('id', $projectIds)
            ->with('organization:id,currency')
            ->get(['id', 'name', 'code', 'status', 'contract_type', 'currency', 'organization_id', 'address', 'city', 'country', 'start_date', 'end_date']);
        $projectsById = $allProjects->keyBy('id');

        $today = TimezoneResolver::today($user, $user->organization);
        $allItems = $this->dashboard->collectAllItems($projectIds, $projectsById, $today);
        $attentionByProject = $this->buildAttentionByProject($allProjects, $allItems);
        $requiringAttentionIds = $this->dashboard->projectsRequiringAttention($allItems);

        // Headline summary always describes the full accessible portfolio,
        // never the current filters — filtered results are the paginated
        // `projects.pagination.total` below, kept clearly separate.
        $summary = [
            'total_projects'               => $allProjects->count(),
            'active_projects'              => $allProjects->where('status', 'active')->count(),
            'projects_requiring_attention' => $requiringAttentionIds->count(),
            // "Completed or closed" = no longer an ongoing project — the two
            // real terminal statuses in this codebase (there is no separate
            // "closed" status).
            'completed_projects'           => $allProjects->whereIn('status', ['completed', 'cancelled'])->count(),
        ];

        $filtered = $this->applyFilters($allProjects, $params, $requiringAttentionIds);
        $filteredIds = $filtered->pluck('id');

        $paTotals          = $this->aggregation->paymentApplicationTotalsByProject($filteredIds);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($filteredIds);
        $contractValues    = $this->aggregation->contractValueByProject($filteredIds);
        $variationTotals   = $this->aggregation->variationTotalsByProject($filteredIds);

        $rows = $filtered->map(fn(Project $project) => $this->buildRow(
            $project, $attentionByProject, $paTotals, $retentionReleased, $contractValues, $variationTotals
        ));

        $sort = in_array($params['sort'] ?? null, self::VALID_SORTS, true) ? $params['sort'] : 'attention';
        $sorted = $this->applySort($rows, $sort);

        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE)));
        $total   = $sorted->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $pageRows = $sorted->slice(($page - 1) * $perPage, $perPage)->values();

        $lastActivityByProject = $this->buildLastActivity($pageRows->pluck('id'), $user);
        $pageRows = $pageRows->map(function (array $row) use ($lastActivityByProject) {
            $row['last_activity'] = $lastActivityByProject->get($row['id']);
            return $row;
        });

        return [
            'summary'  => $summary,
            'projects' => [
                'data'       => $pageRows->values()->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $perPage,
                    'total'        => $total,
                ],
            ],
            'filters' => [
                'statuses'          => $allProjects->pluck('status')->unique()->sort()->values()->all(),
                'currencies'        => $allProjects->pluck('resolved_currency')->unique()->sort()->values()->all(),
                'attention_options' => ['requires_attention', 'on_track'],
            ],
            'meta' => [
                'effective_timezone' => TimezoneResolver::effectiveTimezone($user, $user->organization),
                'generated_at'       => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Per-project attention summary (requires_attention, overdue_count,
     * due_today_count, nearest_deadline) — grouping the exact same item
     * collection Dashboard uses, never a second per-project query.
     */
    private function buildAttentionByProject(Collection $projects, Collection $allItems): Collection
    {
        $grouped = $allItems->groupBy('project_id');

        return $projects->mapWithKeys(function (Project $project) use ($grouped) {
            $items = $grouped->get($project->id, collect());

            $nearest = $items->isEmpty() ? null : $items->sortBy(fn($i) => $this->dashboard->urgencySortKey($i))->first();

            return [$project->id => [
                'requires_attention' => $items->whereIn('status', ['overdue', 'due_today'])->isNotEmpty(),
                'overdue_count'      => $items->where('status', 'overdue')->count(),
                'due_today_count'    => $items->where('status', 'due_today')->count(),
                'nearest_deadline'   => $nearest['due_date'] ?? null,
            ]];
        });
    }

    private function applyFilters(Collection $projects, array $params, Collection $requiringAttentionIds): Collection
    {
        $search     = trim((string) ($params['search'] ?? ''));
        $status     = $params['status'] ?? null;
        $attention  = $params['attention'] ?? null;
        $currency   = $params['currency'] ?? null;

        return $projects->filter(function (Project $project) use ($search, $status, $attention, $currency, $requiringAttentionIds) {
            if ($search !== '') {
                $needle = mb_strtolower($search);
                $haystack = mb_strtolower(implode(' ', array_filter([
                    $project->name, $project->code, $project->address, $project->city, $project->country,
                ])));
                if (!str_contains($haystack, $needle)) {
                    return false;
                }
            }

            if ($status && $status !== 'all' && $project->status !== $status) {
                return false;
            }

            if ($attention === 'requires_attention' && !$requiringAttentionIds->contains($project->id)) {
                return false;
            }
            if ($attention === 'on_track' && $requiringAttentionIds->contains($project->id)) {
                return false;
            }

            if ($currency && $project->resolved_currency !== $currency) {
                return false;
            }

            return true;
        })->values();
    }

    private function buildRow(
        Project $project,
        Collection $attentionByProject,
        Collection $paTotals,
        Collection $retentionReleased,
        Collection $contractValues,
        Collection $variationTotals
    ): array {
        $certified = (float) ($paTotals[$project->id]->certified ?? 0);
        $paid      = (float) ($paTotals[$project->id]->paid ?? 0);
        $retention = $this->aggregation->retentionHeld(
            (float) ($paTotals[$project->id]->retention_withheld ?? 0),
            (float) ($retentionReleased[$project->id]->released ?? 0)
        );
        $attention = $attentionByProject->get($project->id, [
            'requires_attention' => false, 'overdue_count' => 0, 'due_today_count' => 0, 'nearest_deadline' => null,
        ]);

        $location = implode(', ', array_filter([$project->city, $project->country]));

        return [
            'id'              => $project->id,
            'name'            => $project->name,
            'reference'       => $project->code,
            'status'          => $project->status,
            'location'        => $location !== '' ? $location : null,
            'contract_type'   => $project->contract_type,
            'start_date'      => $project->start_date?->toDateString(),
            'completion_date' => $project->end_date?->toDateString(),
            'attention'       => $attention,
            'commercial'      => [
                'currency'            => $project->resolved_currency,
                'contract_value'      => (float) ($contractValues[$project->id]->value ?? 0),
                'certified'           => $certified,
                'paid'                => $paid,
                'outstanding'         => (float) ($certified - $paid),
                'retention_held'      => $retention,
                'approved_variations' => (float) ($variationTotals[$project->id]->approved_value ?? 0),
                'pending_variations'  => (float) ($variationTotals[$project->id]->pending_value ?? 0),
            ],
            'last_activity' => null,
            'urls' => [
                'workspace'  => "/app/projects/{$project->id}/overview",
                'commercial' => "/app/projects/{$project->id}/commercial",
                'documents'  => "/app/projects/{$project->id}/documents",
                'programme'  => "/app/projects/{$project->id}/programme",
            ],
        ];
    }

    /**
     * Deterministic sort. Default: projects requiring attention first, then
     * by overdue-item count descending, then nearest deadline ascending,
     * then project name as the final deterministic tie-break — never
     * database insertion order.
     */
    private function applySort(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'name'            => $rows->sortBy(fn($r) => mb_strtolower($r['name']))->values(),
            'completion_date' => $rows->sortBy(fn($r) => [$r['completion_date'] === null ? 1 : 0, $r['completion_date'] ?? '9999-12-31', mb_strtolower($r['name'])])->values(),
            'activity'        => $rows->sortByDesc(fn($r) => $r['last_activity']['timestamp'] ?? '')->values(),
            // Only meaningful when every row shares one currency (e.g. a
            // currency filter is applied) — mixed-currency rows sort last,
            // grouped by currency, rather than being compared as if the
            // figures were interchangeable.
            'outstanding'     => $rows->sortBy(fn($r) => [$r['commercial']['currency'], -$r['commercial']['outstanding'], mb_strtolower($r['name'])])->values(),
            default           => $rows->sortBy(fn($r) => [
                $r['attention']['requires_attention'] ? 0 : 1,
                -$r['attention']['overdue_count'],
                $r['attention']['nearest_deadline'] ?? '9999-12-31',
                mb_strtolower($r['name']),
            ])->values(),
        };
    }

    /**
     * Last activity for the given (already-paginated) project IDs only —
     * one batched query for the current page, never one query per project.
     * Deliberately not computed for the full filtered set, since only the
     * displayed page needs it.
     */
    private function buildLastActivity(Collection $projectIds, User $user): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }

        $timezone = TimezoneResolver::effectiveTimezone($user, $user->organization);

        // MAX(id) is used as a proxy for "most recent" rather than
        // MAX(created_at) — ProjectActivity rows are always written via
        // ProjectActivityService::record() at the moment the event occurs
        // (no backdating anywhere in the codebase), so id order and
        // created_at order agree. This avoids a second query to resolve
        // ties on the timestamp itself.
        $latestIds = ProjectActivity::whereIn('project_id', $projectIds)
            ->selectRaw('MAX(id) as id')
            ->groupBy('project_id')
            ->pluck('id');

        return ProjectActivity::whereIn('id', $latestIds)
            ->with('user:id,name')
            ->get(['id', 'project_id', 'title', 'user_id', 'created_at'])
            ->keyBy('project_id')
            ->map(fn(ProjectActivity $activity) => [
                'description' => $activity->title,
                'actor'       => $activity->user?->name ?? 'System',
                'timestamp'   => $activity->created_at->copy()->setTimezone($timezone)->toIso8601String(),
            ]);
    }
}
