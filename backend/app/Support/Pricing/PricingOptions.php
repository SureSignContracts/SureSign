<?php

namespace App\Support\Pricing;

/**
 * Fixed, validated option sets for pricing plan styling — kept as an
 * allow-list rather than free-text/hex input so Super Admin can't ship a
 * value that doesn't exist in the marketing site's design tokens.
 */
class PricingOptions
{
    public const BADGE_COLORS = ['gold', 'accent', 'success', 'neutral', 'danger'];

    public const ACCENT_COLORS = ['gold', 'accent', 'neutral', 'success'];

    public const BACKGROUND_STYLES = ['solid', 'surface', 'gradient', 'elevated'];

    public const ICONS = [
        'zap', 'shield', 'star', 'rocket', 'building', 'users',
        'layers', 'check-circle', 'crown', 'sparkles', 'briefcase', 'award',
    ];

    public const PLAN_FEATURE_STATUSES = ['included', 'not_included', 'limited', 'custom'];

    public const PLAN_STATUSES = ['draft', 'active', 'archived'];
}
