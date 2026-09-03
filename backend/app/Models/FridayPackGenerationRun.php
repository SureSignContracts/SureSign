<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, V1E — the durable per-project/per-week scheduled-
 * generation checkpoint. See the creating migration for the full
 * reasoning (mirrors DeadlineReminderRun's exact shape).
 */
class FridayPackGenerationRun extends Model
{
    protected $table = 'friday_pack_generation_runs';

    protected $fillable = [
        'project_id', 'organization_id', 'week_ending', 'timezone',
        'generation_hour_local', 'friday_pack_id',
        'started_at', 'completed_at', 'failed_at', 'failure_reason',
    ];

    protected $casts = [
        'week_ending'   => 'date',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'failed_at'     => 'datetime',
    ];

    public function project()     { return $this->belongsTo(Project::class); }
    public function organization(){ return $this->belongsTo(Organization::class); }
    public function fridayPack()  { return $this->belongsTo(FridayPack::class); }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
