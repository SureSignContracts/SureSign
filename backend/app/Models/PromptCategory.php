<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromptCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function templates(): HasMany
    {
        return $this->hasMany(PromptTemplate::class);
    }

    public function activeTemplates(): HasMany
    {
        return $this->hasMany(PromptTemplate::class)->where('is_active', true);
    }
}
