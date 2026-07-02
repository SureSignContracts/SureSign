<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuresignNotification extends Model
{
    protected $table = 'suresign_notifications';

    // ── Status lifecycle ──────────────────────────────────────────────────────
    public const STATUS_UNREAD    = 'unread';
    public const STATUS_READ      = 'read';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_EXPIRED   = 'expired';

    // ── Priorities ────────────────────────────────────────────────────────────
    public const PRIORITY_CRITICAL = 'critical';
    public const PRIORITY_WARNING  = 'warning';
    public const PRIORITY_REMINDER = 'reminder';
    public const PRIORITY_INFO     = 'info';

    // ── Categories ────────────────────────────────────────────────────────────
    public const CATEGORY_COMMERCIAL  = 'commercial';
    public const CATEGORY_CONTRACT    = 'contract';
    public const CATEGORY_PROGRAMME   = 'programme';
    public const CATEGORY_COMPLIANCE  = 'compliance';
    public const CATEGORY_PAYMENT     = 'payment';
    public const CATEGORY_VARIATION   = 'variation';
    public const CATEGORY_RETENTION   = 'retention';
    public const CATEGORY_DELIVERABLE = 'deliverable';
    public const CATEGORY_NOTICE      = 'notice';
    public const CATEGORY_RISK        = 'risk';
    public const CATEGORY_GENERAL     = 'general';

    // ── Email gate (categories + priorities that may be emailed) ─────────────
    // Used by EmailNotificationService when it gains scheduled-digest support.
    public const EMAILABLE_CATEGORIES = [
        self::CATEGORY_PAYMENT,
        self::CATEGORY_COMPLIANCE,
        self::CATEGORY_CONTRACT,
        self::CATEGORY_NOTICE,
    ];
    public const EMAILABLE_PRIORITIES = [
        self::PRIORITY_CRITICAL,
        self::PRIORITY_WARNING,
    ];

    protected $fillable = [
        'user_id',
        'organization_id',
        'project_id',
        'type',
        'category',
        'priority',
        'status',
        'title',
        'message',
        'source_type',
        'source_id',
        'source_field',
        'action_url',
        'data',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeUnread($query)
    {
        return $query->where('status', self::STATUS_UNREAD);
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_EXPIRED]);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function markRead(): void
    {
        $this->update([
            'is_read' => true,
            'read_at' => now(),
            'status'  => self::STATUS_READ,
        ]);
    }

    public function dismiss(): void
    {
        $this->update(['status' => self::STATUS_DISMISSED]);
    }

    public function resolve(): void
    {
        $this->update(['status' => self::STATUS_RESOLVED]);
    }

    public function isEmailWorthy(): bool
    {
        return in_array($this->category, self::EMAILABLE_CATEGORIES)
            && in_array($this->priority, self::EMAILABLE_PRIORITIES);
    }
}
