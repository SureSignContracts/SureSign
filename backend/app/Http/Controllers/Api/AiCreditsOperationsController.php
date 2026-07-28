<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiCreditLedgerEntry;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\TradePackageAiAnalysis;
use App\Services\AI\AiCreditBalanceService;
use App\Support\AI\AiCreditOperatingMode;
use App\Support\AI\AiWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Phase G4C.3D-1 — the AI Credits Operations Dashboard's read-only backend.
 * `role:Super Admin|Admin` only (see routes/api.php) — neither role is
 * org-scoped in this codebase's role model (see OrganizationController's
 * equivalent `subscription()` endpoint), so no per-request tenancy check
 * is needed beyond that route middleware. No Client-role access exists.
 *
 * Deliberately NOT backed by a separate reporting service — every query
 * here is either a direct call into the existing AiCreditBalanceService or
 * a small, focused Eloquent query with no independent logic worth
 * extracting. If a genuine orchestration need emerges later (e.g. shared
 * caching, cross-query composition), promote these into a service then —
 * not speculatively now.
 *
 * Read-only: no mutating action exists anywhere in this controller.
 */
class AiCreditsOperationsController extends Controller
{
    public function __construct(private readonly AiCreditBalanceService $balance)
    {
    }

    public function summary(): JsonResponse
    {
        $platform = $this->balance->platformBalance();

        $shadow = $this->shadowCounts();

        return response()->json([
            'issued' => $platform['issued'],
            'consumed' => $platform['consumed'],
            'reserved' => $platform['reserved'],
            'available' => $platform['available'],
            'active_organizations' => AiCreditLedgerEntry::query()->distinct('organization_id')->count('organization_id'),
            'total_analyses' => ContractAiAnalysis::query()->count() + TradePackageAiAnalysis::query()->count(),
            'shadow' => $shadow,
            'by_workflow' => [
                AiWorkflow::CONTRACT_ANALYSIS => $this->workflowSummary(ContractAiAnalysis::class, AiWorkflow::CONTRACT_ANALYSIS),
                AiWorkflow::TRADE_PACKAGE_ANALYSIS => $this->workflowSummary(TradePackageAiAnalysis::class, AiWorkflow::TRADE_PACKAGE_ANALYSIS),
            ],
        ]);
    }

    /**
     * GET /admin/ai-credits/operating-mode — read-only status for the Super
     * Admin AI Credit operating mode control (App\Support\AI\
     * AiCreditOperatingMode — disabled/shadow/enforced). Visible to Admin
     * too (same 'Super Admin|Admin' gate as the rest of this controller);
     * only AiCreditsGrantController::updateOperatingMode() (Super Admin
     * only) can change it.
     */
    public function operatingModeSettings(): JsonResponse
    {
        return response()->json([
            'operating_mode' => AiCreditOperatingMode::current(),
            'customer_meter_enabled' => (bool) config('ai_credit_shadow.customer_meter_enabled', false),
            'active_candidate' => config('ai_credit_shadow.active_candidate'),
            'approved_candidate' => config('ai_credit_shadow.approved_candidate'),
        ]);
    }

