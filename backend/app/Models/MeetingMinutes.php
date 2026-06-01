<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingMinutes extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'meeting_number', 'title', 'meeting_date', 'location', 'type',
        'attendees', 'agenda', 'minutes', 'action_items', 'status',
    ];

    protected $casts = ['meeting_date' => 'date', 'attendees' => 'array', 'action_items' => 'array'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
