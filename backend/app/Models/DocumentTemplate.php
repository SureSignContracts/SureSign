<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'category',
        'type',
        'description',
        'content',
        'variables',
        'file_path',
        'is_global',
        'is_active',
    ];

    protected $casts = [
        'variables'  => 'array',
        'is_global'  => 'boolean',
        'is_active'  => 'boolean',
    ];

    // Category labels used across the system
    const CATEGORIES = [
        'subcontract'          => 'Subcontract',
        'payment_application'  => 'Payment Application',
        'variation'            => 'Variation',
        'rfi'                  => 'RFI',
        'notice'               => 'Notice',
        'meeting_minutes'      => 'Meeting Minutes',
        'site_report'          => 'Site Report',
        'letter'               => 'Letter',
        'eot'                  => 'EOT',
        'other'                => 'Other',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public static function generateSlug(string $name): string
    {
        $base = \Illuminate\Support\Str::slug($name);
        $slug = $base;
        $i    = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
