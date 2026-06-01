<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Services\DocumentGenerationService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class PaymentApplicationController extends Controller
{
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    // GET /projects/{project}/payment-applications
    public function indexByProject(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $apps = PaymentApplication::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title,reference_number'])
            ->latest()
            ->paginate(25);

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
            'application_number'      => 'nullable|integer',
            'reference'               => 'nullable|string|max:100',
            'application_date'        => 'required|date',
            'due_date'                => 'nullable|date',
            'gross_valuation'         => 'required|numeric|min:0',
            'less_retention'          => 'nullable|numeric|min:0',
            'less_previous_payments'  => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string',
            'breakdown'               => 'nullable|array',
        ]);

        // Auto-increment application number if not provided
        if (empty($validated['application_number'])) {
            $last = PaymentApplication::where('contract_id', $contract->id)->max('application_number');
            $validated['application_number'] = ($last ?? 0) + 1;
        }

        $gross      = (float) $validated['gross_valuation'];
        $retention  = (float) ($validated['less_retention'] ?? 0);
        $previous   = (float) ($validated['less_previous_payments'] ?? 0);
        $amountDue  = max(0, $gross - $retention - $previous);

        $app = PaymentApplication::create(array_merge($validated, [
            'project_id'      => $project->id,
            'contract_id'     => $contract->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'less_retention'  => $retention,
            'less_previous_payments' => $previous,
            'amount_due'      => $amountDue,
            'status'          => 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'payment_application_created',
            "Payment Application #{$app->application_number} created",
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
            $paymentApplication->load(['creator:id,name', 'contract', 'project'])
        );
    }

    public function update(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'reference'              => 'nullable|string|max:100',
            'application_date'       => 'sometimes|date',
            'due_date'               => 'nullable|date',
            'gross_valuation'        => 'sometimes|numeric|min:0',
            'less_retention'         => 'nullable|numeric|min:0',
            'less_previous_payments' => 'nullable|numeric|min:0',
            'notes'                  => 'nullable|string',
            'breakdown'              => 'nullable|array',
        ]);

        // Recalculate amount_due if financials change
        if (isset($validated['gross_valuation']) || isset($validated['less_retention']) || isset($validated['less_previous_payments'])) {
            $gross     = (float) ($validated['gross_valuation'] ?? $paymentApplication->gross_valuation);
            $retention = (float) ($validated['less_retention'] ?? $paymentApplication->less_retention ?? 0);
            $previous  = (float) ($validated['less_previous_payments'] ?? $paymentApplication->less_previous_payments ?? 0);
            $validated['amount_due'] = max(0, $gross - $retention - $previous);
        }

        $paymentApplication->update($validated);

        return response()->json($paymentApplication->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);
        $paymentApplication->delete();
        return response()->json(['message' => 'Payment application deleted.']);
    }

    // POST /payment-applications/{paymentApplication}/submit
    public function submit(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        if ($paymentApplication->status !== 'draft') {
            return response()->json(['message' => 'Only draft applications can be submitted.'], 422);
        }

        $paymentApplication->update(['status' => 'submitted']);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'payment_application_submitted',
            "Payment Application #{$paymentApplication->application_number} submitted",
            null,
            $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/certify
    public function certify(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'certified_amount' => 'required|numeric|min:0',
            'certified_date'   => 'nullable|date',
        ]);

        $paymentApplication->update(array_merge($validated, [
            'status'         => 'certified',
            'certified_date' => $validated['certified_date'] ?? now()->toDateString(),
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'payment_application_certified',
            "Payment Application #{$paymentApplication->application_number} certified",
            "Certified amount: " . number_format($validated['certified_amount'], 2),
            $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/mark-paid
    public function markPaid(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $validated = $request->validate([
            'paid_amount'  => 'required|numeric|min:0',
            'payment_date' => 'nullable|date',
        ]);

        $paymentApplication->update(array_merge($validated, [
            'status'       => 'paid',
            'payment_date' => $validated['payment_date'] ?? now()->toDateString(),
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'payment_application_paid',
            "Payment Application #{$paymentApplication->application_number} marked as paid",
            "Paid amount: " . number_format($validated['paid_amount'], 2),
            $paymentApplication
        );

        return response()->json($paymentApplication->fresh());
    }

    // POST /payment-applications/{paymentApplication}/generate-pdf
    public function generatePdf(Request $request, PaymentApplication $paymentApplication)
    {
        $project = $paymentApplication->project;
        $this->authorizeProject($request, $project);

        $paymentApplication->load(['contract', 'creator:id,name']);

        $document = DocumentGenerationService::generatePdf(
            $project,
            $request->user(),
            'pdfs.payment-application',
            ['paymentApplication' => $paymentApplication],
            "Payment Application #{$paymentApplication->application_number}",
            'payment_app',
            '06_Monthly_Payment_Applications',
            $paymentApplication->reference,
            $paymentApplication
        );

        ProjectActivityService::record(
            $project,
            $request->user(),
            'pdf_generated',
            "PDF generated: Payment Application #{$paymentApplication->application_number}",
            null,
            $document
        );

        return response()->json($document, 201);
    }
}

