<?php

namespace App\Services\FridayPack;

use App\Models\FileUpload;
use App\Models\FridayPackPhotoSelection;
use App\Models\Project;
use App\Models\SiteDiary;
use App\Models\ToolboxTalk;
use App\Support\FridayPack\FridayPackPhotoSourceType;
use Illuminate\Support\Collection;

/**
 * Automated Friday Pack, R1B — the ONE authoritative service that
 * discovers ELIGIBLE Site Photograph candidates for a reporting period.
 * Read-only: never persists anything, never selects anything. Selection
 * is an entirely separate, explicit action — see
 * App\Services\FridayPack\FridayPackPhotoSelectionService.
 *
 * Source priority (approved, V1): Site Report (SiteDiary) image
 * attachments, then Toolbox Talk image attachments — reusing the exact
 * same generic FileUpload/RecordAttachmentService infrastructure both
 * already have (SiteDiary's own attachment support is a R1B small
 * extension — see SiteDiary::fileUploads()). No other FileUpload source
 * is searched; Friday Pack photo discovery is deliberately NOT "every
 * project Document."
 *
 * Candidate dates come from the SOURCE RECORD's own reporting/event date
 * (SiteDiary::diary_date / ToolboxTalk::talk_date) — never
 * FileUpload::created_at, since an attachment may be uploaded later than
 * the event it evidences.
 */
class FridayPackPhotoDiscoveryService
{
    /**
     * @return array<int, array{
     *   source_type: string, source_id: int, source_date: string,
     *   file_upload_id: int, file_name: string, mime_type: string,
     *   preview_url: string, default_caption: ?string, default_location: ?string,
     *   already_selected: bool
     * }>
     */
    public function discover(Project $project, array $period): array
    {
        $start = $period['period_start'];
        $end   = $period['period_end'];

        $selectedFileUploadIds = $this->alreadySelectedFileUploadIds($project);

        $siteReportCandidates = $this->fromSiteDiaries($project, $start, $end, $selectedFileUploadIds);
        $toolboxTalkCandidates = $this->fromToolboxTalks($project, $start, $end, $selectedFileUploadIds);

        return $siteReportCandidates->concat($toolboxTalkCandidates)->values()->all();
    }

    /**
     * A FileUpload counted as "already selected" — used so a candidate
     * list can flag `already_selected` without the caller needing a
     * second query. Deliberately scoped project-wide (not just the
     * current pack) since the same source photo could theoretically be
     * selected by a different Friday Pack for an overlapping/adjacent
     * week — R1B does not attempt to prevent that, only same-pack
     * duplicates (see the table's own UNIQUE(friday_pack_id,
     * file_upload_id) constraint).
     */
    private function alreadySelectedFileUploadIds(Project $project): Collection
    {
        return FridayPackPhotoSelection::where('project_id', $project->id)->pluck('file_upload_id');
    }

    /**
     * Mirrors FridayPackSnapshotService::dateRange()'s exact reasoning —
     * `date`-cast columns are written back using the connection's full
     * datetime format, which a bare [$start, $end] pair mismatches on
     * SQLite's plain-TEXT date columns (masked on MySQL's real DATE type
     * only by coincidence).
     */
    private function dateRange(string $start, string $end): array
    {
        return ["{$start} 00:00:00", "{$end} 23:59:59"];
    }

    private function fromSiteDiaries(Project $project, string $start, string $end, Collection $selectedIds): Collection
    {
        $diaries = SiteDiary::where('project_id', $project->id)
            ->whereBetween('diary_date', $this->dateRange($start, $end))
            ->with(['fileUploads' => fn ($q) => $q->where('mime_type', 'like', 'image/%')])
            ->get();

        return $diaries->flatMap(function (SiteDiary $diary) use ($selectedIds) {
            return $diary->fileUploads->map(fn (FileUpload $upload) => $this->shapeCandidate(
                FridayPackPhotoSourceType::SITE_REPORT,
                $diary->id,
                $diary->diary_date->toDateString(),
                $upload,
                $selectedIds,
            ));
        });
    }

    private function fromToolboxTalks(Project $project, string $start, string $end, Collection $selectedIds): Collection
    {
        $talks = ToolboxTalk::where('project_id', $project->id)
            ->whereBetween('talk_date', $this->dateRange($start, $end))
            ->with(['fileUploads' => fn ($q) => $q->where('mime_type', 'like', 'image/%')])
            ->get();

        return $talks->flatMap(function (ToolboxTalk $talk) use ($selectedIds) {
            return $talk->fileUploads->map(fn (FileUpload $upload) => $this->shapeCandidate(
                FridayPackPhotoSourceType::TOOLBOX_TALK,
                $talk->id,
                optional($talk->talk_date)->toDateString(),
                $upload,
                $selectedIds,
            ));
        });
    }

    /**
     * Deliberately never exposes file_path/disk/attachable_type FQCN —
     * only a stable source_type, a preview URL (the existing authenticated
     * /file-uploads/{id}/preview route), and display-safe fields.
     * default_caption/default_location are null today — neither SiteDiary
     * nor ToolboxTalk has a per-photo caption/location field; never
     * fabricated.
     */
    private function shapeCandidate(string $sourceType, int $sourceId, ?string $sourceDate, FileUpload $upload, Collection $selectedIds): array
    {
        return [
            'source_type'       => $sourceType,
            'source_id'         => $sourceId,
            'source_date'       => $sourceDate,
            'file_upload_id'    => $upload->id,
            'file_name'         => $upload->original_name,
            'mime_type'         => $upload->mime_type,
            'preview_url'       => "/api/file-uploads/{$upload->id}/preview",
            'default_caption'   => null,
            'default_location'  => null,
            'already_selected'  => $selectedIds->contains($upload->id),
        ];
    }
}
