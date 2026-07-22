<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentBlockedPeriod extends Model
{
    protected $fillable = ['user_id', 'starts_at', 'ends_at', 'timezone', 'reason', 'created_by_user_id'];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    public function user(): BelongsTo      { return $this->belongsTo(User::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
