<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Drawing Phase 6B — the polymorphic join between a DrawingHotspot and one
 * of the supported construction record types. `linkable_type` is always an
 * actual model class string resolved through
 * App\Support\Drawings\DrawingLinkableType::modelFor() by the controller —
 * never written from raw client input.
 *
 * No SoftDeletes, and never cascade-cleaned when the linked record is
 * hard-deleted — see the creating migration's own docblock for the
 * FileUpload::attachable() precedent this follows. Any code reading
 * `linkable` must tolerate it resolving to null (the record was deleted)
 * and skip that row rather than fail.
 */
class DrawingHotspotLink extends Model
{
    protected $fillable = [
        'drawing_hotspot_id', 'linkable_type', 'linkable_id', 'created_by',
    ];

    public function hotspot(): BelongsTo
    {
        return $this->belongsTo(DrawingHotspot::class, 'drawing_hotspot_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
