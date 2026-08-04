<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAvailabilityOverride extends Model
{
    // 'context' (App\Support\Appointments\AvailabilityContext) is set
    // exclusively by AppointmentAvailabilityService from a validated
    // constant — never mass-assigned from raw request input.
    protected $fillable = ['user_id', 'context', 'local_date', 'is_unavailable', 'start_time', 'end_time'];

    protected $casts = [
        'local_date'     => 'date',
        'is_unavailable' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
