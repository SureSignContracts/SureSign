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

    public function index(Contract $contract)
    {
        return response()->json(
            ContractProgrammeMilestone::where('contract_id', $contract->id)
                ->orderBy('sort_order')
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function indexByProject(Project $project)
    {
        return response()->json(
            ContractProgrammeMilestone::where('project_id', $project->id)
                ->with(['contract:id,title,reference_number', 'tradePackage:id,name'])
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function indexByTradePackage(Project $project, TradePackage $tradePackage)
    {
        return response()->json(
            ContractProgrammeMilestone::where('trade_package_id', $tradePackage->id)
                ->orderBy('sort_order')
                ->orderBy('planned_date')
                ->get()
        );
    }

    public function store(Request $request, Contract $contract)
    {
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
        $validated = $request->validate(array_merge(self::RULES, ['name' => 'sometimes|string|max:255']));

        $milestone->update($validated);

        return response()->json($milestone->fresh());
    }

    public function destroy(ContractProgrammeMilestone $milestone)
    {
        $milestone->delete();
        return response()->json(null, 204);
    }

    /**
     * Seed programme milestones from the contract's confirmed AI analysis key_dates.
     */
    public function seedFromAnalysis(Request $request, Contract $contract)
    {
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
