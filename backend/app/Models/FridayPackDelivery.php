<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, V1F — one durable, immutable-once-written
 * recipient snapshot row per Send initiation. See the creating migration
 * for the full schema reasoning (recipient snapshotting, PDF lock,
 * signed-link token, provider-idempotency fields).
 *
 * Written exclusively by App\Services\FridayPack\FridayPackDeliveryService
 * (creation) and SendFridayPackDeliveryJob (status/attempt updates). No
 * controller writes to this table directly.
 */
class FridayPackDelivery extends Model
{
    protected $table = 'friday_pack_deliveries';

    protected $fillable = [
        'friday_pack_id', 'project_id', 'organization_id', 'document_id',
        'recipient_name', 'recipient_email',
        'initiated_by', 'public_token',
        'status', 'attempt_count',
        'last_attempted_at', 'sent_at', 'failed_at', 'failure_reason',
        'provider_message_id',
        'link_expires_at',
    ];

    protected $casts = [
        'attempt_count'     => 'integer',
        'last_attempted_at' => 'datetime',
        'sent_at'           => 'datetime',
        'failed_at'         => 'datetime',
        'link_expires_at'   => 'datetime',
    ];

    public function fridayPack()   { return $this->belongsTo(FridayPack::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function document()     { return $this->belongsTo(Document::class); }
    public function initiatedBy()  { return $this->belongsTo(User::class, 'initiated_by'); }
}
