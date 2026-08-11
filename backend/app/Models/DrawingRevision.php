<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — the actual issued file for one revision of a Drawing. Append-
 * only historical data: no removal workflow exists yet (deliberately
 * deferred — see the creating migration's own docblock), and normal
 * application code never deletes a row here. `document_id` is immutable
 * after creation (see DrawingRevisionController::update()) — a wrong file
 * means creating a new, correct revision, never rewriting history in place.
 *
 * `status` is purely descriptive issue-purpose metadata (e.g. "For
 * Construction") — it is NEVER programmatically set to "Superseded" when a
 * newer revision becomes current. Drawing.current_revision_id is the sole
 * source of truth for current/non-current; overloading this column to
 * encode that a second time would recreate the exact duplicate-source-of-
 * truth problem Drawing.status already has (see Drawing::effectiveDocument()'s
 * docblock).
 */
class DrawingRevision extends Model
{
    protected $fillable = [
        'drawing_id', 'document_id', 'revision_code', 'status',
        'issued_date', 'issued_by', 'notes', 'created_by',
    ];

    protected $casts = [
        'issued_date' => 'date',
    ];

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(Drawing::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
