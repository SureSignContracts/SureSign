<?php

namespace App\Services\Commercial;

use App\Models\Project;
use App\Models\User;
use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the Commercial Summary Report — a presentation/export snapshot of
 * commercial performance over a reporting period, for reviewing, comparing,
 * and exporting rather than live monitoring.
 *
 * Reports and Global Commercial (CommercialOverviewService) are
 * deliberately distinct concepts consuming the SAME underlying totals via
 * CommercialAggregationService — this class never recomputes certified,
 * paid, retention, or variation figures independently. The only things this
 * class adds that Global Commercial doesn't need are: period scoping,
 * currency-grouped narrative summaries, and a report-shaped (non-actionable,
 * non-deep-linking) presentation.
 */
class CommercialReportService
{
    public function __construct(private CommercialAggregationService $aggregation) {}

    public function build(User $user, array $period): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);
        $projects   = Project::whereIn('id', $projectIds)->with('organization:id,currency')->get(['id', 'name', 'currency', 'organization_id', 'status']);

        $from = $period['from'] ?? null;
        $to   = $period['to'] ?? null;

        $paTotals          = $this->aggregation->paymentApplicationTotalsByProject($projectIds, $from, $to);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($projectIds, $from, $to);
        $contractValues    = $this->aggregation->contractValueByProject($projectIds);
        $variationTotals   = $this->aggregation->variationTotalsByProject($projectIds, $from, $to);
        $pipeline          = $this->aggregation->paymentApplicationPipelineByProject($projectIds, $from, $to);

        return [
            'metadata'         => $this->buildMetadata($user, $period, $projects),
            'currency_sections' => $this->buildCurrencySections($projects, $paTotals, $retentionReleased, $variationTotals, $pipeline),
            'projects'         => $this->buildProjectRows($projects, $paTotals, $retentionReleased, $contractValues, $variationTotals),
        ];
    }

    private function buildMetadata(User $user, array $period, Collection $projects): array
    {
        $organization = $user->organization;
        $timezone     = TimezoneResolver::effectiveTimezone($user, $organization);
        $now          = TimezoneResolver::now($user, $organization);
        $currencies   = $projects->pluck('resolved_currency')->unique()->values();

        return [
            'report_type'         => 'Commercial Summary',
            'organisation'        => $organization?->name ?? '—',
            'period'               => [
                'key'   => $period['key'],
                'label' => $period['label'],
                'from'  => $period['from']->toDateString(),
                'to'    => $period['to']->toDateString(),
            ],
            'generated_date'       => $now->format('d F Y'),
            'generated_time'       => $now->format('H:i'),
            'effective_timezone'   => $timezone,
            'generated_by'         => $user->name,
            'currency_context'     => $currencies->count() <= 1
                ? ($currencies->first() ?? '—')
                : 'Multiple currencies (' . $currencies->implode(', ') . ')',
        ];
    }

    /**
     * One section per currency present across accessible projects — never
     * merged, per the approved currency rule shared with Global Commercial.
     */
    private function buildCurrencySections(
        Collection $projects,
        Collection $paTotals,
        Collection $retentionReleased,
        Collection $variationTotals,
        Collection $pipeline
    ): array {
        return $projects->groupBy('resolved_currency')->map(function (Collection $group, string $currency) use (
            $paTotals, $retentionReleased, $variationTotals, $pipeline
        ) {
            $ids = $group->pluck('id');

            $certified = (float) $ids->sum(fn($id) => $paTotals[$id]->certified ?? 0);
            $paid      = (float) $ids->sum(fn($id) => $paTotals[$id]->paid ?? 0);
            $withheld  = (float) $ids->sum(fn($id) => $paTotals[$id]->retention_withheld ?? 0);
            $released  = (float) $ids->sum(fn($id) => $retentionReleased[$id]->released ?? 0);
            $retention = $this->aggregation->retentionHeld($withheld, $released);
            $outstanding = $certified - $paid;

            $pendingVariationValue  = (float) $ids->sum(fn($id) => $variationTotals[$id]->pending_value ?? 0);
            $approvedVariationValue = (float) $ids->sum(fn($id) => $variationTotals[$id]->approved_value ?? 0);

            $awaitingSubmissionCount    = (int) $ids->sum(fn($id) => $pipeline[$id]->awaiting_submission_count ?? 0);
            $awaitingSubmissionValue    = (float) $ids->sum(fn($id) => $pipeline[$id]->awaiting_submission_value ?? 0);
            $awaitingCertificationCount = (int) $ids->sum(fn($id) => $pipeline[$id]->awaiting_certification_count ?? 0);
            $awaitingCertificationValue = (float) $ids->sum(fn($id) => $pipeline[$id]->awaiting_certification_value ?? 0);
            $certifiedUnpaidCount       = (int) $ids->sum(fn($id) => $pipeline[$id]->certified_unpaid_count ?? 0);
            $certifiedUnpaidValue       = (float) $ids->sum(fn($id) => $pipeline[$id]->certified_unpaid_value ?? 0);

            return [
                'currency'           => $currency,
                'project_count'      => $ids->count(),
                'financial_position' => [
                    'certified_total'   => $certified,
                    'paid_total'        => $paid,
                    'outstanding_total' => $outstanding,
                ],
                'retention_position' => [
                    'retention_total' => $retention,
                ],
                'commercial_pipeline' => [
                    'awaiting_submission'    => ['count' => $awaitingSubmissionCount, 'value' => $awaitingSubmissionValue],
                    'awaiting_certification' => ['count' => $awaitingCertificationCount, 'value' => $awaitingCertificationValue],
                    'certified_unpaid'       => ['count' => $certifiedUnpaidCount, 'value' => $certifiedUnpaidValue],
                ],
                'variation_position' => [
                    'approved_variation_value' => $approvedVariationValue,
                    'pending_variation_value'  => $pendingVariationValue,
                ],
                'narrative' => $this->narrative(
                    $currency, $certified, $paid, $outstanding, $retention,
                    $ids->count(), $awaitingCertificationCount, $pendingVariationValue > 0
                ),
            ];
        })->values()->all();
    }

    /**
     * A short, entirely-derived-from-real-figures narrative — never
     * fabricated wording beyond assembling the calculated values above into
     * sentences. Deliberately omits deadline-at-risk language (that is
     * Global Commercial's domain, not Reports').
     */
    private function narrative(
        string $currency,
        float $certified,
        float $paid,
        float $outstanding,
        float $retention,
        int $projectCount,
        int $awaitingCertificationCount,
        bool $hasPendingVariations
    ): string {
        $money = fn(float $v) => $this->formatMoney($v, $currency);

        $sentences = [
            "The organisation has certified {$money($certified)} across {$projectCount} " . ($projectCount === 1 ? 'project' : 'projects') . " in {$currency}.",
            "{$money($paid)} has been received, leaving {$money(abs($outstanding))} " . ($outstanding < 0 ? 'paid in advance of certification' : 'outstanding') . ".",
            "Retention currently held totals {$money($retention)}.",
        ];

        if ($awaitingCertificationCount > 0) {
            $sentences[] = $awaitingCertificationCount === 1
                ? 'One payment application is awaiting certification.'
                : "{$awaitingCertificationCount} payment applications are awaiting certification.";
        }

        if ($hasPendingVariations) {
            $sentences[] = 'Pending variations remain unvalued or unapproved for this period.';
        }

        return implode(' ', $sentences);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $formatted = number_format(abs($amount), 0);
        $sign      = $amount < 0 ? '-' : '';

        return "{$sign}{$currency} {$formatted}";
    }

    private function buildProjectRows(
        Collection $projects,
        Collection $paTotals,
        Collection $retentionReleased,
        Collection $contractValues,
        Collection $variationTotals
    ): array {
        return $projects->map(function (Project $project) use (
            $paTotals, $retentionReleased, $contractValues, $variationTotals
        ) {
            $certified = (float) ($paTotals[$project->id]->certified ?? 0);
            $paid      = (float) ($paTotals[$project->id]->paid ?? 0);
            $retention = $this->aggregation->retentionHeld(
                (float) ($paTotals[$project->id]->retention_withheld ?? 0),
                (float) ($retentionReleased[$project->id]->released ?? 0)
            );

            return [
                'project_id'                => $project->id,
                'project_name'              => $project->name,
                'currency'                  => $project->resolved_currency,
                'status'                    => $project->status,
                'contract_value'            => (float) ($contractValues[$project->id]->value ?? 0),
                'certified'                 => $certified,
                'paid'                      => $paid,
                'outstanding'               => (float) ($certified - $paid),
                'retention'                 => $retention,
                'approved_variation_value'  => (float) ($variationTotals[$project->id]->approved_value ?? 0),
                'pending_variation_value'   => (float) ($variationTotals[$project->id]->pending_value ?? 0),
            ];
        })->values()->all();
    }
}
