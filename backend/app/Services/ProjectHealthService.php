<?php

namespace App\Services;

use App\Models\ContractDeadline;
use App\Models\ContractDeliverable;
use App\Models\ContractRisk;
use App\Models\FinalAccount;
use App\Models\PaymentApplication;
use App\Models\Project;

/**
 * Transparent score-based project health calculator.
 *
 * Starts at 100. Deducts points across three domains:
 *   commercial  — overdue payment dates
 *   compliance  — missed deadlines, overdue deliverables
 *   risk        — critical/high risks, non-standard amendments
 *
 * Returns a score (0–100) plus per-domain breakdowns so the frontend
 * can render health bars independently.
 */
class ProjectHealthService
{
    // ── Rating thresholds ────────────────────────────────────────────────────

    public const RATING_HEALTHY            = 'healthy';
    public const RATING_ATTENTION_REQUIRED = 'attention_required';
    public const RATING_CRITICAL           = 'critical';

    // ── Point deductions ────────────────────────────────────────────────────

    private const COMMERCIAL = [
        'overdue_pay_less_per'     => 20,   // max -40
        'overdue_pay_less_cap'     => 40,
        'overdue_notice_per'       => 15,   // max -30
        'overdue_notice_cap'       => 30,
        'overdue_final_date_per'   => 25,   // max -40
        'overdue_final_date_cap'   => 40,
        'final_account_review_overdue_per'  => 15,  // max -15
        'final_account_review_overdue_cap'  => 15,
        'final_account_closeout_overdue_per' => 10, // max -20
        'final_account_closeout_overdue_cap' => 20,
    ];

    private const COMPLIANCE = [
        'missed_deadline_per'  => 10,       // max -25
        'missed_deadline_cap'  => 25,
        'overdue_deliverable_per' => 8,     // max -20
        'overdue_deliverable_cap' => 20,
    ];

    private const RISK = [
        'critical_risk_per'    => 15,       // max -30
        'critical_risk_cap'    => 30,
        'high_risk_per'        => 8,        // max -20
        'high_risk_cap'        => 20,
        'non_standard_per'     => 5,        // max -15
        'non_standard_cap'     => 15,
    ];

