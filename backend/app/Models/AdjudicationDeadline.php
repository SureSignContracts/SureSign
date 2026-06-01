<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjudicationDeadline extends Model
{
    protected $fillable = [
        'organization_id', 'project_id', 'adjudication_case_id',
        'title', 'description', 'deadline_type', 'due_date',
        'status', 'reminder_sent', 'completed_at',
    ];

    protected $casts = [
        'due_date'      => 'date',
        'completed_at'  => 'datetime',
        'reminder_sent' => 'boolean',
    ];

    public function adjudicationCase() { return $this->belongsTo(AdjudicationCase::class); }

    /**
     * Recompute deadline status based on date.
     */
    public function computedStatus(): string
    {
        if ($this->completed_at) {
            return 'completed';
        }
        if ($this->due_date->isPast()) {
            return 'overdue';
        }
        if ($this->due_date->diffInDays(now()) <= 3) {
            return 'due_soon';
        }
        return 'upcoming';
    }
}
