<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\LocalDocumentMirrorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentGenerationService
{
    /**
     * Generate a PDF from a Blade view, save it, and create a Document record.
     *
     * @param  Project        $project
     * @param  User           $user
     * @param  string         $viewName    Blade view path, e.g. 'pdfs.payment-application'
     * @param  array          $viewData    Data to pass to the view
     * @param  string         $title       Document title
     * @param  string         $type        Document type slug
     * @param  string|null    $category    Folder category / folder path
     * @param  string|null    $reference   Reference number
     * @param  Model|null     $relatedModel  Morphable parent (PaymentApplication, etc.)
     * @return Document
     */
    public static function generatePdf(
        Project $project,
        User $user,
        string $viewName,
        array $viewData,
        string $title,
        string $type,
        ?string $category = null,
        ?string $reference = null,
        ?Model $relatedModel = null
    ): Document {
        // Load branding for the organisation
        $branding = Organization::with('branding')->find($project->organization_id)?->branding;
        $viewData['branding'] = $branding;
        $viewData['project']  = $project;

        $pdf = Pdf::loadView($viewName, $viewData)
            ->setPaper('a4', 'portrait');

        $fileName  = Str::slug($title) . '-' . now()->format('Ymd-His') . '.pdf';
        $filePath  = "projects/{$project->id}/generated/{$fileName}";

        Storage::disk('local')->put($filePath, $pdf->output());

        $doc = Document::create([
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'created_by'       => $user->id,
            'title'            => $title,
            'type'             => $type,
            'category'         => $category,
            'reference_number' => $reference,
            'status'           => 'issued',
            'file_path'        => $filePath,
            'file_name'        => $fileName,
            'mime_type'        => 'application/pdf',
            'file_size'        => Storage::disk('local')->size($filePath),
            'ai_generated'     => false,
            'template_data'    => $viewData,
            'documentable_type' => $relatedModel ? get_class($relatedModel) : null,
            'documentable_id'   => $relatedModel ? $relatedModel->id : null,
        ]);

        // Mirror generated PDF to local mirror path if enabled
        LocalDocumentMirrorService::mirrorDocument($doc, $project);

        return $doc;
    }
}
