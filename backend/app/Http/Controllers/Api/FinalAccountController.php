<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateFinalAccountRequest;
use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\FinalAccountItem;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\SuresignNotification;
use App\Services\DocumentGenerationService;
use App\Services\EmailNotificationService;
use App\Services\FinalAccountService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinalAccountController extends Controller
{
    public function __construct(private FinalAccountService $service) {}

    // ── GET /projects/{project}/final-accounts ────────────────────────────────

    public function indexByProject(Request $request, Project $project): JsonResponse
    {
        $this->authorizeProject($request, $project);

        $accounts = FinalAccount::where('project_id', $project->id)
            ->with(['contract:id,title,reference_number', 'tradePackage:id,name,package_code'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($accounts);
    }

    // ── GET /contracts/{contract}/final-account ───────────────────────────────

    public function showForContract(Request $request, Contract $contract): JsonResponse
    {
        $this->authorizeContract($request, $contract);

        $fa = FinalAccount::where('contract_id', $contract->id)
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->first();

        if (!$fa) {
            return response()->json(['data' => null, 'exists' => false]);
        }

        return response()->json([
            'data'   => $this->buildResponse($fa),
            'exists' => true,
        ]);
    }

    // ── GET /trade-packages/{tradePackage}/final-account ─────────────────────

    public function showForTradePackage(Request $request, TradePackage $tradePackage): JsonResponse
    {
        $this->authorizeTradePackage($request, $tradePackage);

        $fa = FinalAccount::where('trade_package_id', $tradePackage->id)
            ->with(['items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])
            ->first();

        if (!$fa) {
            return response()->json(['data' => null, 'exists' => false]);
        }

        return response()->json([
            'data'   => $this->buildResponse($fa),
            'exists' => true,
        ]);
    }

    // ── GET /final-accounts/{finalAccount} ───────────────────────────────────

    public function show(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        $finalAccount->load([
            'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'documents' => fn ($q) => $q->orderBy('created_at', 'desc'),
        ]);

        return response()->json($this->buildResponse($finalAccount));
    }

    // ── POST /contracts/{contract}/final-account ──────────────────────────────

    public function storeForContract(Request $request, Contract $contract): JsonResponse
    {
        $this->authorizeContract($request, $contract);

        if ($contract->finalAccount()->exists()) {
            return response()->json(['message' => 'A Final Account already exists for this contract.'], 422);
        }

        $fa = $this->service->createFromContract($contract, $request->user()->id);

        if ($request->filled('notes')) {
            $fa->update(['notes' => $request->input('notes')]);
        }

        ProjectActivityService::record(
            $contract->project,
            $request->user(),
            'final_account.created',
            "Final Account {$fa->reference} created for contract: {$contract->title}",
            null,
            $fa
        );

        $fa->load('items');

        return response()->json($this->buildResponse($fa), 201);
    }

    // ── POST /trade-packages/{tradePackage}/final-account ────────────────────

    public function storeForTradePackage(Request $request, TradePackage $tradePackage): JsonResponse
    {
        $this->authorizeTradePackage($request, $tradePackage);

        if ($tradePackage->finalAccount()->exists()) {
            return response()->json(['message' => 'A Final Account already exists for this trade package.'], 422);
        }

        $fa = $this->service->createFromTradePackage($tradePackage, $request->user()->id);

        if ($request->filled('notes')) {
            $fa->update(['notes' => $request->input('notes')]);
        }

        ProjectActivityService::record(
            $tradePackage->project,
            $request->user(),
            'final_account.created',
            "Final Account {$fa->reference} created for trade package: {$tradePackage->name}",
            null,
            $fa
        );

        $fa->load('items');

        return response()->json($this->buildResponse($fa), 201);
    }

    // ── PUT /final-accounts/{finalAccount} ───────────────────────────────────

    public function update(UpdateFinalAccountRequest $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        if ($finalAccount->isLocked()) {
            return response()->json(['message' => 'This Final Account is locked and cannot be edited.'], 422);
        }

        $finalAccount->update($request->validated());

        return response()->json($this->buildResponse($finalAccount->fresh()->load('items')));
    }

    // ── Lifecycle actions ─────────────────────────────────────────────────────

    public function submit(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        return $this->transition($request, $finalAccount, FinalAccount::STATUS_SUBMITTED, function ($fa, $user) {
            $fa->update(['submitted_at' => now(), 'submitted_by' => $user->id]);
        });
    }

    public function startReview(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        return $this->transition($request, $finalAccount, FinalAccount::STATUS_UNDER_REVIEW, function ($fa, $user) {
            $fa->update(['reviewed_at' => now(), 'reviewed_by' => $user->id]);
        });
    }

    public function revise(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        // Return to draft (only valid from submitted or under_review)
        return $this->transition($request, $finalAccount, FinalAccount::STATUS_DRAFT, function () {});
    }

    public function agree(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        $guard = $this->service->canTransition($finalAccount, FinalAccount::STATUS_AGREED);
        if (!$guard['allowed']) {
            return response()->json(['message' => $guard['reason']], 422);
        }

        try {
            $fa = $this->service->snapshotAgreement($finalAccount, $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        ProjectActivityService::record(
            $fa->project,
            $request->user(),
            'final_account.agreed',
            "Final Account {$fa->reference} agreed — Adjusted Contract Sum: " . number_format((float) $fa->adjusted_contract_sum, 2),
            null,
            $fa
        );

        return response()->json($this->buildResponse($fa->fresh()->load('items')));
    }

    public function sign(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        return $this->transition($request, $finalAccount, FinalAccount::STATUS_SIGNED, function ($fa, $user) {
            $fa->update(['signed_at' => now(), 'signed_by' => $user->id]);
        });
    }

    public function issueFinalCertificate(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        $guard = $this->service->canTransition($finalAccount, FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED);
        if (!$guard['allowed']) {
            return response()->json(['message' => $guard['reason']], 422);
        }

        // JCT: dispute window = 28 days from Final Certificate
        $finalAccount->update([
            'status'                      => FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED,
            'final_certificate_issued_at' => now(),
            'dispute_window_expires_at'   => now()->addDays(28)->toDateString(),
        ]);

        ProjectActivityService::record(
            $finalAccount->project,
            $request->user(),
            'final_account.final_certificate_issued',
            "Final Certificate issued for {$finalAccount->reference}",
            null,
            $finalAccount
        );

        $this->notifyLifecycleEvent(
            $finalAccount, $request->user(), 'final_certificate_issued',
            "Final Certificate Issued — {$finalAccount->reference}",
            '28-day dispute window now open.',
            SuresignNotification::PRIORITY_WARNING,
            'final_account.final_certificate_issued',
        );

        return response()->json($this->buildResponse($finalAccount->fresh()->load('items')));
    }

    public function close(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        return $this->transition($request, $finalAccount, FinalAccount::STATUS_COMMERCIALLY_CLOSED, function ($fa, $user) {
            $fa->update(['closed_at' => now(), 'closed_by' => $user->id]);
        });
    }

    // ── Document generation ─────────────────────────────────────────────────────

    // POST /final-accounts/{finalAccount}/generate-statement
    public function generateStatement(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        $finalAccount->load(['contract', 'tradePackage', 'items' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

        $isDraft = in_array($finalAccount->status, [
            FinalAccount::STATUS_DRAFT,
            FinalAccount::STATUS_SUBMITTED,
            FinalAccount::STATUS_UNDER_REVIEW,
        ]);

        $totals = $this->resolveTotalsForDocument($finalAccount);

        $title = ($isDraft ? 'Draft Final Account Statement — ' : 'Final Account Statement — ') . $finalAccount->reference;

        $document = DocumentGenerationService::generatePdf(
            $finalAccount->project, $request->user(),
            'pdfs.final-account-statement',
            [
                'finalAccount' => $finalAccount,
                'contract'     => $finalAccount->contract,
                'tradePackage' => $finalAccount->tradePackage,
                'items'        => $finalAccount->items,
                'totals'       => $totals,
                'isDraft'      => $isDraft,
            ],
            $title,
            'final_account_statement', '02_Commercial', $finalAccount->reference, $finalAccount, false, $finalAccount->tradePackage
        );

        ProjectActivityService::record(
            $finalAccount->project,
            $request->user(),
            'final_account.statement_generated',
            ($isDraft ? 'Draft ' : '') . "Final Account Statement generated for {$finalAccount->reference}",
            null,
            $document
        );

        return response()->json($document, 201);
    }

    // POST /final-accounts/{finalAccount}/generate-certificate
    public function generateCertificate(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        if (!$finalAccount->isFinalCertificateIssued()) {
            return response()->json(['message' => 'The Final Certificate can only be generated once it has been issued.'], 422);
        }

        $finalAccount->load(['contract', 'tradePackage']);

        $certificateNumber = str_replace('FA-', 'FC-', $finalAccount->reference);

        $document = DocumentGenerationService::generatePdf(
            $finalAccount->project, $request->user(),
            'pdfs.final-certificate',
            [
                'finalAccount'       => $finalAccount,
                'contract'           => $finalAccount->contract,
                'tradePackage'       => $finalAccount->tradePackage,
                'certificateNumber'  => $certificateNumber,
                'issuedBy'           => $request->user(),
            ],
            "Final Certificate — {$finalAccount->reference}",
            'final_certificate', '02_Commercial', $certificateNumber, $finalAccount, false, $finalAccount->tradePackage
        );

        ProjectActivityService::record(
            $finalAccount->project,
            $request->user(),
            'final_account.certificate_generated',
            "Final Certificate generated for {$finalAccount->reference}",
            null,
            $document
        );

        return response()->json($document, 201);
    }

    // ── Item management ───────────────────────────────────────────────────────

    public function storeItem(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        if ($finalAccount->isLocked()) {
            return response()->json(['message' => 'Cannot add items to a locked Final Account.'], 422);
        }

        $validated = $request->validate([
            'category'    => 'required|in:loss_and_expense,daywork,provisional_sum,prime_cost_sum,contra_charge,deduction,other',
            'description' => 'required|string|max:500',
            'amount'      => 'required|numeric',
            'notes'       => 'nullable|string|max:2000',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $item = FinalAccountItem::create(array_merge($validated, [
            'final_account_id' => $finalAccount->id,
            'is_auto_seeded'   => false,
        ]));

        return response()->json($item, 201);
    }

    public function updateItem(Request $request, FinalAccount $finalAccount, FinalAccountItem $item): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        if ($finalAccount->isLocked()) {
            return response()->json(['message' => 'Cannot edit items on a locked Final Account.'], 422);
        }

        if (!$item->isEditable()) {
            return response()->json(['message' => 'The contract sum item cannot be edited.'], 422);
        }

        if ($item->final_account_id !== $finalAccount->id) {
            return response()->json(['message' => 'Item does not belong to this Final Account.'], 422);
        }

        $validated = $request->validate([
            'description' => 'sometimes|string|max:500',
            'amount'      => 'sometimes|numeric',
            'notes'       => 'nullable|string|max:2000',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $item->update($validated);

        return response()->json($item->fresh());
    }

    public function destroyItem(Request $request, FinalAccount $finalAccount, FinalAccountItem $item): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        if ($finalAccount->isLocked()) {
            return response()->json(['message' => 'Cannot remove items from a locked Final Account.'], 422);
        }

        if (!$item->isEditable()) {
            return response()->json(['message' => 'The contract sum item cannot be removed.'], 422);
        }

        if ($item->final_account_id !== $finalAccount->id) {
            return response()->json(['message' => 'Item does not belong to this Final Account.'], 422);
        }

        $item->delete();

        return response()->json(['success' => true]);
    }

    // ── Live totals ───────────────────────────────────────────────────────────

    /**
     * GET /final-accounts/{finalAccount}/totals
     * Returns current live totals (always recomputed — not from snapshot).
     * Useful before Agreement so the UI can show up-to-date figures.
     */
    public function totals(Request $request, FinalAccount $finalAccount): JsonResponse
    {
        $this->authorizeFinalAccount($request, $finalAccount);

        $totals = $this->service->calculateCurrentTotals($finalAccount);

        return response()->json($totals);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the totals array to render into a Final Account document.
     *
     * Before agreement: live totals via FinalAccountService::calculateCurrentTotals().
     * After agreement: snapshot columns/accessors on the model itself — the
     * document must reflect the agreed contractual position, never live figures.
     */
    private function resolveTotalsForDocument(FinalAccount $fa): array
    {
        if ($fa->isSnapshotted()) {
            return [
                'original_contract_sum'      => (float) $fa->original_contract_sum,
                'approved_variations_total'  => (float) $fa->approved_variations_total,
                'loss_and_expense_total'     => (float) $fa->loss_and_expense_total,
                'dayworks_total'             => (float) $fa->dayworks_total,
                'provisional_sum_adjustment' => (float) $fa->provisional_sum_adjustment,
                'prime_cost_sum_adjustment'  => (float) $fa->prime_cost_sum_adjustment,
                'contra_charges_total'       => (float) $fa->contra_charges_total,
                'other_adjustments_total'    => (float) $fa->other_adjustments_total,
                'adjusted_contract_sum'      => (float) $fa->adjusted_contract_sum,
                'certified_to_date'          => (float) $fa->certified_to_date,
                'paid_to_date'               => (float) $fa->paid_to_date,
                'retention_held'             => (float) $fa->retention_held,
                'retention_released'         => (float) $fa->retention_released,
                'retention_outstanding'      => (float) $fa->retention_outstanding,
                'final_balance_due'          => (float) $fa->final_balance_due,
            ];
        }

        return $this->service->calculateCurrentTotals($fa);
    }

    private function buildResponse(FinalAccount $fa): array
    {
        $data = $fa->toArray();

        // Append computed accessors (null before agreement)
        $data['adjusted_contract_sum'] = $fa->adjusted_contract_sum;
        $data['retention_outstanding'] = $fa->retention_outstanding;
        $data['final_balance_due']     = $fa->final_balance_due;
        $data['is_locked']             = $fa->isLocked();
        $data['is_snapshotted']        = $fa->isSnapshotted();
        $data['can_return_to_draft']   = $fa->canReturnToDraft();
        $data['close_out_progress']    = $this->service->getCloseOutProgress($fa);

        return $data;
    }

    private function transition(Request $request, FinalAccount $fa, string $toStatus, callable $after): JsonResponse
    {
        $this->authorizeFinalAccount($request, $fa);

        $guard = $this->service->canTransition($fa, $toStatus);
        if (!$guard['allowed']) {
            return response()->json(['message' => $guard['reason']], 422);
        }

        $fa->update(['status' => $toStatus]);
        $after($fa, $request->user());

        ProjectActivityService::record(
            $fa->project,
            $request->user(),
            'final_account.' . $toStatus,
            "Final Account {$fa->reference} moved to: " . str_replace('_', ' ', $toStatus),
            null,
            $fa
        );

        // Only the events explicitly approved for notification — startReview/
        // revise/agree are internal review-state transitions, not stakeholder
        // milestones, and stay silent to avoid low-value notification spam.
        match ($toStatus) {
            FinalAccount::STATUS_SUBMITTED => $this->notifyLifecycleEvent(
                $fa, $request->user(), 'submitted',
                "Final Account Submitted — {$fa->reference}",
                'Ready for review.',
                SuresignNotification::PRIORITY_REMINDER,
            ),
            FinalAccount::STATUS_SIGNED => $this->notifyLifecycleEvent(
                $fa, $request->user(), 'signed',
                "Final Account Signed — {$fa->reference}",
                'Signed by both parties.',
                SuresignNotification::PRIORITY_WARNING,
                'final_account.signed',
            ),
            FinalAccount::STATUS_COMMERCIALLY_CLOSED => $this->notifyLifecycleEvent(
                $fa, $request->user(), 'closed',
                "Final Account Closed — {$fa->reference}",
                'Commercially closed — no further changes expected.',
                SuresignNotification::PRIORITY_INFO,
                'final_account.closed',
            ),
            default => null,
        };

        return response()->json($this->buildResponse($fa->fresh()->load('items')));
    }

    /**
     * In-app fan-out (+ optional email, per the approved channel policy) for
     * a Final Account lifecycle milestone. Actor excluded — these are all
     * synchronous, user-initiated transitions.
     */
    private function notifyLifecycleEvent(
        FinalAccount $fa, $actor, string $sourceField, string $title, string $message,
        string $priority, ?string $emailEvent = null,
    ): void {
        NotificationService::sendToOrganization(
            $fa->organization,
            'final_account_' . $sourceField,
            $title,
            $message,
            [],
            [
                'project_id' => $fa->project_id, 'organization_id' => $fa->organization_id,
                'category' => SuresignNotification::CATEGORY_COMMERCIAL, 'priority' => $priority,
                'source_type' => 'final_account', 'source_id' => $fa->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($fa->project_id, 'final_account', $fa->id, $fa->trade_package_id),
            ],
            $actor,
        );

        if ($emailEvent) {
            EmailNotificationService::send($emailEvent, $title, $message, [], $fa->organization);
        }
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeContract(Request $request, Contract $contract): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $contract->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeTradePackage(Request $request, TradePackage $tradePackage): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $tradePackage->organization_id) abort(403, 'Access denied.');
    }

    private function authorizeFinalAccount(Request $request, FinalAccount $fa): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $fa->organization_id) abort(403, 'Access denied.');
    }
}
