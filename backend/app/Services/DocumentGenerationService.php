<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\CurrencyService;
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
     * @param  TradePackage|null $tradePackage  Set when this document belongs to a trade
     *                                          package's subcontract, so it surfaces on
     *                                          that package's Documents tab (Sprint 6C).
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
        ?Model $relatedModel = null,
        bool $skipCanvas = false,
        ?TradePackage $tradePackage = null
    ): Document {
        // Load branding for the organisation
        $branding = BrandingService::forOrganization($project->organization_id);
        $viewData['branding']          = $branding;
        $viewData['branding_logo_uri'] = BrandingService::logoFileUri($branding);
        $viewData['project']           = $project;

        // Inject currency symbol unless the caller already provided one.
        // Priority: project currency → platform currency → £ (never uses contract.currency
        // directly, which may have been populated by AI extraction from contract text).
        if (!isset($viewData['currency'])) {
            $viewData['currency'] = CurrencyService::resolveSymbol($project);
        }

        $pdf = Pdf::loadView($viewName, $viewData)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'DejaVu Sans',
            ]);

        // Draw letterhead header/footer images directly onto every page canvas.
        // The @page margins in each view already reserve this space (145px top, 110px bottom).
        $headerAbsPath = BrandingService::headerPath($branding);
        $footerAbsPath = BrandingService::footerPath($branding);

        if (!$skipCanvas && ($headerAbsPath || $footerAbsPath)) {
            $pdf->render();
            $canvas = $pdf->getDomPDF()->getCanvas();
            $pageW  = $canvas->get_width();
            $pageH  = $canvas->get_height();
            $headerH = 145 * (72 / 96); // CSS px → PDF pts
            $footerH = 110 * (72 / 96);

            $canvas->page_script(function (int $pageNum, int $pageCount, $canvas) use (
                $headerAbsPath, $footerAbsPath, $pageW, $pageH, $headerH, $footerH
            ) {
                if ($headerAbsPath) {
                    $canvas->image($headerAbsPath, 0, 0, $pageW, $headerH);
                }
                if ($footerAbsPath) {
                    $canvas->image($footerAbsPath, 0, $pageH - $footerH, $pageW, $footerH);
                }
            });
        }

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
            'trade_package_id'  => $tradePackage?->id,
        ]);

        // Mirror generated PDF to local mirror path if enabled
        LocalDocumentMirrorService::mirrorDocument($doc, $project);

        return $doc;
    }
}
