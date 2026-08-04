<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinalAccount;
use App\Models\FinalAccountItem;
use App\Models\LossAndExpenseClaim;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\TradePackage;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

class LossAndExpenseClaimController extends Controller
{
    private const RULES = [
        'title'            => 'required|string|max:255',
        'description'      => 'nullable|string',
        'amount_claimed'   => 'nullable|numeric|min:0',
        'amount_assessed'  => 'nullable|numeric|min:0',
        'status'           => 'nullable|in:draft,submitted,under_assessment,agreed,rejected',
        'notes'            => 'nullable|string',
        'contract_id'      => 'nullable|integer|exists:contracts,id',
        'delay_event_id'   => 'nullable|integer|exists:delay_events,id',
        'eot_request_id'   => 'nullable|integer|exists:eot_requests,id',
    ];

    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeTradePackage.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|TradePackage|LossAndExpenseClaim $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the claim's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectClaim(Request $request, Project $project, LossAndExpenseClaim $lossAndExpenseClaim): void
    {
        $this->authorize($request, $lossAndExpenseClaim);
        if ($lossAndExpenseClaim->project_id !== $project->id) {
            abort(404, 'Loss & Expense claim not found for this project.');
        }
    }

    /** Re-derives the trade package's REAL parent project (see TradePackageController::authorizeProjectPackage). */
    private function authorizeProjectPackage(Request $request, Project $project, TradePackage $tradePackage): void
    {
        $this->authorize($request, $tradePackage);
        if ($tradePackage->project_id !== $project->id) {
            abort(404, 'Trade package not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = LossAndExpenseClaim::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $claims = LossAndExpenseClaim::where('trade_package_id', $tradePackage->id)
            ->with(['creator:id,name'])
            ->latest()
            ->get();

        return response()->json($claims);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(self::RULES);

        $claimNumber = (LossAndExpenseClaim::where('project_id', $project->id)->max('claim_number') ?? 0) + 1;

        $claim = LossAndExpenseClaim::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'claim_number'    => $claimNumber,
            'status'          => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'loss_and_expense_claimed',
            "L&E Claim #{$claimNumber} raised: {$claim->title}",
            null,
            $claim
        );

        if ($claim->status === 'submitted') {
            $this->notifyClaim($request, $project, $claim, 'submitted', 'submitted', $claim->title);
        }

        return response()->json($claim, 201);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $validated = $request->validate(self::RULES);

        $claimNumber = (LossAndExpenseClaim::where('project_id', $tradePackage->project_id)->max('claim_number') ?? 0) + 1;

        $claim = LossAndExpenseClaim::create(array_merge($validated, [
            'project_id'       => $tradePackage->project_id,
            'organization_id'  => $tradePackage->organization_id,
            'trade_package_id' => $tradePackage->id,
            'created_by'       => $request->user()->id,
            'claim_number'     => $claimNumber,
            'status'           => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'loss_and_expense_claimed',
            "L&E Claim #{$claimNumber} raised for {$tradePackage->name}: {$claim->title}",
            null,
            $claim
        );

        if ($claim->status === 'submitted') {
            $this->notifyClaim($request, $project, $claim, 'submitted', 'submitted', "{$claim->title} ({$tradePackage->name}).");
        }

        return response()->json($claim, 201);
    }

    // Not shallow (api/projects/{project}/loss-and-expense-claims/{loss_and_expense_claim})
    // — both segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/SiteDiaryController/etc.
    public function show(Request $request, Project $project, LossAndExpenseClaim $lossAndExpenseClaim)
    {
        $this->authorizeProjectClaim($request, $project, $lossAndExpenseClaim);

        return response()->json(
            $lossAndExpenseClaim->load([
                'creator:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name',
                'delayEvent:id,event_number,title', 'eotRequest:id,eot_number,title',
                'finalAccountItem:id,final_account_id,amount',
            ])
        );
    }

    public function update(Request $request, Project $project, LossAndExpenseClaim $lossAndExpenseClaim)
    {
        $this->authorizeProjectClaim($request, $project, $lossAndExpenseClaim);

        $validated = $request->validate(array_merge(self::RULES, ['title' => 'sometimes|string|max:255']));

        $lossAndExpenseClaim->update($validated);

        return response()->json($lossAndExpenseClaim->fresh());
    }

    /**
     * Record the assessment decision (agreed|rejected). On agreement, a
     * Final Account item is created immediately IF a Final Account already
     * exists for the claim's contract/trade package and isn't locked.
     * Otherwise the claim is simply left agreed with no item — when a Final
     * Account is later created, FinalAccountService::seedItemsFrom* picks up
     * any agreed claims that don't have a final_account_item_id yet, so
     * nothing is lost regardless of ordering.
     */
    public function decide(Request $request, Project $project, LossAndExpenseClaim $lossAndExpenseClaim)
    {
        $this->authorizeProjectClaim($request, $project, $lossAndExpenseClaim);

        $validated = $request->validate([
            'status'          => 'required|in:agreed,rejected',
            'amount_agreed'   => 'required_if:status,agreed|nullable|numeric|min:0',
        ]);

        $lossAndExpenseClaim->status        = $validated['status'];
        $lossAndExpenseClaim->amount_agreed = $validated['status'] === 'agreed' ? ($validated['amount_agreed'] ?? 0) : null;
        $lossAndExpenseClaim->save();

        if ($validated['status'] === 'agreed') {
            $this->createFinalAccountItemIfPossible($lossAndExpenseClaim);
        }

        $decisionMessage = $validated['status'] === 'agreed'
            ? "L&E Claim #{$lossAndExpenseClaim->claim_number} agreed — £" . number_format((float) $lossAndExpenseClaim->amount_agreed, 2)
            : "L&E Claim #{$lossAndExpenseClaim->claim_number} rejected";
        $notifyMessage = $validated['status'] === 'agreed'
            ? 'Agreed — £' . number_format((float) $lossAndExpenseClaim->amount_agreed, 2) . '.'
            : 'Rejected.';

        ProjectActivityService::record(
            $lossAndExpenseClaim->project,
            $request->user(),
            'loss_and_expense_decided',
            $decisionMessage,
            null,
            $lossAndExpenseClaim
        );

        // No decided_at column on this model — updated_at (fresh after the
        // save() above) is the next best instance discriminator, same
        // reasoning as EotRequestController::decide().
        $this->notifyClaim(
            $request, $project, $lossAndExpenseClaim, 'decided',
            "decided_{$validated['status']}_" . $lossAndExpenseClaim->updated_at->timestamp, $notifyMessage,
            SuresignNotification::PRIORITY_WARNING, 'loss_and_expense.decided',
        );

        return response()->json($lossAndExpenseClaim->fresh());
    }

    private function notifyClaim(
        Request $request, Project $project, LossAndExpenseClaim $claim, string $kind, string $sourceField, string $message,
        string $priority = SuresignNotification::PRIORITY_INFO, ?string $emailEvent = null,
    ): void {
        $title = $kind === 'submitted'
            ? "L&E Claim #{$claim->claim_number} Submitted"
            : "L&E Claim #{$claim->claim_number} " . ($claim->status === 'agreed' ? 'Agreed' : 'Rejected');

        // Computed once and reused for both the in-app notification and
        // (Batch 4) the email's own CTA button.
        $actionUrl = WorkspaceNavigationResolver::actionUrl($project->id, 'loss_and_expense_claim', $claim->id, $claim->trade_package_id);

        NotificationService::sendToOrganization(
            $project->organization,
            'loss_and_expense_' . $kind,
            $title,
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_COMMERCIAL, 'priority' => $priority,
                'source_type' => 'loss_and_expense_claim', 'source_id' => $claim->id, 'source_field' => $sourceField,
                'action_url' => $actionUrl,
            ],
            $request->user(),
        );

        if ($emailEvent) {
            EmailNotificationService::send($emailEvent, "L&E Claim #{$claim->claim_number}", $message, EmailNotificationService::actionMeta($actionUrl, 'View Claim'), $project->organization);
        }
    }

    private function createFinalAccountItemIfPossible(LossAndExpenseClaim $claim): void
    {
        if ($claim->final_account_item_id) {
            return; // already seeded — never double-create
        }

        $finalAccount = $claim->trade_package_id
            ? FinalAccount::where('trade_package_id', $claim->trade_package_id)->first()
            : ($claim->contract_id ? FinalAccount::where('contract_id', $claim->contract_id)->first() : null);

        if (!$finalAccount || $finalAccount->isLocked()) {
            return;
        }

        $item = FinalAccountItem::create([
            'final_account_id' => $finalAccount->id,
            'category'         => FinalAccount::CATEGORY_LOSS_AND_EXPENSE,
            'description'      => "L&E Claim #{$claim->claim_number} — {$claim->title}",
            'source_type'      => 'loss_and_expense_claim',
            'source_id'        => $claim->id,
            'amount'           => $claim->amount_agreed ?? 0,
            'is_auto_seeded'   => true,
            'sort_order'       => FinalAccountItem::where('final_account_id', $finalAccount->id)->max('sort_order') + 1,
        ]);

        $claim->update(['final_account_item_id' => $item->id]);
    }

    public function destroy(Request $request, Project $project, LossAndExpenseClaim $lossAndExpenseClaim)
    {
        $this->authorizeProjectClaim($request, $project, $lossAndExpenseClaim);

        $lossAndExpenseClaim->delete();
        return response()->json(null, 204);
    }
}
