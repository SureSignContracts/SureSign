<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SuresignSetting extends Model
{
    protected $table = 'suresign_settings';

    protected $fillable = [
        'ai_enabled',
        'prompts_enabled',
        'notification_settings',
        'ai_provider',
        'ai_model',
        'anthropic_api_key',
        'logo_path',
        'letterhead_header_path',
        'letterhead_footer_path',
        'letterhead_pdf_path',
        'email_header_path',
        'email_footer_path',
        'email_reply_to',
        'admin_email',
        'email_sender_email',
        'email_sender_name',
        'email_subject_line',
        'email_body_template',
        'brevo_api_key',
        'currency',
        'currency_symbol',
        'date_format',
        'timezone',
        'hidden_pages',
    ];

    protected $casts = [
        'hidden_pages'          => 'array',
        'ai_enabled'            => 'boolean',
        'prompts_enabled'       => 'boolean',
        'notification_settings' => 'array',
        'brevo_api_key'         => 'encrypted',
        'anthropic_api_key'     => 'encrypted',
    ];

    // ─── Accessors — return public URLs ──────────────────────────────────────

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getLetterheadHeaderUrlAttribute(): ?string
    {
        return $this->letterhead_header_path ? Storage::disk('public')->url($this->letterhead_header_path) : null;
    }

    public function getLetterheadFooterUrlAttribute(): ?string
    {
        return $this->letterhead_footer_path ? Storage::disk('public')->url($this->letterhead_footer_path) : null;
    }

    public function getLetterheadPdfUrlAttribute(): ?string
    {
        return $this->letterhead_pdf_path ? Storage::disk('public')->url($this->letterhead_pdf_path) : null;
    }

    public function getEmailHeaderUrlAttribute(): ?string
    {
        return $this->email_header_path ? Storage::disk('public')->url($this->email_header_path) : null;
    }

    public function getEmailFooterUrlAttribute(): ?string
    {
        return $this->email_footer_path ? Storage::disk('public')->url($this->email_footer_path) : null;
    }

    protected $appends = [
        'logo_url',
        'letterhead_header_url',
        'letterhead_footer_url',
        'letterhead_pdf_url',
        'email_header_url',
        'email_footer_url',
    ];

    protected $hidden = [
        'logo_path',
        'letterhead_header_path',
        'letterhead_footer_path',
        'letterhead_pdf_path',
        'email_header_path',
        'email_footer_path',
        'brevo_api_key',      // never expose raw API key in list responses
        'anthropic_api_key',  // never expose raw API key in list responses
    ];

    /**
     * Singleton helper — always returns the one settings row.
     */
    public static function instance(): self
    {
        return static::firstOrCreate([], [
            'currency'         => 'GBP',
            'currency_symbol'  => '£',
            'date_format'      => 'DD/MM/YYYY',
            'timezone'         => 'Europe/London',
            'prompts_enabled'  => true,
        ]);
    }
}
