<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\Commercial\CommercialReportService;
use App\Services\Commercial\ReportExcelExportService;
use App\Services\TimezoneResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Cross-project reporting — the org-wide counterpart to per-project
 * financial data already surfaced in ProjectController::dashboardIntelligence()
 * and the Commercial page. Reuses the exact same tenant-scoping rule as
 * DashboardController::index() rather than inventing a new one.
 *
 * All certified/paid/retention/variation totals are computed by
 * CommercialAggregationService — the single authoritative implementation
 * also consumed by CommercialOverviewController (Global Commercial). This
 * controller must never recompute those figures independently.
 *
 * Reports is presentation/export-focused (period review, comparison,
 * export) — deliberately distinct from Global Commercial's live
 * operational monitoring. Neither concept is merged into the other; see
 * CommercialReportService for the period-scoped report builder.
 */
class ReportController extends Controller
{
    private const VALID_PERIODS = ['today', 'last_7_days', 'this_month', 'last_month', 'quarter', 'year', 'custom'];

    public function __construct(
        private CommercialAggregationService $aggregation,
        private CommercialReportService $report,
    ) {}

    /**
     * GET /reports/summary — org-wide financial + operational headline
     * figures, backing the Reports page's stat cards.
     */
    public function summary(Request $request)
    {
        $projectIds = $this->aggregation->scopedProjectIds($request->user());

        $paTotals         = $this->aggregation->paymentApplicationTotalsByProject($projectIds);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($projectIds);
        $contractValues   = $this->aggregation->contractValueByProject($projectIds);
        $variationTotals  = $this->aggregation->variationTotalsByProject($projectIds);

        $certifiedToDate = (float) $paTotals->sum('certified');
        $paidToDate      = (float) $paTotals->sum('paid');
        $retentionHeld   = $this->aggregation->retentionHeld(
            (float) $paTotals->sum('retention_withheld'),
            (float) $retentionReleased->sum('released')
        );

        return response()->json([
            'total_contract_value'      => (float) $contractValues->sum('value'),
            'certified_to_date'         => $certifiedToDate,
            'paid_to_date'              => $paidToDate,
            'outstanding_balance'       => (float) ($certifiedToDate - $paidToDate),
            'retention_held'            => $retentionHeld,
            'pending_variations'        => (int) $variationTotals->sum('pending_count'),
            'pending_variations_value'  => (float) $variationTotals->sum('pending_value'),
            'approved_variations_value' => (float) $variationTotals->sum('approved_value'),
            'open_rfis'                 => Rfi::whereIn('project_id', $projectIds)->where('status', 'open')->count(),
        ]);
    }

    /**
     * GET /reports/commercial-summary — per-project breakdown backing the
     * "Commercial Summary" report card's drill-down. Kept unchanged — the
     * richer, period-aware report lives at commercialSummaryReport() below.
     */
    public function commercialSummary(Request $request)
    {
        $projectIds = $this->aggregation->scopedProjectIds($request->user());

        $projects         = Project::whereIn('id', $projectIds)->get(['id', 'name']);
        $paTotals         = $this->aggregation->paymentApplicationTotalsByProject($projectIds);
        $retentionReleased = $this->aggregation->retentionReleasedByProject($projectIds);
        $contractValues   = $this->aggregation->contractValueByProject($projectIds);
        $variationTotals  = $this->aggregation->variationTotalsByProject($projectIds);

        $rows = $projects->map(function (Project $project) use ($paTotals, $retentionReleased, $contractValues, $variationTotals) {
            $certified = (float) ($paTotals[$project->id]->certified ?? 0);
            $paid      = (float) ($paTotals[$project->id]->paid ?? 0);
            $retention = $this->aggregation->retentionHeld(
                (float) ($paTotals[$project->id]->retention_withheld ?? 0),
                (float) ($retentionReleased[$project->id]->released ?? 0)
            );

            return [
                'project_id'                => $project->id,
                'project_name'              => $project->name,
                'contract_value'            => (float) ($contractValues[$project->id]->value ?? 0),
                'certified_to_date'         => $certified,
                'paid_to_date'              => $paid,
                'outstanding_balance'       => (float) ($certified - $paid),
                'retention_held'            => $retention,
                'approved_variations_value' => (float) ($variationTotals[$project->id]->approved_value ?? 0),
                'pending_variations_value'  => (float) ($variationTotals[$project->id]->pending_value ?? 0),
            ];
        });

        return response()->json(['data' => $rows->values()]);
    }

    /**
     * GET /reports/commercial-summary-report — the Phase 5 period-scoped
     * Commercial Summary Report (Reports module). Distinct endpoint from
     * commercialSummary() above: this returns the full report shape
     * (metadata, per-currency financial/pipeline/variation/retention
     * sections, per-project rows) rather than a flat per-project table.
     */
    public function commercialSummaryReport(Request $request)
    {
        $period = $this->resolvePeriod($request, $request->user());

        return response()->json($this->report->build($request->user(), $period));
    }

