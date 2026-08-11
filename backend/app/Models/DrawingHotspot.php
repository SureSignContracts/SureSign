<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Drawing Phase 5 — a persisted, normalized-coordinate location marker on
 * one page of one specific DrawingRevision.
 *
 * CRITICAL OWNERSHIP RULE: a hotspot belongs to a DrawingRevision, never to
 * Drawing directly. Ownership/authorization always resolves via
 * drawing_revision_id -> drawing_id -> project_id/organization_id — never
 * through Drawing::effectiveDocument() and never through the legacy
 * Drawing.document_id fallback. If Drawing.current_revision_id changes to
 * point at a different revision later, every hotspot already created stays
 * attached to the exact revision it was created against — there is no
 * automatic carry-forward, copy, or migration of hotspots between
 * revisions, ever, by any code path in this application.
 *
 * COORDINATE CONVENTION: `x`/`y` are normalized (0.0-1.0) relative to the
 * PDF page's CSS-rendered display dimensions (`canvas.style.width/height`
 * in DrawingPdfCanvas — i.e. `viewport.width`/`viewport.height` from
 * pdf.js), NEVER the devicePixelRatio-scaled canvas backing store
 * (`canvas.width/height`), and never raw screen/viewport pixels. This is
 * what makes a stored coordinate render correctly at any zoom level, Fit
 * Width state, container width, or devicePixelRatio without any
 * transformation beyond simple percentage positioning.
 *
 * ANCHOR CONVENTION: (x, y) is the CENTER of the marker, not a corner or
 * tip — rendered via `left: x*100%; top: y*100%; transform: translate(-50%,
 * -50%)`. Any future authoring UI must persist coordinates using this exact
 * same convention.
 */
class DrawingHotspot extends Model
{
    protected $fillable = [
        'drawing_revision_id', 'page_number', 'x', 'y', 'label', 'created_by',
    ];

    protected $casts = [
        'page_number' => 'integer',
        'x' => 'float',
        'y' => 'float',
    ];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class, 'drawing_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
