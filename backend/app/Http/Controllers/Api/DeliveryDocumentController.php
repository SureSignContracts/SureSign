<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\DeliveryDocument;
use App\Models\Document;
use App\Models\Project;
use App\Models\TradePackage;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

/**
 * Delivery Documentation — RAMS, Method Statements, ITPs, Lift Plans, COSHH,
 * Permits, etc. Reuses the existing Document/file-storage system entirely
 * (via a nullable document_id pointer); this table only tracks the
 * requirement/status lifecycle, never stores files itself. Mirrors
 * RiskController's project/trade-package split and manual authorize() pattern.
 */
class DeliveryDocumentController extends Controller
{
    private const RULES = [
        'title'         => 'required|string|max:255',
        'description'   => 'nullable|string',
        'category'      => 'nullable|in:method_statement,rams,itp,lift_plan,temporary_works,coshh,permit,installation_procedure,manufacturer_instruction,task_briefing,other',
        'status'        => 'nullable|in:required,pending,submitted,under_review,approved,rejected,expired,superseded',
        'revision'      => 'nullable|string|max:50',
        'document_id'   => 'nullable|integer|exists:documents,id',
        'submitted_by'  => 'nullable|string|max:255',
        'reviewed_by'   => 'nullable|string|max:255',
        'approved_by'   => 'nullable|string|max:255',
        'submitted_at'  => 'nullable|date',
        'reviewed_at'   => 'nullable|date',
        'approved_at'   => 'nullable|date',
        'due_date'      => 'nullable|date',
        'expiry_date'   => 'nullable|date',
        'notes'         => 'nullable|string',
    ];

    private function authorize(Request $request, Project|TradePackage|DeliveryDocument $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorize($request, $tradePackage);

        $docs = DeliveryDocument::where('trade_package_id', $tradePackage->id)
            ->with('document:id,title,file_name')
            ->latest()
            ->get();

        return response()->json($docs);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorize($request, $tradePackage);

        $validated = $request->validate(self::RULES);

        $doc = DeliveryDocument::create(array_merge($validated, [
            'organization_id'  => $tradePackage->organization_id,
            'project_id'       => $tradePackage->project_id,
            'trade_package_id' => $tradePackage->id,
            'contract_id'      => null,
            'category'         => $validated['category'] ?? 'other',
            'status'           => $validated['status'] ?? 'required',
            'is_ai_extracted'  => false,
            'created_by'       => $request->user()->id,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'delivery_document_created',
            "Delivery document raised for {$tradePackage->name}: {$doc->title}",
            null,
            $doc
        );

        return response()->json($doc, 201);
    }

    /**
     * All delivery documents for a project — main contract(s) AND every
     * trade package — for the project-level Delivery Documents page.
     */
    public function indexForProject(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $docs = DeliveryDocument::where('project_id', $project->id)
            ->with(['contract:id,title', 'tradePackage:id,name', 'document:id,title,file_name'])
            ->latest()
            ->get()
            ->map(function (DeliveryDocument $doc) use ($project) {
                $doc->source_name = $doc->contract->title ?? $doc->tradePackage->name ?? null;
                $doc->action_url = WorkspaceNavigationResolver::actionUrl(
                    $project->id, CalendarEvent::SOURCE_DELIVERY_DOCUMENT, $doc->id, $doc->trade_package_id
                );
                return $doc;
            });

        return response()->json(['data' => $docs->values()]);
    }

    public function storeForProject(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(array_merge(self::RULES, [
            'contract_id'      => 'nullable|integer|exists:contracts,id',
            'trade_package_id' => 'nullable|integer|exists:trade_packages,id',
        ]));

        if (empty($validated['contract_id']) === empty($validated['trade_package_id'])) {
            return response()->json(['message' => 'A delivery document must belong to either a contract or a trade package (not both, not neither).'], 422);
        }

        $doc = DeliveryDocument::create(array_merge($validated, [
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'category'        => $validated['category'] ?? 'other',
            'status'          => $validated['status'] ?? 'required',
            'is_ai_extracted' => false,
            'created_by'      => $request->user()->id,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'delivery_document_created',
            "Delivery document raised: {$doc->title}",
            null,
            $doc
        );

        return response()->json($doc, 201);
    }

    /**
     * Documents already uploaded/generated for this trade package, to
     * populate the "link an existing document" dropdown — never a new
     * storage mechanism, just a scoped read of the existing Document table.
     */
    public function availableDocuments(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorize($request, $tradePackage);

        $documents = Document::where('trade_package_id', $tradePackage->id)
            ->latest()
            ->get(['id', 'title', 'file_name', 'category']);

        return response()->json($documents);
    }

    public function update(Request $request, DeliveryDocument $deliveryDocument)
    {
        $this->authorize($request, $deliveryDocument);

        $validated = $request->validate(array_merge(self::RULES, ['title' => 'sometimes|string|max:255']));

        $deliveryDocument->update($validated);

        return response()->json($deliveryDocument->fresh());
    }

    public function destroy(Request $request, DeliveryDocument $deliveryDocument)
    {
        $this->authorize($request, $deliveryDocument);

        $deliveryDocument->delete();

        return response()->json(null, 204);
    }
}
