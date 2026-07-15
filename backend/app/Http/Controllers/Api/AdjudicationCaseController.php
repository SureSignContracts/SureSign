<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationStep;
use App\Models\Project;
use App\Models\Contract;
use App\Models\PaymentApplication;
use App\Models\Variation;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class AdjudicationCaseController extends Controller
{
    private const STEP_DEFINITIONS = [
        ['key' => 'notice_of_dispute',       'title' => 'Notice of Dispute',        'description' => 'Capture dispute details and generate initial Notice of Dispute document.',           'sort' => 1],
        ['key' => 'notice_of_adjudication',  'title' => 'Notice of Adjudication',   'description' => 'Generate formal Notice of Adjudication using previous dispute data.',              'sort' => 2],
        ['key' => 'adjudicator_appointment', 'title' => 'Adjudicator Appointment',  'description' => 'Prepare adjudicator appointment application and nominating body details.',           'sort' => 3],
        ['key' => 'referral_submission',     'title' => 'Referral Submission',       'description' => 'Prepare and submit referral pack with supporting documents and evidence bundle.',    'sort' => 4],
        ['key' => 'response_analysis',       'title' => 'Response Analysis',         'description' => 'Upload and analyse opposing party response, record risks and weaknesses.',           'sort' => 5],
        ['key' => 'further_submissions',     'title' => 'Further Submissions',       'description' => 'Prepare further reply submissions and record counterarguments.',                     'sort' => 6],
        ['key' => 'decision_analysis',       'title' => 'Decision Analysis',         'description' => 'Record adjudicator decision, awarded amount, and key decision points.',             'sort' => 7],
        ['key' => 'enforcement',             'title' => 'Enforcement',               'description' => 'Generate enforcement and payment demand documents, record enforcement deadline.',    'sort' => 8],
    ];

    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeProject.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|AdjudicationCase $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /** Re-derives the case's REAL parent project (see MeetingMinutesController). */
    private function authorizeProjectCase(Request $request, Project $project, AdjudicationCase $adjudicationCase): void
    {
        $this->authorize($request, $adjudicationCase);
        if ($adjudicationCase->project_id !== $project->id) {
            abort(404, 'Adjudication case not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $query = AdjudicationCase::where('project_id', $project->id)
            ->with(['creator:id,name', 'deadlines'])
            ->withCount('steps');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('dispute_type')) {
            $query->where('dispute_type', $request->dispute_type);
        }
        if ($request->filled('current_step')) {
            $query->where('current_step', $request->current_step);
        }

        return response()->json($query->latest()->paginate(25));
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'title'                        => 'required|string|max:255',
            'dispute_type'                 => 'required|in:payment_dispute,variation_dispute,delay_dispute,defect_dispute,contract_interpretation,non_payment,other',
            'claimant_name'                => 'required|string|max:255',
            'respondent_name'              => 'required|string|max:255',
            'contract_id'                  => 'nullable|integer|exists:contracts,id',
            'payment_application_id'       => 'nullable|integer|exists:payment_applications,id',
            'variation_id'                 => 'nullable|integer|exists:variations,id',
            'claim_amount'                 => 'nullable|numeric|min:0',
            'currency'                     => 'nullable|string|max:3',
            'summary'                      => 'nullable|string',
            'notice_of_dispute_date'       => 'nullable|date',
            'notice_of_adjudication_date'  => 'nullable|date',
            'referral_due_date'            => 'nullable|date',
            'response_due_date'            => 'nullable|date',
            'decision_due_date'            => 'nullable|date',
        ]);

        // Validate date chronology
        $this->validateDateChronology($validated);

        // Verify related records belong to same project
        if (!empty($validated['contract_id'])) {
            abort_unless(
                \App\Models\Contract::where('id', $validated['contract_id'])->where('project_id', $project->id)->exists(),
                422, 'Contract does not belong to this project.'
            );
        }
        if (!empty($validated['payment_application_id'])) {
            abort_unless(
                \App\Models\PaymentApplication::where('id', $validated['payment_application_id'])->where('project_id', $project->id)->exists(),
                422, 'Payment Application does not belong to this project.'
            );
        }
        if (!empty($validated['variation_id'])) {
            abort_unless(
                \App\Models\Variation::where('id', $validated['variation_id'])->where('project_id', $project->id)->exists(),
                422, 'Variation does not belong to this project.'
            );
        }

        // Generate case number
        $lastNumber = AdjudicationCase::where('project_id', $project->id)->withTrashed()->count();
        $caseNumber = 'ADJ-' . str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);

        $case = AdjudicationCase::create(array_merge($validated, [
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'created_by'      => $request->user()->id,
            'case_number'     => $caseNumber,
            'status'          => 'draft',
            'current_step'    => 'notice_of_dispute',
            'currency'        => $validated['currency'] ?? 'GBP',
        ]));

        // Auto-create the 8 adjudication steps (first is in_progress)
        foreach (self::STEP_DEFINITIONS as $idx => $step) {
            AdjudicationStep::create([
                'adjudication_case_id' => $case->id,
                'step_key'             => $step['key'],
                'title'                => $step['title'],
                'description'          => $step['description'],
                'status'               => $idx === 0 ? 'in_progress' : 'pending',
                'sort_order'           => $step['sort'],
            ]);
        }

        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_created',
            "Adjudication case {$caseNumber} created: {$case->title}",
            null,
            $case
        );

        return response()->json($case->load(['creator:id,name', 'steps']), 201);
    }

    public function show(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        return response()->json(
            $adjudicationCase->load([
                'creator:id,name',
                'steps',
                'documents.uploadedBy:id,name',
                'deadlines',
                'contract:id,title,reference_number',
                'paymentApplication:id,application_number,amount_due',
                'variation:id,variation_number,title',
            ])
        );
    }

    public function update(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $validated = $request->validate([
            'title'                        => 'sometimes|string|max:255',
            'dispute_type'                 => 'sometimes|in:payment_dispute,variation_dispute,delay_dispute,defect_dispute,contract_interpretation,non_payment,other',
            'claimant_name'                => 'sometimes|string|max:255',
            'respondent_name'              => 'sometimes|string|max:255',
            'contract_id'                  => 'nullable|integer|exists:contracts,id',
            'payment_application_id'       => 'nullable|integer|exists:payment_applications,id',
            'variation_id'                 => 'nullable|integer|exists:variations,id',
            'claim_amount'                 => 'nullable|numeric|min:0',
            'currency'                     => 'nullable|string|max:3',
            'summary'                      => 'nullable|string',
            'status'                       => 'sometimes|in:draft,notice_of_dispute,notice_of_adjudication,adjudicator_appointment,referral_submission,response_analysis,further_submissions,decision_analysis,enforcement,closed',
            'current_step'                 => 'sometimes|in:notice_of_dispute,notice_of_adjudication,adjudicator_appointment,referral_submission,response_analysis,further_submissions,decision_analysis,enforcement',
            'notice_of_dispute_date'       => 'nullable|date',
            'notice_of_adjudication_date'  => 'nullable|date',
            'referral_due_date'            => 'nullable|date',
            'response_due_date'            => 'nullable|date',
            'decision_due_date'            => 'nullable|date',
            'decision_received_date'       => 'nullable|date',
            'enforcement_deadline'         => 'nullable|date',
        ]);

        // Validate date chronology
        $this->validateDateChronology($validated);

        $adjudicationCase->update($validated);

        return response()->json($adjudicationCase->fresh()->load(['creator:id,name', 'steps']));
    }

    public function destroy(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $project = $adjudicationCase->project;
        $adjudicationCase->delete();

        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_deleted',
            "Adjudication case {$adjudicationCase->case_number} archived.",
            null,
            null
        );

        return response()->json(null, 204);
    }

    public function advanceStep(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $stepKeys = array_keys(AdjudicationCase::STEPS);
        $currentIndex = array_search($adjudicationCase->current_step, $stepKeys);

        if ($currentIndex === false || $currentIndex >= count($stepKeys) - 1) {
            return response()->json(['message' => 'Already at final step or invalid step.'], 422);
        }

        // Mark current step as completed
        $currentStep = $adjudicationCase->steps()->where('step_key', $adjudicationCase->current_step)->first();
        if ($currentStep) {
            $currentStep->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
                'notes'        => $request->input('notes'),
            ]);
        }

        $nextStepKey = $stepKeys[$currentIndex + 1];

        // Set next step to in_progress
        $nextStep = $adjudicationCase->steps()->where('step_key', $nextStepKey)->first();
        if ($nextStep) {
            $nextStep->update(['status' => 'in_progress']);
        }

        $adjudicationCase->update([
            'current_step' => $nextStepKey,
            'status'       => $nextStepKey,
        ]);

        $project = $adjudicationCase->project;
        $stepTitle = AdjudicationCase::STEPS[$nextStepKey];
        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_step_advanced',
            "Adjudication case {$adjudicationCase->case_number} advanced to: {$stepTitle}",
            null,
            $adjudicationCase
        );

        return response()->json($adjudicationCase->fresh()->load(['creator:id,name', 'steps']));
    }

    public function archive(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $adjudicationCase->update([
            'status'      => 'archived',
            'archived_at' => now(),
            'archived_by' => $request->user()->id,
        ]);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_archived',
            "Adjudication case {$adjudicationCase->case_number} archived.",
            null,
            $adjudicationCase
        );

        return response()->json($adjudicationCase->fresh());
    }

    private function validateDateChronology(array &$data): void
    {
        $dateOrder = [
            'notice_of_dispute_date'      => 'Notice of Dispute',
            'notice_of_adjudication_date' => 'Notice of Adjudication',
            'referral_due_date'           => 'Referral Due',
            'response_due_date'           => 'Response Due',
            'decision_due_date'           => 'Decision Due',
        ];

        $prevDate  = null;
        $prevLabel = null;
        foreach ($dateOrder as $key => $label) {
            if (!empty($data[$key])) {
                if ($prevDate && $data[$key] < $prevDate) {
                    abort(422, "'{$label}' date cannot be before '{$prevLabel}'.");
                }
                $prevDate  = $data[$key];
                $prevLabel = $label;
            }
        }
    }

    public function updateStatus(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $validated = $request->validate([
            'status' => 'required|in:draft,active,awaiting_response,decision_pending,notice_of_dispute,notice_of_adjudication,adjudicator_appointment,referral_submission,response_analysis,further_submissions,decision_analysis,enforcement,closed,archived',
        ]);

        $adjudicationCase->update($validated);

        $project = $adjudicationCase->project;
        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_status_changed',
            "Adjudication case {$adjudicationCase->case_number} status changed to: {$validated['status']}",
            null,
            $adjudicationCase
        );

        return response()->json($adjudicationCase->fresh());
    }
}
