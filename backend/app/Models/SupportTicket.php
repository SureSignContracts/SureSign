<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'subject', 'message', 'status', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function user()         { return $this->belongsTo(User::class); }
}
