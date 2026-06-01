<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Flatten trade package document metadata.
 *
 * For existing file_uploads that have a trade_package_folder_key,
 * map that key to the equivalent document_type value and clear
 * trade_package_folder_key (set to null).
 *
 * This is a data migration only — no schema changes.
 *
 * Mapping:
 *   tender_enquiry        → tender_enquiry_letter
 *   schedule_of_documents → schedule_of_documents
 *   drawings              → drawings
 *   specifications        → specification
 *   pricing_documents     → pricing_document
 *   contract_draft        → subcontract_draft
 *   correspondence        → correspondence
 *   returned_tender       → returned_tender
 *   executed_contract     → executed_contract
 */
return new class extends Migration
{
    private array $map = [
        'tender_enquiry'        => 'tender_enquiry_letter',
        'schedule_of_documents' => 'schedule_of_documents',
        'drawings'              => 'drawings',
        'specifications'        => 'specification',
        'pricing_documents'     => 'pricing_document',
        'contract_draft'        => 'subcontract_draft',
        'correspondence'        => 'correspondence',
        'returned_tender'       => 'returned_tender',
        'executed_contract'     => 'executed_contract',
    ];

    public function up(): void
    {
        foreach ($this->map as $folderKey => $documentType) {
            DB::table('file_uploads')
                ->where('trade_package_folder_key', $folderKey)
                ->whereNull('document_type')
                ->update(['document_type' => $documentType]);
        }
        // Also set document_type for any records that already had it set
        // but still have a folder key — keep document_type, just clear the folder key
        // so there's no ambiguity. We intentionally do NOT wipe trade_package_folder_key
        // for backward compatibility (nullable is fine).
    }

    public function down(): void
    {
        // Reverse: restore folder keys from document_type where we set them
        $reverse = array_flip($this->map);
        foreach ($reverse as $documentType => $folderKey) {
            DB::table('file_uploads')
                ->where('document_type', $documentType)
                ->whereNotNull('trade_package_id')
                ->update(['trade_package_folder_key' => $folderKey]);
        }
    }
};
