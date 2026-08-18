<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per feature key that currently has a non-default override.
 * `App\Services\FeatureAvailability\FeatureAvailabilityService` is the only
 * intended read/write path — see that class for the "no row = Active"
 * resolution rule and every fail-safe behaviour. This model itself holds no
 * business logic beyond casts/relationships, matching this codebase's
 * existing model convention.
 */
class FeatureAvailability extends Model
{
    protected $table = 'feature_availabilities';

    protected $fillable = [
        'feature_key',
        'status',
        'message',
        'available_at',
        'updated_by',
    ];

    protected $casts = [
        'available_at' => 'datetime',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
