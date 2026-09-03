<?php

namespace App\Services\FridayPack;

use App\Models\FileUpload;
use App\Models\FridayPack;
use App\Models\FridayPackPhotoSelection;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectActivityService;
use App\Support\FridayPack\FridayPackPhotoSelectionPresenter;
use App\Support\FridayPack\FridayPackPhotoSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Automated Friday Pack, R1B — the ONE authoritative service for every
 * mutation of a FridayPack's photo curation
 * (App\Models\FridayPackPhotoSelection). No controller writes to that
 * table directly. Mirrors FridayPackGenerationService/
 * FridayPackLifecycleService's own "must be draft" + transaction shape.
 *
 * Every mutation here also refreshes the pack's OWN `snapshot_json.sections.
 * site_photographs` (via FridayPackPhotoSelectionPresenter, the same
 * shaping FridayPackSnapshotService's full collector uses) and clears
 * `pdf_document_id` if one exists — photo curation is report-visible
 * Draft content, exactly like editing commentary (V1C's existing
 * invalidation rule). This is a small, targeted merge into the already-
 * persisted JSON, never a full re-collection of every other section —
 * deliberately NOT routed through FridayPackGenerationService::generate(),
 * which would also touch generated_at/generated_by/generation_source and
 * record a misleading "regenerated" activity entry for what is really a
 * curation edit.
 */
class FridayPackPhotoSelectionService
{
    public function __construct(private FridayPackPhotoDiscoveryService $discovery) {}

    /**
     * @throws RuntimeException when the pack isn't draft, the selection
     *   already exists, or the resolved evidence fails validation.
     */
    public function select(FridayPack $pack, Project $project, User $actor, int $fileUploadId, ?string $caption, ?string $location): FridayPackPhotoSelection
    {
        $this->assertDraft($pack);

        return DB::transaction(function () use ($pack, $project, $actor, $fileUploadId, $caption, $location) {
            $upload = $this->resolveEligibleFileUpload($project, $fileUploadId);

            $sourceType = FridayPackPhotoSourceType::forAttachableClass($upload->attachable_type);
            $sourceRecord = $upload->attachable;
            if (!$sourceRecord || (int) $sourceRecord->project_id !== (int) $project->id) {
                abort(422, 'Evidence source does not belong to this project.');
            }

            $sourceDate = match ($sourceType) {
                FridayPackPhotoSourceType::SITE_REPORT  => $sourceRecord->diary_date,
                FridayPackPhotoSourceType::TOOLBOX_TALK => $sourceRecord->talk_date,
            };

            $nextOrder = (int) ($pack->photoSelections()->max('sort_order') ?? 0) + 1;

            try {
                $selection = FridayPackPhotoSelection::create([
                    'friday_pack_id'     => $pack->id,
                    'project_id'         => $project->id,
                    'organization_id'    => $project->organization_id,
                    'file_upload_id'     => $upload->id,
                    'source_type'        => $sourceType,
                    'source_id'          => $sourceRecord->id,
                    'source_date'        => $sourceDate,
                    'original_file_name' => $upload->original_name,
                    'caption'            => $caption,
                    'location'           => $location,
                    'sort_order'         => $nextOrder,
                    'selected_by'        => $actor->id,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($this->isUniqueConstraintViolation($e)) {
                    throw new RuntimeException('This photo has already been selected for this Friday Pack.');
                }
                throw $e;
            }

            $this->refreshSnapshotAndInvalidatePdf($pack);
            ProjectActivityService::record($project, $actor, 'friday_pack_photo_selected', 'Photo selected for Friday Pack week ending ' . $pack->week_ending->toDateString(), null, $pack);

            return $selection->fresh();
        });
    }

    public function deselect(FridayPack $pack, Project $project, User $actor, FridayPackPhotoSelection $selection): void
    {
        $this->assertDraft($pack);
        $this->assertBelongsToPack($pack, $selection);

        DB::transaction(function () use ($pack, $project, $actor, $selection) {
            $selection->delete();
            $this->refreshSnapshotAndInvalidatePdf($pack);
            ProjectActivityService::record($project, $actor, 'friday_pack_photo_removed', 'Photo removed from Friday Pack week ending ' . $pack->week_ending->toDateString(), null, $pack);
        });
    }

    public function updateMetadata(FridayPack $pack, Project $project, User $actor, FridayPackPhotoSelection $selection, ?string $caption, ?string $location): FridayPackPhotoSelection
    {
        $this->assertDraft($pack);
        $this->assertBelongsToPack($pack, $selection);

        return DB::transaction(function () use ($pack, $project, $actor, $selection, $caption, $location) {
            // Caption/location are Friday-Pack-specific — this update
            // never touches FileUpload/SiteDiary/ToolboxTalk.
            $selection->update(['caption' => $caption, 'location' => $location]);
            $this->refreshSnapshotAndInvalidatePdf($pack);
            ProjectActivityService::record($project, $actor, 'friday_pack_photo_metadata_updated', 'Photo caption/location updated for Friday Pack week ending ' . $pack->week_ending->toDateString(), null, $pack);

            return $selection->fresh();
        });
    }

    /**
     * @param  int[]  $orderedSelectionIds  Every id must belong to this
     *   pack — validated up front, never trusting the caller's list
     *   beyond that. A partial list (fewer than the pack's total
     *   selection count) is rejected rather than silently reordering only
     *   some rows.
     */
    public function reorder(FridayPack $pack, Project $project, User $actor, array $orderedSelectionIds): void
    {
        $this->assertDraft($pack);

        DB::transaction(function () use ($pack, $project, $actor, $orderedSelectionIds) {
            $current = $pack->photoSelections()->pluck('id');

            if ($current->count() !== count($orderedSelectionIds)
                || $current->diff($orderedSelectionIds)->isNotEmpty()
                || collect($orderedSelectionIds)->diff($current)->isNotEmpty()
            ) {
                throw new RuntimeException('The supplied photo order does not match this Friday Pack\'s current selections.');
            }

            foreach (array_values($orderedSelectionIds) as $index => $selectionId) {
                FridayPackPhotoSelection::where('id', $selectionId)
                    ->where('friday_pack_id', $pack->id)
                    ->update(['sort_order' => $index + 1]);
            }

            $this->refreshSnapshotAndInvalidatePdf($pack);
            ProjectActivityService::record($project, $actor, 'friday_pack_photo_order_changed', 'Photo order changed for Friday Pack week ending ' . $pack->week_ending->toDateString(), null, $pack);
        });
    }

    /**
     * R1B lifecycle preflight — called by
     * FridayPackLifecycleService::submitForReview() BEFORE the existing
     * draft -> ready_for_review transition. Proves every currently
     * selected photo still resolves to a real, tenant-valid FileUpload
     * whose physical file genuinely exists on disk — never deferred to
     * PDF generation time.
     *
     * @throws RuntimeException naming the first invalid selection found.
     */
    public function assertAllSelectionsValid(FridayPack $pack): void
    {
        foreach ($pack->photoSelections()->with('fileUpload')->get() as $selection) {
            $upload = $selection->fileUpload;

            if (!$upload
                || (int) $upload->project_id !== (int) $pack->project_id
                || (int) $upload->organization_id !== (int) $pack->organization_id
            ) {
                throw new RuntimeException('One or more selected photographs are no longer valid and must be removed before this Friday Pack can be submitted for review.');
            }

            if (!Storage::disk($upload->disk ?? 'local')->exists($upload->file_path)) {
                throw new RuntimeException('One or more selected photographs are missing their underlying file and must be removed before this Friday Pack can be submitted for review.');
            }
        }
    }

    /**
     * Post-Deploy Photo Hardening, P1 — the one place a Friday Pack
     * photo-source record's own deletion is guarded. `FileUpload` uses a
     * polymorphic `attachable` relation with no real database foreign
     * key, so a SiteDiary/ToolboxTalk row could previously be deleted
     * outright even while one of its attachments was currently selected
     * as Friday Pack evidence — the attachment itself and the
     * FridayPackPhotoSelection row would both survive (nothing breaks
     * the PDF), but the record the evidence's provenance actually points
     * back to would silently disappear.
     *
     * Called by SiteDiaryController::destroy()/ToolboxTalkController::
     * destroy() — the only two current Friday Pack photo-source
     * controllers — immediately after their own project/tenant
     * authorization and before the real delete. Deliberately NOT scoped
     * to any particular pack lifecycle status (Draft/Ready for Review/
     * Approved/Sent) — a selection frozen into an already-Approved or
     * Sent pack's snapshot must keep its real provenance just as much as
     * a Draft's does; this checks for the EXISTENCE of any referencing
     * FridayPackPhotoSelection row, regardless of which FridayPack or
     * status it belongs to.
     *
     * @param  Model  $sourceRecord  A SiteDiary or ToolboxTalk instance —
     *   any model exposing the same `fileUploads()` morphMany relation
     *   every Friday Pack photo source already has.
     */
    public function assertSourceRecordCanBeDeleted(Model $sourceRecord): void
    {
        $fileUploadIds = $sourceRecord->fileUploads()->pluck('id');

        if ($fileUploadIds->isEmpty()) {
            return;
        }

        if (FridayPackPhotoSelection::whereIn('file_upload_id', $fileUploadIds)->exists()) {
            abort(409, 'This record contains evidence currently selected in a Friday Pack. Remove the selected evidence from the Friday Pack before deleting this record.');
        }
    }

    private function resolveEligibleFileUpload(Project $project, int $fileUploadId): FileUpload
    {
        $upload = FileUpload::where('id', $fileUploadId)
            ->where('project_id', $project->id)
            ->where('organization_id', $project->organization_id)
            ->first();

        if (!$upload) {
            abort(422, 'The selected attachment is invalid.');
        }

        if (!$upload->mime_type || !str_starts_with($upload->mime_type, 'image/')) {
            abort(422, 'Only image attachments can be selected as Site Photographs.');
        }

        try {
            FridayPackPhotoSourceType::forAttachableClass($upload->attachable_type ?? '');
        } catch (InvalidArgumentException) {
            abort(422, 'The selected attachment is not an approved Friday Pack photo evidence source.');
        }

        // Important Change 3 — evidence integrity at selection time:
        // reject broken evidence outright rather than letting it enter
        // curation.
        if (!Storage::disk($upload->disk ?? 'local')->exists($upload->file_path)) {
            abort(422, 'This photo\'s underlying file could not be found and cannot be selected.');
        }

        return $upload;
    }

    private function assertDraft(FridayPack $pack): void
    {
        if ($pack->status !== 'draft') {
            throw new RuntimeException('Photo selection can only be changed while this Friday Pack is a Draft.');
        }
    }

    private function assertBelongsToPack(FridayPack $pack, FridayPackPhotoSelection $selection): void
    {
        if ((int) $selection->friday_pack_id !== (int) $pack->id) {
            abort(404, 'Photo selection not found for this Friday Pack.');
        }
    }

    private function refreshSnapshotAndInvalidatePdf(FridayPack $pack): void
    {
        $snapshot = $pack->snapshot_json ?? [];
        $snapshot['sections'] = array_merge($snapshot['sections'] ?? [], [
            'site_photographs' => FridayPackPhotoSelectionPresenter::sectionFor($pack),
        ]);

        $pack->forceFill([
            'snapshot_json'   => $snapshot,
            // V1C stale-PDF rule: photo curation is report-visible Draft
            // content — clears the current PDF pointer exactly like any
            // other content edit. Never applies if no PDF exists yet.
            'pdf_document_id' => null,
        ])->save();
    }

    private function isUniqueConstraintViolation(\Illuminate\Database\QueryException $e): bool
    {
        return in_array((int) $e->getCode(), [23000, 23505], true);
    }
}
