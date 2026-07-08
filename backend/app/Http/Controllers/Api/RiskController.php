<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CalendarEvent;
use App\Models\ContractRisk;
use App\Models\Project;
use App\Models\TradePackage;
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
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
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

        return response()->json($risk, 201);
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorize($request, $tradePackage);

        $risks = ContractRisk::where('trade_package_id', $tradePackage->id)
            ->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
            ->latest()
            ->get();

        return response()->json($risks);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorize($request, $tradePackage);

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

        return response()->json($risk, 201);
    }

    public function update(Request $request, Project $project, ContractRisk $risk)
    {
        $this->authorize($request, $risk);

        $validated = $request->validate(array_merge(self::RULES, ['title' => 'sometimes|string|max:255']));

        $risk->update($validated);

        return response()->json($risk->fresh());
    }

    public function destroy(Request $request, Project $project, ContractRisk $risk)
    {
        $this->authorize($request, $risk);

        $risk->delete();

        return response()->json(null, 204);
    }
}
