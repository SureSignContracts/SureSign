<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RetentionRelease extends Model
{
    use SoftDeletes;

    // Retention moiety constants — UK construction two-stage release
    public const MOIETY_HALF_1 = 'half_1'; // Practical Completion
    public const MOIETY_HALF_2 = 'half_2'; // Making Good Defects / Defects Expiry
    public const MOIETY_OTHER  = 'other';

    protected $fillable = [
        'project_id', 'organization_id', 'created_by',
        'contract_id', 'trade_package_id',
        'release_amount', 'release_date', 'release_reason', 'moiety', 'notes',
    ];

    protected $casts = [
        'release_date'   => 'date',
        'release_amount' => 'decimal:2',
    ];

    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function contract()     { return $this->belongsTo(Contract::class); }
    public function tradePackage() { return $this->belongsTo(TradePackage::class); }
}
