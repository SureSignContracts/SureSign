<?php

namespace App\Support\FridayPack;

use App\Models\FileUpload;
use App\Models\FridayPackPhotoSelection;

/**
 * Automated Friday Pack, R1B — targeted FileUpload deletion guard,
 * mirrors V1F's exact FridayPackDelivery-referenced-Document guard shape
 * (see DocumentController::destroy()). Called from BOTH existing
 * FileUpload deletion paths — RecordAttachmentService::delete() and
 * DocumentController::destroyFile() — never a broader FileUpload
 * immutability rule.
 *
 * Product rule (approved, R1B): once a FileUpload is selected for ANY
 * Friday Pack, in ANY status, it cannot be deleted until explicitly
 * deselected first (Draft only — see FridayPackPhotoSelectionService).
 * This is enforced at the database layer too (`file_upload_id` is
 * `restrictOnDelete()`) — this guard exists purely to turn that into a
 * clear, controlled 409 instead of a raw DB constraint-violation error.
 */
final class FridayPackPhotoProtectionGuard
{
    public static function assertDeletable(FileUpload $fileUpload): void
    {
        if (FridayPackPhotoSelection::where('file_upload_id', $fileUpload->id)->exists()) {
            abort(409, 'This attachment is currently included in a Friday Pack. Remove it from the Draft Friday Pack before deleting the attachment.');
        }
    }
}
