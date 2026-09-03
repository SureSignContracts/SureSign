<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated Friday Pack, R1B — Site Photographs & Evidence Selection.
 * One row per source photo explicitly selected for a specific Friday Pack
 * — the durable, independently-queryable Draft curation state that
 * `snapshot_json` (a wholesale-overwritten blob, see
 * FridayPackSnapshotService) cannot safely provide on its own. See
 * project-context.md's R1B entry for the full schema-approval writeup.
 *
 * `file_upload_id` is NON-NULL with `restrictOnDelete()` — approved
 * product rule: once a source photo is selected, it cannot be deleted
 * until deselected first (Draft only) or the pack itself is deleted. The
 * DB constraint is defense-in-depth; the real UX guard is the targeted
 * 409 check in RecordAttachmentService::delete()/DocumentController::
 * destroyFile() (see FridayPackPhotoProtectionGuard).
 *
 * `source_type`/`source_id`/`source_date`/`original_file_name` are
 * FROZEN at selection time — copied from the resolved FileUpload/source
 * record, never re-derived live afterwards, so this row's own historical
 * evidence contract survives a later edit to the source record and
 * (source_type/source_id/source_date/original_file_name specifically)
 * would still be meaningful even if a future phase ever needs to reason
 * about the selection after the FileUpload itself is gone by some other
 * path than the one this restrictive FK already prevents.
 *
 * `project_id`/`organization_id` are denormalized, matching every other
 * Project-owned table in this codebase (FileUpload, ContractRisk,
 * DelayEvent, etc.) — direct tenant scoping, not a join through
 * friday_pack_id, per CLAUDE.md's P0 Security Remediation precedent.
 *
 * R1B.3 fix (2026-08-31): the unique constraint below is given an
 * explicit short name (`fpps_pack_file_unique`) — Laravel's default
 * auto-generated name for it
 * (`friday_pack_photo_selections_friday_pack_id_file_upload_id_unique`)
 * is 65 characters, one over MySQL/MariaDB's 64-character identifier
 * limit, and was proven (via `Schema::create()`'s compiled statement
 * list on the `mysql` connection) to fail as its own separate `ALTER
 * TABLE ... ADD UNIQUE` statement — AFTER the table itself and all five
 * foreign keys have already been created as their own separate
 * statements. This is an edit to a migration that has never been
 * committed, released, or reached production — see project-context.md's
 * R1B.3 entry for the full investigation. The composite index
 * (`fpps_pack_sort_index`) is also given an explicit short name for
 * consistency/future safety, even though its auto-generated name
 * (60 characters) was already within the limit. All five foreign-key
 * constraint names remain Laravel's defaults (51/47/52/51/48
 * characters) — none exceed the limit, so none were renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_pack_photo_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('friday_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('file_upload_id')->constrained()->restrictOnDelete();

            // Frozen source identity — App\Support\FridayPack\FridayPackPhotoSourceType's
            // stable values ('site_report'/'toolbox_talk'), never a raw FQCN.
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->date('source_date');
            $table->string('original_file_name')->nullable();

            $table->string('caption')->nullable();
            $table->string('location')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('selected_by')->constrained('users');

            $table->timestamps();

            $table->unique(['friday_pack_id', 'file_upload_id'], 'fpps_pack_file_unique');
            $table->index(['friday_pack_id', 'sort_order'], 'fpps_pack_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_pack_photo_selections');
    }
};
