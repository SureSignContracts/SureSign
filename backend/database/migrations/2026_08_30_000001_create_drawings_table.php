<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // The single authoritative stored file — Document/FileUpload
            // remain the file source, this table only layers structured
            // drawing metadata on top. Mandatory (a Drawing always starts
            // from an existing, already-uploaded Project Document) — unlike
            // DeliveryDocument's nullable document_id, which supports a
            // requirement existing before a file is attached.
            //
            // restrictOnDelete() rather than DeliveryDocument's nullOnDelete():
            // Document is only ever soft-deleted by the application
            // (DocumentController::destroy() never calls forceDelete() —
            // confirmed repository-wide), so this FK action is inert for the
            // normal soft-delete flow (an UPDATE, not a DELETE) and only
            // matters for a genuine hard DB delete — restricting that is the
            // safer choice given document_id is NOT NULL here.
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();

            $table->string('drawing_number', 100);
            $table->string('title', 255);

            // Flexible string metadata, deliberately not a DB enum — see
            // Drawing Phase 1 spec Parts H/I. Frontend owns the controlled
            // option list; backend stays structurally extensible.
            $table->string('discipline', 100)->nullable();
            $table->string('status', 100)->nullable();

            // Free text bridging until a real ProjectLocation hierarchy is
            // ever justified — see Drawing Phase 1 spec Part J.
            $table->string('location_reference', 255)->nullable();

            $table->foreignId('created_by')->constrained('users');

            $table->softDeletes();
            $table->timestamps();

            // Deliberately NOT a unique index on (project_id, document_id):
            // MySQL/MariaDB has no partial/filtered unique index, so a plain
            // composite unique here would also apply to soft-deleted rows
            // and could block re-registering the same Document after a
            // Drawing is removed (the exact latent flaw already present in
            // trade_packages' own unique(['project_id','slug']) index).
            // Active-registration uniqueness is enforced at the application
            // level instead (DrawingController, transaction + lockForUpdate)
            // — this is a plain lookup/performance index only.
            $table->index(['project_id', 'document_id']);
            $table->index(['project_id', 'discipline']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawings');
    }
};
