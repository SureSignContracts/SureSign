<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BillingCustomer extends Model
{
    protected $fillable = [
        'organization_id',
        'provider',
        'provider_customer_id',
        'livemode',
        'billing_email',
        'billing_name',
        'billing_address_json',
        'tax_id',
        'tax_status',
        'currency',
        'metadata_json',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'billing_address_json' => 'array',
        'metadata_json' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Restricts to mappings created under the given Stripe mode — see
     * App\Services\Billing\BillingCustomerService.
     */
    public function scopeForLivemode(Builder $query, bool $livemode): Builder
    {
        return $query->where('livemode', $livemode);
    }
}
