<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The commercial/presentation catalogue wrapper around exactly one
 * AppointmentType (see App\Services\Consultancy\ConsultancyCatalogueService,
 * the only place both are written together). Scheduling fields (duration,
 * buffers, notice/advance windows, assignment mode, default assignee) are
 * never duplicated here — read them from appointmentType().
 */
class ConsultancyService extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'appointment_type_id', 'display_name', 'description', 'public_description',
        'enabled', 'publicly_bookable', 'available_to_existing_customers',
        'price_minor_units', 'currency', 'display_order', 'is_introductory', 'max_bookings_per_day',
    ];

    protected $casts = [
        'enabled'                          => 'boolean',
        'publicly_bookable'                => 'boolean',
        'available_to_existing_customers'  => 'boolean',
        'price_minor_units'                => 'integer',
        'display_order'                    => 'integer',
        'is_introductory'                  => 'boolean',
        'max_bookings_per_day'             => 'integer',
    ];

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    public function enquiries(): HasMany
    {
        return $this->hasMany(ConsultationEnquiry::class);
    }
}
