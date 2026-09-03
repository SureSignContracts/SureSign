<?php

namespace App\Services;

use App\Models\DocumentNumberSequence;
use App\Models\DocumentRegister;
use App\Models\Project;
use App\Models\TradePackage;
use Illuminate\Support\Facades\DB;

class DocumentNumberService
{
    // Document type codes — configurable defaults
    public const TYPES = [
        'TEL' => 'Tender Enquiry Letter',
        'SOD' => 'Schedule Of Documents',
        'PSM' => 'Procurement Summary',
        'CON' => 'Contract / Subcontract',
        'RFI' => 'Request For Information',
        'VAR' => 'Variation',
        'NOT' => 'Notice',
        'PAY' => 'Payment Application',
        'MIN' => 'Meeting Minutes',
        'SRP' => 'Site Report',
        'QAR' => 'QA Report',
        'SNG' => 'Snagging',
        'CLS' => 'Closeout',
        'ADJ' => 'Adjudication',
        'FPK' => 'Friday Pack',
    ];

    /**
     * Generate the next document number atomically.
     * Format: {PROJECT_REF}-{PACKAGE_CODE}-{DOC_TYPE}-{SEQ}
     * Example: SP-COL-001-RF-RFI-015
     *
     * @throws \InvalidArgumentException
     */
    public function generate(
        Project       $project,
        string        $documentType,
        ?TradePackage $package = null,
        string        $title   = '',
        ?int          $fileUploadId = null
    ): string {
        $documentType = strtoupper(trim($documentType));

        if (!array_key_exists($documentType, self::TYPES)) {
            throw new \InvalidArgumentException("Unknown document type: {$documentType}");
        }

        return DB::transaction(function () use ($project, $package, $documentType, $title, $fileUploadId) {
            // Lock the sequence row for this project/package/type
            $seq = DocumentNumberSequence::lockForUpdate()->firstOrCreate(
                [
                    'project_id'    => $project->id,
                    'package_id'    => $package?->id,
                    'document_type' => $documentType,
                ],
                ['current_sequence' => 0]
            );

            $seq->increment('current_sequence');
            $seq->refresh();

            $number = $this->format($project, $package, $documentType, $seq->current_sequence);

            // Write to document register
            DocumentRegister::create([
                'document_number' => $number,
                'title'           => $title ?: "{$documentType} Document",
                'document_type'   => $documentType,
                'project_id'      => $project->id,
                'package_id'      => $package?->id,
                'file_upload_id'  => $fileUploadId,
            ]);

            return $number;
        });
    }

    /**
     * Build the document number string without incrementing (preview/format only).
     */
    public function format(
        Project       $project,
        ?TradePackage $package,
        string        $documentType,
        int           $sequence
    ): string {
        $parts = [$project->code];

        if ($package) {
            $parts[] = strtoupper($package->code ?? $package->package_code ?? 'PKG');
        }

        $parts[] = strtoupper($documentType);
        $parts[] = str_pad($sequence, 3, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    /**
     * Get the current sequence for a project/package/type without incrementing.
     */
    public function peek(Project $project, string $documentType, ?TradePackage $package = null): int
    {
        return DocumentNumberSequence::where('project_id', $project->id)
            ->where('package_id', $package?->id)
            ->where('document_type', $documentType)
            ->value('current_sequence') ?? 0;
    }

    /**
     * Friday Pack Realignment, R1D — allocates the next Friday Pack report
     * number for a project. Reuses the exact same `document_number_sequences`
     * row-locking primitive `generate()` uses above (same transaction/lock
     * semantics, `document_type = 'FPK'`, `package_id = null`) — a
     * genuinely new sibling method rather than a duplicated lock, because
     * unlike `generate()` this deliberately does NOT write a
     * `DocumentRegister` row (a Friday Pack is not a project document in
     * that sense) and does NOT compose the project/package-prefixed
     * composite string `format()` produces — the business Friday Pack
     * reference is a plain zero-padded sequence ("001"), not
     * "SP-COL-001-FPK-001".
     *
     * The caller (App\Services\FridayPack\FridayPackGenerationService) is
     * responsible for calling this exactly once, at first-time creation
     * only — never on regeneration. This method itself has no "already
     * allocated" concept; it always allocates the next sequence value,
     * exactly like `generate()`/`peek()` do for every other document type.
     */
    public function allocateFridayPackSequence(Project $project): string
    {
        return DB::transaction(function () use ($project) {
            $seq = DocumentNumberSequence::lockForUpdate()->firstOrCreate(
                [
                    'project_id'    => $project->id,
                    'package_id'    => null,
                    'document_type' => 'FPK',
                ],
                ['current_sequence' => 0]
            );

            $seq->increment('current_sequence');
            $seq->refresh();

            return str_pad((string) $seq->current_sequence, 3, '0', STR_PAD_LEFT);
        });
    }
}
