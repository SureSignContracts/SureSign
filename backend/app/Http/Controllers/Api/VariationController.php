<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Project;
use App\Models\Variation;
use App\Services\DocumentGenerationService;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class VariationController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────────────────

    public function indexByProject(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $query = Variation::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title,reference_number,contract_sum'])
            ->withCount('paymentApplicationVariations as pa_inclusion_count');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function index(Request $request, Contract $contract)
    {
        $this->authorizeContractProject($request, $contract);

        return response()->json(
            Variation::where('contract_id', $contract->id)
                ->with('creator:id,name')
                ->latest()
                ->paginate(25)
        );
    }

    // ── CRUD ──────────────────────────────────────────────────────────────────

    public function store(Request $request, Contract $contract)
    {
        $this->authorizeContractProject($request, $contract);

        $validated = $request->validate([
            'title'                  => 'required|string|max:255',
            'description'            => 'nullable|string',
            'type'                   => 'nullable|string|max:100',
            'quoted_amount'          => 'nullable|numeric|min:0',
            'agreed_amount'          => 'nullable|numeric|min:0',
            'variation_date'         => 'nullable|date',
            'programme_impact_days'  => 'nullable|integer|min:0',
            'instruction_method'     => 'nullable|in:written,verbal_emergency',
            'valuation_method'       => 'nullable|in:schedule_rates,fair_reasonable,daywork',
            'quotation_submitted_at' => 'nullable|date',
            'agreed_in_writing'      => 'nullable|boolean',
        ]);

        $variationNumber = (Variation::where('project_id', $contract->project_id)->max('variation_number') ?? 0) + 1;

        // Default to "today" for this contract's own organisation when no
        // variation_date is supplied — a business-day default, not the
        // server's UTC calendar day.
        $instructionDate   = Carbon::parse($validated['variation_date'] ?? \App\Services\TimezoneResolver::today(null, $contract->organization));
        $instructionMethod = $validated['instruction_method'] ?? 'written';

        $writtenConfirmationDue = $instructionMethod === 'verbal_emergency'
            ? $instructionDate->copy()->addWeekday()->toDateString()
            : null;

        $quotationDueDays = $this->quotationDaysFromAnalysis($contract);
        $quotationDueDate = $instructionDate->copy()->addWeekdays($quotationDueDays)->toDateString();

        $valuationMethod = $validated['valuation_method']
            ?? $this->defaultValuationMethodFromAnalysis($contract);

        $variation = Variation::create(array_merge($validated, [
            'contract_id'              => $contract->id,
            'project_id'               => $contract->project_id,
            'organization_id'          => $request->user()->organization_id,
            'created_by'               => $request->user()->id,
            'variation_number'         => $variationNumber,
            'status'                   => Variation::STATUS_DRAFT,
            'variation_date'           => $instructionDate->toDateString(),
            'instruction_method'       => $instructionMethod,
            'written_confirmation_due' => $writtenConfirmationDue,
            'quotation_due_date'       => $quotationDueDate,
            'valuation_method'         => $valuationMethod,
        ]));

        $project = $contract->project;
        ProjectActivityService::record(
            $project, $request->user(),
            'variation_created',
            "Variation #{$variationNumber} created: {$variation->title}",
            null, $variation
        );

        ActivityLog::record(
            'variation.created',
            "Variation #{$variationNumber} created — {$variation->title}",
            $request->user(), $variation, [], $project->id, $project->organization_id
        );

        return response()->json($variation->load('creator:id,name'), 201);
    }

    public function show(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        return response()->json($variation->load([
            'creator:id,name',
            'contract:id,title',
            'submittedBy:id,name',
            'instructedBy:id,name',
            'quotedBy:id,name',
            'assessedBy:id,name',
            'approvedBy:id,name',
            'rejectedBy:id,name',
        ]));
    }

    public function update(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        // Approved variations are commercially agreed records.
        // Only non-commercial fields (description, notes, etc.) may be updated.
        // agreed_amount changes must go through a formal revision — not implemented yet.
        if ($variation->status === Variation::STATUS_APPROVED) {
            if ($request->has('agreed_amount') || $request->has('quoted_amount')) {
                return response()->json([
                    'message' => 'The agreed amount on an approved variation cannot be changed. If the agreed value has changed, reject and resubmit the variation.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'title'                    => 'sometimes|string|max:255',
            'description'              => 'nullable|string',
            'type'                     => 'nullable|string|max:100',
            'quoted_amount'            => 'nullable|numeric|min:0',
            'agreed_amount'            => 'nullable|numeric|min:0',
            'variation_date'           => 'nullable|date',
            'programme_impact_days'    => 'nullable|integer|min:0',
            'instruction_method'       => 'nullable|in:written,verbal_emergency',
            'valuation_method'         => 'nullable|in:schedule_rates,fair_reasonable,daywork',
            'quotation_submitted_at'   => 'nullable|date',
            'agreed_in_writing'        => 'nullable|boolean',
            'written_confirmation_due' => 'nullable|date',
            'quotation_due_date'       => 'nullable|date',
            // Direct status editing is limited to non-workflow statuses only.
            // Use action endpoints (submit, instruct, etc.) for lifecycle transitions.
            'status'                   => 'nullable|in:draft,pending,on_hold',
        ]);

        $variation->update($validated);

        ProjectActivityService::record(
            $variation->project, $request->user(),
            'variation_updated',
            "Variation #{$variation->variation_number} updated: {$variation->title}",
            null, $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    public function destroy(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        // Check PA inclusion first — gives a more specific error than the generic isDeletable() message.
        if ($variation->isIncludedInPaymentApplication()) {
            return response()->json([
                'message' => 'This variation has been included in a Payment Application and cannot be deleted. Payment Application snapshots are permanent records.',
            ], 422);
        }

        if (!$variation->isDeletable()) {
            return response()->json([
                'message' => 'Only draft or rejected variations can be deleted. Approved or in-progress variations must be rejected first.',
            ], 422);
        }

        $variation->delete();
        return response()->json(null, 204);
    }

    // ── Workflow Action Endpoints ─────────────────────────────────────────────

    /**
     * Draft / Pending → Submitted
     * Contractor formally submits the variation for instruction.
     */
    public function submit(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if (!in_array($variation->status, [Variation::STATUS_DRAFT, Variation::STATUS_PENDING])) {
            return response()->json(['message' => 'Only draft variations can be submitted.'], 422);
        }

        $variation->update([
            'status'       => Variation::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
        ]);

        $this->logTransition($variation, $request->user(), 'variation_submitted', 'submitted');

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_SUBMITTED,
            "Variation #{$variation->variation_number} Submitted",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been submitted.",
            'variation.submitted',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Submitted → Instructed
     * Employer formally instructs the variation, triggering the quotation clock.
     */
    public function instruct(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Only submitted variations can be instructed.'], 422);
        }

        $validated = $request->validate([
            'instruction_notes' => 'nullable|string|max:2000',
        ]);

        $variation->update([
            'status'            => Variation::STATUS_INSTRUCTED,
            'instructed_at'     => now(),
            'instructed_by'     => $request->user()->id,
            'instruction_notes' => $validated['instruction_notes'] ?? null,
        ]);

        $this->logTransition($variation, $request->user(), 'variation_instructed', 'instructed');

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_INSTRUCTED,
            "Variation #{$variation->variation_number} Instructed",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been formally instructed. Quotation due: {$this->formatDate($variation->quotation_due_date)}.",
            'variation.instructed',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Instructed → Quoted
     * Contractor has submitted their quotation.
     */
    public function quote(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_INSTRUCTED) {
            return response()->json(['message' => 'Only instructed variations can receive a quotation.'], 422);
        }

        $validated = $request->validate([
            'quoted_amount'          => 'required|numeric|min:0',
            'quotation_submitted_at' => 'nullable|date',
            'valuation_method'       => 'nullable|in:schedule_rates,fair_reasonable,daywork',
        ]);

        $variation->update([
            'status'                 => Variation::STATUS_QUOTED,
            'quoted_amount'          => $validated['quoted_amount'],
            // Business-day default (today, for this variation's organisation)
            // when no quotation_submitted_at is supplied. Note this field is
            // DATE-typed despite its "_at" name (a pre-existing naming
            // inconsistency, out of scope for this batch — see the earlier
            // architecture audit).
            'quotation_submitted_at' => $validated['quotation_submitted_at'] ?? \App\Services\TimezoneResolver::today(null, $variation->organization)->toDateString(),
            'quoted_by'              => $request->user()->id,
            'valuation_method'       => $validated['valuation_method'] ?? $variation->valuation_method,
        ]);

        $this->logTransition($variation, $request->user(), 'variation_quoted', 'quoted');

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_QUOTED,
            "Quotation Received — Variation #{$variation->variation_number}",
            "Quotation received for Variation #{$variation->variation_number} \"{$variation->title}\".",
            'variation.quoted',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Quoted → Assessed
     * Employer formally assesses the quotation (may counter-assess before approving).
     */
    public function assess(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_QUOTED) {
            return response()->json(['message' => 'Only quoted variations can be assessed.'], 422);
        }

        $validated = $request->validate([
            'agreed_amount'    => 'nullable|numeric|min:0',
            'assessment_notes' => 'nullable|string|max:2000',
        ]);

        $variation->update([
            'status'           => Variation::STATUS_ASSESSED,
            'assessed_at'      => now(),
            'assessed_by'      => $request->user()->id,
            'assessment_notes' => $validated['assessment_notes'] ?? null,
            'agreed_amount'    => $validated['agreed_amount'] ?? $variation->agreed_amount,
        ]);

        $this->logTransition($variation, $request->user(), 'variation_assessed', 'assessed');

        $assessedAmount = $validated['agreed_amount'] ?? $variation->agreed_amount;
        $this->notify(
            $request->user(),
            NotificationService::VARIATION_ASSESSED,
            "Variation #{$variation->variation_number} Assessed",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been assessed."
                . ($assessedAmount ? " Counter-assessed value: {$this->formatAmount($assessedAmount)}." : ''),
            'variation.assessed',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Assessed → Approved
     * Employer formally approves the variation and agreed amount.
     */
    public function approve(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_ASSESSED) {
            return response()->json(['message' => 'Only assessed variations can be approved.'], 422);
        }

        $validated = $request->validate([
            'agreed_amount'    => 'nullable|numeric|min:0',
            'approval_notes'   => 'nullable|string|max:2000',
            'agreed_in_writing'=> 'nullable|boolean',
        ]);

        $variation->update([
            'status'           => Variation::STATUS_APPROVED,
            'approved_at'      => now(),
            'approved_by'      => $request->user()->id,
            'approval_notes'   => $validated['approval_notes'] ?? null,
            'agreed_amount'    => $validated['agreed_amount'] ?? $variation->agreed_amount,
            'agreed_in_writing'=> $validated['agreed_in_writing'] ?? $variation->agreed_in_writing,
        ]);

        $this->logTransition($variation, $request->user(), 'variation_approved', 'approved');

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_APPROVED,
            "Variation #{$variation->variation_number} Approved",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been approved. Agreed amount: {$this->formatAmount($variation->agreed_amount)}.",
            'variation.approved',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Assessed → Rejected
     * Employer rejects the variation with a reason.
     */
    public function reject(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_ASSESSED) {
            return response()->json(['message' => 'Only assessed variations can be rejected.'], 422);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $variation->update([
            'status'           => Variation::STATUS_REJECTED,
            'rejected_at'      => now(),
            'rejected_by'      => $request->user()->id,
            'rejection_reason' => $validated['rejection_reason'],
        ]);

        $this->logTransition($variation, $request->user(), 'variation_rejected', 'rejected');

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_REJECTED,
            "Variation #{$variation->variation_number} Rejected",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been rejected. Reason: {$validated['rejection_reason']}",
            'variation.rejected',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    /**
     * Rejected → Submitted
     * Contractor resubmits after addressing the rejection reason.
     */
    public function resubmit(Request $request, Variation $variation)
    {
        $this->authorizeVariation($request, $variation);

        if ($variation->status !== Variation::STATUS_REJECTED) {
            return response()->json(['message' => 'Only rejected variations can be resubmitted.'], 422);
        }

        $validated = $request->validate([
            'quoted_amount'  => 'nullable|numeric|min:0',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $variation->update([
            'status'       => Variation::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by' => $request->user()->id,
            // Reset rejection fields so the new submission is clean
            'rejected_at'      => null,
            'rejected_by'      => null,
            // rejection_reason is preserved for audit — it remains on record
            'quoted_amount' => $validated['quoted_amount'] ?? $variation->quoted_amount,
        ]);

        $project = $variation->project;
        ProjectActivityService::record(
            $project, $request->user(),
            'variation_resubmitted',
            "Variation #{$variation->variation_number} resubmitted: {$variation->title}",
            null, $variation
        );

        ActivityLog::record(
            'variation.resubmitted',
            "Variation #{$variation->variation_number} resubmitted — {$variation->title}",
            $request->user(), $variation, [], $project->id, $project->organization_id
        );

        $this->notify(
            $request->user(),
            NotificationService::VARIATION_RESUBMITTED,
            "Variation #{$variation->variation_number} Resubmitted",
            "Variation #{$variation->variation_number} \"{$variation->title}\" has been resubmitted.",
            'variation.resubmitted',
            $variation
        );

        return response()->json($variation->fresh()->load('creator:id,name'));
    }

    // ── PDF Generation ────────────────────────────────────────────────────────

    public function generatePdf(Request $request, Variation $variation)
    {
        $variation->load(['contract', 'creator:id,name']);
        $project = \App\Models\Project::findOrFail($variation->project_id);

        $title = "Variation Order #{$variation->variation_number} — {$variation->title}";

        $doc = DocumentGenerationService::generatePdf(
            $project,
            $request->user(),
            'pdfs.variation-order',
            ['variation' => $variation],
            $title,
            'variation_order',
            '04_Variations',
            "VO-{$variation->variation_number}",
            $variation
        );

        ProjectActivityService::record(
            $project, $request->user(),
            'variation_pdf_generated',
            "PDF generated for Variation #{$variation->variation_number}: {$variation->title}",
            null, $variation
        );

        return response()->json([
            'message'  => 'PDF generated successfully.',
            'document' => $doc,
        ]);
    }

    // ── Private Helpers ───────────────────────────────────────────────────────

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeContractProject(Request $request, Contract $contract): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $contract->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeVariation(Request $request, Variation $variation): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $variation->organization_id) abort(403, 'Access denied.');
    }

    private function logTransition(Variation $variation, $user, string $event, string $statusLabel): void
    {
        $project = $variation->project;

        ProjectActivityService::record(
            $project, $user,
            $event,
            "Variation #{$variation->variation_number} {$statusLabel}: {$variation->title}",
            null, $variation
        );

        ActivityLog::record(
            "variation.{$statusLabel}",
            "Variation #{$variation->variation_number} {$statusLabel} — {$variation->title}",
            $user, $variation, ['status' => $statusLabel], $project->id, $project->organization_id
        );
    }

    private function notify($user, string $type, string $title, string $message, string $emailEvent, ?Variation $variation = null): void
    {
        // Synchronous workflow transition — the actor already knows they just
        // did this; the other org stakeholders are who need telling.
        if ($variation && $variation->organization) {
            NotificationService::sendToOrganization(
                $variation->organization,
                $type,
                $title,
                $message,
                [],
                [
                    'project_id' => $variation->project_id, 'organization_id' => $variation->organization_id,
                    'category' => \App\Models\SuresignNotification::CATEGORY_VARIATION,
                    'source_type' => 'variation', 'source_id' => $variation->id, 'source_field' => $type,
                    'action_url' => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                        $variation->project_id, 'variation', $variation->id, $variation->trade_package_id
                    ),
                ],
                $user,
            );
        } else {
            NotificationService::send($user, $type, $title, $message);
        }

        // Channel policy: only the approved/rejected decision points are
        // important enough to email — the other five transitions (submitted,
        // instructed, quoted, assessed, resubmitted) stay in-app only.
        if (in_array($emailEvent, ['variation.approved', 'variation.rejected'], true)) {
            EmailNotificationService::send($emailEvent, $title, $message, [], $variation?->organization);
        }
    }

    private function formatDate($date): string
    {
        if (!$date) return 'TBC';
        try { return \Carbon\Carbon::parse($date)->format('d M Y'); } catch (\Throwable) { return 'TBC'; }
    }

    private function formatAmount($amount): string
    {
        if ($amount === null) return 'TBC';
        return '£' . number_format((float) $amount, 2);
    }

    private function quotationDaysFromAnalysis(Contract $contract): int
    {
        $analysis = ContractAiAnalysis::where('contract_id', $contract->id)
            ->where('status', 'confirmed')->latest()->first();

        if ($analysis) {
            $procedure = $analysis->confirmed_data_json['extracted_fields']['variation_procedure'] ?? '';
            if (preg_match('/(\d+)\s*working\s*day/i', $procedure, $m)) {
                return (int) $m[1];
            }
        }

        return 5;
    }

    private function defaultValuationMethodFromAnalysis(Contract $contract): string
    {
        $analysis = ContractAiAnalysis::where('contract_id', $contract->id)
            ->where('status', 'confirmed')->latest()->first();

        if ($analysis) {
            $procedure = strtolower($analysis->confirmed_data_json['extracted_fields']['variation_procedure'] ?? '');
            if (str_contains($procedure, 'commercial schedule') || str_contains($procedure, 'schedule rate')) {
                return 'schedule_rates';
            }
            if (str_contains($procedure, 'daywork')) {
                return 'daywork';
            }
        }

        return 'fair_reasonable';
    }
}
