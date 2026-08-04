<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * see the creation migration's own docblock for the full idempotency
 * rationale. Rows are never deleted or updated after `sent`/`failed` is
 * recorded, except by a genuine retry of a `failed` row.
 */
class ConsultationCommunicationDelivery extends Model
{
    protected $fillable = [
        'appointment_id', 'communication_type', 'recipient', 'schedule_version',
        'status', 'queued_at', 'sent_at', 'failed_at', 'attempt_count',
        'provider_message_id', 'failure_category', 'idempotency_key',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at'   => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public static function idempotencyKeyFor(int $appointmentId, string $communicationType, int $scheduleVersion): string
    {
        return "{$communicationType}:{$appointmentId}:{$scheduleVersion}";
    }
}
