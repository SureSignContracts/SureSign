<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentApplicationVariation extends Model
{
    protected $fillable = [
        'payment_application_id', 'variation_id',
        'variation_number_at_inclusion', 'title_at_inclusion',
        'description_at_inclusion', 'amount_at_inclusion', 'status_at_inclusion',
    ];

    protected $casts = [
        'amount_at_inclusion' => 'decimal:2',
    ];

    public function paymentApplication() { return $this->belongsTo(PaymentApplication::class); }
    public function variation()           { return $this->belongsTo(Variation::class); }
}
