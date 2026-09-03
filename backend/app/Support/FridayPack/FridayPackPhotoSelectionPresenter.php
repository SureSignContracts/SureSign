<?php

namespace App\Support\FridayPack;

use App\Models\FridayPack;

/**
 * Automated Friday Pack, R1B — the ONE place a FridayPack's persisted
 * `photoSelections()` are shaped into the `site_photographs` snapshot
 * section. Shared by both `FridayPackSnapshotService::collectSection()`
 * (full generation/regeneration) and
 * `FridayPackPhotoSelectionService` (incremental refresh after a single
 * curation action) — both must always produce byte-identical shaping,
 * never two slightly different implementations.
 *
 * Never includes `file_path`/`disk`/any private storage detail — only
 * `file_upload_id` (so the frontend can build a preview URL) plus the
 * frozen evidence fields already on the selection row itself.
 */
final class FridayPackPhotoSelectionPresenter
{
    public static function sectionFor(FridayPack $pack): array
    {
        $selections = $pack->photoSelections()->with('fileUpload:id,mime_type')->get();

        return [
            'count' => $selections->count(),
            'items' => $selections->map(fn ($selection) => [
                'source_type'        => $selection->source_type,
                'source_id'          => $selection->source_id,
                'source_date'        => optional($selection->source_date)->toDateString(),
                'file_upload_id'     => $selection->file_upload_id,
                'original_file_name' => $selection->original_file_name,
                'mime_type'          => $selection->fileUpload?->mime_type,
                'caption'            => $selection->caption,
                'location'           => $selection->location,
                'order'              => $selection->sort_order,
            ])->values()->all(),
        ];
    }
}
