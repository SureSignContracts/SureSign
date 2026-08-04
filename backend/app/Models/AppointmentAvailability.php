<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAvailability extends Model
{
    // 'context' (App\Support\Appointments\AvailabilityContext) is set
    // exclusively by AppointmentAvailabilityService from a validated
    // constant — never mass-assigned from raw request input.
    protected $fillable = ['user_id', 'context', 'weekday', 'start_time', 'end_time', 'is_active'];

    protected $casts = [
        'weekday'   => 'integer',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
