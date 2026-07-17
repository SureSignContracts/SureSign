<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organisation, per-local-day checkpoint for reminder commands.
 * See the migration for the full reasoning.
 */
class DeadlineReminderRun extends Model
{
    protected $fillable = [
        'organization_id', 'command_key', 'local_date', 'timezone',
        'started_at', 'completed_at', 'failed_at', 'failure_message',
        'reminders_evaluated', 'emails_sent',
    ];

    protected $casts = [
        'local_date'    => 'date',
        'started_at'    => 'datetime',
        'completed_at'  => 'datetime',
        'failed_at'     => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }
}