    public function organizations(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $search = trim((string) $request->input('search', ''));

        $query = Organization::query()->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        $paginated = $query->paginate($perPage, ['id', 'name'], 'page', $page);

        $rows = collect($paginated->items())->map(function (Organization $org) {
            $balance = $this->balance->balanceFor($org->id);
            $shadow = $this->shadowCountsFor($org->id);

            return [
                'organization_id' => $org->id,
                'organization_name' => $org->name,
                'issued' => $balance['issued'],
                'consumed' => $balance['consumed'],
                'reserved' => $balance['reserved'],
                'available' => $balance['available'],
                'total_analyses' => ContractAiAnalysis::where('organization_id', $org->id)->count()
                    + TradePackageAiAnalysis::where('organization_id', $org->id)->count(),
                'shadow_sufficient' => $shadow['sufficient'],
                'shadow_insufficient' => $shadow['insufficient'],
                'shadow_unresolved' => $shadow['unresolved'],
                'shadow_bypassed' => $shadow['bypassed'],
            ];
        });

        return response()->json([
            'data' => $rows,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    public function organizationDetail(int $id): JsonResponse
    {
        $organization = Organization::query()->select(['id', 'name'])->findOrFail($id);

        $recentTransactions = AiCreditLedgerEntry::query()
            ->where('organization_id', $id)
            ->latest('created_at')
            ->limit(10)
            ->get(['id', 'created_at', 'workflow', 'transaction_type', 'amount', 'reason', 'actor_type', 'reference_type', 'reference_id']);

        $recentContract = ContractAiAnalysis::where('organization_id', $id)
            ->latest('created_at')->limit(10)
            ->get(['id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at']);
        $recentTradePackage = TradePackageAiAnalysis::where('organization_id', $id)
            ->latest('created_at')->limit(10)
            ->get(['id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at']);

        $recentAnalyses = $recentContract->map(fn ($a) => ['workflow' => AiWorkflow::CONTRACT_ANALYSIS, ...$a->only(['id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at'])])
            ->concat($recentTradePackage->map(fn ($a) => ['workflow' => AiWorkflow::TRADE_PACKAGE_ANALYSIS, ...$a->only(['id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at'])]))
            ->sortByDesc('created_at')
            ->take(10)
            ->values();

        return response()->json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'balance' => $this->balance->balanceFor($id),
            // Phase G4C.3D-1, adjustment 3 — "AI Workflow Usage": derived entirely
            // from the existing ledger (consumedByWorkflow) and analysis tables
            // (counts), no duplicate calculation of anything AiCreditBalanceService
            // or the analysis rows already represent.
            'workflow_usage' => [
                AiWorkflow::CONTRACT_ANALYSIS => $this->workflowSummary(ContractAiAnalysis::class, AiWorkflow::CONTRACT_ANALYSIS, $id),
                AiWorkflow::TRADE_PACKAGE_ANALYSIS => $this->workflowSummary(TradePackageAiAnalysis::class, AiWorkflow::TRADE_PACKAGE_ANALYSIS, $id),
            ],
            'recent_transactions' => $recentTransactions,
            'recent_analyses' => $recentAnalyses,
        ]);
    }

    public function transactions(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));

