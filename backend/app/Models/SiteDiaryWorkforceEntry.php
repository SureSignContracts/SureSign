<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Friday Pack Realignment, R1C — Workforce. A supplementary trade/role
 * breakdown against a single Site Report; SiteDiary::$workers_on_site
 * remains the authoritative overall daily headcount (see
 * App\Services\FridayPack\FridayPackWorkforceService). Free-text
 * `trade_or_role` — no taxonomy, no individual Worker identity.
 */
class SiteDiaryWorkforceEntry extends Model
{
    protected $fillable = [
        'site_diary_id', 'project_id', 'organization_id',
        'trade_or_role', 'operative_count', 'sort_order',
    ];

    protected $casts = [
        'operative_count' => 'integer',
        'sort_order'      => 'integer',
    ];

    public function siteDiary() { return $this->belongsTo(SiteDiary::class); }
    public function project()  { return $this->belongsTo(Project::class); }
}
