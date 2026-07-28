<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingAdjustment extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'invoice_id',
        'type',
        'description',
        'currency',
        'amount',
        'effective_at',
        'created_by_user_id',
        'metadata_json',
    ];

    protected $casts = [
        'amount' => 'integer',
        'effective_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function invoice()
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
