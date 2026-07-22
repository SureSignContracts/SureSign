<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAvailabilityOverride extends Model
{
    protected $fillable = ['user_id', 'local_date', 'is_unavailable', 'start_time', 'end_time'];

    protected $casts = [
        'local_date'     => 'date',
        'is_unavailable' => 'boolean',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
