<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EotRequest extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'eot_number', 'title', 'notice_date', 'grounds',
        'days_claimed', 'days_granted', 'status',
    ];

    protected $casts = ['notice_date' => 'date'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
