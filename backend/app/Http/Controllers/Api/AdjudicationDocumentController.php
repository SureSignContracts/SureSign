<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdjudicationCase;
use App\Models\AdjudicationDocument;
use App\Models\Project;
use App\Services\LocalDocumentMirrorService;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdjudicationDocumentController extends Controller
{
    public function index(Project $project, AdjudicationCase $adjudicationCase)
    {
        return response()->json(
            $adjudicationCase->documents()
                ->with('uploadedBy:id,name')
                ->latest()
                ->get()
        );
    }

    public function store(Request $request, Project $project, AdjudicationCase $adjudicationCase)
    {
        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'document_type' => 'required|in:notice_of_dispute,notice_of_adjudication,adjudicator_application,referral_submission,response,further_submission,decision,enforcement_letter,evidence,supporting_document,other',
            'category'      => 'nullable|string|max:100',
            'tags'          => 'nullable|array',
            'tags.*'        => 'string|max:50',
            'source_step'   => 'nullable|string',
            'status'        => 'nullable|in:draft,pending_review,approved,issued,archived',
            'ai_generated'  => 'nullable|boolean',
            'file_name'     => 'nullable|string|max:255',
            'file_path'     => 'nullable|string|max:1000',
            'mime_type'     => 'nullable|string|max:100',
            'file_size'     => 'nullable|integer',
            'document_id'   => 'nullable|integer|exists:documents,id',
            'file'          => 'nullable|file|max:51200',
        ]);

        $fileData = [];
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store("adjudication/{$adjudicationCase->id}", 'local');
            $fileData = [
                'file_path'  => $path,
                'file_name'  => $file->getClientOriginalName(),
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

        // Mirror to local export path if enabled and a file was attached
        if (!empty($fileData['file_path'])) {
            LocalDocumentMirrorService::mirrorAdjudicationDocument($document, $project);
        }

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

    public function destroy(Project $project, AdjudicationDocument $adjudicationDocument)
    {
        $adjudicationDocument->delete();
        return response()->json(null, 204);
    }
}
