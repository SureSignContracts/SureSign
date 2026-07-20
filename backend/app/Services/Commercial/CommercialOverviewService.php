<?php

namespace App\Services\Commercial;

use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use App\Models\Variation;
use App\Services\DeadlineClassifier;
use App\Services\TimezoneResolver;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Global Commercial "overview" payload — organisation-wide cash
 * position, deadlines at risk, awaiting-action pipelines, and a per-project
 * commercial roll-up.
 *
 * Read-only and monitoring/triage only. This service never edits a
 * commercial record — it only aggregates and classifies records that
 * already exist, exactly per the Global Commercial product boundary
 * (Project Workspace remains the sole place commercial records are
 * created/edited/certified).
 *
 * Totals are built on top of CommercialAggregationService — the same
 * authoritative certified/paid/retention/variation calculations Reports
 * uses — so this never recomputes those figures independently.
 */
class CommercialOverviewService
{
    /**
     * Kept as an alias so existing external references (API response field
     * `due_soon_threshold_days`, this class's own doc comments) don't need
     * to change — the authoritative value now lives on DeadlineClassifier,
     * shared with the Dashboard's Needs Attention queue (Phase 2).
     */
    public const DUE_SOON_THRESHOLD_DAYS = DeadlineClassifier::DUE_SOON_THRESHOLD_DAYS;

    /** Payment Application statuses considered "open" — the same exclusion OperationalIntelligenceService already uses for deadline collection. */
    private const OPEN_APPLICATION_STATUSES_EXCLUDED = ['paid', 'cancelled'];

    public function __construct(private CommercialAggregationService $aggregation) {}

