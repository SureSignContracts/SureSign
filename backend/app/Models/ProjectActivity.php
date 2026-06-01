<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectActivity extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'user_id',
        'activity_type',
        'title',
        'description',
        'related_type',
        'related_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function user()         { return $this->belongsTo(User::class); }
    public function related()      { return $this->morphTo(); }
}
