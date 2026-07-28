<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingInvoice extends Model
{
    protected $fillable = [
        'organization_id',
        'subscription_id',
        'billing_customer_id',
        'provider',
        'provider_invoice_id',
        'invoice_number',
        'provider_invoice_number',
        'status',
        'currency',
        'subtotal_amount',
        'tax_amount',
        'discount_amount',
        'total_amount',
        'amount_due',
        'amount_paid',
        'amount_remaining',
        'hosted_invoice_url',
        'invoice_pdf_url',
        'billing_reason',
        'period_starts_at',
        'period_ends_at',
        'due_at',
        'paid_at',
        'voided_at',
        'metadata_json',
        'provider_payload_json',
    ];

    protected $casts = [
        'subtotal_amount' => 'integer',
        'tax_amount' => 'integer',
        'discount_amount' => 'integer',
        'total_amount' => 'integer',
        'amount_due' => 'integer',
        'amount_paid' => 'integer',
        'amount_remaining' => 'integer',
        'period_starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
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

    public function billingCustomer()
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    public function payments()
    {
        return $this->hasMany(BillingPayment::class, 'invoice_id');
    }
}
