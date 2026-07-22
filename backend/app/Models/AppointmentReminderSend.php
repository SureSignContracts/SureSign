<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stable identity for one individual appointment reminder — the DB-level
 * unique constraint (appointment_id, offset_minutes, schedule_version), not
 * application logic, is what actually prevents a retried/overlapping
 * scheduler tick from sending the same reminder twice. Rows are never
 * deleted; a reschedule bumps the appointment's schedule_version, which
 * naturally makes reminders "due again" without losing send history.
 */
class AppointmentReminderSend extends Model
{
    protected $fillable = [
        'appointment_id', 'offset_minutes', 'schedule_version',
        'scheduled_for', 'sent_at', 'status', 'failure_message',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'sent_at'       => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
