<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingPayment extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'invoice_id',
        'provider',
        'provider_payment_intent_id',
        'provider_charge_id',
        'provider_checkout_session_id',
        'internal_reference',
        'status',
        'currency',
        'amount',
        'amount_refunded',
        'payment_method_type',
        'failure_code',
        'failure_message',
        'paid_at',
        'refunded_at',
        'metadata_json',
        'provider_payload_json',
    ];

    protected $casts = [
        'amount' => 'integer',
        'amount_refunded' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'metadata_json' => 'array',
        'provider_payload_json' => 'array',
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
}
