<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\ContractRisk;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\TradePackage;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

/**
 * Risk Register management. Extends the existing ContractRisk table
 * (Sprint 6F) rather than introducing a parallel model — mirrors
 * DelayEventController's project/trade-package split.
 *
 * AI-generated (contract-level) risks are still only ever created by the
 * main-contract AI analysis confirmation flow — this controller never writes
 * is_ai_generated = true. Manual risks created here (Sprint 6H) may target
 * either a Contract or a Trade Package; exactly one must be set.
 */
class RiskController extends Controller
{
    private const RULES = [
        'title'         => 'required|string|max:255',
        'description'   => 'nullable|string',
        'category'      => 'nullable|in:commercial,programme,delay,payment,design,information,procurement,client,subcontractor,other',
        'severity'      => 'nullable|in:low,medium,high,critical',
        'probability'   => 'nullable|in:low,medium,high',
        'risk_owner'    => 'nullable|string|max:255',
        'recommended_action' => 'nullable|string',
        'mitigation'    => 'nullable|string',
        'status'        => 'nullable|in:open,in_progress,resolved',
        'review_date'   => 'nullable|date',
    ];

    /**
     * Mirrors DelayEventController::authorize / FinalAccountController's
     * per-type authorize methods. Super Admin / Admin can cross organisations;
     * everyone else must match.
     */
    private function authorize(Request $request, Project|TradePackage|ContractRisk $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the risk's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectRisk(Request $request, Project $project, ContractRisk $risk): void
    {
        $this->authorize($request, $risk);
        if ($risk->project_id !== $project->id) {
            abort(404, 'Risk not found for this project.');
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

    /**
     * All risks for a project — main contract(s) AND every trade package —
     * for the project-level Risk Register page. Same contract-or-trade-package
     * scoping already used by dashboardIntelligence()'s risk_summary, just
     * returning the full list with source/navigation info instead of counts.
     */
    public function indexForProject(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $contractIds = \App\Models\Contract::where('project_id', $project->id)->pluck('id');
        $tradePackageIds = TradePackage::where('project_id', $project->id)->pluck('id');

        $risks = ContractRisk::where(function ($q) use ($contractIds, $tradePackageIds) {
                $q->whereIn('contract_id', $contractIds)
                  ->orWhereIn('trade_package_id', $tradePackageIds);
            })
            ->with(['contract:id,title', 'tradePackage:id,name'])
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 0
                    WHEN 'high'     THEN 1
                    WHEN 'medium'   THEN 2
                    WHEN 'low'      THEN 3
                    ELSE 4
                END
            ")
            ->latest()
            ->get()
            ->map(function (ContractRisk $risk) use ($project) {
                $risk->source_name = $risk->contract->title ?? $risk->tradePackage->name ?? null;
                $risk->action_url = WorkspaceNavigationResolver::actionUrl(
                    $project->id, CalendarEvent::SOURCE_CONTRACT_RISK, $risk->id, $risk->trade_package_id
                );
                return $risk;
            });

        return response()->json($risks);
    }

    /**
     * Manual risk creation at project level — the user picks either a
     * contract or a trade package as the parent (mirrors the nullable-FK
     * invariant already enforced elsewhere: exactly one, never neither).
     */
    public function storeForProject(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate(array_merge(self::RULES, [
            'contract_id'      => 'nullable|integer|exists:contracts,id',
            'trade_package_id' => 'nullable|integer|exists:trade_packages,id',
        ]));

        if (empty($validated['contract_id']) === empty($validated['trade_package_id'])) {
            return response()->json(['message' => 'A risk must belong to either a contract or a trade package (not both, not neither).'], 422);
        }

        $risk = ContractRisk::create(array_merge($validated, [
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'severity'        => $validated['severity'] ?? 'medium',
            'category'        => $validated['category'] ?? 'other',
            'status'          => $validated['status'] ?? 'open',
            'is_ai_generated' => false,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'contract_risk_created',
            "Risk raised: {$risk->title}",
            null,
            $risk
        );

        $this->notifyRisk($request, $project, $risk, 'created', "Added to the risk register for {$project->name}.");

        return response()->json($risk, 201);
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $risks = ContractRisk::where('trade_package_id', $tradePackage->id)
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 0
                    WHEN 'high'     THEN 1
                    WHEN 'medium'   THEN 2
                    WHEN 'low'      THEN 3
                    ELSE 4
                END
            ")
            ->latest()
            ->get();

        return response()->json($risks);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $validated = $request->validate(self::RULES);

        $risk = ContractRisk::create(array_merge($validated, [
            'organization_id'  => $tradePackage->organization_id,
            'project_id'       => $tradePackage->project_id,
            'trade_package_id' => $tradePackage->id,
            'contract_id'      => null,
            'severity'         => $validated['severity'] ?? 'medium',
            'category'         => $validated['category'] ?? 'other',
            'status'           => $validated['status'] ?? 'open',
            'is_ai_generated'  => false,
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'trade_package_risk_created',
            "Risk raised for {$tradePackage->name}: {$risk->title}",
            null,
            $risk
        );

        $this->notifyRisk($request, $project, $risk, 'created', "Added to the risk register for {$tradePackage->name}.");

        return response()->json($risk, 201);
    }

    public function update(Request $request, Project $project, ContractRisk $risk)
    {
        $this->authorizeProjectRisk($request, $project, $risk);

        $validated = $request->validate(array_merge(self::RULES, ['title' => 'sometimes|string|max:255']));

        $previousStatus   = $risk->status;
        $previousSeverity = $risk->severity;
        $risk->update($validated);

        // "Materially updated" per the approved channel policy = a status or
        // severity change — the risk register's two stakeholder-relevant
        // signals. Editing description/mitigation/notes alone stays silent.
        if ($risk->status !== $previousStatus || $risk->severity !== $previousSeverity) {
            $severityChanged = $risk->severity !== $previousSeverity;
            $statusChanged   = $risk->status !== $previousStatus;

            $title = match (true) {
                $severityChanged && $statusChanged => "Risk Severity & Status Changed: {$risk->title}",
                $severityChanged                    => "Risk Severity Changed: {$risk->title}",
                default                              => "Risk Status Changed: {$risk->title}",
            };

            $this->notifyRisk(
                $request, $project, $risk,
                "from_{$previousStatus}_{$previousSeverity}_to_{$risk->status}_{$risk->severity}_" . $risk->updated_at->timestamp,
                "Status: " . str_replace('_', ' ', $risk->status) . ", severity: {$risk->severity}.",
                $title,
            );
        }

        return response()->json($risk->fresh());
    }

    private function notifyRisk(Request $request, Project $project, ContractRisk $risk, string $sourceField, string $message, ?string $title = null): void
    {
        $isCreated = $sourceField === 'created';

        NotificationService::sendToOrganization(
            $project->organization,
            'contract_risk_' . ($isCreated ? 'created' : 'updated'),
            $title ?? ($isCreated ? "Risk Raised: {$risk->title}" : "Risk Status Changed: {$risk->title}"),
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_RISK, 'priority' => SuresignNotification::PRIORITY_INFO,
                'source_type' => 'contract_risk', 'source_id' => $risk->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, CalendarEvent::SOURCE_CONTRACT_RISK, $risk->id, $risk->trade_package_id),
            ],
            $request->user(),
        );
    }

    public function destroy(Request $request, Project $project, ContractRisk $risk)
    {
        $this->authorizeProjectRisk($request, $project, $risk);

        $risk->delete();

        return response()->json(null, 204);
    }
}
