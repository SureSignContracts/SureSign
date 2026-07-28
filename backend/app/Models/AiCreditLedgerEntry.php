<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Phase G4C.3A — one immutable row per AI Credit ledger event. Never updated
 * or deleted after creation; balances and reservation-lifecycle state are
 * always derived from the full set of rows, never from a mutable summary
 * column on this or any other table. See App\Services\AI\AiCreditLedgerService
 * (the sole writer) and App\Services\AI\AiCreditBalanceService (the sole
 * reader of derived balances).
 */
class AiCreditLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('AiCreditLedgerEntry rows are immutable — corrections must be a new compensating entry, never an update.');
        });

        static::deleting(function () {
            throw new RuntimeException('AiCreditLedgerEntry rows are immutable — corrections must be a new compensating entry, never a delete.');
        });
    }

    protected $fillable = [
        'organization_id',
        'workflow',
        'transaction_type',
        'reference_type',
        'reference_id',
        'amount',
        'reason',
        'actor_type',
        'actor_id',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