    /**
     * GET /reports/commercial-summary-report/export/pdf — renders the same
     * report data built above as a branded PDF. Not persisted as a Document
     * (a cross-project report belongs to no single project's register) —
     * streamed directly to the browser, following the same DomPDF +
     * canvas-drawn letterhead pattern already used elsewhere (see
     * SuresignSettingController::letterheadTest() and
     * DocumentGenerationService::generatePdf()), just without a Document
     * record this time since there's no project to attach one to.
     */
    public function exportCommercialSummaryPdf(Request $request)
    {
        $user   = $request->user();
        $period = $this->resolvePeriod($request, $user);
        $data   = $this->report->build($user, $period);

        $branding = BrandingService::forOrganization($user->organization_id);

        $pdf = Pdf::loadView('pdfs.commercial-summary-report', ['report' => $data])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'DejaVu Sans',
            ]);

        $headerAbsPath = BrandingService::headerPath($branding);
        $footerAbsPath = BrandingService::footerPath($branding);

        if ($headerAbsPath || $footerAbsPath) {
            $pdf->render();
            $canvas  = $pdf->getDomPDF()->getCanvas();
            $pageW   = $canvas->get_width();
            $pageH   = $canvas->get_height();
            $headerH = 145 * (72 / 96);
            $footerH = 110 * (72 / 96);

            $canvas->page_script(function (int $pageNum, int $pageCount, $canvas) use (
                $headerAbsPath, $footerAbsPath, $pageW, $pageH, $headerH, $footerH
            ) {
                if ($headerAbsPath) {
                    $canvas->image($headerAbsPath, 0, 0, $pageW, $headerH);
                }
                if ($footerAbsPath) {
                    $canvas->image($footerAbsPath, 0, $pageH - $footerH, $pageW, $footerH);
                }
            });
        }

        $fileName = 'commercial-summary-report-' . $data['metadata']['period']['key'] . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->download($fileName);
    }

    /**
     * GET /reports/commercial-summary-report/export/excel — the same report
     * data as an Excel workbook, via ReportExcelExportService (reuses
     * PhpSpreadsheet + BrandingService exactly as ExcelGenerationService
     * does, but not that class directly — it is hard-wired to generating a
     * single Payment Application's workbook and always saves a Document
     * tied to a project, neither of which applies to an org-wide report).
     */
    public function exportCommercialSummaryExcel(Request $request, ReportExcelExportService $excel)
    {
        $user   = $request->user();
        $period = $this->resolvePeriod($request, $user);
        $data   = $this->report->build($user, $period);

        return $excel->downloadCommercialSummary($data, $user->organization_id);
    }

    /**
     * Resolves the `period` query param (today|last_7_days|this_month|
     * last_month|quarter|year|custom) into a concrete [from, to] date range,
     * anchored on the organisation-effective "today" via TimezoneResolver —
     * no second timezone/date mechanism, per the platform's standing rule.
     * `custom` reads `from`/`to` query params (Y-m-d), swapping them if
     * reversed, and falls back to This Month if either is missing/invalid.
     */
    private function resolvePeriod(Request $request, User $user): array
    {
        $key   = $request->query('period', 'this_month');
        $key   = in_array($key, self::VALID_PERIODS, true) ? $key : 'this_month';
        $today = TimezoneResolver::today($user, $user->organization);

        [$from, $to, $label] = match ($key) {
            'today'       => [$today->copy(), $today->copy(), 'Today'],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy(), 'Last 7 Days'],
            'this_month'  => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth(), 'This Month'],
            'last_month'  => [
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
                'Last Month',
            ],
            'quarter' => [$today->copy()->firstOfQuarter(), $today->copy()->lastOfQuarter(), 'This Quarter'],
            'year'    => [$today->copy()->startOfYear(), $today->copy()->endOfYear(), 'This Year'],
            'custom'  => $this->resolveCustomRange($request, $today),
            default   => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth(), 'This Month'],
        };

        return ['key' => $key, 'label' => $label, 'from' => $from, 'to' => $to];
    }

    private function resolveCustomRange(Request $request, Carbon $today): array
    {
        try {
            $from = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : null;
            $to   = $request->query('to') ? Carbon::parse($request->query('to'))->startOfDay() : null;
        } catch (\Throwable) {
            $from = $to = null;
        }

        if (!$from || !$to) {
            return [$today->copy()->startOfMonth(), $today->copy()->endOfMonth(), 'This Month'];
        }

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, 'Custom Range'];
    }
}
