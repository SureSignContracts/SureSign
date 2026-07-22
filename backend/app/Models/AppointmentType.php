<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppointmentType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'description', 'public_title', 'public_description', 'internal_notes',
        'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes',
        'min_notice_hours', 'max_advance_days',
        'is_public', 'is_active', 'color',
        'default_assigned_user_id', 'assignment_mode', 'requires_confirmation',
        'meeting_method', 'default_location',
        'cancellation_notice_hours', 'reschedule_notice_hours', 'display_order',
    ];

    protected $casts = [
        'is_public'              => 'boolean',
        'is_active'               => 'boolean',
        'requires_confirmation'   => 'boolean',
        'duration_minutes'        => 'integer',
        'buffer_before_minutes'   => 'integer',
        'buffer_after_minutes'    => 'integer',
        'min_notice_hours'        => 'integer',
        'max_advance_days'        => 'integer',
        'cancellation_notice_hours' => 'integer',
        'reschedule_notice_hours'   => 'integer',
        'display_order'           => 'integer',
    ];

    public function defaultAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_assigned_user_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
