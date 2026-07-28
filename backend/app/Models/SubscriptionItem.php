<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionItem extends Model
{
    protected $fillable = [
        'subscription_id',
        'item_type',
        'code',
        'name',
        'provider_subscription_item_id',
        'provider_price_id',
        'quantity',
        'unit_amount',
        'currency',
        'billing_interval',
        'metadata_json',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_amount' => 'integer',
        'metadata_json' => 'array',
    ];

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
