<?php

namespace App\Services\Dashboard;

use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DeliveryDocument;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Rfi;
use App\Models\User;
use App\Models\Variation;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\DeadlineClassifier;
use App\Services\TimezoneResolver;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Global Dashboard "Organisation Action Centre" payload — the
 * triage-focused starting point answering "what requires attention across
 * the organisation today," distinct from Global Commercial (live detailed
 * commercial monitoring) and Reports (period review/export). Dashboard never
 * duplicates either — Needs Attention items deep-link out to the precise
 * project record, and the Commercial Snapshot reuses
 * CommercialAggregationService's figures rather than recomputing them.
 *
 * All record collectors below use one batched `whereIn('project_id', ...)`
 * query per source type across every accessible project — never a
 * per-project loop — matching the pattern already established by
 * CommercialOverviewService (Global Commercial, Batch 1).
 */
class OrganisationDashboardService
{
    /** Bounded — Dashboard is a triage surface, not a full register. */
    private const NEEDS_ATTENTION_LIMIT = 50;
    private const RECENT_ACTIVITY_LIMIT = 10;

    private const STATUS_ORDER = ['overdue' => 0, 'due_today' => 1, 'due_soon' => 2, 'upcoming' => 3];

    /**
     * Payment Application statuses considered "in progress" for deadline
     * purposes — the same exclusion already used by
     * CommercialOverviewService/OperationalIntelligenceService.
     */
    private const OPEN_APPLICATION_STATUSES_EXCLUDED = ['paid', 'cancelled'];

    public function __construct(private CommercialAggregationService $aggregation) {}

