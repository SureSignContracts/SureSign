<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Friday Pack Realignment, R1F.2 — Content Readiness + Weekly
 * Declarations. Implements the R1F.1 approved design (see
 * project-context.md's R1F.1 entry) — ONE row = the CURRENT declaration
 * slot for a (FridayPack, section, subsection) triple. This table is NOT
 * a version history — ActivityLog carries the change history
 * (`friday_pack_section_declared`/`_declaration_cleared`/
 * `_declaration_invalidated`); this row is always overwritten in place
 * on re-declaration, never duplicated.
 *
 * `subsection_key` is deliberately `NOT NULL DEFAULT ''` — NEVER
 * nullable. MySQL's UNIQUE index treats NULL as never-equal-to-itself,
 * so a nullable subsection_key would let unlimited duplicate rows exist
 * for every ordinary top-level-section declaration (every section
 * except `permits_inspections`, which alone uses real subsection values
 * `'permits'`/`'inspections'`, matching the snapshot's own two array
 * keys exactly). The empty string is the "top-level section, no
 * subsection" sentinel and participates normally in unique-index
 * deduplication on both SQLite and MySQL — no generated column or
 * trigger workaround needed.
 *
 * `invalidated_at` (nullable) is the sole distinguishing state between
 * an EFFECTIVE and a STALE declaration — see
 * App\Services\FridayPack\FridayPackSectionDeclarationService for the
 * one place this is ever set, and
 * App\Services\FridayPack\FridayPackReadinessService for the one place
 * it is ever read for readiness purposes (an invalidated row is never
 * "effective"). A row is never deleted on invalidation — the
 * confirmation itself remains a real historical fact.
 *
 * `friday_pack_id` uses `cascadeOnDelete()`, matching every other
 * FridayPack-child table (`friday_pack_deliveries`,
 * `friday_pack_photo_selections`) — a declaration has no independent
 * meaning once its pack is gone. `confirmed_by` uses `nullOnDelete()`,
 * matching `friday_packs.reviewed_by`/`approved_by`'s own established
 * attribution convention — a declaration must survive the confirming
 * user's own account deletion as historical evidence, never cascade
 * away with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friday_pack_section_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('friday_pack_id')->constrained()->cascadeOnDelete();

            $table->string('section_key');
            $table->string('subsection_key')->default('');

            $table->string('declaration');
            $table->text('note')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at');
            $table->timestamp('invalidated_at')->nullable();

            $table->timestamps();

            $table->unique(['friday_pack_id', 'section_key', 'subsection_key'], 'fpsd_pack_section_subsection_unique');
            $table->index(['friday_pack_id', 'section_key'], 'fpsd_pack_section_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friday_pack_section_declarations');
    }
};
