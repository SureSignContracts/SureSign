<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationDocument;
use App\Models\Project;
use App\Models\SuresignSetting;
use App\Services\FileSecurityService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdjudicationDocumentController extends Controller
{
    /**
     * Super Admin / Admin can cross organisations; everyone else must match.
     */
    private function authorize(Request $request, Project|AdjudicationCase|AdjudicationDocument $subject): void
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

    /** Re-derives the document's REAL parent project. */
    private function authorizeProjectDocument(Request $request, Project $project, AdjudicationDocument $adjudicationDocument): void
    {
        $this->authorize($request, $adjudicationDocument);
        if ($adjudicationDocument->project_id !== $project->id) {
            abort(404, 'Adjudication document not found for this project.');
        }
    }

    public function index(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        return response()->json(
            $adjudicationCase->documents()
                ->with('uploadedBy:id,name')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $this->authorizeProjectCase($request, $project, $adjudicationCase);

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'document_type' => 'required|in:notice_of_dispute,notice_of_adjudication,adjudicator_application,referral_submission,response,further_submission,decision,enforcement_letter,evidence,supporting_document,other',
            'category'      => 'nullable|string|max:100',
            'tags'          => 'nullable|array',
            'tags.*'        => 'string|max:50',
            'source_step'   => 'nullable|string',
            'status'        => 'nullable|in:draft,pending_review,approved,issued,archived',
            'ai_generated'  => 'nullable|boolean',
            'document_id'   => 'nullable|integer|exists:documents,id',
            'file'          => 'nullable|file|max:' . SuresignSetting::maxUploadKb(),
        ]);

        // file_path/file_name/mime_type/file_size are never trusted from the
        // request — they are only ever derived from an actual uploaded file
        // below, or (for the document_id linking flow) left null and
        // resolved from the linked Document record elsewhere.
        $fileData = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
            $storedName = FileSecurityService::randomStorageName($file);
            $path = $file->storeAs("adjudication/{$adjudicationCase->id}", $storedName, 'local');
            $fileData = [
                'file_path'  => $path,
                'file_name'  => FileSecurityService::sanitizeDisplayName($file->getClientOriginalName()),
                'mime_type'  => $file->getMimeType(),
                'file_size'  => $file->getSize(),
            ];
        }

        $document = AdjudicationDocument::create(array_merge($validated, $fileData, [
            'organization_id'       => $adjudicationCase->organization_id,
            'project_id'            => $adjudicationCase->project_id,
            'adjudication_case_id'  => $adjudicationCase->id,
            'uploaded_by'           => $request->user()->id,
            'status'                => $validated['status'] ?? 'draft',
            'ai_generated'          => $validated['ai_generated'] ?? false,
        ]));

        $project = $adjudicationCase->project;

        ProjectActivityService::record(
            $project,
            $request->user(),
            'adjudication_document_added',
            "Document '{$document->title}' added to adjudication case {$adjudicationCase->case_number}",
            null,
            $adjudicationCase
        );

        return response()->json($document->load('uploadedBy:id,name'), 201);
    }

    public function destroy(Request $request, Project $project, AdjudicationDocument $adjudicationDocument)
    {
        $this->authorizeProjectDocument($request, $project, $adjudicationDocument);

        $adjudicationDocument->delete();
        return response()->json(null, 204);
    }
}
