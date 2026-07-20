<?php

namespace App\Services\Commercial;

use App\Models\Contract;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\RetentionRelease;
use App\Models\User;
use App\Models\Variation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Single authoritative source for organisation-wide commercial totals.
 *
 * Both ReportController (Reports) and CommercialOverviewController (Global
 * Commercial) consume this — neither recomputes certified/paid/retention/
 * variation totals independently. Every method here is grouped-by-project in
 * one query rather than looping per project, so callers can build both an
 * org-wide total and a per-project breakdown from the same query results.
 */
class CommercialAggregationService
{
    /**
     * Org-scoped, Admin-narrowed project IDs — the exact tenant-scoping rule
     * already used by DashboardController::index() and (pre-refactor)
     * ReportController::scopedProjectIds().
     */
    public function scopedProjectIds(User $user): Collection
    {
        $query = Project::where('organization_id', $user->organization_id);

        if ($user->hasRole('Admin')) {
            $query->whereHas('users', fn($q) => $q->where('user_id', $user->id));
        }

        return $query->pluck('id');
    }

    /**
     * Per-project certified/paid/retention-withheld totals — one grouped
     * query covering every project in $projectIds. Cancelled applications
     * are excluded everywhere they're not part of a genuine commercial
     * position.
     *
     * $from/$to (both required together) scope the totals to applications
     * whose application_date falls within a reporting period — used by
     * Reports. Global Commercial never passes these, so its always-live,
     * all-time totals are unaffected.
     */
    public function paymentApplicationTotalsByProject(Collection $projectIds, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = PaymentApplication::whereIn('project_id', $projectIds)
            ->where('status', '!=', 'cancelled');
        $this->applyPeriod($query, 'application_date', $from, $to);

        return $query
            ->selectRaw('project_id, SUM(certified_amount) as certified, SUM(paid_amount) as paid, SUM(less_retention) as retention_withheld')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Per-project retention already released — one grouped query.
     * $from/$to scope to releases whose release_date falls within a
     * reporting period — see paymentApplicationTotalsByProject() note.
     */
    public function retentionReleasedByProject(Collection $projectIds, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = RetentionRelease::whereIn('project_id', $projectIds);
        $this->applyPeriod($query, 'release_date', $from, $to);

        return $query
            ->selectRaw('project_id, SUM(release_amount) as released')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Per-project main contract value. Project.contract_value is unused in
     * practice (always 0) — the real figure lives on the main contract's
     * contract_sum, exactly as ReportController::summary() already notes.
     */
    public function contractValueByProject(Collection $projectIds): Collection
    {
        return Contract::whereIn('project_id', $projectIds)
            ->where('type', 'main_contract')
            ->selectRaw('project_id, SUM(contract_sum) as value')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Per-project pending/approved variation totals — one grouped query.
     * Pending value = quoted_amount for Variation::IN_PROGRESS_STATUSES.
     * Approved value = agreed_amount for Variation::STATUS_APPROVED.
     * $from/$to scope to variations whose variation_date falls within a
     * reporting period — see paymentApplicationTotalsByProject() note.
     */
    public function variationTotalsByProject(Collection $projectIds, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $inProgress    = Variation::IN_PROGRESS_STATUSES;
        $inProgressPh  = implode(',', array_fill(0, count($inProgress), '?'));

        $query = Variation::whereIn('project_id', $projectIds);
        $this->applyPeriod($query, 'variation_date', $from, $to);

        return $query
            ->selectRaw(
                "project_id,
                 SUM(CASE WHEN status IN ($inProgressPh) THEN quoted_amount ELSE 0 END) as pending_value,
                 SUM(CASE WHEN status = ? THEN agreed_amount ELSE 0 END) as approved_value,
                 SUM(CASE WHEN status IN ($inProgressPh) THEN 1 ELSE 0 END) as pending_count",
                [...$inProgress, Variation::STATUS_APPROVED, ...$inProgress]
            )
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
    }

    /**
     * Per-project payment-application pipeline counts/values — awaiting
     * submission (draft), awaiting certification (submitted), and
     * certified-but-unpaid — one grouped query. Used by Reports' Commercial
     * Pipeline section; Global Commercial builds its own row-level queue
     * separately since it needs individual actionable items, not counts.
     * $from/$to scope by application_date — see the note above.
     */
    public function paymentApplicationPipelineByProject(Collection $projectIds, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $query = PaymentApplication::whereIn('project_id', $projectIds);
        $this->applyPeriod($query, 'application_date', $from, $to);

        return $query
            ->selectRaw(
                'project_id,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as awaiting_submission_count,
                 SUM(CASE WHEN status = ? THEN amount_due ELSE 0 END) as awaiting_submission_value,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as awaiting_certification_count,
                 SUM(CASE WHEN status = ? THEN amount_due ELSE 0 END) as awaiting_certification_value,
                 SUM(CASE WHEN status = ? AND paid_amount IS NULL THEN 1 ELSE 0 END) as certified_unpaid_count,
                 SUM(CASE WHEN status = ? AND paid_amount IS NULL THEN certified_amount ELSE 0 END) as certified_unpaid_value',
                ['draft', 'draft', 'submitted', 'submitted', 'certified', 'certified']
            )
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
    }

    /** Applies an optional inclusive date-range filter — no-op unless both bounds are given. */
    private function applyPeriod(Builder $query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from && $to) {
            $query->whereBetween($column, [$from->toDateString(), $to->toDateString()]);
        }
    }

    /**
     * Retention held = withheld minus released, clamped at zero — the
     * canonical rule already established by ReportController. A negative raw
     * value (more released than ever withheld, e.g. a data-entry correction)
     * is treated as "nothing currently held," never a negative display value.
     */
    public function retentionHeld(float $withheld, float $released): float
    {
        return max(0.0, $withheld - $released);
    }
}
