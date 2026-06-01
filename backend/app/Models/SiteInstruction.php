<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteInstruction extends Model
{
    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'instruction_number', 'title', 'issued_date', 'description', 'status', 'issued_to',
    ];

    protected $casts = ['issued_date' => 'date'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function project() { return $this->belongsTo(Project::class); }
}
