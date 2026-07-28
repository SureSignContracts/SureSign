<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BillingReferenceSequence extends Model
{
    protected $fillable = ['type', 'current_sequence'];
}
