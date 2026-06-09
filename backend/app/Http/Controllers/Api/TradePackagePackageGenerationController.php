<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\FileUpload;
use App\Models\TradePackage;
use App\Services\DocxToPdfService;
use App\Services\DocumentNumberService;
use App\Services\LocalDocumentMirrorService;
use App\Services\NotificationService;
use App\Services\ProjectActivityService;
use App\Services\ProjectStorageService;
use App\Services\SureSignFolderPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use ZipArchive;

class TradePackagePackageGenerationController extends Controller
{
    /**
     * Document type labels for generated files.
     */
    const DOCUMENT_TYPE_LABELS = [
        'master_package'        => 'Master Package',
        'procurement_summary'   => 'Procurement Summary',
        'tender_enquiry_letter' => 'Tender Enquiry Letter',
        'schedule_of_documents' => 'Schedule of Documents',
        'subcontract_draft'     => 'Subcontract Draft',
    ];

    /**
     * Filename suffixes for each document type.
     */
    const DOCUMENT_TYPE_SUFFIXES = [
        'master_package'        => 'Master_Trade_Package_Draft',
        'procurement_summary'   => 'Procurement_Summary',
        'tender_enquiry_letter' => 'Tender_Enquiry_Letter',
        'schedule_of_documents' => 'Schedule_Of_Documents',
        'subcontract_draft'     => 'Subcontract_Draft',
    ];

    /**
     * Maps internal document type keys to DocumentNumberService codes.
     */
    const DOCUMENT_NUMBER_TYPE_MAP = [
        'master_package'        => 'CON',
        'procurement_summary'   => 'PSM',
        'tender_enquiry_letter' => 'TEL',
        'schedule_of_documents' => 'SOD',
        'subcontract_draft'     => 'CON',
    ];

    private DocumentNumberService $documentNumberService;

    public function __construct(DocumentNumberService $documentNumberService)
    {
        $this->documentNumberService = $documentNumberService;
    }

