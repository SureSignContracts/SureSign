<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryDocument extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'trade_package_id',
        'document_id',
        'category',
        'title',
        'description',
        'status',
        'revision',
        'submitted_by',
        'reviewed_by',
        'approved_by',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'due_date',
        'expiry_date',
        'notes',
        'is_ai_extracted',
        'source_ai_analysis_id',
        'source_document_id',
        'extracted_data_json',
        'created_by',
    ];

    protected $casts = [
        'submitted_at'         => 'datetime',
        'reviewed_at'          => 'datetime',
        'approved_at'          => 'datetime',
        'due_date'             => 'date',
        'expiry_date'          => 'date',
        'is_ai_extracted'      => 'boolean',
        'extracted_data_json'  => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function tradePackage(): BelongsTo
    {
        return $this->belongsTo(TradePackage::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
