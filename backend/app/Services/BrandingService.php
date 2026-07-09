<?php

namespace App\Services;

use App\Models\BrandingSetting;
use App\Models\Organization;
use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Storage;

class BrandingService
{
    /**
     * Load branding for the given organization ID — unless white-label
     * branding is disabled platform-wide, in which case every organization
     * falls back to SureSign's own default branding (accent colour, name,
     * logo) via the null-coalescing defaults already used by every helper
     * below.
     */
    public static function forOrganization(int $organizationId): ?BrandingSetting
    {
        if (! SuresignSetting::instance()->feature_white_label) {
            return null;
        }

        return Organization::with('branding')->find($organizationId)?->branding;
    }

    /**
     * Absolute filesystem path for the letterhead header image, or null if not set.
     * Uses the public disk.
     */
    public static function headerPath(?BrandingSetting $branding): ?string
    {
        if (!$branding?->header_template_path) {
            return null;
        }
        $path = Storage::disk('public')->path($branding->header_template_path);
        return file_exists($path) ? $path : null;
    }

    /**
     * Absolute filesystem path for the letterhead footer image, or null if not set.
     */
    public static function footerPath(?BrandingSetting $branding): ?string
    {
        if (!$branding?->footer_template_path) {
            return null;
        }
        $path = Storage::disk('public')->path($branding->footer_template_path);
        return file_exists($path) ? $path : null;
    }

    /**
     * Absolute filesystem path for the company logo, or null if not set.
     */
    public static function logoPath(?BrandingSetting $branding): ?string
    {
        if (!$branding?->logo_path) {
            return null;
        }
        $path = Storage::disk('public')->path($branding->logo_path);
        return file_exists($path) ? $path : null;
    }

    /**
     * file:// URI for the logo, suitable for use in DomPDF <img> src attributes.
     * Returns null if no logo is configured or the file is missing.
     */
    public static function logoFileUri(?BrandingSetting $branding): ?string
    {
        $path = static::logoPath($branding);
        return $path ? 'file://' . $path : null;
    }

    /**
     * Resolve the accent colour, falling back to SureSign default gold.
     */
    public static function accentColour(?BrandingSetting $branding): string
    {
        return $branding?->accent_color ?: '#b99566';
    }

    /**
     * Company display name from branding, falling back to the organization name.
     */
    public static function displayName(?BrandingSetting $branding, ?Organization $organization = null): string
    {
        return $branding?->company_display_name
            ?: $organization?->name
            ?: 'SureSign';
    }
}
