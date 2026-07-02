<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinalAccountItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'final_account_id', 'category', 'description',
        'source_type', 'source_id', 'amount',
        'is_auto_seeded', 'notes', 'sort_order',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'is_auto_seeded' => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function finalAccount() { return $this->belongsTo(FinalAccount::class); }

    public function isContractSum(): bool
    {
        return $this->category === FinalAccount::CATEGORY_CONTRACT_SUM;
    }

    public function isEditable(): bool
    {
        // Contract sum item is read-only once seeded
        return !$this->isContractSum();
    }
}
