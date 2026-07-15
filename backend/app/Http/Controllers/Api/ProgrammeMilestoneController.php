<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\ContractProgrammeMilestone;
use App\Models\Project;
use App\Models\TradePackage;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProgrammeMilestoneController extends Controller
{
    private const RULES = [
        'name'             => 'required|string|max:255',
        'milestone_type'   => 'nullable|in:commencement,sectional_completion,completion,handover,obligation,other',
        'responsible_party'=> 'nullable|in:contractor,employer,both',
        'status'           => 'nullable|in:not_started,in_progress,complete,delayed,at_risk',
        'planned_date'     => 'nullable|date',
        'forecast_date'    => 'nullable|date',
        'actual_date'      => 'nullable|date',
        'planned_start'    => 'nullable|date',
        'forecast_start'   => 'nullable|date',
        'actual_start'     => 'nullable|date',
        'duration_days'    => 'nullable|integer|min:0',
        'progress_pct'     => 'nullable|integer|min:0|max:100',
        'depends_on'       => 'nullable|array',
        'depends_on.*'     => 'integer',
        'group_name'       => 'nullable|string|max:255',
        'source_text'      => 'nullable|string',
        'notes'            => 'nullable|string',
        'sort_order'       => 'nullable|integer',
    ];

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

    /**
     * Milestones have no organization_id column of their own — organisation
     * is derived through the real parent (project, which every milestone
     * always has). Every method below was previously missing this check
     * entirely: any authenticated user of any organisation could view,
     * create, update, or delete another organisation's programme
     * milestones, and even trigger seedFromAnalysis against an arbitrary
     * contract. Fixed for every role, not just Client.
     */
    private function authorizeMilestone(Request $request, ContractProgrammeMilestone $milestone): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        $project = $milestone->project;
        if (!$project || $user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    private function authorizeProjectPackage(Request $request, Project $project, TradePackage $tradePackage): void
    {
        $this->authorizeProject($request, $project);
        if ($tradePackage->project_id !== $project->id) {
            abort(404, 'Trade package not found for this project.');
        }
    }

    public function index(Request $request, Contract $contract)
    {
        $this->authorizeContract($request, $contract);

        return response()->json(
            ContractProgrammeMilestone::where('contract_id', $contract->id)
                ->orderBy('sort_order')
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function indexByProject(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        return response()->json(
            ContractProgrammeMilestone::where('project_id', $project->id)
                ->with(['contract:id,title,reference_number', 'tradePackage:id,name'])
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function indexByTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        return response()->json(
            ContractProgrammeMilestone::where('trade_package_id', $tradePackage->id)
                ->orderBy('sort_order')
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function store(Request $request, Contract $contract)
    {
        $this->authorizeContract($request, $contract);

        $validated = $request->validate(self::RULES);

        $milestone = ContractProgrammeMilestone::create(array_merge($validated, [
            'contract_id'    => $contract->id,
            'project_id'     => $contract->project_id,
            'is_ai_generated'=> false,
            'milestone_type' => $validated['milestone_type'] ?? 'other',
            'status'         => $validated['status'] ?? 'not_started',
        ]));

        return response()->json($milestone, 201);
    }

    public function storeForTradePackage(Request $request, Project $project, TradePackage $tradePackage)
    {
        $this->authorizeProjectPackage($request, $project, $tradePackage);

        $validated = $request->validate(self::RULES);

        $milestone = ContractProgrammeMilestone::create(array_merge($validated, [
            'trade_package_id' => $tradePackage->id,
            'project_id'       => $tradePackage->project_id,
            'is_ai_generated'  => false,
            'milestone_type'   => $validated['milestone_type'] ?? 'other',
            'status'           => $validated['status'] ?? 'not_started',
        ]));

        return response()->json($milestone, 201);
    }

    public function update(Request $request, ContractProgrammeMilestone $milestone)
    {
        $this->authorizeMilestone($request, $milestone);

        $validated = $request->validate(array_merge(self::RULES, ['name' => 'sometimes|string|max:255']));

        $milestone->update($validated);

        return response()->json($milestone->fresh());
    }

    public function destroy(Request $request, ContractProgrammeMilestone $milestone)
    {
        $this->authorizeMilestone($request, $milestone);

        $milestone->delete();
        return response()->json(null, 204);
    }

    /**
     * Seed programme milestones from the contract's confirmed AI analysis key_dates.
     */
    public function seedFromAnalysis(Request $request, Contract $contract)
    {
        $this->authorizeContract($request, $contract);

        $analysis = ContractAiAnalysis::where('contract_id', $contract->id)
            ->where('status', 'confirmed')
            ->latest()
            ->first();

        if (!$analysis) {
            return response()->json(['message' => 'No confirmed AI analysis found for this contract.'], 422);
        }

        $keyDates = $analysis->confirmed_data_json['key_dates'] ?? [];

        if (empty($keyDates)) {
            return response()->json(['message' => 'No key dates found in the AI analysis.'], 422);
        }

        // Delete any existing AI-generated milestones for this contract before re-seeding
        ContractProgrammeMilestone::where('contract_id', $contract->id)
            ->where('is_ai_generated', true)
            ->delete();

        $milestones = [];
        $sort = 0;

        foreach ($keyDates as $entry) {
            $name       = $entry['name'] ?? null;
            $dateStr    = $entry['date'] ?? null;
            $sourceText = $entry['source'] ?? null;

            if (!$name) continue;

            // Try to parse the date — skip if unparseable
            $date = null;
            if ($dateStr && $dateStr !== 'null') {
                try {
                    $date = Carbon::parse($dateStr)->toDateString();
                } catch (\Throwable) {
                    $date = null;
                }
            }

            $type = self::inferMilestoneType($name);

            $milestones[] = ContractProgrammeMilestone::create([
                'contract_id'     => $contract->id,
                'project_id'      => $contract->project_id,
                'analysis_id'     => $analysis->id,
                'name'            => $name,
                'milestone_type'  => $type,
                'planned_date'    => $date,
                'responsible_party' => 'contractor',
                'status'          => 'not_started',
                'source_text'     => $sourceText,
                'is_ai_generated' => true,
                'sort_order'      => $sort++,
            ]);
        }

        return response()->json([
            'message'    => count($milestones) . ' milestones seeded from AI analysis.',
            'milestones' => $milestones,
        ], 201);
    }

    private static function inferMilestoneType(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'complet') || str_contains($lower, 'practical completion')) return 'completion';
        if (str_contains($lower, 'commenc') || str_contains($lower, 'start')) return 'commencement';
        if (str_contains($lower, 'handover') || str_contains($lower, 'crane')) return 'handover';
        if (str_contains($lower, 'sectional')) return 'sectional_completion';
        return 'milestone';
    }
}
