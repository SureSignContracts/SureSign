<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stable identity for one individual reminder email — the DB-level unique
 * constraint (not application logic) is what actually prevents a retried
 * or overlapping run from sending the same reminder twice. See the
 * migration for the full reasoning.
 */
class DeadlineReminderSend extends Model
{
    protected $fillable = [
        'organization_id', 'source_type', 'source_id',
        'reminder_field', 'reminder_offset_days', 'effective_deadline_date',
    ];

    protected $casts = [
        'effective_deadline_date' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