    public function generate(Request $request, TradePackage $tradePackage)
    {
        $project = $tradePackage->project;
        if (!$project) {
            return response()->json(['message' => 'Project not found for this trade package.'], 404);
        }

        $user = $request->user();
        if (
            $user->organization_id !== $project->organization_id
            && !$user->hasRole('Super Admin')
            && !$user->hasRole('Admin')
        ) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'generation_type'          => 'nullable|string|in:master_package,separate_documents',
            'template_id'              => 'nullable|integer|exists:document_templates,id',
            'selected_document_types'  => 'nullable|array',
            'selected_document_types.*'=> 'string|in:procurement_summary,tender_enquiry_letter,schedule_of_documents,subcontract_draft',
            'company_name'                  => 'nullable|string|max:255',
            'project_name'                  => 'nullable|string|max:255',
            'project_reference'             => 'nullable|string|max:255',
            'site_address'                  => 'nullable|string|max:1000',
            'employer_name'                 => 'nullable|string|max:255',
            'architect_name'                => 'nullable|string|max:255',
            'qs_name'                       => 'nullable|string|max:255',
            'principal_designer'            => 'nullable|string|max:255',
            'trade_package'                 => 'nullable|string|max:255',
            'package_reference'             => 'nullable|string|max:255',
            'pkg_code'                      => 'nullable|string|max:255',
            'package_scope'                 => 'nullable|string|max:5000',
            'contractor_name'               => 'nullable|string|max:255',
            'contractor_legal_name'         => 'nullable|string|max:255',
            'contractor_company_number'     => 'nullable|string|max:100',
            'contractor_registered_address' => 'nullable|string|max:1000',
            'contractor_contact_name'       => 'nullable|string|max:255',
            'contractor_email'              => 'nullable|email|max:255',
            'contract_sum'                  => 'nullable|string|max:255',
            'contract_sum_words'            => 'nullable|string|max:1000',
            'start_date'                    => 'nullable|string|max:255',
            'completion_date'               => 'nullable|string|max:255',
            'contract_duration'             => 'nullable|string|max:255',
            'retention_percentage'          => 'nullable|string|max:255',
            'retention_half_percentage'     => 'nullable|string|max:255',
            'ld_rate'                       => 'nullable|string|max:255',
            'rectification_period'          => 'nullable|string|max:255',
            'valuation_day'                 => 'nullable|string|max:255',
            'document_date'                 => 'nullable|string|max:255',
            'drawing_schedule_ref'          => 'nullable|string|max:255',
            'specification_ref'             => 'nullable|string|max:255',
            'pricing_doc_ref'               => 'nullable|string|max:255',
            'prelims_ref'                   => 'nullable|string|max:255',
        ]);

        $generationType = $validated['generation_type'] ?? 'master_package';

        $company  = $project->organization;
        $client   = $project->client;
        $metadata = is_array($project->metadata ?? null) ? $project->metadata : [];

        $pkgCodeDefault = $tradePackage->package_code ?? '';
        $projCodeDefault = $project->code ?? '';
        $packageReferenceDefault = $tradePackage->package_reference
            ?: ($projCodeDefault && $pkgCodeDefault ? "{$projCodeDefault}-{$pkgCodeDefault}" : $pkgCodeDefault);

        $values = [
            'company_name'                  => $validated['company_name'] ?? ($company->name ?? ''),
            'project_name'                  => $validated['project_name'] ?? ($project->name ?? ''),
            'project_reference'             => $validated['project_reference'] ?? ($project->code ?? ''),
            'site_address'                  => $validated['site_address'] ?? ($project->address ?? ''),
            'employer_name'                 => $validated['employer_name'] ?? ($client?->name ?? ($metadata['employer_name'] ?? '')),
            'architect_name'                => $validated['architect_name'] ?? ($metadata['architect_name'] ?? ''),
            'qs_name'                       => $validated['qs_name'] ?? ($metadata['qs_name'] ?? ''),
            'principal_designer'            => $validated['principal_designer'] ?? ($metadata['principal_designer'] ?? ''),
            'trade_package'                 => $validated['trade_package'] ?? ($tradePackage->name ?? ''),
            'package_reference'             => $validated['package_reference'] ?? $packageReferenceDefault,
            'pkg_code'                      => $validated['pkg_code'] ?? $pkgCodeDefault,
            'package_scope'                 => $validated['package_scope'] ?? ($tradePackage->description ?? ''),
            'contractor_name'               => $validated['contractor_name'] ?? '',
            'contractor_legal_name'         => $validated['contractor_legal_name'] ?? '',
            'contractor_company_number'     => $validated['contractor_company_number'] ?? '',
            'contractor_registered_address' => $validated['contractor_registered_address'] ?? '',
            'contractor_contact_name'       => $validated['contractor_contact_name'] ?? '',
            'contractor_email'              => $validated['contractor_email'] ?? '',
            'contract_sum'                  => $validated['contract_sum'] ?? '',
            'contract_sum_words'            => $validated['contract_sum_words'] ?? '',
            'start_date'                    => $validated['start_date'] ?? '',
            'completion_date'               => $validated['completion_date'] ?? '',
            'contract_duration'             => $validated['contract_duration'] ?? '',
            'retention_percentage'          => $validated['retention_percentage'] ?? '',
            'retention_half_percentage'     => $validated['retention_half_percentage'] ?? '',
            'ld_rate'                       => $validated['ld_rate'] ?? '',
            'rectification_period'          => $validated['rectification_period'] ?? '',
            'valuation_day'                 => $validated['valuation_day'] ?? '',
            'document_date'                 => $validated['document_date'] ?? '',
            'drawing_schedule_ref'          => $validated['drawing_schedule_ref'] ?? '',
            'specification_ref'             => $validated['specification_ref'] ?? '',
            'pricing_doc_ref'               => $validated['pricing_doc_ref'] ?? '',
            'prelims_ref'                   => $validated['prelims_ref'] ?? '',
        ];

        $projRef  = $project->code ?: Str::slug($project->name);
        $pkgCode  = $tradePackage->package_code ?: 'PKG';
        $dateStr  = now()->format('Y-m-d');
        $pkgFolder   = SureSignFolderPathService::sanitizeSegment($tradePackage->name);
        $storageDir  = ProjectStorageService::modulePath($project, 'subcontracts') . '/' . $pkgFolder;
        Storage::disk('local')->makeDirectory($storageDir);

        if ($generationType === 'separate_documents') {
            return $this->generateSeparateDocuments(
                $request, $tradePackage, $project, $user,
                $validated, $values, $projRef, $pkgCode, $dateStr, $storageDir
            );
        }

        return $this->generateMasterPackage(
            $request, $tradePackage, $project, $user,
            $validated, $values, $projRef, $pkgCode, $dateStr, $storageDir
        );
    }

    // -------------------------------------------------------------------------
    // Master Package mode
    // -------------------------------------------------------------------------

    private function generateMasterPackage($request, $tradePackage, $project, $user, $validated, $values, $projRef, $pkgCode, $dateStr, $storageDir)
    {
        if (!empty($validated['template_id'])) {
            $template = DocumentTemplate::findOrFail($validated['template_id']);
        } else {
            $template = DocumentTemplate::findForGeneration('subcontract', 'master_package', $project->organization_id);
        }

        if (!$template) {
            return response()->json(['message' => 'No active master package template found. Please upload a Master Package template first.'], 422);
        }

        if (empty($template->file_path) || !Storage::disk('local')->exists($template->file_path)) {
            return response()->json(['message' => 'The selected template does not have a valid DOCX file attached.'], 422);
        }

        $templatePath = Storage::disk('local')->path($template->file_path);

        // Pre-generate document number so it can be substituted in the template.
        $docNumberPreview = $this->documentNumberService->format(
            $project, $tradePackage, 'CON',
            $this->documentNumberService->peek($project, 'CON', $tradePackage) + 1
        );
        $valuesWithNumber = array_merge($values, ['document_number' => $docNumberPreview]);

        [$filename, $resolvedList, $unresolvedList] = $this->processTemplate(
            $templatePath, $valuesWithNumber, "{$projRef}-{$pkgCode}_Master_Trade_Package_Draft_{$dateStr}.docx", $storageDir
        );

        $upload   = $this->createFileUpload($project, $user, $tradePackage, $filename, $storageDir, 'master_package');
        $document = $this->createDocument($project, $user, $tradePackage, $template, $filename, $storageDir, 'master_package', $valuesWithNumber);

        // Register document number now that the file upload exists.
        $this->documentNumberService->generate($project, 'CON', $tradePackage, 'Master Trade Package: ' . $tradePackage->name, $upload->id);

        // Pre-generate PDF preview in the background (best-effort — don't fail generation if PDF fails)
        $this->generatePdfPreview($upload, $document);

        LocalDocumentMirrorService::mirrorFileUpload($upload, $project);
        ProjectActivityService::record($project, $user, 'document_generated', "Generated master package: {$filename}", 'contracts', $document);
        NotificationService::send(
            $user,
            NotificationService::TRADE_PACKAGE_GENERATED,
            'Documents Generated',
            '1 subcontract document generated for ' . $tradePackage->name . ' package.'
        );

        return response()->json([
            'message'                 => 'Master package generated successfully.',
            'generation_type'         => 'master_package',
            'filename'                => $filename,
            'resolved_count'          => count($resolvedList),
            'unresolved_count'        => count($unresolvedList),
            'resolved_placeholders'   => array_values($resolvedList),
            'unresolved_placeholders' => $unresolvedList,
            'file_upload'             => $upload->load('uploader:id,name'),
            'document'                => $document,
            'generated_files'         => [
                [
                    'name'          => $filename,
                    'document_type' => 'Master Package',
                    'file_upload_id'=> $upload->id,
                ],
            ],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Separate Documents mode
    // -------------------------------------------------------------------------

    private function generateSeparateDocuments($request, $tradePackage, $project, $user, $validated, $values, $projRef, $pkgCode, $dateStr, $storageDir)
    {
        $selectedTypes = $validated['selected_document_types'] ?? [
            'procurement_summary',
            'tender_enquiry_letter',
            'schedule_of_documents',
            'subcontract_draft',
        ];

        if (empty($selectedTypes)) {
            return response()->json(['message' => 'Please select at least one document type to generate.'], 422);
        }

        $generatedFiles  = [];
        $totalResolved   = 0;
        $totalUnresolved = 0;
        $errors          = [];

        foreach ($selectedTypes as $docType) {
            $template = DocumentTemplate::findForGeneration('subcontract', $docType, $project->organization_id);

            if (!$template) {
                $errors[] = "No active template found for: " . (self::DOCUMENT_TYPE_LABELS[$docType] ?? $docType);
                continue;
            }

            if (empty($template->file_path) || !Storage::disk('local')->exists($template->file_path)) {
                $errors[] = "Template file missing for: " . (self::DOCUMENT_TYPE_LABELS[$docType] ?? $docType);
                continue;
            }

            $suffix   = self::DOCUMENT_TYPE_SUFFIXES[$docType] ?? Str::studly($docType);
            $filename = "{$projRef}-{$pkgCode}_{$suffix}_{$dateStr}.docx";
            $templatePath = Storage::disk('local')->path($template->file_path);

            // Pre-generate document number for template substitution.
            $numberTypeCode = self::DOCUMENT_NUMBER_TYPE_MAP[$docType] ?? null;
            $valuesForDoc   = $values;
            if ($numberTypeCode) {
                $preview = $this->documentNumberService->format(
                    $project, $tradePackage, $numberTypeCode,
                    $this->documentNumberService->peek($project, $numberTypeCode, $tradePackage) + 1
                );
                $valuesForDoc = array_merge($values, ['document_number' => $preview]);
            }

            [$filename, $resolvedList, $unresolvedList] = $this->processTemplate($templatePath, $valuesForDoc, $filename, $storageDir);
            $totalResolved   += count($resolvedList);
            $totalUnresolved += count($unresolvedList);

            $upload   = $this->createFileUpload($project, $user, $tradePackage, $filename, $storageDir, $docType);
            $document = $this->createDocument($project, $user, $tradePackage, $template, $filename, $storageDir, $docType, $valuesForDoc);

            // Register document number now that the file upload exists.
            if ($numberTypeCode) {
                $this->documentNumberService->generate(
                    $project,
                    $numberTypeCode,
                    $tradePackage,
                    (self::DOCUMENT_TYPE_LABELS[$docType] ?? $docType) . ': ' . $tradePackage->name,
                    $upload->id
                );
            }

            // Pre-generate PDF preview (best-effort)
            $this->generatePdfPreview($upload, $document);

            LocalDocumentMirrorService::mirrorFileUpload($upload, $project);

            $generatedFiles[] = [
                'name'           => $filename,
                'document_type'  => self::DOCUMENT_TYPE_LABELS[$docType] ?? $docType,
                'file_upload_id' => $upload->id,
            ];
        }

        if (empty($generatedFiles)) {
            return response()->json([
                'message' => 'No documents could be generated. ' . implode(' ', $errors),
                'errors'  => $errors,
            ], 422);
        }

        $count = count($generatedFiles);
        ProjectActivityService::record(
            $project, $user, 'document_generated',
            "Generated {$count} separate subcontract document(s) for {$tradePackage->name}",
            'contracts'
        );
        NotificationService::send(
            $user,
            NotificationService::TRADE_PACKAGE_GENERATED,
            'Documents Generated',
            count($generatedFiles) . ' subcontract document(s) generated for ' . $tradePackage->name . ' package.'
        );

        return response()->json([
            'message'          => "{$count} subcontract document" . ($count !== 1 ? 's' : '') . " generated successfully.",
            'generation_type'  => 'separate_documents',
            'generated_files'  => $generatedFiles,
            'resolved_count'   => $totalResolved,
            'unresolved_count' => $totalUnresolved,
            'errors'           => $errors,
        ], 201);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function processTemplate(string $templatePath, array $values, string $filename, string $storageDir): array
    {
        $storagePath  = "{$storageDir}/{$filename}";
        $fullDiskPath = Storage::disk('local')->path($storagePath);
        $resolvedList = [];

        try {
            // PHPWord handles placeholders split across multiple XML run elements
            // (e.g. underlined text in Word) via fixBrokenMacros() in its constructor.
            // We use Reflection to retrieve the processed XML without calling saveAs(),
            // which re-packages the ZIP and can corrupt complex DOCX files.
            $processor = new TemplateProcessor($templatePath);
            foreach ($values as $key => $value) {
                if ($value !== null && trim((string) $value) !== '') {
                    $processor->setValue($key, $value);
                    $resolvedList[] = $key;
                }
            }

            $ref     = new \ReflectionObject($processor);
            $getProp = static function (string $name) use ($processor, $ref): mixed {
                $prop = $ref->getProperty($name);
                $prop->setAccessible(true);
                return $prop->getValue($processor);
            };

            $mainXml  = (string) $getProp('tempDocumentMainPart');
            $headers  = (array)  $getProp('tempDocumentHeaders');
            $footers  = (array)  $getProp('tempDocumentFooters');
            $mainName = $processor->getMainPartName();

            if ($mainXml === '') {
                throw new \RuntimeException('PHPWord returned empty document XML — falling back.');
            }

            // Build a fresh DOCX by iterating every ZIP entry in the original
            // template and swapping in the PHPWord-processed XML parts.
            // Building from scratch (OVERWRITE) eliminates the ZipArchive
            // in-place-modification bug where addFromString() appends a second
            // entry instead of replacing the first, causing Word to read the
            // original (unprocessed) content.
            $replacements = [$mainName => $mainXml];
            foreach ($headers as $idx => $xml) {
                $replacements["word/header{$idx}.xml"] = (string) $xml;
            }
            foreach ($footers as $idx => $xml) {
                $replacements["word/footer{$idx}.xml"] = (string) $xml;
            }

            $this->rebuildDocx($templatePath, $fullDiskPath, $replacements);

        } catch (\Throwable) {
            // Fallback: read XML directly from the original template, apply
            // fixBrokenMacros, then rebuild the DOCX from scratch.
            $resolvedList = [];
            $this->rebuildDocxWithDirectReplacement($templatePath, $fullDiskPath, $values, $resolvedList);
        }

        // Scan for remaining unresolved placeholders.
        $unresolvedList = [];
        $zip = new ZipArchive();

        if ($zip->open($fullDiskPath) === true) {
            $parts = ['word/document.xml'];
            for ($i = 1; $i <= 10; $i++) {
                $parts[] = "word/header{$i}.xml";
                $parts[] = "word/footer{$i}.xml";
            }
            foreach ($parts as $part) {
                $xml = $zip->getFromName($part);
                if ($xml !== false && preg_match_all('/\$\{([^}]+)\}/', $xml, $m)) {
                    $unresolvedList = array_unique(array_merge($unresolvedList, $m[1]));
                }
            }
            $zip->close();
        }

        $unresolvedList = array_values(array_diff($unresolvedList, $resolvedList));

        return [$filename, $resolvedList, $unresolvedList];
    }

    /**
     * Rebuild a DOCX by copying every entry from the source template and
     * swapping in caller-supplied XML parts. Uses CREATE|OVERWRITE so the
     * output is a fresh ZIP — no duplicate-entry risk.
     *
     * @param array<string,string> $replacements  part-name → processed XML content
     */
    private function rebuildDocx(string $templatePath, string $outputPath, array $replacements): void
    {
        $src = new ZipArchive();
        if ($src->open($templatePath) !== true) {
            throw new \RuntimeException("Cannot open template ZIP: {$templatePath}");
        }

        $dst = new ZipArchive();
        if ($dst->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $src->close();
            throw new \RuntimeException("Cannot create output ZIP: {$outputPath}");
        }

        for ($i = 0; $i < $src->numFiles; $i++) {
            $name = $src->getNameIndex($i);
            $dst->addFromString(
                $name,
                isset($replacements[$name]) ? $replacements[$name] : $src->getFromIndex($i)
            );
        }

        $src->close();
        $dst->close();
    }

    /**
     * Fallback: rebuild the DOCX while applying fixBrokenMacros + str_replace
     * directly on the XML, without PHPWord. Handles split-run placeholders
     * using the same regex logic as PHPWord's fixBrokenMacros().
     */
    private function rebuildDocxWithDirectReplacement(
        string $templatePath,
        string $outputPath,
        array  $values,
        array  &$resolvedList
    ): void {
        $src = new ZipArchive();
        if ($src->open($templatePath) !== true) {
            copy($templatePath, $outputPath);
            return;
        }

        $dst = new ZipArchive();
        if ($dst->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $src->close();
            copy($templatePath, $outputPath);
            return;
        }

        $xmlParts = ['word/document.xml'];
        for ($i = 1; $i <= 10; $i++) {
            $xmlParts[] = "word/header{$i}.xml";
            $xmlParts[] = "word/footer{$i}.xml";
        }

        for ($i = 0; $i < $src->numFiles; $i++) {
            $name    = $src->getNameIndex($i);
            $content = $src->getFromIndex($i);

            if (in_array($name, $xmlParts, true)) {
                $content = $this->fixAndReplace((string) $content, $values, $resolvedList);
            }

            $dst->addFromString($name, $content);
        }

        $src->close();
        $dst->close();
        $resolvedList = array_unique($resolvedList);
    }

    /**
     * Apply PHPWord-style fixBrokenMacros then str_replace on an XML string.
     * Consolidates ${...} tokens split across XML run elements before replacing.
     */
    private function fixAndReplace(string $xml, array $values, array &$resolvedList): string
    {
        // Consolidate split placeholder runs: find $...} spans that contain
        // XML tags (run boundaries) and strip the tags to get ${variable_name}.
        $xml = preg_replace_callback(
            '/\$[^$]+\}/',
            static fn($m) => preg_replace('/<[^<>]+>/', '', $m[0]) ?? $m[0],
            $xml
        ) ?? $xml;

        foreach ($values as $key => $value) {
            if ($value !== null && trim((string) $value) !== '') {
                $needle = '${' . $key . '}';
                if (str_contains($xml, $needle)) {
                    $xml = str_replace(
                        $needle,
                        htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                        $xml
                    );
                    $resolvedList[] = $key;
                }
            }
        }

        return $xml;
    }

    private function createFileUpload($project, $user, $tradePackage, string $filename, string $storageDir, string $documentType): FileUpload
    {
        $storagePath  = "{$storageDir}/{$filename}";
        $fullDiskPath = Storage::disk('local')->path($storagePath);

        return FileUpload::create([
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'uploaded_by'      => $user->id,
            'original_name'    => $filename,
            'stored_name'      => $filename,
            'file_path'        => $storagePath,
            'mime_type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size'        => filesize($fullDiskPath),
            'folder_path'      => $storageDir,
            'module_key'       => 'subcontracts',
            'folder_key'       => 'subcontracts',
            'trade_package_id' => $tradePackage->id,
            'document_type'    => $documentType,
            'status'           => 'active',
            'disk'             => 'local',
        ]);
    }

    private function createDocument($project, $user, $tradePackage, $template, string $filename, string $storageDir, string $documentType, array $values): Document
    {
        $storagePath  = "{$storageDir}/{$filename}";
        $fullDiskPath = Storage::disk('local')->path($storagePath);

        return Document::create([
            'project_id'        => $project->id,
            'organization_id'   => $project->organization_id,
            'created_by'        => $user->id,
            'template_id'       => $template->id,
            'documentable_type' => TradePackage::class,
            'documentable_id'   => $tradePackage->id,
            'title'             => basename($filename, '.docx'),
            'type'              => $documentType,
            'category'          => 'subcontract',
            'reference_number'  => $tradePackage->package_reference,
            'status'            => 'draft',
            'file_path'         => $storagePath,
            'file_name'         => $filename,
            'mime_type'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size'         => filesize($fullDiskPath),
            'version'           => 1,
            'ai_generated'      => false,
            'template_data'     => $values,
        ]);
    }

    private function generatePdfPreview(FileUpload $upload, Document $document): void
    {
        try {
            $pdfPath = DocxToPdfService::generateAndStore($upload->file_path);
            $upload->update(['preview_pdf_path' => $pdfPath]);
            $document->update(['preview_pdf_path' => $pdfPath]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'PDF preview generation failed for ' . $upload->original_name . ': ' . $e->getMessage()
            );
        }
    }
}
