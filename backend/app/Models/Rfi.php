<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rfi extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'rfi_number', 'subject', 'description', 'priority',
        'status', 'raised_date', 'response_due_date', 'response',
    ];

    protected $casts = ['raised_date' => 'date', 'response_due_date' => 'date'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
