<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjudicationStep extends Model
{
    protected $fillable = [
        'adjudication_case_id', 'step_key', 'title', 'description',
        'status', 'due_date', 'completed_at', 'completed_by',
        'notes', 'metadata', 'sort_order',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
        'metadata'     => 'array',
    ];

    public function adjudicationCase() { return $this->belongsTo(AdjudicationCase::class); }
    public function completedBy()      { return $this->belongsTo(User::class, 'completed_by'); }
}
