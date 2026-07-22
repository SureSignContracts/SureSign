<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Appointment extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'requested', 'pending_confirmation', 'confirmed',
        'declined', 'cancelled', 'completed', 'no_show',
    ];

    protected $fillable = [
        'reference', 'public_token', 'schedule_version',
        'appointment_type_id', 'assigned_user_id', 'created_by_user_id',
        'organization_id', 'linked_user_id', 'company_name', 'project_id',
        'attendee_name', 'attendee_email', 'attendee_phone', 'attendee_job_title',
        'attendee_company', 'attendee_timezone',
        'starts_at', 'ends_at', 'booking_timezone',
        'status', 'booking_source', 'meeting_method', 'meeting_url', 'location',
        'attendee_message', 'internal_notes', 'cancellation_reason', 'reschedule_reason',
        'completion_notes', 'metadata', 'cancelled_at', 'completed_at',
    ];

    protected $casts = [
        'starts_at'     => 'datetime',
        'ends_at'       => 'datetime',
        'cancelled_at'  => 'datetime',
        'completed_at'  => 'datetime',
        'metadata'      => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (Appointment $appointment) {
            $appointment->public_token ??= Str::random(48);
        });
    }

    public function appointmentType(): BelongsTo { return $this->belongsTo(AppointmentType::class); }
    public function reminderSends(): HasMany      { return $this->hasMany(AppointmentReminderSend::class); }
    public function assignedUser(): BelongsTo    { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function createdBy(): BelongsTo       { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function organization(): BelongsTo    { return $this->belongsTo(Organization::class); }
    public function linkedUser(): BelongsTo      { return $this->belongsTo(User::class, 'linked_user_id'); }
    public function project(): BelongsTo         { return $this->belongsTo(Project::class); }
}
