<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteDiary extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'diary_date', 'weather', 'temperature', 'workers_on_site',
        'works_carried_out', 'materials_delivered', 'issues', 'visitors', 'status',
    ];

    protected $casts = ['diary_date' => 'date', 'attendees' => 'array'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
