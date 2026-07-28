<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $table = 'pricing_settings';

    protected $fillable = [
        'hero_title',
        'hero_subtitle',
        'section_title',
        'monthly_billing_enabled',
        'annual_billing_enabled',
        'discount_label',
        'everything_included_title',
        'everything_included_subtitle',
        'final_cta_title',
        'final_cta_subtitle',
        'primary_cta_text',
        'primary_cta_url',
        'primary_cta_new_tab',
        'secondary_cta_text',
        'secondary_cta_url',
        'secondary_cta_new_tab',
        'published',
    ];

    protected $casts = [
        'monthly_billing_enabled' => 'boolean',
        'annual_billing_enabled'  => 'boolean',
        'primary_cta_new_tab'     => 'boolean',
        'secondary_cta_new_tab'   => 'boolean',
        'published'               => 'boolean',
    ];

    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'hero_title'    => 'Simple, transparent pricing',
            'hero_subtitle' => 'Choose the plan that fits how your team runs contracts.',
            'section_title' => 'Plans',
        ]);
    }
}
