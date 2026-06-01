<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CloseoutItem extends Model
{
    protected $fillable = [
        'closeout_id', 'category', 'title', 'status',
        'due_date', 'completed_at', 'notes', 'sort_order',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'completed_at' => 'datetime',
    ];

    public function closeout() { return $this->belongsTo(Closeout::class); }
}
