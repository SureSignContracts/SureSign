<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drawing Phase 5 — Hotspot Foundation. A DrawingHotspot belongs to a
 * specific DrawingRevision, never to Drawing directly, and never resolved
 * through Drawing::effectiveDocument()/Drawing.document_id — see
 * App\Models\DrawingHotspot's own docblock for the full reasoning. If
 * Drawing.current_revision_id changes later, existing hotspots stay
 * attached to their original revision; there is no automatic carry-forward.
 *
 * Deliberately NOT denormalizing drawing_id/project_id/organization_id
 * onto this table — ownership resolves through drawing_revision_id ->
 * drawing_id -> project_id/organization_id (the exact same normalization
 * discipline already used by DrawingRevision itself). No concrete
 * performance/security need justifies duplicating those columns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drawing_hotspots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drawing_revision_id')->constrained()->cascadeOnDelete();

            // 1-based, matching the Viewer's own page numbering (Part D) —
            // never a PDF.js-internal zero-based index. No server-side PDF
            // parsing exists to validate an upper bound against the actual
            // page count; the application layer must not invent one either.
            $table->unsignedInteger('page_number');

            // Normalized position (0.0-1.0) relative to the PDF page's
            // CSS-rendered dimensions, CENTER-anchored (Part C/E) — never
            // screen/canvas/backing-store pixels, never PDF points. This is
            // what lets the exact same stored value render correctly at
            // every zoom level, Fit Width state, and devicePixelRatio.
            //
            // decimal(10,8): 8 fractional digits gives a quantization step
            // of 0.5x10^-8 of the page's normalized width/height — even on
            // an extreme 20,000px-wide rendered page that's ~0.0002px of
            // positional error, far below anything visible. No existing
            // repository precedent fits this (lat/lng's decimal(10,7) is a
            // geographic convention with different semantics — a different
            // quantity being measured, not reused here just because the
            // shape looks similar).
            $table->decimal('x', 10, 8);
            $table->decimal('y', 10, 8);

            $table->string('label', 255)->nullable();

            $table->foreignId('created_by')->constrained('users');

            // No soft deletes: no removal workflow exists in this phase
            // (mirrors DrawingRevision's own identical reasoning) — a
            // future phase adds real removal architecture once that
            // workflow is actually designed, not speculative schema now.
            $table->timestamps();

            $table->index(['drawing_revision_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drawing_hotspots');
    }
};
