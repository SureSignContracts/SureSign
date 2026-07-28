<?php

namespace App\Models;

use App\Support\Billing\WebhookProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BillingWebhookEvent extends Model
{
    protected $fillable = [
        'provider',
        'provider_event_id',
        'event_type',
        'api_version',
        'livemode',
        'provider_created_at',
        'processing_status',
        'attempt_count',
        'processing_started_at',
        'received_at',
        'processed_at',
        'failed_at',
        'failure_message',
        'retryable',
        'payload_json',
        'payload_hash',
    ];

    protected $casts = [
        'livemode' => 'boolean',
        'attempt_count' => 'integer',
        'processing_started_at' => 'datetime',
        'provider_created_at' => 'datetime',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
        'retryable' => 'boolean',
        'payload_json' => 'array',
    ];

    /**
     * `conflict` rows that have not been resolved manually — the primary
     * operator-facing entry point into Part 20's discoverability
     * requirement (see App\Services\Billing\WebhookEventProcessor's class
     * docblock for the manual investigation process). Nothing in this
     * codebase automatically moves a row out of `conflict`, so every row
     * this scope returns is currently unresolved by definition.
     */
    public function scopeUnresolvedConflicts(Builder $query): Builder
    {
        return $query->where('processing_status', WebhookProcessingStatus::CONFLICT);
    }

    /**
     * `failed` rows explicitly marked safe to retry — distinct from a
     * `failed` row with `retryable = false` (a permanent, non-retryable
     * failure needing the same manual attention as a conflict).
     */
    public function scopeRetryableFailed(Builder $query): Builder
    {
        return $query->where('processing_status', WebhookProcessingStatus::FAILED)
            ->where('retryable', true);
    }
}