    public function getHealth(int $projectId, ?int $contractId = null): array
    {
        // "Overdue" is a business-day concept scoped to this project's own
        // organisation, not the server's UTC calendar day.
        $today = TimezoneResolver::today(null, Project::find($projectId)?->organization)->toDateString();

        // ── Commercial ───────────────────────────────────────────────────────

        $apps = PaymentApplication::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->get();

        $overduePayLess = $apps->filter(fn($a) => $a->pay_less_notice_deadline && $a->pay_less_notice_deadline < $today)->count();
        $overdueNotice  = $apps->filter(fn($a) => $a->payment_notice_deadline  && $a->payment_notice_deadline  < $today)->count();
        $overdueFinal   = $apps->filter(fn($a) => $a->final_date_for_payment   && $a->final_date_for_payment   < $today)->count();

        $finalAccounts = FinalAccount::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->whereNotIn('status', [FinalAccount::STATUS_DRAFT, FinalAccount::STATUS_COMMERCIALLY_CLOSED])
            ->get();

        $faReviewOverdue  = $finalAccounts->filter(fn($fa) => $fa->isReviewOverdue())->count();
        $faCloseOutOverdue = $finalAccounts->filter(fn($fa) => $fa->isCloseOutOverdue())->count();

        $commercialDeductions = min($overduePayLess  * self::COMMERCIAL['overdue_pay_less_per'],   self::COMMERCIAL['overdue_pay_less_cap'])
            + min($overdueNotice  * self::COMMERCIAL['overdue_notice_per'],     self::COMMERCIAL['overdue_notice_cap'])
            + min($overdueFinal   * self::COMMERCIAL['overdue_final_date_per'], self::COMMERCIAL['overdue_final_date_cap'])
            + min($faReviewOverdue  * self::COMMERCIAL['final_account_review_overdue_per'],  self::COMMERCIAL['final_account_review_overdue_cap'])
            + min($faCloseOutOverdue * self::COMMERCIAL['final_account_closeout_overdue_per'], self::COMMERCIAL['final_account_closeout_overdue_cap']);

        $commercialScore = max(0, 100 - $commercialDeductions);

        // ── Compliance ───────────────────────────────────────────────────────

        $contractIds = $contractId
            ? collect([$contractId])
            : \App\Models\Contract::where('project_id', $projectId)->pluck('id');

        $missedDeadlines = ContractDeadline::whereIn('contract_id', $contractIds)
            ->whereNotNull('resolved_date')
            ->whereNotIn('status', [ContractDeadline::STATUS_COMPLETED, ContractDeadline::STATUS_WAIVED, ContractDeadline::STATUS_CANCELLED])
            ->whereRaw('resolved_date < ?', [$today])
            ->count();

        $overdueDeliverables = ContractDeliverable::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->whereNotNull('resolved_date')
            ->whereNotIn('status', [ContractDeliverable::STATUS_ACCEPTED, ContractDeliverable::STATUS_CANCELLED, ContractDeliverable::STATUS_SUBMITTED])
            ->whereRaw('resolved_date < ?', [$today])
            ->count();

        $complianceDeductions = min($missedDeadlines     * self::COMPLIANCE['missed_deadline_per'],     self::COMPLIANCE['missed_deadline_cap'])
            + min($overdueDeliverables * self::COMPLIANCE['overdue_deliverable_per'], self::COMPLIANCE['overdue_deliverable_cap']);

        $complianceScore = max(0, 100 - $complianceDeductions);

        // ── Risk ─────────────────────────────────────────────────────────────

        $risks = ContractRisk::whereIn('contract_id', $contractIds)
            ->where('status', '!=', 'resolved')
            ->get();

        $criticalRisks   = $risks->where('severity', 'critical')->count();
        $highRisks       = $risks->where('severity', 'high')->count();
        $nonStandardAmendments = $risks->where('is_non_standard_amendment', true)->count();

        $riskDeductions = min($criticalRisks       * self::RISK['critical_risk_per'], self::RISK['critical_risk_cap'])
            + min($highRisks          * self::RISK['high_risk_per'],     self::RISK['high_risk_cap'])
            + min($nonStandardAmendments * self::RISK['non_standard_per'],  self::RISK['non_standard_cap']);

        $riskScore = max(0, 100 - $riskDeductions);

        // ── Overall ──────────────────────────────────────────────────────────

        // Weighted: commercial 40%, compliance 35%, risk 25%
        $overallScore = (int) round(
            ($commercialScore * 0.40) + ($complianceScore * 0.35) + ($riskScore * 0.25)
        );

        return [
            'score'  => $overallScore,
            'rating' => $this->rating($overallScore),
            'domains' => [
                'commercial' => [
                    'score'  => (int) $commercialScore,
                    'rating' => $this->rating((int) $commercialScore),
                    'issues' => array_filter([
                        $overduePayLess > 0 ? "{$overduePayLess} overdue pay less notice deadline" . ($overduePayLess > 1 ? 's' : '') : null,
                        $overdueNotice  > 0 ? "{$overdueNotice} overdue payment notice deadline" .  ($overdueNotice  > 1 ? 's' : '') : null,
                        $overdueFinal   > 0 ? "{$overdueFinal} overdue final date for payment" .   ($overdueFinal   > 1 ? 's' : '') : null,
                        $faReviewOverdue  > 0 ? "{$faReviewOverdue} Final Account review overdue" .  ($faReviewOverdue  > 1 ? 's' : '') : null,
                        $faCloseOutOverdue > 0 ? "{$faCloseOutOverdue} Final Account close-out overdue" . ($faCloseOutOverdue > 1 ? 's' : '') : null,
                    ]),
                ],
                'compliance' => [
                    'score'  => (int) $complianceScore,
                    'rating' => $this->rating((int) $complianceScore),
                    'issues' => array_filter([
                        $missedDeadlines    > 0 ? "{$missedDeadlines} missed deadline" .    ($missedDeadlines    > 1 ? 's' : '') : null,
                        $overdueDeliverables > 0 ? "{$overdueDeliverables} overdue deliverable" . ($overdueDeliverables > 1 ? 's' : '') : null,
                    ]),
                ],
                'risk' => [
                    'score'  => (int) $riskScore,
                    'rating' => $this->rating((int) $riskScore),
                    'issues' => array_filter([
                        $criticalRisks        > 0 ? "{$criticalRisks} critical risk" .          ($criticalRisks        > 1 ? 's' : '') : null,
                        $highRisks            > 0 ? "{$highRisks} high risk" .                  ($highRisks            > 1 ? 's' : '') : null,
                        $nonStandardAmendments > 0 ? "{$nonStandardAmendments} non-standard amendment" . ($nonStandardAmendments > 1 ? 's' : '') : null,
                    ]),
                ],
            ],
            'counts' => [
                'overdue_pay_less_notices'  => $overduePayLess,
                'overdue_payment_notices'   => $overdueNotice,
                'overdue_final_dates'       => $overdueFinal,
                'final_account_review_overdue'   => $faReviewOverdue,
                'final_account_closeout_overdue' => $faCloseOutOverdue,
                'missed_deadlines'          => $missedDeadlines,
                'overdue_deliverables'      => $overdueDeliverables,
                'critical_risks'            => $criticalRisks,
                'high_risks'                => $highRisks,
                'non_standard_amendments'   => $nonStandardAmendments,
            ],
        ];
    }

    private function rating(int $score): string
    {
        return match (true) {
            $score >= 80 => self::RATING_HEALTHY,
            $score >= 60 => self::RATING_ATTENTION_REQUIRED,
            default      => self::RATING_CRITICAL,
        };
    }
}
