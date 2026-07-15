<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\PayLessNotice;
use App\Models\PaymentApplication;
use App\Models\PaymentApplicationVariation;
use App\Models\PaymentNotice;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\Variation;
use App\Models\ContractAiAnalysis;
use App\Services\DocumentGenerationService;
use App\Services\EmailNotificationService;
use App\Services\ExcelGenerationService;
use App\Services\PaymentDateService;
use App\Services\ProjectActivityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PaymentApplicationController extends Controller
{
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Cap the retention being withheld so that total retention held never exceeds
     * contract_sum × retention_cap_percentage.  Returns the (possibly reduced) amount.
     *
     * Only applies when the contract has both a cap percentage and a contract_sum.
     * Trade-package calls pass $contractSum = null to skip the cap.
     */
    private function applyRetentionCap(
        float  $proposedRetention,
        float  $previousRetentionHeld,
        ?float $contractSum,
        ?float $capPct
    ): float {
        if ($contractSum === null || $capPct === null || $capPct <= 0 || $contractSum <= 0) {
            return $proposedRetention;
        }

        $maxTotal   = round($contractSum * $capPct / 100, 2);
        $remaining  = max(0.0, $maxTotal - $previousRetentionHeld);

        return min($proposedRetention, $remaining);
    }

    /**
     * Calculate previous values for a new payment application from prior applications
     * under the same commercial source (contract or trade package).
     */
    private function calculatePreviousValues(array $scope): array
    {
        $prior = PaymentApplication::where($scope)
            ->whereIn('status', ['certified', 'paid'])
            ->orderBy('application_number')
            ->get();

        $allPrior = PaymentApplication::where($scope)
            ->where('status', '!=', 'cancelled')
            ->count();

        $previousCertified  = $prior->sum(fn($a) => (float) $a->certified_amount);
        $previousPaid       = PaymentApplication::where($scope)
            ->where('status', 'paid')
            ->sum('paid_amount');
        $previousRetention  = $prior->sum(fn($a) => (float) $a->less_retention);
        $latestGross        = $prior->last()?->gross_valuation ?? 0;

        return [
            'previous_certified_value'   => $previousCertified,
            'previous_paid_value'        => (float) $previousPaid,
            'previous_retention_held'    => $previousRetention,
            'previous_gross_valuation'   => (float) $latestGross,
            'previous_applications_count' => $allPrior,
            'less_previous_payments'     => $previousCertified,
        ];
    }

    /**
     * GET /projects/{project}/payment-application-defaults
     *
     * Returns everything the "New Payment Application" form should pre-fill for a given
     * source (contract or trade package) and application date — so the user sees the
     * automation (statutory dates, carried-forward values, next number) before saving.
     *
     * Query params: source=contract|trade_package, contract_id|trade_package_id, application_date
     */
    public function applicationDefaults(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'source'           => 'required|in:contract,trade_package',
            'contract_id'      => 'required_if:source,contract|integer',
            'trade_package_id' => 'required_if:source,trade_package|integer',
            'application_date' => 'nullable|date',
        ]);

        $applicationDate = Carbon::parse($validated['application_date'] ?? now()->toDateString());

        $contract  = null;
        $scope     = [];
        $numberCol = null;

        if ($validated['source'] === 'contract') {
            $contract = Contract::where('id', $validated['contract_id'])
                ->where('project_id', $project->id)
                ->firstOrFail();
            $scope = ['contract_id' => $contract->id];
        } else {
            $tradePackage = TradePackage::where('id', $validated['trade_package_id'])
                ->where('project_id', $project->id)
                ->firstOrFail();
            $scope = ['trade_package_id' => $tradePackage->id];
        }

        // Next application number + whether this is the first application for the source.
        $lastNumber   = PaymentApplication::where($scope)->max('application_number');
        $appNumber    = ($lastNumber ?? 0) + 1;
        $isFirst      = ($lastNumber ?? 0) === 0;

        // Carried-forward values from prior certified/paid applications.
        $previous = $this->calculatePreviousValues($scope);

        // Valuation period start = day after the previous application's period end;
        // for the first application, fall back to the contract commencement date.
        $latestApp = PaymentApplication::where($scope)
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('application_number')
            ->first();

        $valuationStart = null;
        if ($latestApp?->valuation_period_end) {
            $valuationStart = Carbon::parse($latestApp->valuation_period_end)->addDay()->format('Y-m-d');
        } elseif ($contract?->commencement_date) {
            $valuationStart = Carbon::parse($contract->commencement_date)->format('Y-m-d');
        } elseif (isset($tradePackage) && $tradePackage->commencement_date) {
            $valuationStart = Carbon::parse($tradePackage->commencement_date)->format('Y-m-d');
        }

        // Statutory payment dates — derivable for main contracts (rules on the contract /
        // AI analysis) and for trade packages (rules on the package's offset columns).
        $dates = [
            'due_date'                 => null,
            'final_date_for_payment'   => null,
            'payment_notice_deadline'  => null,
            'pay_less_notice_deadline' => null,
        ];

        if ($contract) {
            $aiAnalysis = ContractAiAnalysis::where('contract_id', $contract->id)
                ->where('status', 'confirmed')
                ->latest('completed_at')
                ->first();

            $dates = PaymentDateService::calculateForApplication($applicationDate, $contract, $aiAnalysis);
        } elseif (isset($tradePackage)) {
            $dates = PaymentDateService::calculateForTradePackageApplication($applicationDate, $tradePackage);
        }

        // Retention cap info so the frontend can display the cap and warn when near/at limit
        $retentionCapPct  = null;
        $retentionCapMax  = null;
        $retentionCapLeft = null;

        if ($contract && $contract->retention_cap_percentage > 0 && $contract->contract_sum > 0) {
            $retentionCapPct  = (float) $contract->retention_cap_percentage;
            $retentionCapMax  = round((float) $contract->contract_sum * $retentionCapPct / 100, 2);
            $retentionCapLeft = max(0.0, $retentionCapMax - (float) ($previous['previous_retention_held'] ?? 0));
        }

        // For trade packages: pull defaults from the package's commercial terms if set
        $tradePackageRetentionPct   = null;
        $tradePackagePaymentTerms   = null;
        $tradePackagePaymentFreq    = null;
        if (isset($tradePackage)) {
            $tradePackageRetentionPct = isset($tradePackage->retention_percentage)
                ? (float) $tradePackage->retention_percentage : null;
            $tradePackagePaymentTerms = $tradePackage->payment_terms_days ?? null;
            $tradePackagePaymentFreq  = $tradePackage->payment_frequency ?? null;
        }

        return response()->json([
            'data' => [
                'application_number'          => $appNumber,
                'is_first_application'        => $isFirst,
                'suggested_reference'         => 'AFP-' . str_pad((string) $appNumber, 3, '0', STR_PAD_LEFT),
                'retention_percentage'        => $contract
                    ? (float) ($contract->retention_percentage ?? 0)
                    : $tradePackageRetentionPct,
                'retention_cap_percentage'    => $retentionCapPct,
                'retention_cap_max'           => $retentionCapMax,
                'retention_cap_remaining'     => $retentionCapLeft,
                'trade_package_payment_terms' => $tradePackagePaymentTerms,
                'trade_package_payment_freq'  => $tradePackagePaymentFreq,
                'valuation_period_start'      => $valuationStart,
                'valuation_period_end'        => $applicationDate->format('Y-m-d'),
                'dates'                       => $dates,
                'previous'                    => $previous,
            ],
        ]);
    }

    // GET /projects/{project}/payment-applications
    public function indexByProject(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $apps = PaymentApplication::where('project_id', $project->id)
            ->with([
                'creator:id,name',
                'contract:id,title,reference_number,contract_sum,retention_percentage,party_name',
                'tradePackage:id,name,package_reference,contractor_name',
                'paymentNotices:id,payment_application_id,reference,notice_date,notified_sum,issued_by,status',
                'paymentNotices.documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
                'payLessNotices:id,payment_application_id,payment_notice_id,reference,notice_date,revised_amount_payable,total_deductions,status',
                'payLessNotices.documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
            ])
            ->latest()
            ->paginate(50);

        return response()->json($apps);
    }

    // GET /contracts/{contract}/payment-applications
    public function index(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        return response()->json(
            PaymentApplication::where('contract_id', $contract->id)
                ->with('creator:id,name')
                ->latest()
                ->paginate(25)
        );
    }

    // POST /contracts/{contract}/payment-applications
    public function store(Request $request, Contract $contract)
    {
        $project = $contract->project;
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'application_number'        => 'nullable|integer',
            'reference'                 => 'nullable|string|max:100',
            'application_date'          => 'required|date',
            'valuation_period_start'    => 'nullable|date',
            'valuation_period_end'      => 'nullable|date',
            'due_date'                  => 'nullable|date',
            'final_date_for_payment'    => 'nullable|date',
            'payment_notice_deadline'   => 'nullable|date',
            'pay_less_notice_deadline'  => 'nullable|date',
            'gross_valuation'           => 'required|numeric|min:0',
            'less_retention'            => 'nullable|numeric|min:0',
            'notes'                     => 'nullable|string',
            'breakdown'                 => 'nullable|array',
        ]);

        if (empty($validated['application_number'])) {
            $last = PaymentApplication::where('contract_id', $contract->id)->max('application_number');
            $validated['application_number'] = ($last ?? 0) + 1;
        }

        // Auto-calculate payment dates from contract rules / AI analysis if not supplied
        $missingDates = empty($validated['due_date'])
            || empty($validated['final_date_for_payment'])
            || empty($validated['payment_notice_deadline'])
            || empty($validated['pay_less_notice_deadline']);

        if ($missingDates) {
            $aiAnalysis = ContractAiAnalysis::where('contract_id', $contract->id)
                ->where('status', 'confirmed')
                ->latest('completed_at')
                ->first();

            $calculated = PaymentDateService::calculateForApplication(
                Carbon::parse($validated['application_date']),
                $contract,
                $aiAnalysis
            );

            foreach ($calculated as $key => $value) {
                if (empty($validated[$key]) && $value !== null) {
                    $validated[$key] = $value;
                }
            }
        }

        $previousValues = $this->calculatePreviousValues(['contract_id' => $contract->id]);

        $gross     = (float) $validated['gross_valuation'];
        $retPct    = (float) ($contract->retention_percentage ?? 0);
        $retention = isset($validated['less_retention'])
            ? (float) $validated['less_retention']
            : ($retPct > 0 ? round($gross * $retPct / 100, 2) : 0);

        // Enforce retention cap
        $retention = $this->applyRetentionCap(
            $retention,
            (float) ($previousValues['previous_retention_held'] ?? 0),
            isset($contract->contract_sum) ? (float) $contract->contract_sum : null,
            isset($contract->retention_cap_percentage) ? (float) $contract->retention_cap_percentage : null
        );

        $previous  = $previousValues['less_previous_payments'];
        $amountDue = max(0, $gross - $retention - $previous);

        $app = PaymentApplication::create(array_merge($validated, $previousValues, [
            'project_id'             => $project->id,
            'contract_id'            => $contract->id,
            'organization_id'        => $project->organization_id,
            'created_by'             => $request->user()->id,
            'less_retention'         => $retention,
            'less_previous_payments' => $previous,
            'amount_due'             => $amountDue,
            'status'                 => 'draft',
        ]));

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_created',
            "Payment Application #{$app->application_number} created",
            "Gross valuation: " . number_format($gross, 2) . " — Amount due: " . number_format($amountDue, 2),
            $app
        );

        return response()->json($app->load('creator:id,name'), 201);
    }

    // POST /projects/{project}/trade-packages/{tradePackage}/payment-applications
    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'application_number'        => 'nullable|integer',
            'reference'                 => 'nullable|string|max:100',
            'application_date'          => 'required|date',
            'valuation_period_start'    => 'nullable|date',
            'valuation_period_end'      => 'nullable|date',
            'due_date'                  => 'nullable|date',
            'final_date_for_payment'    => 'nullable|date',
            'payment_notice_deadline'   => 'nullable|date',
            'pay_less_notice_deadline'  => 'nullable|date',
            'gross_valuation'           => 'required|numeric|min:0',
            'less_retention'            => 'nullable|numeric|min:0',
            'notes'                     => 'nullable|string',
            'breakdown'                 => 'nullable|array',
        ]);

        if (empty($validated['application_number'])) {
            $last = PaymentApplication::where('trade_package_id', $tradePackage->id)->max('application_number');
            $validated['application_number'] = ($last ?? 0) + 1;
        }

        // Calculate statutory dates from the package's payment rules if the client
        // did not supply them (keeps subcontract applications HGCRA-compliant).
        $hasAnyDate = !empty($validated['due_date'])
            || !empty($validated['final_date_for_payment'])
            || !empty($validated['payment_notice_deadline'])
            || !empty($validated['pay_less_notice_deadline']);

        if (!$hasAnyDate) {
            $calculated = PaymentDateService::calculateForTradePackageApplication(
                Carbon::parse($validated['application_date']),
                $tradePackage
            );
            $validated['due_date']                 = $validated['due_date']                 ?? $calculated['due_date'];
            $validated['final_date_for_payment']   = $validated['final_date_for_payment']   ?? $calculated['final_date_for_payment'];
            $validated['payment_notice_deadline']  = $validated['payment_notice_deadline']  ?? $calculated['payment_notice_deadline'];
            $validated['pay_less_notice_deadline'] = $validated['pay_less_notice_deadline'] ?? $calculated['pay_less_notice_deadline'];
        }

        $previousValues = $this->calculatePreviousValues(['trade_package_id' => $tradePackage->id]);

        $gross  = (float) $validated['gross_valuation'];

        // Default retention from trade package's own retention_percentage if not supplied
        if (isset($validated['less_retention'])) {
            $retention = (float) $validated['less_retention'];
        } elseif (isset($tradePackage->retention_percentage) && (float) $tradePackage->retention_percentage > 0) {
            $retention = round($gross * (float) $tradePackage->retention_percentage / 100, 2);
        } else {
            $retention = 0.0;
        }

        // Trade packages: apply cap if the package has a contract_value set
        $retention = $this->applyRetentionCap(
            $retention,
            (float) ($previousValues['previous_retention_held'] ?? 0),
            isset($tradePackage->contract_value) ? (float) $tradePackage->contract_value : null,
            null // Trade packages don't have a separate retention cap % — cap only applies to main contracts
        );

        $previous  = $previousValues['less_previous_payments'];
        $amountDue = max(0, $gross - $retention - $previous);

        $app = PaymentApplication::create(array_merge($validated, $previousValues, [
            'project_id'             => $project->id,
            'trade_package_id'       => $tradePackage->id,
            'organization_id'        => $project->organization_id,
            'created_by'             => $request->user()->id,
            'less_retention'         => $retention,
            'less_previous_payments' => $previous,
            'amount_due'             => $amountDue,
            'status'                 => 'draft',
        ]));

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_created',
            "Payment Application #{$app->application_number} created for {$tradePackage->name}",
            "Gross valuation: " . number_format($gross, 2) . " — Amount due: " . number_format($amountDue, 2),
            $app
        );

        return response()->json($app->load('creator:id,name'), 201);
    }

    public function show(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        return response()->json(
            $paymentApplication->load([
                'creator:id,name', 'contract', 'tradePackage', 'project',
                'paymentNotices.documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
                'payLessNotices.documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
                'linkedVariations',
            ])
        );
    }

    public function update(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'draft') {
            return response()->json(['message' => 'Only draft applications can be edited.'], 422);
        }

        $validated = $request->validate([
            'reference'                 => 'nullable|string|max:100',
            'application_date'          => 'sometimes|date',
            'valuation_period_start'    => 'nullable|date',
            'valuation_period_end'      => 'nullable|date',
            'due_date'                  => 'nullable|date',
            'final_date_for_payment'    => 'nullable|date',
            'payment_notice_deadline'   => 'nullable|date',
            'pay_less_notice_deadline'  => 'nullable|date',
            'gross_valuation'           => 'sometimes|numeric|min:0',
            'less_retention'            => 'nullable|numeric|min:0',
            'less_previous_payments'    => 'nullable|numeric|min:0',
            'notes'                     => 'nullable|string',
            'breakdown'                 => 'nullable|array',
        ]);

        if (isset($validated['gross_valuation']) || isset($validated['less_retention']) || isset($validated['less_previous_payments'])) {
            $gross     = (float) ($validated['gross_valuation'] ?? $paymentApplication->gross_valuation);
            $retention = (float) ($validated['less_retention'] ?? $paymentApplication->less_retention ?? 0);
            $previous  = (float) ($validated['less_previous_payments'] ?? $paymentApplication->less_previous_payments ?? 0);
            $validated['amount_due'] = max(0, $gross - $retention - $previous);
        }

        // Backfill any payment dates that are still null after an edit
        $contract = $paymentApplication->contract;
        if ($contract) {
            $missingDates = empty($validated['due_date'] ?? $paymentApplication->due_date)
                || empty($validated['final_date_for_payment'] ?? $paymentApplication->final_date_for_payment)
                || empty($validated['payment_notice_deadline'] ?? $paymentApplication->payment_notice_deadline)
                || empty($validated['pay_less_notice_deadline'] ?? $paymentApplication->pay_less_notice_deadline);

            if ($missingDates) {
                $baseDate   = Carbon::parse($validated['application_date'] ?? $paymentApplication->application_date);
                $aiAnalysis = ContractAiAnalysis::where('contract_id', $contract->id)
                    ->where('status', 'confirmed')->latest('completed_at')->first();
                $calculated = PaymentDateService::calculateForApplication($baseDate, $contract, $aiAnalysis);
                foreach ($calculated as $key => $value) {
                    if (empty($validated[$key]) && empty($paymentApplication->$key) && $value !== null) {
                        $validated[$key] = $value;
                    }
                }
            }
        }

        $paymentApplication->update($validated);

        return response()->json($paymentApplication->fresh()->load('creator:id,name'));
    }

    // POST /payment-applications/{paymentApplication}/submit
    public function submit(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'draft') {
            return response()->json(['message' => 'Only draft applications can be submitted.'], 422);
        }

        $paymentApplication->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
        ]);

        ActivityLog::record('payment_application.submitted', 'Payment Application #' . $paymentApplication->application_number . ' submitted', $request->user(), $paymentApplication, [], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_submitted',
            "Payment Application #{$paymentApplication->application_number} submitted",
            null, $paymentApplication
        );

        EmailNotificationService::send(
            'payment_application.submitted',
            'New Payment Application Submitted',
            "Payment Application #{$paymentApplication->application_number} has been submitted for project: {$project->name}.",
            [],
            $project->organization
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/certify
    public function certify(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'submitted') {
            return response()->json(['message' => 'Only submitted applications can be certified.'], 422);
        }

        $validated = $request->validate([
            'certified_amount'      => 'required|numeric|min:0',
            'certified_date'        => 'nullable|date',
            'certificate_reference' => 'nullable|string|max:100',
            'certificate_notes'     => 'nullable|string',
        ]);

        $paymentApplication->update(array_merge($validated, [
            'status'         => 'certified',
            'certified_date' => $validated['certified_date'] ?? now()->toDateString(),
            'certified_at'   => now(),
            'certified_by'   => $request->user()->id,
        ]));

        $paymentApplication->load(['contract', 'tradePackage']);
        $certifiedByUser = $request->user();

        try {
            $certRef = $paymentApplication->certificate_reference ?? "CERT-{$paymentApplication->application_number}";
            DocumentGenerationService::generatePdf(
                $project, $request->user(),
                'pdfs.payment-certificate',
                ['paymentApplication' => $paymentApplication, 'certifiedBy' => $certifiedByUser],
                "Payment Certificate — Application #{$paymentApplication->application_number}",
                'payment_certificate', '02_Commercial', $certRef, $paymentApplication, false, $paymentApplication->tradePackage
            );
        } catch (\Throwable $e) {
            \Log::warning("Certificate PDF generation failed: " . $e->getMessage());
        }

        ActivityLog::record('payment_application.certified', 'Payment Application #' . $paymentApplication->application_number . ' certified — £' . number_format($validated['certified_amount'], 2), $request->user(), $paymentApplication, ['certified_amount' => $validated['certified_amount']], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_certified',
            "Payment Application #{$paymentApplication->application_number} certified",
            "Certified amount: " . number_format($validated['certified_amount'], 2),
            $paymentApplication
        );

        EmailNotificationService::send(
            'payment_application.certified',
            'Payment Application Certified',
            "Payment Application #{$paymentApplication->application_number} has been certified. Certified amount: {$paymentApplication->certified_amount}.",
            [],
            $project->organization
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/mark-paid
    public function markPaid(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'certified') {
            return response()->json(['message' => 'Only certified applications can be marked as paid.'], 422);
        }

        $validated = $request->validate([
            'paid_amount'       => 'required|numeric|min:0',
            'payment_date'      => 'nullable|date',
            'payment_reference' => 'nullable|string|max:100',
            'notes'             => 'nullable|string',
        ]);

        $paymentApplication->update(array_merge($validated, [
            'status'       => 'paid',
            'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
            'paid_at'      => now(),
            'paid_by'      => $request->user()->id,
        ]));

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_paid',
            "Payment Application #{$paymentApplication->application_number} marked as paid",
            "Paid amount: " . number_format($validated['paid_amount'], 2)
                . (($validated['payment_reference'] ?? null) ? " — Ref: {$validated['payment_reference']}" : ''),
            $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/cancel
    public function cancel(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['submitted', 'payment_notice_issued', 'pay_less_notice_issued'])) {
            return response()->json(['message' => 'Only submitted applications can be cancelled.'], 422);
        }

        $paymentApplication->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        ActivityLog::record('payment_application.cancelled', 'Payment Application #' . $paymentApplication->application_number . ' cancelled', $request->user(), $paymentApplication, [], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_cancelled',
            "Payment Application #{$paymentApplication->application_number} cancelled",
            null, $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/withdraw
    public function withdraw(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'submitted') {
            return response()->json(['message' => 'Only submitted applications can be withdrawn.'], 422);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $paymentApplication->update([
            'status'           => 'draft',
            'submitted_at'     => null,
            'submitted_by'     => null,
            'withdrawal_count' => $paymentApplication->withdrawal_count + 1,
            'withdrawn_at'     => now(),
            'withdrawn_by'     => $request->user()->id,
            'withdrawal_reason' => $validated['reason'] ?? null,
        ]);

        ActivityLog::record(
            'payment_application.withdrawn',
            'Payment Application #' . $paymentApplication->application_number . ' withdrawn back to draft',
            $request->user(), $paymentApplication,
            ['reason' => $validated['reason'] ?? null],
            $project->id, $project->organization_id
        );

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_withdrawn',
            "Payment Application #{$paymentApplication->application_number} withdrawn to draft",
            $validated['reason'] ?? null,
            $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // DELETE /payment-applications/{paymentApplication}
    public function destroy(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['draft', 'cancelled'])) {
            return response()->json(['message' => 'Only draft or cancelled applications can be deleted.'], 422);
        }

        $appNum = $paymentApplication->application_number;
        $paymentApplication->delete();

        ActivityLog::record('payment_application.deleted', 'Payment Application #' . $appNum . ' deleted', $request->user(), null, ['application_number' => $appNum], $project->id, $project->organization_id);

        return response()->json(['message' => 'Payment application deleted.']);
    }

    // POST /payment-applications/{paymentApplication}/generate-pdf
    public function generatePdf(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $paymentApplication->load(['contract', 'creator:id,name', 'tradePackage']);

        $document = DocumentGenerationService::generatePdf(
            $project, $request->user(),
            'pdfs.payment-application',
            ['paymentApplication' => $paymentApplication],
            "Payment Application #{$paymentApplication->application_number}",
            'payment_app', '02_Commercial', $paymentApplication->reference, $paymentApplication, false, $paymentApplication->tradePackage
        );

        ProjectActivityService::record(
            $project, $request->user(),
            'pdf_generated',
            "PDF generated: Payment Application #{$paymentApplication->application_number}",
            null, $document
        );

        return response()->json($document, 201);
    }

    // POST /payment-applications/{paymentApplication}/generate-certificate
    public function generateCertificate(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['certified', 'paid'])) {
            return response()->json(['message' => 'Application must be certified to generate a certificate.'], 422);
        }

        $paymentApplication->load(['contract', 'tradePackage']);
        $certRef = $paymentApplication->certificate_reference ?? "CERT-{$paymentApplication->application_number}";

        $document = DocumentGenerationService::generatePdf(
            $project, $request->user(),
            'pdfs.payment-certificate',
            [
                'paymentApplication' => $paymentApplication,
                'certifiedBy'        => \App\Models\User::find($paymentApplication->certified_by),
            ],
            "Payment Certificate — Application #{$paymentApplication->application_number}",
            'payment_certificate', '02_Commercial', $certRef, $paymentApplication, false, $paymentApplication->tradePackage
        );

        return response()->json($document, 201);
    }

    // POST /payment-applications/{paymentApplication}/payment-notice
    public function createPaymentNotice(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['submitted', 'certified'])) {
            return response()->json(['message' => 'Payment Notices can only be issued on submitted or certified applications.'], 422);
        }

        $validated = $request->validate([
            'notice_date'         => 'required|date',
            'reference'           => 'nullable|string|max:100',
            'notified_sum'        => 'required|numeric|min:0',
            'basis_of_assessment' => 'nullable|string',
            'issued_by'           => 'nullable|string|max:200',
        ]);

        $isLate = $paymentApplication->payment_notice_deadline
            && \Carbon\Carbon::parse($validated['notice_date'])->gt($paymentApplication->payment_notice_deadline);

        $notice = PaymentNotice::create(array_merge($validated, [
            'project_id'             => $project->id,
            'organization_id'        => $project->organization_id,
            'created_by'             => $request->user()->id,
            'payment_application_id' => $paymentApplication->id,
            'status'                 => 'issued',
            'is_late'                => $isLate,
        ]));

        $notice->load([
            'paymentApplication.contract',
            'paymentApplication.tradePackage',
            'paymentApplication.linkedVariations',
            'paymentApplication.project',
            'creator:id,name',
        ]);

        $generatedDocument = null;
        try {
            $ref = $notice->reference ?? "PN-{$paymentApplication->application_number}";
            $generatedDocument = DocumentGenerationService::generatePdf(
                $project, $request->user(),
                'pdfs.payment-notice',
                [
                    'paymentNotice' => $notice,
                    'issuedBy'      => $request->user(),
                ],
                "Payment Notice — Application #{$paymentApplication->application_number}",
                'payment_notice', '02_Commercial', $ref, $notice, true, $notice->paymentApplication->tradePackage
            );
        } catch (\Throwable $e) {
            \Log::warning("Payment Notice PDF generation failed: " . $e->getMessage());
        }

        ActivityLog::record('payment_notice.issued', 'Payment Notice issued on Application #' . $paymentApplication->application_number . ' — notified sum £' . number_format($validated['notified_sum'], 2), $request->user(), $notice, ['notified_sum' => $validated['notified_sum']], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_notice_issued',
            "Payment Notice issued on Application #{$paymentApplication->application_number}",
            "Notified sum: " . number_format($validated['notified_sum'], 2),
            $notice
        );

        return response()->json([
            'notice'   => $notice,
            'document' => $generatedDocument,
        ], 201);
    }

    // POST /payment-applications/{paymentApplication}/pay-less-notice
    public function createPayLessNotice(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['submitted', 'certified'])) {
            return response()->json(['message' => 'Pay Less Notices can only be issued on submitted or certified applications.'], 422);
        }

        $validated = $request->validate([
            'notice_date'              => 'required|date',
            'reference'                => 'nullable|string|max:100',
            'original_amount_due'      => 'required|numeric|min:0',
            'total_deductions'         => 'required|numeric|min:0',
            'deduction_reason'         => 'required|string',
            'detailed_deduction_notes' => 'nullable|string',
            'issued_by'                => 'nullable|string|max:200',
            'payment_notice_id'        => 'nullable|integer|exists:payment_notices,id',
            // Legacy compatibility
            'amount'                   => 'nullable|numeric|min:0',
            'notified_sum'             => 'nullable|numeric|min:0',
            'reason'                   => 'nullable|string',
            'basis_of_difference'      => 'nullable|string',
        ]);

        $originalAmountDue    = (float) $validated['original_amount_due'];
        $totalDeductions      = (float) $validated['total_deductions'];
        $revisedAmountPayable = max(0, $originalAmountDue - $totalDeductions);

        $isLate = $paymentApplication->pay_less_notice_deadline
            && \Carbon\Carbon::parse($validated['notice_date'])->gt($paymentApplication->pay_less_notice_deadline);

        $notice = PayLessNotice::create(array_merge($validated, [
            'project_id'             => $project->id,
            'organization_id'        => $project->organization_id,
            'created_by'             => $request->user()->id,
            'payment_application_id' => $paymentApplication->id,
            'revised_amount_payable' => $revisedAmountPayable,
            'notified_sum'           => $revisedAmountPayable,
            'amount'                 => $totalDeductions,
            'reason'                 => $validated['deduction_reason'],
            'status'                 => 'issued',
            'is_late'                => $isLate,
        ]));

        $notice->load(['paymentApplication.contract', 'paymentApplication.tradePackage', 'paymentNotice']);

        $generatedDocument = null;
        try {
            $ref = $notice->reference ?? "PLN-{$paymentApplication->application_number}";
            $generatedDocument = DocumentGenerationService::generatePdf(
                $project, $request->user(),
                'pdfs.pay-less-notice',
                ['payLessNotice' => $notice, 'issuedBy' => $request->user()],
                "Pay Less Notice — Application #{$paymentApplication->application_number}",
                'pay_less_notice', '02_Commercial', $ref, $notice, false, $notice->paymentApplication->tradePackage
            );
        } catch (\Throwable $e) {
            \Log::warning("Pay Less Notice PDF generation failed: " . $e->getMessage());
        }

        ActivityLog::record('pay_less_notice.issued', 'Pay Less Notice issued on Application #' . $paymentApplication->application_number . ' — revised payable £' . number_format($revisedAmountPayable, 2), $request->user(), $notice, ['original_amount_due' => $originalAmountDue, 'total_deductions' => $totalDeductions, 'revised_amount_payable' => $revisedAmountPayable], $project->id, $project->organization_id);

        ProjectActivityService::record(
            $project, $request->user(),
            'pay_less_notice_issued',
            "Pay Less Notice issued on Application #{$paymentApplication->application_number}",
            "Revised payable amount: " . number_format($revisedAmountPayable, 2),
            $notice
        );

        EmailNotificationService::send(
            'pay_less_notice.issued',
            'Pay Less Notice Issued',
            "A Pay Less Notice has been issued for Payment Application #{$paymentApplication->application_number}.",
            [],
            $project->organization
        );

        return response()->json([
            'notice'   => $notice->load(['creator:id,name', 'documents:id,documentable_type,documentable_id,file_name,file_size,created_at']),
            'document' => $generatedDocument,
        ], 201);
    }

    // GET /payment-applications/{paymentApplication}/previous-values
    public function previousValues(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $scope = $paymentApplication->contract_id
            ? ['contract_id' => $paymentApplication->contract_id]
            : ['trade_package_id' => $paymentApplication->trade_package_id];

        return response()->json($this->calculatePreviousValues($scope));
    }

    // POST /payment-applications/{paymentApplication}/breakdown
    public function updateBreakdown(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if (!in_array($paymentApplication->status, ['draft'])) {
            return response()->json(['message' => 'Breakdown can only be edited on draft applications.'], 422);
        }

        $validated = $request->validate([
            'breakdown'                       => 'required|array',
            'breakdown.measured_works'        => 'nullable|array',
            'breakdown.measured_works.*.description'    => 'nullable|string',
            'breakdown.measured_works.*.contract_value' => 'nullable|numeric',
            'breakdown.measured_works.*.pct_complete'   => 'nullable|numeric|min:0|max:100',
            'breakdown.variations'            => 'nullable|array',
            'breakdown.materials_on_site'     => 'nullable|array',
            'use_breakdown'                   => 'nullable|boolean',
            'vat_rate'                        => 'nullable|numeric|min:0|max:100',
        ]);

        $paymentApplication->breakdown    = $validated['breakdown'];
        $paymentApplication->use_breakdown = $validated['use_breakdown'] ?? $paymentApplication->use_breakdown;
        $paymentApplication->vat_rate     = $validated['vat_rate'] ?? $paymentApplication->vat_rate ?? 20;
        $paymentApplication->save();

        if ($paymentApplication->use_breakdown) {
            $this->recalculateFromBreakdown($paymentApplication);
        } else {
            // Still update VAT fields even when not using breakdown
            $amountDue = (float) $paymentApplication->amount_due;
            $vatRate   = (float) $paymentApplication->vat_rate;
            $vatAmount = round($amountDue * $vatRate / 100, 2);
            $paymentApplication->vat_amount             = $vatAmount;
            $paymentApplication->total_due_including_vat = $amountDue + $vatAmount;
            $paymentApplication->save();
        }

        return response()->json($paymentApplication->fresh()->load(['creator:id,name', 'contract', 'tradePackage', 'project']));
    }

    // POST /payment-applications/{paymentApplication}/generate-excel
    public function generateExcel(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        try {
            $document = ExcelGenerationService::generatePaymentApplicationWorkbook(
                $paymentApplication,
                $request->user()
            );
        } catch (\Throwable $e) {
            \Log::error('Payment application Excel generation failed', [
                'user_id'                => $request->user()?->id,
                'payment_application_id' => $paymentApplication->id,
                'exception'              => $e,
            ]);
            return response()->json(['message' => 'The Excel workbook could not be generated.'], 500);
        }

        ProjectActivityService::record(
            $project, $request->user(),
            'payment_application_excel_generated',
            "Excel workbook generated for Application #{$paymentApplication->application_number}",
            null,
            $paymentApplication
        );

        return response()->json($document, 201);
    }

    // GET /payment-applications/{paymentApplication}/eligible-variations
    public function eligibleVariations(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $query = Variation::where('organization_id', $project->organization_id)
            ->where('status', 'approved');

        if ($paymentApplication->contract_id) {
            $query->where('contract_id', $paymentApplication->contract_id);
        } elseif ($paymentApplication->trade_package_id) {
            $query->where('trade_package_id', $paymentApplication->trade_package_id);
        } else {
            return response()->json(['data' => []]);
        }

        $alreadyLinked = $paymentApplication->linkedVariations()->pluck('variation_id');

        $variations = $query->orderBy('variation_number')->get()->map(fn($v) => [
            'id'               => $v->id,
            'variation_number' => $v->variation_number,
            'title'            => $v->title,
            'description'      => $v->description,
            'agreed_amount'    => $v->agreed_amount,
            'status'           => $v->status,
            'is_linked'        => $alreadyLinked->contains($v->id),
        ]);

        return response()->json(['data' => $variations]);
    }

    // POST /payment-applications/{paymentApplication}/sync-variations
    public function syncLinkedVariations(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'draft') {
            return response()->json(['message' => 'Linked variations can only be edited on draft applications.'], 422);
        }

        $validated = $request->validate([
            'variation_ids'   => 'required|array',
            'variation_ids.*' => 'integer|exists:variations,id',
        ]);

        // Re-validate scope: must belong to same org + same contract or trade package + approved status
        $scopeQuery = Variation::where('organization_id', $project->organization_id)
            ->where('status', 'approved')
            ->whereIn('id', $validated['variation_ids']);

        if ($paymentApplication->contract_id) {
            $scopeQuery->where('contract_id', $paymentApplication->contract_id);
        } elseif ($paymentApplication->trade_package_id) {
            $scopeQuery->where('trade_package_id', $paymentApplication->trade_package_id);
        }

        $validVariations = $scopeQuery->get()->keyBy('id');
        $safeIds         = $validVariations->keys()->toArray();

        // Remove de-selected or out-of-scope
        $paymentApplication->linkedVariations()
            ->whereNotIn('variation_id', $safeIds)
            ->delete();

        // Create new snapshots (skip if already linked)
        $existing = $paymentApplication->linkedVariations()->pluck('variation_id')->toArray();

        foreach ($safeIds as $varId) {
            if (!in_array($varId, $existing)) {
                $v = $validVariations[$varId];
                $paymentApplication->linkedVariations()->create([
                    'variation_id'                  => $v->id,
                    'variation_number_at_inclusion' => (string) $v->variation_number,
                    'title_at_inclusion'            => $v->title,
                    'description_at_inclusion'      => $v->description,
                    'amount_at_inclusion'           => $v->agreed_amount ?? 0,
                    'status_at_inclusion'           => $v->status,
                ]);
            }
        }

        // Recompute linked total, then full breakdown if in use
        $linkedTotal = (float) $paymentApplication->linkedVariations()->sum('amount_at_inclusion');
        $paymentApplication->linked_variations_total = $linkedTotal;
        $paymentApplication->save();

        if ($paymentApplication->use_breakdown) {
            $this->recalculateFromBreakdown($paymentApplication->fresh());
        }

        return response()->json(
            $paymentApplication->fresh()->load([
                'creator:id,name', 'contract', 'tradePackage', 'project',
                'paymentNotices.documents:id,documentable_type,documentable_id,file_name,file_size,created_at',
                'payLessNotices', 'linkedVariations',
            ])
        );
    }

    // ─── Private helpers ───────────────────────────────────────────────────────

    private function recalculateFromBreakdown(PaymentApplication $pa): void
    {
        $breakdown = $pa->breakdown ?? [];

        $measuredTotal = collect($breakdown['measured_works'] ?? [])->sum(function ($row) {
            $cv  = (float) ($row['contract_value'] ?? ((float)($row['qty'] ?? 1) * (float)($row['rate'] ?? 0)));
            $pct = (float) ($row['pct_complete'] ?? 0) / 100;
            return (float) ($row['valuation'] ?? ($cv * $pct));
        });

        $variationsTotal = collect($breakdown['variations'] ?? [])->sum(function ($row) {
            $vv  = (float) ($row['variation_value'] ?? 0);
            $pct = (float) ($row['pct_complete'] ?? 100) / 100;
            return (float) ($row['valuation'] ?? ($vv * $pct));
        });

        $materialsTotal = collect($breakdown['materials_on_site'] ?? [])->sum(function ($row) {
            $qty  = (float) ($row['qty'] ?? 1);
            $rate = (float) ($row['rate'] ?? 0);
            $mv   = (float) ($row['material_value'] ?? ($qty * $rate));
            $pct  = (float) ($row['claim_pct'] ?? 100) / 100;
            return (float) ($row['valuation'] ?? ($mv * $pct));
        });

        // Include snapshotted linked approved variations (pivot table, historically accurate)
        $linkedVariationsTotal = (float) PaymentApplicationVariation::where('payment_application_id', $pa->id)
            ->sum('amount_at_inclusion');

        $grossValuation = $measuredTotal + $variationsTotal + $materialsTotal + $linkedVariationsTotal;

        // Retention from contract, capped if the contract has a retention cap
        $contract  = $pa->contract;
        $retPct    = (float) ($contract?->retention_percentage ?? 0);
        $retention = $retPct > 0 ? round($grossValuation * $retPct / 100, 2) : (float) ($pa->less_retention ?? 0);

        if ($contract) {
            $prevScope = ['contract_id' => $pa->contract_id];
            $prevPrior = PaymentApplication::where($prevScope)
                ->whereIn('status', ['certified', 'paid'])
                ->where('id', '!=', $pa->id)
                ->sum('less_retention');

            $retention = $this->applyRetentionCap(
                $retention,
                (float) $prevPrior,
                isset($contract->contract_sum) ? (float) $contract->contract_sum : null,
                isset($contract->retention_cap_percentage) ? (float) $contract->retention_cap_percentage : null
            );
        }

        // Previous certified
        $prevCert  = (float) ($pa->less_previous_payments ?? 0);

        $netValuation = $grossValuation - $retention;
        $amountDue    = max(0, $netValuation - $prevCert);

        $vatRate   = (float) ($pa->vat_rate ?? 20);
        $vatAmount = round($amountDue * $vatRate / 100, 2);
        $totalDue  = $amountDue + $vatAmount;

        $pa->measured_works_total    = $measuredTotal;
        $pa->variations_total        = $variationsTotal;
        $pa->materials_on_site_total = $materialsTotal;
        $pa->linked_variations_total = $linkedVariationsTotal;
        $pa->gross_valuation         = $grossValuation;
        $pa->less_retention          = $retention;
        $pa->amount_due              = $amountDue;
        $pa->vat_amount              = $vatAmount;
        $pa->total_due_including_vat = $totalDue;
        $pa->save();
    }
}
