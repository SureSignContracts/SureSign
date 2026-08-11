<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Structured drawing register metadata layered on top of an existing
 * Document — Drawing never stores a file itself (see documents/drawing.md
 * and Drawing Phase 1's architecture discovery). Mirrors DeliveryDocument's
 * metadata→Document precedent, with document_id mandatory here (a Drawing
 * always starts from an already-uploaded Project Document).
 *
 * Phase 4 (Drawing Revision Foundation) layered proper revision history on
 * top via DrawingRevision + current_revision_id, WITHOUT removing or
 * repurposing document_id — see effectiveDocument()'s own docblock for why
 * both must keep working together indefinitely, not just during a one-time
 * migration window.
 */
class Drawing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'document_id', 'current_revision_id', 'created_by',
        'drawing_number', 'title', 'discipline', 'status', 'location_reference',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * The legacy/compatibility file link (Phase 1A) — kept exactly as-is,
     * never removed or repurposed. Do NOT read this directly anywhere new;
     * use effectiveDocument() below instead.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DrawingRevision::class);
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(DrawingRevision::class, 'current_revision_id');
    }

    /**
     * THE single authoritative place the effective/current Document is
     * resolved (Phase 4 Part J) — every controller/serializer must call
     * this instead of independently reimplementing the fallback.
     *
     * Resolution order:
     *   1. currentRevision's Document, when a current revision exists —
     *      the real, permanent, ongoing behaviour for any Drawing that has
     *      had at least one revision explicitly added (via Add Revision).
     *   2. The legacy document_id Document otherwise — covers BOTH a
     *      pre-Phase-4 migrated Drawing AND a freshly-registered Drawing
     *      that hasn't had its first revision added yet (Register Drawing
     *      itself is deliberately unchanged this phase — it does not
     *      auto-create an initial revision). This is not "migration-era
     *      residue" to be cleaned up later; it is permanent, correct
     *      behaviour for as long as document_id itself remains in the
     *      schema.
     *
     * Callers should eager-load both `currentRevision.document` and
     * `document` (via with()) to avoid N+1s when resolving this for a list.
     */
    public function effectiveDocument(): ?Document
    {
        if ($this->relationLoaded('currentRevision') && $this->currentRevision) {
            return $this->currentRevision->document;
        }

        if (! $this->relationLoaded('currentRevision') && $this->current_revision_id) {
            return $this->currentRevision?->document;
        }

        return $this->document;
    }
}
