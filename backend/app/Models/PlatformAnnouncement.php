<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PlatformAnnouncement extends Model
{
    public const SEVERITIES = ['information', 'maintenance', 'degraded_service', 'outage'];

    protected $fillable = [
        'title', 'message', 'severity', 'is_active', 'starts_at', 'ends_at', 'link_url', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Live right now: flagged active, already started, and not yet ended. */
    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
