<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\EotRequest;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\TradePackage;
use App\Services\DocumentGenerationService;
use App\Services\EmailNotificationService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;

class EotRequestController extends Controller
{
    private const RULES = [
        'eot_number'      => 'nullable|integer',
        'title'           => 'required|string|max:255',
        'notice_date'     => 'required|date',
        'grounds'         => 'nullable|string',
        'days_claimed'    => 'nullable|integer|min:0',
        'days_granted'    => 'nullable|integer|min:0',
        'status'          => 'nullable|in:draft,submitted,under_assessment,granted,refused',
        'contract_id'     => 'nullable|integer|exists:contracts,id',
        'delay_event_id'  => 'nullable|integer|exists:delay_events,id',
    ];

    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeTradePackage.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|TradePackage|EotRequest $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the EOT request's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectEot(Request $request, Project $project, EotRequest $eotRequest): void
    {
        $this->authorize($request, $eotRequest);
        if ($eotRequest->project_id !== $project->id) {
            abort(404, 'EOT request not found for this project.');
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

        $eots = EotRequest::where('project_id', $project->id)
            ->with(['creator:id,name', 'decisionUser:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name'])
            ->latest()
            ->paginate(25);

        $eots->getCollection()->transform(fn ($eot) => $this->withCurrentCompletionDate($eot));

        return response()->json($eots);
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $eots = EotRequest::where('trade_package_id', $tradePackage->id)
            ->with(['creator:id,name', 'decisionUser:id,name'])
            ->latest()
            ->get()
            ->map(fn ($eot) => $this->withCurrentCompletionDate($eot));

        return response()->json($eots);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(self::RULES);

        $validated['eot_number'] = $validated['eot_number']
            ?? (EotRequest::where('project_id', $project->id)->max('eot_number') ?? 0) + 1;

        $eot = EotRequest::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $project->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'eot_submitted',
            "EOT #{$eot->eot_number} submitted: {$eot->title}",
            null,
            $eot
        );

        if ($eot->status === 'submitted') {
            $this->notifyEot($request, $project, $eot, 'submitted', 'submitted', $eot->title);
        }

        return response()->json($eot, 201);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $validated = $request->validate(self::RULES);

        $validated['eot_number'] = $validated['eot_number']
            ?? (EotRequest::where('project_id', $tradePackage->project_id)->max('eot_number') ?? 0) + 1;

        $eot = EotRequest::create(array_merge($validated, [
            'project_id'       => $tradePackage->project_id,
            'organization_id'  => $tradePackage->organization_id,
            'trade_package_id' => $tradePackage->id,
            'created_by'       => $request->user()->id,
            'status'           => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'eot_submitted',
            "EOT #{$eot->eot_number} submitted for {$tradePackage->name}: {$eot->title}",
            null,
            $eot
        );

        if ($eot->status === 'submitted') {
            $this->notifyEot($request, $project, $eot, 'submitted', 'submitted', "{$eot->title} ({$tradePackage->name}).");
        }

        return response()->json($eot, 201);
    }

    // Not shallow (api/projects/{project}/eot-requests/{eot_request}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/SiteDiaryController/etc.
    public function show(Request $request, Project $project, EotRequest $eotRequest)
    {
        $this->authorizeProjectEot($request, $project, $eotRequest);

        $eotRequest->load(['creator:id,name', 'decisionUser:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name', 'delayEvent:id,event_number,title']);

        return response()->json($this->withCurrentCompletionDate($eotRequest));
    }

    public function update(Request $request, Project $project, EotRequest $eotRequest)
    {
        $this->authorizeProjectEot($request, $project, $eotRequest);

        $validated = $request->validate(array_merge(self::RULES, [
            'title'       => 'sometimes|string|max:255',
            'notice_date' => 'sometimes|date',
        ]));

        $eotRequest->update($validated);

        return response()->json($eotRequest);
    }

    /**
     * Record the assessment decision on an EOT and, if granted, compute the
     * revised completion date. This is intentionally a single state-change
     * action, not a multi-stage approval workflow — that stays deferred.
     *
     * Revised completion date = base completion date (from whichever the EOT
     * is scoped to — contract or trade package) + the cumulative granted days
     * of every granted EOT against that same contract/trade package,
     * including this one. There is no separate ledger table: the sequence of
     * EotRequest rows themselves is the history, and the "current" revised
     * completion date is simply the most recent granted one.
     */
    public function decide(Request $request, Project $project, EotRequest $eotRequest)
    {
        $this->authorizeProjectEot($request, $project, $eotRequest);

        $validated = $request->validate([
            'status'       => 'required|in:granted,refused',
            'days_granted' => 'required_if:status,granted|nullable|integer|min:0',
        ]);

        $eotRequest->status       = $validated['status'];
        $eotRequest->days_granted = $validated['status'] === 'granted' ? ($validated['days_granted'] ?? 0) : 0;
        $eotRequest->decided_by   = $request->user()->id;
        $eotRequest->decided_at   = now();
        $eotRequest->save();

        $eotRequest->revised_completion_date = $validated['status'] === 'granted'
            ? $this->computeRevisedCompletionDate($eotRequest)
            : null;
        $eotRequest->save();

        $decisionMessage = $validated['status'] === 'granted'
            ? "EOT #{$eotRequest->eot_number} granted — {$eotRequest->days_granted} days"
            : "EOT #{$eotRequest->eot_number} refused";
        $notifyMessage = $validated['status'] === 'granted'
            ? "Granted — {$eotRequest->days_granted} day" . ($eotRequest->days_granted !== 1 ? 's' : '') . '.'
            : 'Refused.';

        ProjectActivityService::record(
            $eotRequest->project,
            $request->user(),
            'eot_decided',
            $decisionMessage,
            null,
            $eotRequest
        );

        // decided_at (just set above) makes the key unique per decision
        // instance — decide() has no guard against re-deciding an EOT (e.g.
        // amending days_granted later), and a from_X_to_Y string alone
        // wouldn't distinguish a genuine second "granted" decision from a
        // duplicate report of the first one.
        $this->notifyEot(
            $request, $project, $eotRequest, 'decided',
            "decided_{$validated['status']}_" . $eotRequest->decided_at->timestamp, $notifyMessage,
            SuresignNotification::PRIORITY_WARNING, 'eot.decided',
        );

        return response()->json($eotRequest->fresh());
    }

    private function notifyEot(
        Request $request, Project $project, EotRequest $eot, string $kind, string $sourceField, string $message,
        string $priority = SuresignNotification::PRIORITY_INFO, ?string $emailEvent = null,
    ): void {
        $title = $kind === 'submitted'
            ? "EOT #{$eot->eot_number} Submitted"
            : "EOT #{$eot->eot_number} " . ($eot->status === 'granted' ? 'Granted' : 'Refused');

        // Computed once and reused for both the in-app notification and
        // (Batch 4) the email's own CTA button.
        $actionUrl = WorkspaceNavigationResolver::actionUrl($project->id, 'eot_request', $eot->id, $eot->trade_package_id);

        NotificationService::sendToOrganization(
            $project->organization,
            'eot_' . $kind,
            $title,
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_PROGRAMME, 'priority' => $priority,
                'source_type' => 'eot_request', 'source_id' => $eot->id, 'source_field' => $sourceField,
                'action_url' => $actionUrl,
            ],
            $request->user(),
        );

        if ($emailEvent) {
            EmailNotificationService::send($emailEvent, "EOT #{$eot->eot_number}", $message, EmailNotificationService::actionMeta($actionUrl, 'View EOT'), $project->organization);
        }
    }

    private function computeRevisedCompletionDate(EotRequest $eotRequest): ?string
    {
        $baseDate = $eotRequest->trade_package_id
            ? TradePackage::find($eotRequest->trade_package_id)?->completion_date
            : ($eotRequest->contract_id ? Contract::find($eotRequest->contract_id)?->completion_date : null);

        if (!$baseDate) {
            return null;
        }

        $totalGrantedDays = EotRequest::where('project_id', $eotRequest->project_id)
            ->where('status', 'granted')
            ->when($eotRequest->trade_package_id, fn($q) => $q->where('trade_package_id', $eotRequest->trade_package_id))
            ->when(!$eotRequest->trade_package_id && $eotRequest->contract_id, fn($q) => $q->where('contract_id', $eotRequest->contract_id))
            ->sum('days_granted');

        return Carbon::parse($baseDate)->addDays((int) $totalGrantedDays)->toDateString();
    }

    /**
     * Attach the authoritative current_completion_date (Phase 9) — latest
     * granted EOT's revised date, falling back to the base contract/trade
     * package completion date. Computed here, not in the frontend.
     */
    /**
     * Returns a plain array, not a mutated model — setAttribute() would write
     * this computed field into the model's real attribute bag, and if that
     * same instance were ever saved afterward Eloquent would try to persist
     * a column that doesn't exist and fail. This can only ever be a response
     * payload, never a model passed on to something that might save() it.
     */
    private function withCurrentCompletionDate(EotRequest $eotRequest): array
    {
        $source = $eotRequest->trade_package_id
            ? TradePackage::find($eotRequest->trade_package_id)
            : ($eotRequest->contract_id ? Contract::find($eotRequest->contract_id) : null);

        return array_merge($eotRequest->toArray(), [
            'current_completion_date' => $source?->currentCompletionDate()?->toDateString(),
        ]);
    }

    public function destroy(Request $request, Project $project, EotRequest $eotRequest)
    {
        $this->authorizeProjectEot($request, $project, $eotRequest);

        $eotRequest->delete();
        return response()->json(null, 204);
    }

    /**
     * Generate a formal EOT Decision Notice PDF. Only available once the EOT
     * has actually been decided — a Decision Notice records a decision that
     * was made, not a pending assessment.
     */
    public function generateDecisionNotice(Request $request, Project $project, EotRequest $eotRequest)
    {
        $this->authorizeProjectEot($request, $project, $eotRequest);

        if (!in_array($eotRequest->status, ['granted', 'refused'])) {
            return response()->json(['message' => 'A Decision Notice can only be generated once the EOT has been decided (granted or refused).'], 422);
        }

        $eotRequest->load(['contract', 'tradePackage', 'delayEvent']);

        $reference = "EOT-{$eotRequest->eot_number}";

        $document = DocumentGenerationService::generatePdf(
            $eotRequest->project, $request->user(),
            'pdfs.eot-decision',
            [
                'eotRequest'   => $eotRequest,
                'contract'     => $eotRequest->contract,
                'tradePackage' => $eotRequest->tradePackage,
                'reference'    => $reference,
                'issuedBy'     => $request->user(),
            ],
            "EOT Decision Notice — {$reference}",
            'eot_decision_notice', '05_Notices', $reference, $eotRequest, false, $eotRequest->tradePackage
        );

        ProjectActivityService::record(
            $eotRequest->project,
            $request->user(),
            'eot_request.decision_notice_generated',
            "Decision Notice generated for EOT #{$eotRequest->eot_number}",
            null,
            $document
        );

        return response()->json($document, 201);
    }
}
