<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentNotice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'organization_id', 'payment_application_id', 'created_by',
        'reference', 'notice_date', 'notified_sum', 'basis_of_assessment',
        'issued_by', 'status',
    ];

    protected $casts = [
        'notice_date'   => 'date',
        'notified_sum'  => 'decimal:2',
    ];

    public function creator()            { return $this->belongsTo(User::class, 'created_by'); }
    public function project()            { return $this->belongsTo(Project::class); }
    public function organization()       { return $this->belongsTo(Organization::class); }
    public function paymentApplication() { return $this->belongsTo(PaymentApplication::class); }
    public function payLessNotices()     { return $this->hasMany(PayLessNotice::class); }
    public function documents()          { return $this->morphMany(Document::class, 'documentable'); }
}
