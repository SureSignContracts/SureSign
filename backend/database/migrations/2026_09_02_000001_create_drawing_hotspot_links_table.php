<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drawing Phase 6B — the polymorphic join between a DrawingHotspot and one
 * of the supported construction record types (Snag/Rfi/QaReport/Variation —
 * see App\Support\Drawings\DrawingLinkableType's own docblock for the
 * allowlist this table's `linkable_type` values are always drawn from,
 * never an arbitrary client-supplied class string).
 *
 * A join model rather than a direct FK on drawing_hotspots (Part O) — one
 * hotspot may link to multiple records, and one record may be linked from
 * multiple hotspots/drawings.
 *
 * Deliberately NOT soft-deleted and NOT cascade-cleaned when the linked
 * record itself is hard-deleted — mirrors the existing, established
 * precedent of `file_uploads.attachable_type/id` (App\Models\FileUpload):
 * Snag/Rfi/QaReport/Variation are all hard-deleted with no cleanup of their
 * own polymorphic FileUpload children today, and this table follows that
 * same convention rather than inventing a new one. Every read path
 * (DrawingHotspotLinkController) resolves the linkable model defensively
 * and skips a row whose target no longer exists — see that controller's
 * own docblock for the presentation-layer contract this requires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_hotspot_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawing_hotspot_id')->constrained()->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            // Prevents the same hotspot linking to the exact same record
            // twice (Part S). No SoftDeletes on this table (see above), so a
            // plain unique index is sufficient — there is no "restore a
            // soft-deleted duplicate" case to additionally guard against.
            $table->unique(['drawing_hotspot_id', 'linkable_type', 'linkable_id'], 'drawing_hotspot_links_unique');
            $table->index(['linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_hotspot_links');
    }
};