    public function build(User $user): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);

        $projects = Project::whereIn('id', $projectIds)->with('organization:id,currency')->get(['id', 'name', 'currency', 'organization_id']);
        $projectsById = $projects->keyBy('id');

        $paTotals          = $this->aggregation->paymentApplicationTotalsByProject($projectIds);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($projectIds);
        $contractValues    = $this->aggregation->contractValueByProject($projectIds);
        $variationTotals   = $this->aggregation->variationTotalsByProject($projectIds);

        $today = TimezoneResolver::today($user, $user->organization);

        $openApplications = PaymentApplication::whereIn('project_id', $projectIds)
            ->whereNotIn('status', self::OPEN_APPLICATION_STATUSES_EXCLUDED)
            ->get();

        $inProgressVariations = Variation::whereIn('project_id', $projectIds)
            ->whereIn('status', Variation::IN_PROGRESS_STATUSES)
            ->get();

        $deadlines      = $this->buildDeadlines($openApplications, $projectsById, $today);
        $awaitingAction = $this->buildAwaitingAction($openApplications, $inProgressVariations, $projectsById);

        return [
            'summary'         => $this->buildCashPositionByCurrency($projects, $paTotals, $retentionReleased),
            'deadlines'       => [
                'due_soon_threshold_days' => self::DUE_SOON_THRESHOLD_DAYS,
                'overdue'                 => $deadlines['overdue'],
                'due_today'               => $deadlines['due_today'],
                'due_soon'                => $deadlines['due_soon'],
                'upcoming'                => $deadlines['upcoming'],
            ],
            'awaiting_action' => $awaitingAction,
            'projects'        => $this->buildProjectRows(
                $projects, $paTotals, $retentionReleased, $contractValues, $variationTotals, $deadlines, $awaitingAction
            ),
            'meta' => [
                'effective_timezone' => TimezoneResolver::effectiveTimezone($user, $user->organization),
                'generated_at'       => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * One Company Cash Position block per currency present across the
     * organisation's accessible projects — never summed across currencies,
     * per the approved currency rule (projects own their own currency;
     * organisations do not; no canonical FX conversion exists).
     */
    private function buildCashPositionByCurrency(Collection $projects, Collection $paTotals, Collection $retentionReleased): array
    {
        return $projects->groupBy('resolved_currency')->map(function (Collection $group, string $currency) use ($paTotals, $retentionReleased) {
            $ids = $group->pluck('id');

            $certified = (float) $ids->sum(fn($id) => $paTotals[$id]->certified ?? 0);
            $paid      = (float) $ids->sum(fn($id) => $paTotals[$id]->paid ?? 0);
            $withheld  = (float) $ids->sum(fn($id) => $paTotals[$id]->retention_withheld ?? 0);
            $released  = (float) $ids->sum(fn($id) => $retentionReleased[$id]->released ?? 0);

            return [
                'currency'          => $currency,
                'certified_total'   => $certified,
                'paid_total'        => $paid,
                // Deliberately NOT clamped — a negative outstanding balance
                // (e.g. paid ahead of certification) is real information the
                // approved rule requires stays visible, not hidden.
                'outstanding_total' => (float) ($certified - $paid),
                'retention_total'   => $this->aggregation->retentionHeld($withheld, $released),
            ];
        })->values()->all();
    }

    /**
     * Deadlines at risk — only the three genuine stored deadline fields
     * approved for Batch 1 (Payment Notice, Pay Less Notice, Final Date for
     * Payment). Classified against organisation-effective "today" using
     * calendar-date semantics (all three fields are DATE-cast), matching
     * OperationalIntelligenceService's own days-from-today convention rather
     * than introducing a second calculation mechanism.
     */
    private function buildDeadlines(Collection $applications, Collection $projectsById, Carbon $today): array
    {
        $fields = [
            'payment_notice_deadline'  => 'Payment Notice Deadline',
            'pay_less_notice_deadline' => 'Pay Less Notice Deadline',
            'final_date_for_payment'   => 'Final Date for Payment',
        ];

        $buckets = ['overdue' => [], 'due_today' => [], 'due_soon' => [], 'upcoming' => []];

        foreach ($applications as $app) {
            $project = $projectsById->get($app->project_id);
            if (!$project) {
                continue;
            }

            foreach ($fields as $field => $label) {
                $date = $app->{$field};
                if (!$date) {
                    continue;
                }

                $classified = DeadlineClassifier::classify($today, $date);
                $status = $classified['status'];

                $buckets[$status][] = [
                    'type'             => $field,
                    'label'            => $label,
                    'project_id'       => $project->id,
                    'project_name'     => $project->name,
                    'reference'        => $app->reference ?? "Application #{$app->application_number}",
                    'amount'           => $this->deadlineAmount($app, $field),
                    'currency'         => $project->resolved_currency,
                    'due_date'         => $date->toDateString(),
                    'days'             => $classified['days'],
                    'status'           => $status,
                    'action_url'       => WorkspaceNavigationResolver::actionUrl(
                        $project->id, 'payment_application', $app->id, $app->trade_package_id
                    ),
                ];
            }
        }

        foreach ($buckets as $status => $items) {
            $buckets[$status] = collect($items)->sortBy('days')->values()->all();
        }

        return $buckets;
    }

    /**
     * The amount genuinely associated with a deadline field — the
     * certified/agreed figure once it exists, otherwise the application's
     * own claimed amount. Never fabricated.
     */
    private function deadlineAmount(PaymentApplication $app, string $field): ?float
    {
        return match (true) {
            $app->certified_amount !== null => (float) $app->certified_amount,
            $app->amount_due !== null       => (float) $app->amount_due,
            default                         => null,
        };
    }

    /**
     * Awaiting-action pipelines — prioritised work, not a raw dump. Payment
     * Applications: awaiting submission (draft), awaiting certification
     * (submitted), certified but unpaid. Variations: awaiting valuation (no
     * quote yet) vs awaiting a decision (quoted/assessed, pending
     * approval/rejection) — real stored statuses per Variation's documented
     * lifecycle, not invented buckets.
     */
    private function buildAwaitingAction(Collection $applications, Collection $variations, Collection $projectsById): array
    {
        $mapApp = function (PaymentApplication $app) use ($projectsById) {
            $project = $projectsById->get($app->project_id);
            if (!$project) {
                return null;
            }

            return [
                'project_id'   => $project->id,
                'project_name' => $project->name,
                'reference'    => $app->reference ?? "Application #{$app->application_number}",
                'status'       => $app->status,
                'amount'       => (float) ($app->certified_amount ?? $app->amount_due ?? $app->gross_valuation ?? 0),
                'currency'     => $project->resolved_currency,
                'date'         => ($app->application_date ?? $app->created_at)?->toDateString(),
                'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'payment_application', $app->id, $app->trade_package_id),
            ];
        };

        $mapVariation = function (Variation $variation) use ($projectsById) {
            $project = $projectsById->get($variation->project_id);
            if (!$project) {
                return null;
            }

            return [
                'project_id'   => $project->id,
                'project_name' => $project->name,
                'reference'    => $variation->variation_number ?? $variation->title,
                'status'       => $variation->status,
                'amount'       => (float) ($variation->agreed_amount ?? $variation->quoted_amount ?? 0),
                'currency'     => $project->resolved_currency,
                'date'         => $variation->variation_date?->toDateString(),
                'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'variation', $variation->id, $variation->trade_package_id),
            ];
        };

        $awaitingSubmission    = $applications->where('status', 'draft');
        $awaitingCertification = $applications->where('status', 'submitted');
        $certifiedUnpaid       = $applications->where('status', 'certified')->whereNull('paid_amount');

        $awaitingValuation = $variations->whereIn('status', [Variation::STATUS_SUBMITTED, Variation::STATUS_INSTRUCTED]);
        $awaitingDecision  = $variations->whereIn('status', [Variation::STATUS_QUOTED, Variation::STATUS_ASSESSED]);

        return [
            'payment_applications' => [
                'awaiting_submission'    => $awaitingSubmission->map($mapApp)->filter()->values()->all(),
                'awaiting_certification' => $awaitingCertification->map($mapApp)->filter()->values()->all(),
                'certified_unpaid'       => $certifiedUnpaid->map($mapApp)->filter()->values()->all(),
            ],
            'variations' => [
                'awaiting_valuation' => $awaitingValuation->map($mapVariation)->filter()->values()->all(),
                'awaiting_decision'  => $awaitingDecision->map($mapVariation)->filter()->values()->all(),
            ],
        ];
    }

    /**
     * Per-project commercial roll-up — one row per accessible project, with
     * an "items requiring attention" count (deadline items + awaiting-action
     * items belonging to that project) so the table can prioritise which
     * project to open first.
     */
    private function buildProjectRows(
        Collection $projects,
        Collection $paTotals,
        Collection $retentionReleased,
        Collection $contractValues,
        Collection $variationTotals,
        array $deadlines,
        array $awaitingAction
    ): array {
        $attentionCounts = [];

        foreach (['overdue', 'due_today', 'due_soon'] as $bucket) {
            foreach ($deadlines[$bucket] as $item) {
                $attentionCounts[$item['project_id']] = ($attentionCounts[$item['project_id']] ?? 0) + 1;
            }
        }
        foreach ($awaitingAction['payment_applications'] as $group) {
            foreach ($group as $item) {
                $attentionCounts[$item['project_id']] = ($attentionCounts[$item['project_id']] ?? 0) + 1;
            }
        }
        foreach ($awaitingAction['variations'] as $group) {
            foreach ($group as $item) {
                $attentionCounts[$item['project_id']] = ($attentionCounts[$item['project_id']] ?? 0) + 1;
            }
        }

        return $projects->map(function (Project $project) use (
            $paTotals, $retentionReleased, $contractValues, $variationTotals, $attentionCounts
        ) {
            $certified = (float) ($paTotals[$project->id]->certified ?? 0);
            $paid      = (float) ($paTotals[$project->id]->paid ?? 0);
            $retention = $this->aggregation->retentionHeld(
                (float) ($paTotals[$project->id]->retention_withheld ?? 0),
                (float) ($retentionReleased[$project->id]->released ?? 0)
            );

            return [
                'project_id'             => $project->id,
                'project_name'           => $project->name,
                'currency'               => $project->resolved_currency,
                'contract_value'         => (float) ($contractValues[$project->id]->value ?? 0),
                'certified'              => $certified,
                'paid'                   => $paid,
                'outstanding'            => (float) ($certified - $paid),
                'retention'              => $retention,
                'pending_variation_value'  => (float) ($variationTotals[$project->id]->pending_value ?? 0),
                'approved_variation_value' => (float) ($variationTotals[$project->id]->approved_value ?? 0),
                'attention_count'        => $attentionCounts[$project->id] ?? 0,
                'action_url'             => "/app/projects/{$project->id}/commercial",
            ];
        })->values()->all();
    }
}