    public function build(User $user): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);
        $projects   = Project::whereIn('id', $projectIds)->with('organization:id,currency')
            ->get(['id', 'name', 'status', 'currency', 'organization_id', 'city', 'country', 'latitude', 'longitude']);
        $projectsById = $projects->keyBy('id');
        $today = TimezoneResolver::today($user, $user->organization);

        // Aggregates computed once, reused by both the payment-application
        // deadline collector below and the Commercial Snapshot — never
        // recalculated a second time within this request.
        $paTotals          = $this->aggregation->paymentApplicationTotalsByProject($projectIds);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($projectIds);

        $allItems = $this->collectAllItems($projectIds, $projectsById, $today);

        $sorted = $this->sortByUrgency($allItems);

        $counts = [
            'overdue'   => $allItems->where('status', 'overdue')->count(),
            'due_today' => $allItems->where('status', 'due_today')->count(),
            'due_soon'  => $allItems->where('status', 'due_soon')->count(),
            'upcoming'  => $allItems->where('status', 'upcoming')->count(),
        ];

        $projectsRequiringAttention = $this->projectsRequiringAttention($allItems);

        return [
            'needs_attention' => [
                'items'  => $sorted->take(self::NEEDS_ATTENTION_LIMIT)->values()->all(),
                'counts' => $counts,
            ],
            'portfolio_health' => [
                'active_projects'             => $projects->where('status', 'active')->count(),
                'projects_with_overdue_items' => $projectsRequiringAttention->count(),
                'total_overdue_items'         => $counts['overdue'],
                'items_due_soon'              => $counts['due_soon'],
            ],
            'commercial_snapshot' => $this->buildCommercialSnapshot($projects, $projectIds, $paTotals, $retentionReleased, $allItems),
            'project_map'         => $this->buildProjectMap($projects, $allItems),
            'recent_activity'     => $this->buildRecentActivity($projectIds, $user),
            'meta' => [
                'effective_timezone'       => TimezoneResolver::effectiveTimezone($user, $user->organization),
                'due_soon_threshold_days'  => DeadlineClassifier::DUE_SOON_THRESHOLD_DAYS,
                'generated_at'             => now()->toIso8601String(),
                'has_projects'             => $projects->isNotEmpty(),
            ],
        ];
    }

    // ── Sorting ───────────────────────────────────────────────────────────

    /**
     * Deterministic urgency ordering: classification bucket first
     * (overdue → due_today → due_soon → upcoming), then closest/most-overdue
     * date within that bucket (ascending `days` sorts most-negative — i.e.
     * most overdue — first, and soonest-due first for due_soon/upcoming),
     * then project_id and type as a final deterministic tie-break so
     * ordering never depends on database insertion order.
     */
    private function sortByUrgency(Collection $items): Collection
    {
        return $items->sortBy(fn($i) => $this->urgencySortKey($i))->values();
    }

    /**
     * The sort key tuple behind the urgency ordering above — public so the
     * Global Projects portfolio (Phase 3) can sort/pick a project's nearest
     * deadline using the identical ordering rule rather than a second
     * status-priority map.
     *
     * @param array{status: string, days: int, project_id: int, type: string} $item
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    public function urgencySortKey(array $item): array
    {
        return [
            self::STATUS_ORDER[$item['status']],
            $item['days'],
            $item['project_id'],
            $item['type'],
        ];
    }

    /**
     * Distinct project IDs with at least one overdue or due-today item, the
     * single authoritative "project requires attention" rule. Public so the
     * Global Projects portfolio (Phase 3) can reuse the exact same rule
     * against the same collected item set, rather than recomputing
     * attention through a second implementation — Dashboard and Projects
     * must always agree on which projects require attention.
     */
    public function projectsRequiringAttention(Collection $items): Collection
    {
        return $items
            ->whereIn('status', ['overdue', 'due_today'])
            ->pluck('project_id')
            ->unique();
    }

    /**
     * Runs all six batched (never per-project) collectors and merges their
     * results into one flat, unsorted, unbounded item collection. Public so
     * the Global Projects portfolio (Phase 3) can reuse the identical
     * collected dataset (grouped per project for attention counts/nearest
     * deadline) instead of duplicating any of the six queries below.
     */
    public function collectAllItems(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return collect()
            ->concat($this->collectRfis($projectIds, $projectsById, $today))
            ->concat($this->collectVariations($projectIds, $projectsById, $today))
            ->concat($this->collectPaymentApplications($projectIds, $projectsById, $today))
            ->concat($this->collectRisks($projectIds, $projectsById, $today))
            ->concat($this->collectDeliveryDocuments($projectIds, $projectsById, $today))
            ->concat($this->collectMilestones($projectIds, $projectsById, $today));
    }

    // ── Collectors (one batched query per source type) ───────────────────

    private function collectRfis(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return Rfi::whereIn('project_id', $projectIds)
            ->whereNotNull('response_due_date')
            ->whereNotIn('status', ['responded', 'closed'])
            ->get()
            ->map(function (Rfi $rfi) use ($projectsById, $today) {
                $project = $projectsById->get($rfi->project_id);
                if (!$project) return null;

                $classified = DeadlineClassifier::classify($today, $rfi->response_due_date);

                return [
                    'type'         => 'rfi',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $rfi->id,
                    'reference'    => "RFI #{$rfi->rfi_number}",
                    'summary'      => $rfi->subject,
                    'due_date'     => $rfi->response_due_date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $rfi->status,
                    'amount'       => null,
                    'currency'     => null,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'rfi', $rfi->id),
                ];
            })
            ->filter()
            ->values();
    }

    private function collectVariations(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return Variation::whereIn('project_id', $projectIds)
            ->whereIn('status', [Variation::STATUS_SUBMITTED, Variation::STATUS_INSTRUCTED])
            ->whereNotNull('quotation_due_date')
            ->get()
            ->map(function (Variation $variation) use ($projectsById, $today) {
                $project = $projectsById->get($variation->project_id);
                if (!$project) return null;

                $classified = DeadlineClassifier::classify($today, $variation->quotation_due_date);

                return [
                    'type'         => 'variation',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $variation->id,
                    'reference'    => $variation->variation_number ?? "Variation #{$variation->id}",
                    'summary'      => $variation->title,
                    'due_date'     => $variation->quotation_due_date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $variation->status,
                    'amount'       => $variation->quoted_amount !== null ? (float) $variation->quoted_amount : null,
                    'currency'     => $project->resolved_currency,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'variation', $variation->id, $variation->trade_package_id),
                ];
            })
            ->filter()
            ->values();
    }

    private function collectPaymentApplications(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        $fields = [
            'payment_notice_deadline'  => 'Payment Notice Deadline',
            'pay_less_notice_deadline' => 'Pay Less Notice Deadline',
            'final_date_for_payment'   => 'Final Date for Payment',
        ];

        $applications = PaymentApplication::whereIn('project_id', $projectIds)
            ->whereNotIn('status', self::OPEN_APPLICATION_STATUSES_EXCLUDED)
            ->get();

        $items = collect();

        foreach ($applications as $app) {
            $project = $projectsById->get($app->project_id);
            if (!$project) continue;

            foreach ($fields as $field => $label) {
                $date = $app->{$field};
                if (!$date) continue;

                $classified = DeadlineClassifier::classify($today, $date);
                $reference  = $app->reference ?? "Application #{$app->application_number}";

                $items->push([
                    'type'         => 'payment_application',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $app->id,
                    'reference'    => $reference,
                    'summary'      => $label,
                    'due_date'     => $date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $app->status,
                    'amount'       => (float) ($app->certified_amount ?? $app->amount_due ?? 0),
                    'currency'     => $project->resolved_currency,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'payment_application', $app->id, $app->trade_package_id),
                ]);
            }
        }

        return $items;
    }

    private function collectRisks(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return ContractRisk::whereIn('project_id', $projectIds)
            ->whereNotNull('review_date')
            ->where('status', '!=', 'resolved')
            ->get()
            ->map(function (ContractRisk $risk) use ($projectsById, $today) {
                $project = $projectsById->get($risk->project_id);
                if (!$project) return null;

                $classified = DeadlineClassifier::classify($today, $risk->review_date);

                return [
                    'type'         => 'contract_risk',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $risk->id,
                    'reference'    => 'Risk Review',
                    'summary'      => $risk->title,
                    'due_date'     => $risk->review_date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $risk->status,
                    'amount'       => null,
                    'currency'     => null,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'contract_risk', $risk->id, $risk->trade_package_id),
                ];
            })
            ->filter()
            ->values();
    }

    private function collectDeliveryDocuments(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return DeliveryDocument::whereIn('project_id', $projectIds)
            ->whereNotNull('due_date')
            ->whereNotIn('status', ['approved', 'superseded'])
            ->get()
            ->map(function (DeliveryDocument $doc) use ($projectsById, $today) {
                $project = $projectsById->get($doc->project_id);
                if (!$project) return null;

                $classified = DeadlineClassifier::classify($today, $doc->due_date);

                return [
                    'type'         => 'delivery_document',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $doc->id,
                    'reference'    => 'Delivery Document',
                    'summary'      => $doc->title,
                    'due_date'     => $doc->due_date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $doc->status,
                    'amount'       => null,
                    'currency'     => null,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'delivery_document', $doc->id, $doc->trade_package_id),
                ];
            })
            ->filter()
            ->values();
    }

    private function collectMilestones(Collection $projectIds, Collection $projectsById, Carbon $today): Collection
    {
        return ContractProgrammeMilestone::whereIn('project_id', $projectIds)
            ->whereNull('actual_date')
            ->where(function ($q) {
                $q->whereNotNull('forecast_date')->orWhereNotNull('planned_date');
            })
            ->get()
            ->map(function (ContractProgrammeMilestone $milestone) use ($projectsById, $today) {
                $project = $projectsById->get($milestone->project_id);
                if (!$project) return null;

                $date = $milestone->forecast_date ?? $milestone->planned_date;
                $classified = DeadlineClassifier::classify($today, $date);

                return [
                    'type'         => 'programme_milestone',
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'source_id'    => $milestone->id,
                    'reference'    => 'Programme Milestone',
                    'summary'      => $milestone->name,
                    'due_date'     => $date->toDateString(),
                    'status'       => $classified['status'],
                    'days'         => $classified['days'],
                    'record_status' => $milestone->status,
                    'amount'       => null,
                    'currency'     => null,
                    'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'programme_milestone', $milestone->id, $milestone->trade_package_id),
                ];
            })
            ->filter()
            ->values();
    }

    // ── Commercial Snapshot ───────────────────────────────────────────────

    /**
     * A small read-only snapshot — outstanding, retention held, applications
     * awaiting certification, and commercial-deadline count — never the
     * full pipeline/project table Global Commercial already shows. Reuses
     * the same paTotals/retentionReleased aggregates computed once in
     * build(), and reuses the already-collected payment_application items
     * for the deadline count rather than issuing a second deadline query.
     */
    private function buildCommercialSnapshot(
        Collection $projects,
        Collection $projectIds,
        Collection $paTotals,
        Collection $retentionReleased,
        Collection $allItems
    ): array {
        $byCurrency = $projects->groupBy('resolved_currency')->map(function (Collection $group, string $currency) use ($paTotals, $retentionReleased) {
            $ids = $group->pluck('id');

            $certified = (float) $ids->sum(fn($id) => $paTotals[$id]->certified ?? 0);
            $paid      = (float) $ids->sum(fn($id) => $paTotals[$id]->paid ?? 0);
            $withheld  = (float) $ids->sum(fn($id) => $paTotals[$id]->retention_withheld ?? 0);
            $released  = (float) $ids->sum(fn($id) => $retentionReleased[$id]->released ?? 0);

            return [
                'currency'          => $currency,
                'outstanding_total' => (float) ($certified - $paid),
                'retention_total'   => $this->aggregation->retentionHeld($withheld, $released),
            ];
        })->values()->all();

        $awaitingCertificationCount = PaymentApplication::whereIn('project_id', $projectIds)
            ->where('status', 'submitted')
            ->count();

        $commercialDeadlineCount = $allItems
            ->where('type', 'payment_application')
            ->whereIn('status', ['overdue', 'due_today', 'due_soon'])
            ->count();

        return [
            'by_currency'                   => $byCurrency,
            'awaiting_certification_count'  => $awaitingCertificationCount,
            'commercial_deadline_count'     => $commercialDeadlineCount,
            'action_url'                    => '/app/commercial',
        ];
    }

    // ── Project Map ───────────────────────────────────────────────────────

    /**
     * Dashboard Project Map (Dashboard Command Center, Part D) — a minimal,
     * pre-scoped marker payload. Only projects with a real, manually-entered
     * latitude AND longitude are included; a project missing either is
     * simply omitted, never plotted at a guessed or 0,0 location. Reuses
     * the same `$projects` collection and `$allItems` set already loaded/
     * collected in build() — no second project query, no per-marker
     * queries. Overdue/due-soon counts come from grouping the already-
     * collected items in memory, not a new aggregation.
     */
    private function buildProjectMap(Collection $projects, Collection $allItems): array
    {
        $itemsByProject = $allItems->groupBy('project_id');

        $mapped = $projects
            ->filter(fn (Project $p) => $p->latitude !== null && $p->longitude !== null)
            ->map(function (Project $p) use ($itemsByProject) {
                $projectItems = $itemsByProject->get($p->id, collect());

                return [
                    'id'           => $p->id,
                    'name'         => $p->name,
                    'status'       => $p->status,
                    'city'         => $p->city,
                    'country'      => $p->country,
                    'latitude'     => (float) $p->latitude,
                    'longitude'    => (float) $p->longitude,
                    'overdue_count'  => $projectItems->where('status', 'overdue')->count(),
                    'due_soon_count' => $projectItems->whereIn('status', ['due_today', 'due_soon'])->count(),
                    'action_url'   => "/app/projects/{$p->id}/overview",
                ];
            })
            ->values();

        return [
            'projects'         => $mapped->all(),
            'total_projects'   => $projects->count(),
            'mapped_projects'  => $mapped->count(),
        ];
    }

    // ── Recent Activity ───────────────────────────────────────────────────

    /**
     * Organisation-scoped activity feed — ProjectActivity is already
     * project-scoped (68 call sites across the codebase record into it), so
     * `whereIn('project_id', $projectIds)` is exactly the same tenant rule
     * as everything else here, not a new query pattern. action_url is
     * omitted: ProjectActivity's `related_type` is a raw class-string with
     * no established mapping to WorkspaceNavigationResolver's source_type
     * strings — returning a guessed URL would risk sending users to the
     * wrong record, so this is left null rather than invented (see delivery
     * report for the recommended follow-up).
     */
    private function buildRecentActivity(Collection $projectIds, User $user): array
    {
        $timezone = TimezoneResolver::effectiveTimezone($user, $user->organization);

        return ProjectActivity::whereIn('project_id', $projectIds)
            ->with(['user:id,name', 'project:id,name'])
            ->latest()
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get(['id', 'project_id', 'user_id', 'activity_type', 'title', 'description', 'created_at'])
            ->map(fn(ProjectActivity $activity) => [
                'id'          => $activity->id,
                'description' => $activity->title,
                'project_id'  => $activity->project_id,
                'project_name' => $activity->project?->name,
                'actor'       => $activity->user?->name ?? 'System',
                'timestamp'   => $activity->created_at->copy()->setTimezone($timezone)->toIso8601String(),
                'action_url'  => null,
            ])
            ->values()
            ->all();
    }
}
