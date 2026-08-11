<?php

namespace App\Services\Documents;

use App\Models\FileUpload;
use App\Models\Project;
use App\Models\User;
use App\Services\FileSecurityService;
use App\Services\ProjectActivityService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Evidence Attachment Foundation (Phase 0) — the single place a Snag, Rfi,
 * or QaReport attaches evidence, shared by their three controllers rather
 * than tripling the same ~60 lines. Deliberately reuses the exact storage
 * convention `DocumentController::uploadFile()` already established
 * (`projects/{id}/{folder}/{storedName}`, `FileSecurityService`,
 * `SuresignSetting::maxUploadKb()`) — never a second upload pipeline.
 *
 * The polymorphic link (`attachable_type`/`attachable_id`) is the sole
 * authoritative record ownership check; `module_key`/`folder_key` remain
 * useful metadata for the general Documents Explorer (which already groups
 * by `module_key` with no `attachable_type` filtering — see
 * DocumentController::projectExplorer()) but are never relied on for
 * ownership. Preview/download reuse the existing generic
 * `DocumentController::previewFile()`/`downloadFile()` routes unchanged —
 * this service only ever creates/lists/deletes.
 */
class RecordAttachmentService
{
    /**
     * @param  Model  $record  A Snag, Rfi, or QaReport instance.
     */
    public function list(Model $record)
    {
        return $record->fileUploads()->with('uploader:id,name')->latest()->get();
    }

    /**
     * @param  Model  $record     A Snag, Rfi, or QaReport instance — already
     *                            authorized by the caller.
     * @param  string  $moduleKey e.g. 'snagging', 'rfis', 'qa' — used only
     *                            for Documents Explorer grouping, never for
     *                            ownership.
     * @param  string  $recordLabel e.g. "Snag #42" — for activity logging only.
     */
    public function upload(
        Request $request,
        Project $project,
        Model $record,
        User $user,
        string $moduleKey,
        string $recordLabel,
        string $activityType,
    ): FileUpload {
        $request->validate([
            'file' => 'required|file|max:' . \App\Models\SuresignSetting::maxUploadKb(),
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);

        $storedName = FileSecurityService::randomStorageName($file);
        $folderKey  = $moduleKey . '/evidence';
        $path       = "projects/{$project->id}/{$folderKey}/{$storedName}";

        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        $upload = FileUpload::create([
            'project_id'       => $project->id,
            'organization_id'  => $project->organization_id,
            'uploaded_by'      => $user->id,
            'attachable_type'  => $record::class,
            'attachable_id'    => $record->id,
            'trade_package_id' => $record->trade_package_id ?? null,
            'original_name'    => FileSecurityService::sanitizeDisplayName($file->getClientOriginalName()),
            'stored_name'      => $storedName,
            'file_path'        => $path,
            'mime_type'        => $file->getMimeType(),
            'file_size'        => $file->getSize(),
            'folder_path'      => $folderKey,
            'module_key'       => $moduleKey,
            'folder_key'       => $folderKey,
            'source_type'      => 'uploaded',
            'disk'             => 'local',
        ]);

        ProjectActivityService::record(
            $project,
            $user,
            $activityType,
            "Evidence uploaded to {$recordLabel}: {$upload->original_name}",
            null,
            $record,
        );

        return $upload->load('uploader:id,name');
    }

    /**
     * Deletes only when `$fileUpload` genuinely belongs to `$record` (and
     * therefore, transitively, to `$project`) — the exact nested-resource
     * mismatch the spec calls out (e.g. project 5 / snag 42 / attachment 99
     * where 99 actually belongs to a different snag, project, or org).
     * Throws (via `abort`) rather than silently no-op-ing on a mismatch, so
     * a caller can never mistake "nothing happened" for "deleted".
     */
    public function delete(FileUpload $fileUpload, Model $record, Project $project, User $user, string $recordLabel, string $activityType): void
    {
        if (
            $fileUpload->attachable_type !== $record::class
            || (int) $fileUpload->attachable_id !== (int) $record->id
            || (int) $fileUpload->project_id !== (int) $project->id
        ) {
            abort(404, 'Attachment not found.');
        }

        $fileName = $fileUpload->original_name;

        // Matches DocumentController::destroyFile()'s exact convention:
        // FileUpload has no SoftDeletes trait, so this removes the database
        // row, but the physical file on disk is deliberately never touched
        // here (no Storage::delete() call) — same "row goes, bytes stay"
        // behaviour as every other FileUpload deletion in the app today.
        $fileUpload->delete();

        ProjectActivityService::record(
            $project,
            $user,
            $activityType,
            "Evidence removed from {$recordLabel}: {$fileName}",
            null,
            $record,
        );
    }
}