        $query = AiCreditLedgerEntry::query()->with('organization:id,name')->latest('created_at');

        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->input('organization_id'));
        }
        if ($request->filled('workflow')) {
            $query->where('workflow', $request->input('workflow'));
        }
        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->input('transaction_type'));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        $rows = collect($paginated->items())->map(fn (AiCreditLedgerEntry $e) => [
            'id' => $e->id,
            'created_at' => $e->created_at,
            'organization_id' => $e->organization_id,
            'organization_name' => $e->organization?->name,
            'workflow' => $e->workflow,
            'transaction_type' => $e->transaction_type,
            'amount' => (float) $e->amount,
            'reason' => $e->reason,
            'actor_type' => $e->actor_type,
            'actor_id' => $e->actor_id,
            'reference_type' => $e->reference_type,
            'reference_id' => $e->reference_id,
            'idempotency_key' => $e->idempotency_key,
        ]);

        return response()->json([
            'data' => $rows,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ]);
    }

    public function shadowActivity(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        $page = max(1, (int) $request->input('page', 1));
        $workflowFilter = $request->input('workflow');
        $shadowStatus = $request->input('shadow_status');

        $contract = ($workflowFilter === null || $workflowFilter === AiWorkflow::CONTRACT_ANALYSIS)
            ? $this->shadowQuery(ContractAiAnalysis::query(), $request)->get(['id', 'organization_id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at'])
                ->map(fn ($a) => ['workflow' => AiWorkflow::CONTRACT_ANALYSIS, ...$a->toArray()])
            : collect();

        $tradePackage = ($workflowFilter === null || $workflowFilter === AiWorkflow::TRADE_PACKAGE_ANALYSIS)
            ? $this->shadowQuery(TradePackageAiAnalysis::query(), $request)->get(['id', 'organization_id', 'status', 'shadow_enforcement_result', 'credit_reservation_amount', 'created_at'])
                ->map(fn ($a) => ['workflow' => AiWorkflow::TRADE_PACKAGE_ANALYSIS, ...$a->toArray()])
            : collect();

        $merged = $contract->concat($tradePackage)->sortByDesc('created_at')->values();

        $orgNames = Organization::query()->pluck('name', 'id');
        $merged = $merged->map(fn ($row) => [...$row, 'organization_name' => $orgNames->get($row['organization_id'])]);

        $page = max(1, $page);
        $items = $merged->slice(($page - 1) * $perPage, $perPage)->values();
        $paginator = new LengthAwarePaginator($items, $merged->count(), $perPage, $page);

        return response()->json([
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function shadowQuery($query, Request $request)
    {
        if ($request->filled('organization_id')) {
            $query->where('organization_id', (int) $request->input('organization_id'));
        }
        if ($request->filled('shadow_status')) {
            $query->where('shadow_enforcement_result', $request->input('shadow_status'));
        }
        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to'));
        }

        return $query->latest('created_at')->limit(500);
    }

    /** @return array{sufficient: int, insufficient: int, unresolved: int, bypassed: int} */
    private function shadowCounts(): array
    {
        return $this->mergeShadowCounts(
            $this->shadowCountsByStatus(ContractAiAnalysis::query()),
            $this->shadowCountsByStatus(TradePackageAiAnalysis::query())
        );
    }

    private function shadowCountsFor(int $organizationId): array
    {
        return $this->mergeShadowCounts(
            $this->shadowCountsByStatus(ContractAiAnalysis::where('organization_id', $organizationId)),
            $this->shadowCountsByStatus(TradePackageAiAnalysis::where('organization_id', $organizationId))
        );
    }

    /**
     * shadow_enforcement_result is null for two different reasons — a row
     * created while the operating mode was DISABLED (the lifecycle was
     * intentionally never evaluated), or a row that predates this column's
     * introduction / is still pending and hasn't reached the shadow write
     * yet. This method can't tell those apart (that would need recording
     * which mode was active per-row, which nothing here does), so its
     * 'bypassed' figure is reported as "no credit evaluation occurred",
     * not asserted to mean DISABLED specifically. It is never folded into
     * sufficient/insufficient/unresolved — those three remain an exhaustive,
     * mutually exclusive breakdown of rows that WERE evaluated.
     *
     * @return array{sufficient: int, insufficient: int, unresolved: int, bypassed: int}
     */
    private function shadowCountsByStatus($query): array
    {
        $counts = (clone $query)->whereNotNull('shadow_enforcement_result')
            ->selectRaw('shadow_enforcement_result, COUNT(*) as c')
            ->groupBy('shadow_enforcement_result')
            ->pluck('c', 'shadow_enforcement_result')
            ->all();

        $counts['bypassed'] = (clone $query)->whereNull('shadow_enforcement_result')->count();

        return $counts;
    }

    private function mergeShadowCounts(array $a, array $b): array
    {
        return [
            'sufficient' => ($a['sufficient'] ?? 0) + ($b['sufficient'] ?? 0),
            'insufficient' => ($a['insufficient'] ?? 0) + ($b['insufficient'] ?? 0),
            'unresolved' => ($a['unresolved'] ?? 0) + ($b['unresolved'] ?? 0),
            'bypassed' => ($a['bypassed'] ?? 0) + ($b['bypassed'] ?? 0),
        ];
    }

    /**
     * @param class-string<ContractAiAnalysis|TradePackageAiAnalysis> $modelClass
     */
    private function workflowSummary(string $modelClass, string $workflow, ?int $organizationId = null): array
    {
        $query = $modelClass::query();
        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $shadow = $this->shadowCountsByStatus($modelClass::query()->when($organizationId !== null, fn ($q) => $q->where('organization_id', $organizationId)));

        $consumedByWorkflow = $organizationId !== null
            ? $this->balance->consumedByWorkflow($organizationId)
            : $this->balance->platformConsumedByWorkflow();

        $workflowConsumption = $consumedByWorkflow[$workflow] ?? ['consumed' => 0.0, 'settled_count' => 0];

        return [
            'total_analyses' => $query->count(),
            'credits_consumed' => $workflowConsumption['consumed'],
            'average_credits_per_analysis' => $workflowConsumption['settled_count'] > 0
                ? round($workflowConsumption['consumed'] / $workflowConsumption['settled_count'], 2)
                : null,
            'shadow_sufficient' => $shadow['sufficient'] ?? 0,
            'shadow_insufficient' => $shadow['insufficient'] ?? 0,
            'shadow_unresolved' => $shadow['unresolved'] ?? 0,
            'shadow_bypassed' => $shadow['bypassed'] ?? 0,
        ];
    }
}
