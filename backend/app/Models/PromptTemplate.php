<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromptTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'prompt_category_id',
        'title',
        'slug',
        'description',
        'prompt_text',
        'module',
        'use_case',
        'variables',
        'is_global',
        'is_active',
        'is_featured',
        'created_by',
        'copied_count',
        'sort_order',
    ];

    protected $casts = [
        'variables'   => 'array',
        'is_global'   => 'boolean',
        'is_active'   => 'boolean',
        'is_featured' => 'boolean',
        'copied_count'=> 'integer',
        'sort_order'  => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PromptCategory::class, 'prompt_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(PromptFavorite::class);
    }

    public function copyLogs(): HasMany
    {
        return $this->hasMany(PromptCopyLog::class);
    }

    public function incrementCopiedCount(): void
    {
        $this->increment('copied_count');
    }
}
