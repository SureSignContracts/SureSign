<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DelayEvent;
use App\Models\EotRequest;
use App\Models\Project;
use App\Models\TradePackage;
use App\Services\DocumentGenerationService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class DelayEventController extends Controller
{
    private const RULES = [
        'title'                 => 'required|string|max:255',
        'description'           => 'nullable|string',
        'cause_category'        => 'nullable|in:weather,employer_instruction,utility,access,design,third_party,other',
        'date_occurred'         => 'required|date',
        'date_notified'         => 'nullable|date',
        'notified_by'           => 'nullable|string|max:255',
        'estimated_delay_days'  => 'nullable|integer|min:0',
        'status'                => 'nullable|in:open,under_assessment,closed,rejected',
        'notes'                 => 'nullable|string',
        'contract_id'           => 'nullable|integer|exists:contracts,id',
        'variation_id'          => 'nullable|integer|exists:variations,id',
        'affected_milestone_id' => 'nullable|integer|exists:contract_programme_milestones,id',
    ];

    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeTradePackage.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|TradePackage|DelayEvent $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the delay event's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectDelayEvent(Request $request, Project $project, DelayEvent $delayEvent): void
    {
        $this->authorize($request, $delayEvent);
        if ($delayEvent->project_id !== $project->id) {
            abort(404, 'Delay event not found for this project.');
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

        $query = DelayEvent::where('project_id', $project->id)
            ->with(['creator:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest('date_occurred')->paginate(25));
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $events = DelayEvent::where('trade_package_id', $tradePackage->id)
            ->with(['creator:id,name'])
            ->latest('date_occurred')
            ->get();

        return response()->json($events);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(self::RULES);

        $eventNumber = (DelayEvent::where('project_id', $project->id)->max('event_number') ?? 0) + 1;

        $delayEvent = DelayEvent::create(array_merge($validated, [
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $request->user()->id,
            'event_number'    => $eventNumber,
            'cause_category'  => $validated['cause_category'] ?? 'other',
            'status'          => $validated['status'] ?? 'open',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'delay_event_raised',
            "Delay Event #{$eventNumber} raised: {$delayEvent->title}",
            null,
            $delayEvent
        );

        return response()->json($delayEvent, 201);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $validated = $request->validate(self::RULES);

        $eventNumber = (DelayEvent::where('project_id', $tradePackage->project_id)->max('event_number') ?? 0) + 1;

        $delayEvent = DelayEvent::create(array_merge($validated, [
            'project_id'       => $tradePackage->project_id,
            'organization_id'  => $tradePackage->organization_id,
            'trade_package_id' => $tradePackage->id,
            'created_by'       => $request->user()->id,
            'event_number'     => $eventNumber,
            'cause_category'   => $validated['cause_category'] ?? 'other',
            'status'           => $validated['status'] ?? 'open',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'delay_event_raised',
            "Delay Event #{$eventNumber} raised for {$tradePackage->name}: {$delayEvent->title}",
            null,
            $delayEvent
        );

        return response()->json($delayEvent, 201);
    }

    // Not shallow (api/projects/{project}/delay-events/{delay_event}) — both
    // segments are typed model bindings, so Project $project must be
    // declared even though unused here, matching the same fix already
    // applied to MeetingMinutesController/SiteDiaryController/etc.
    public function show(Request $request, Project $project, DelayEvent $delayEvent)
    {
        $this->authorizeProjectDelayEvent($request, $project, $delayEvent);

        $delayEvent->load(['creator:id,name', 'contract:id,title,reference_number', 'tradePackage:id,name', 'variation:id,title', 'affectedMilestone:id,name']);

        // Returned as a plain array merge, not setAttribute() — see the
        // identical note on EotRequestController::withCurrentCompletionDate.
        $relatedEot = EotRequest::where('delay_event_id', $delayEvent->id)->first(['id', 'eot_number', 'status']);

        return response()->json(array_merge($delayEvent->toArray(), ['related_eot' => $relatedEot]));
    }

    public function update(Request $request, Project $project, DelayEvent $delayEvent)
    {
        $this->authorizeProjectDelayEvent($request, $project, $delayEvent);

        $validated = $request->validate(array_merge(self::RULES, ['title' => 'sometimes|string|max:255', 'date_occurred' => 'sometimes|date']));

        $delayEvent->update($validated);

        return response()->json($delayEvent->fresh());
    }

    public function destroy(Request $request, Project $project, DelayEvent $delayEvent)
    {
        $this->authorizeProjectDelayEvent($request, $project, $delayEvent);

        $delayEvent->delete();
        return response()->json(null, 204);
    }

    /**
     * Generate a formal Notice of Delay PDF. Requires date_notified to be
     * set — a Notice document is evidence that notice was actually given,
     * not a draft of an intention to notify.
     */
    public function generateNotice(Request $request, Project $project, DelayEvent $delayEvent)
    {
        $this->authorizeProjectDelayEvent($request, $project, $delayEvent);

        if (!$delayEvent->date_notified) {
            return response()->json(['message' => 'The Delay Event must be marked as notified (date_notified set) before generating a Notice document.'], 422);
        }

        $delayEvent->load(['contract', 'tradePackage', 'affectedMilestone', 'variation']);
        $relatedEot = EotRequest::where('delay_event_id', $delayEvent->id)->first();

        $reference = "DELAY-{$delayEvent->event_number}";

        $document = DocumentGenerationService::generatePdf(
            $delayEvent->project, $request->user(),
            'pdfs.delay-notice',
            [
                'delayEvent'   => $delayEvent,
                'contract'     => $delayEvent->contract,
                'tradePackage' => $delayEvent->tradePackage,
                'relatedEot'   => $relatedEot,
                'reference'    => $reference,
                'issuedBy'     => $request->user(),
            ],
            "Notice of Delay — {$reference}",
            'delay_notice', '05_Notices', $reference, $delayEvent, false, $delayEvent->tradePackage
        );

        ProjectActivityService::record(
            $delayEvent->project,
            $request->user(),
            'delay_event.notice_generated',
            "Notice of Delay generated for Delay Event #{$delayEvent->event_number}",
            null,
            $document
        );

        return response()->json($document, 201);
    }
}
