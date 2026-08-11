<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Structured drawing register metadata layered on top of an existing
 * Document — Drawing never stores a file itself (see documents/drawing.md
 * and Drawing Phase 1's architecture discovery). Mirrors DeliveryDocument's
 * metadata→Document precedent, with document_id mandatory here (a Drawing
 * always starts from an already-uploaded Project Document).
 */
class Drawing extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'project_id', 'document_id', 'created_by',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
