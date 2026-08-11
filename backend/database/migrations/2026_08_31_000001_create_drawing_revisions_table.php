<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawing_id')->constrained()->cascadeOnDelete();

            // Mirrors Drawing.document_id's own reasoning (Phase 1A) —
            // mandatory, restrictOnDelete since Document is only ever
            // soft-deleted by application code (never hard-deleted), so
            // this FK action only matters as DB-integrity insurance.
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();

            // Worldwide/free-form (Phase 4 Part D) — nullable only for the
            // migrated-legacy/unrecorded case (Part F); a user-entered
            // revision always requires one at the application level.
            $table->string('revision_code', 100)->nullable();

            // Descriptive issue-purpose status only — deliberately never
            // auto-set to "Superseded" by application code when a newer
            // revision becomes current. current_revision_id on `drawings`
            // is the sole source of truth for current/non-current; this
            // column must never be overloaded to encode that a second time
            // (see Drawing::effectiveDocument()'s docblock).
            $table->string('status', 100)->nullable();

            // A business calendar date (when the revision was issued), not
            // an instant — same convention as due_date/meeting_date
            // elsewhere in this codebase (see AGENTS.md's Timezone &
            // Scheduling Architecture). Named issued_date rather than the
            // spec's suggested issued_at specifically to avoid the "_at
            // implies instant" ambiguity that convention exists to prevent.
            $table->date('issued_date')->nullable();

            // A free string, not a users FK — mirrors DeliveryDocument's
            // existing submitted_by/reviewed_by/approved_by convention,
            // since a revision's real-world issuer is very often an
            // external consultant/architect, not a SureSign user account.
            $table->string('issued_by', 255)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');

            // No soft deletes: no removal workflow exists in this phase at
            // all (Part V — deliberately deferred, not a UI gap). Adding an
            // unused deleted_at column now would be speculative schema; a
            // future phase can add real removal architecture once that
            // workflow is actually designed.
            $table->timestamps();

            $table->index(['drawing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_revisions');
    }
};
