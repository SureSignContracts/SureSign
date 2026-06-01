<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Closeout extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'created_by',
        'title', 'status', 'completed_at', 'notes',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function items()        { return $this->hasMany(CloseoutItem::class)->orderBy('sort_order'); }
}
