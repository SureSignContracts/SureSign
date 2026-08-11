<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Drawing Revision Foundation. Deliberately a SEPARATE migration
 * from drawing_revisions' own creation (not added as a column on that same
 * migration file) to avoid a circular foreign-key ordering failure:
 * drawing_revisions.drawing_id -> drawings, and drawings.current_revision_id
 * -> drawing_revisions, so drawing_revisions must exist first. See
 * tests/Unit/Migrations/ForeignKeyMigrationOrderTest.php, which enforces
 * this ordering across every migration in the repository.
 *
 * Nullable and additive only — drawings.document_id is NOT touched, removed,
 * or repurposed here. Existing Drawings continue to resolve their file via
 * document_id until an explicit revision exists (see
 * Drawing::effectiveDocument()). Backfilling current_revision_id for
 * pre-existing Drawings is a separate, idempotent, on-demand step (see
 * App\Console\Commands\BackfillDrawingInitialRevisions) — never embedded in
 * this schema migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drawings', function (Blueprint $table) {
            $table->foreignId('current_revision_id')->nullable()->after('document_id')
                ->constrained('drawing_revisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('drawings', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
            $table->dropColumn('current_revision_id');
        });
    }
};
