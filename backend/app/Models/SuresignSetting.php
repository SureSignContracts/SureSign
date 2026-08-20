<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SuresignSetting extends Model
{
    protected $table = 'suresign_settings';

    protected $fillable = [
        'consultancy_consultant_user_id',
        'ai_enabled',
        'prompts_enabled',
        'notification_settings',
        'ai_provider',
        'ai_model',
        'ai_effort',
        'ai_credit_operating_mode',
        'anthropic_api_key',
        'logo_path',
        'favicon_path',
        'notification_sound_path',
        'loader_accent_style',
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
        'platform_name',
        'support_email',
        'max_upload_mb',
        'feature_document_generation',
        'feature_white_label',
        'feature_self_registration',
        'appointment_reminders_enabled',
        'appointment_reminder_offsets_minutes',
        'appointment_cancel_link_ttl_hours',
        'appointment_reschedule_link_ttl_hours',
        'appointment_cancellation_cutoff_hours',
        'appointment_reschedule_cutoff_hours',
        'appointment_ics_enabled',
        'appointment_default_meeting_instructions',
        'consultation_public_link_ttl_hours',
        'consultancy_new_booking_notification_recipients',
    ];

    protected $casts = [
        'hidden_pages'                 => 'array',
        'ai_enabled'                   => 'boolean',
        'prompts_enabled'              => 'boolean',
        'notification_settings'        => 'array',
        'brevo_api_key'                => 'encrypted',
        'anthropic_api_key'            => 'encrypted',
        'feature_document_generation'  => 'boolean',
        'feature_white_label'          => 'boolean',
        'feature_self_registration'    => 'boolean',
        'appointment_reminders_enabled'         => 'boolean',
        'appointment_reminder_offsets_minutes'  => 'array',
        'appointment_ics_enabled'               => 'boolean',
    ];

    /**
     * [1440, 60] (24h + 1h before) — used whenever the platform-wide
     * appointment_reminder_offsets_minutes column is null (unset).
     */
    public const DEFAULT_APPOINTMENT_REMINDER_OFFSETS_MINUTES = [1440, 60];

    // ─── Accessors — return public URLs ──────────────────────────────────────

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getFaviconUrlAttribute(): ?string
    {
        return $this->favicon_path ? Storage::disk('public')->url($this->favicon_path) : null;
    }

    public function getNotificationSoundUrlAttribute(): ?string
    {
        return $this->notification_sound_path ? Storage::disk('public')->url($this->notification_sound_path) : null;
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
        'favicon_url',
        'notification_sound_url',
        'letterhead_header_url',
        'letterhead_footer_url',
        'letterhead_pdf_url',
        'email_header_url',
        'email_footer_url',
    ];

    protected $hidden = [
        'logo_path',
        'favicon_path',
        'notification_sound_path',
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

    /**
     * Configured max upload size in KB, for use with Laravel's `max:` file
     * validation rule (which is expressed in KB, not MB).
     */
    public static function maxUploadKb(): int
    {
        return (self::instance()->max_upload_mb ?: 50) * 1024;
    }
}
