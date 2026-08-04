<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organisation URL Branding, Phase 2 — see the creating migration's
 * docblock. Immutable: a row is written once (when a slug stops being an
 * organisation's current one) and never updated afterwards.
 */
class OrganizationUrlSlugHistory extends Model
{
    const UPDATED_AT = null;

    protected $table = 'organization_url_slug_history';

    protected $fillable = ['organization_id', 'url_slug', 'released_at'];

    protected $casts = [
        'released_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('OrganizationUrlSlugHistory rows are immutable and can never be updated.');
        });
    }
}
