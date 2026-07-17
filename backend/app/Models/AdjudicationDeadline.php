<?php

namespace App\Models;

use App\Services\TimezoneResolver;
use Carbon\Carbon;
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

    public function organization() { return $this->belongsTo(Organization::class); }

    /**
     * Recompute deadline status based on date. `due_date` is a DATE column
     * (no time-of-day) — compared here against this organisation's own
     * "today" (a plain Y-m-d string) rather than the server's UTC calendar
     * day, and never by converting the DATE value's own timezone (which
     * would risk shifting it) — see TimezoneResolver.
     */
    public function computedStatus(): string
    {
        if ($this->completed_at) {
            return 'completed';
        }

        $today = TimezoneResolver::today(null, $this->organization)->toDateString();
        $due   = $this->due_date->toDateString();

        if ($due < $today) {
            return 'overdue';
        }
        // Preserves the exact pre-existing comparison shape
        // (`$this->due_date->diffInDays(now())`, no absolute/int cast) —
        // only the timezone-blind `isPast()`/`now()` source was replaced
        // with this organisation's own "today"; the threshold arithmetic
        // itself is unchanged and out of scope for this batch.
        if (Carbon::parse($due)->diffInDays(Carbon::parse($today)) <= 3) {
            return 'due_soon';
        }
        return 'upcoming';
    }
}
