<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "What's New in SureSign" — platform-level product-communication content
 * (new features, improvements, important updates, tips), managed by Super
 * Admin/Admin and shown to authenticated users via a dismissible modal.
 * Deliberately separate from PlatformAnnouncement (system status/outage
 * banner) and SuresignNotification (per-user operational alerts) — see
 * CLAUDE.md's AI/Announcements context for why these three stay distinct.
 */
class ProductUpdate extends Model
{
    public const CATEGORY_NEW_FEATURE      = 'new_feature';
    public const CATEGORY_IMPROVEMENT      = 'improvement';
    public const CATEGORY_IMPORTANT_UPDATE = 'important_update';
    public const CATEGORY_TIP              = 'tip';

    public const CATEGORIES = [
        self::CATEGORY_NEW_FEATURE,
        self::CATEGORY_IMPROVEMENT,
        self::CATEGORY_IMPORTANT_UPDATE,
        self::CATEGORY_TIP,
    ];

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    public const AUDIENCE_ALL      = 'all';
    public const AUDIENCE_CLIENT   = 'client';
    public const AUDIENCE_OPERATOR = 'operator';

    public const AUDIENCES = [self::AUDIENCE_ALL, self::AUDIENCE_CLIENT, self::AUDIENCE_OPERATOR];

    // Bounded per spec ("consider showing a sensible bounded number such as
    // the newest few rather than months of history") — applies to the
    // automatic pending-modal query only, never to the manual history page.
    public const MAX_PENDING = 5;

    protected $fillable = [
        'title', 'summary', 'content', 'category', 'cta_label', 'cta_url',
        'audience', 'status', 'published_at', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function dismissals(): HasMany
    {
        return $this->hasMany(ProductUpdateDismissal::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /** Restricts to updates visible to a user with the given "is a platform operator" flag. */
    public function scopeForAudience(Builder $query, bool $isOperator): Builder
    {
        $visible = $isOperator ? [self::AUDIENCE_ALL, self::AUDIENCE_OPERATOR] : [self::AUDIENCE_ALL, self::AUDIENCE_CLIENT];

        return $query->whereIn('audience', $visible);
    }

    /**
     * Published, audience-matched updates this user has never dismissed —
     * the exact set the automatic "What's New" modal shows. Newest first,
     * bounded to MAX_PENDING so a user returning after a long absence sees
     * only the newest few rather than an ever-growing backlog.
     */
    public static function pendingFor(User $user): \Illuminate\Support\Collection
    {
        // Same inline role check every controller's authorize() uses (see
        // CLAUDE.md's Authorization section) — no Policy/User-model helper
        // exists for this in the codebase, so this doesn't invent one.
        $isOperator = $user->hasRole('Super Admin') || $user->hasRole('Admin');

        return self::published()
            ->forAudience($isOperator)
            ->whereDoesntHave('dismissals', fn (Builder $q) => $q->where('user_id', $user->id))
            ->orderByDesc('published_at')
            ->limit(self::MAX_PENDING)
            ->get();
    }
}
