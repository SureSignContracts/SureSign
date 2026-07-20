<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteInstruction;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Http\Request;

class SiteInstructionController extends Controller
{
    /**
     * Tenant isolation — mirrors FinalAccountController::authorizeProject.
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|SiteInstruction $subject): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $subject->organization_id) abort(403, 'Access denied.');
    }

    /**
     * Re-derives the site instruction's REAL parent project so a
     * same-organisation but mismatched project ID in the URL can't address
     * an instruction that actually belongs to a different project (see
     * MeetingMinutesController/SiteDiaryController).
     */
    private function authorizeProjectSiteInstruction(Request $request, Project $project, SiteInstruction $siteInstruction): void
    {
        $this->authorize($request, $siteInstruction);
        if ($siteInstruction->project_id !== $project->id) {
            abort(404, 'Site instruction not found for this project.');
        }
    }

    public function index(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $instructions = SiteInstruction::where('project_id', $project->id)
            ->with('creator:id,name')
            ->latest('issued_date')
            ->paginate(25);

        return response()->json($instructions);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'instruction_number' => 'nullable|integer',
            'title'              => 'required|string|max:255',
            'issued_date'        => 'required|date',
            'issued_to'          => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'status'             => 'nullable|in:draft,issued',
        ]);

        $validated['instruction_number'] = $validated['instruction_number']
            ?? (SiteInstruction::where('project_id', $project->id)->max('instruction_number') ?? 0) + 1;

        $instruction = SiteInstruction::create(array_merge($validated, [
            'project_id'     => $project->id,
            'created_by'     => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'status'         => $validated['status'] ?? 'draft',
        ]));

        ProjectActivityService::record(
            $project,
            $request->user(),
            'site_instruction_issued',
            "Site Instruction #{$instruction->instruction_number} issued: {$instruction->title}",
            null,
            $instruction
        );

        if ($instruction->status === 'issued') {
            $this->notifyInstruction($request, $project, $instruction, 'issued', 'issued', $instruction->title);
        }

        return response()->json($instruction, 201);
    }

    // Not shallow (api/projects/{project}/site-instructions/{site_instruction})
    // — both route segments are typed model bindings, so Project $project
    // must be declared even though unused here, or Laravel's implicit
    // binding gets the positional args confused and passes the project's
    // string ID where $siteInstruction (typed SiteInstruction) is expected —
    // matching the same fix already applied to
    // MeetingMinutesController/SiteDiaryController/EotRequestController.
    public function show(Request $request, Project $project, SiteInstruction $siteInstruction)
    {
        $this->authorizeProjectSiteInstruction($request, $project, $siteInstruction);

        return response()->json($siteInstruction->load('creator:id,name'));
    }

    public function update(Request $request, Project $project, SiteInstruction $siteInstruction)
    {
        $this->authorizeProjectSiteInstruction($request, $project, $siteInstruction);

        $oldStatus = $siteInstruction->status;

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'issued_date' => 'sometimes|date',
            'issued_to'   => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'status'      => 'nullable|in:draft,issued',
        ]);

        $siteInstruction->update($validated);

        if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
            ProjectActivityService::record(
                $project,
                $request->user(),
                'site_instruction_updated',
                "Site Instruction #{$siteInstruction->instruction_number} status changed to {$validated['status']}",
                null,
                $siteInstruction
            );

            // "Issued" is the only status change meaningful enough to notify
            // — "draft" is a routine, not-yet-visible working state, matching
            // the same channel policy already used by RFIs/site diaries.
            if ($validated['status'] === 'issued') {
                $this->notifyInstruction(
                    $request, $project, $siteInstruction, 'issued',
                    'issued_' . $siteInstruction->updated_at->timestamp,
                    $siteInstruction->title
                );
            }
        }

        return response()->json($siteInstruction);
    }

    public function destroy(Request $request, Project $project, SiteInstruction $siteInstruction)
    {
        $this->authorizeProjectSiteInstruction($request, $project, $siteInstruction);

        $siteInstruction->delete();
        return response()->json(null, 204);
    }

    private function notifyInstruction(Request $request, Project $project, SiteInstruction $instruction, string $kind, string $sourceField, string $message): void
    {
        NotificationService::sendToOrganization(
            $project->organization,
            'site_instruction_' . $kind,
            "Site Instruction #{$instruction->instruction_number} Issued",
            $message,
            [],
            [
                'project_id' => $project->id, 'organization_id' => $project->organization_id,
                'category' => SuresignNotification::CATEGORY_COMMUNICATION, 'priority' => SuresignNotification::PRIORITY_INFO,
                'source_type' => 'site_instruction', 'source_id' => $instruction->id, 'source_field' => $sourceField,
                'action_url' => WorkspaceNavigationResolver::actionUrl($project->id, 'site_instruction', $instruction->id),
            ],
            $request->user(),
        );
    }
}
