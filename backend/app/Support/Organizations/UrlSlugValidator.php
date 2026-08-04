<?php

namespace App\Support\Organizations;

/**
 * Organisation URL Branding, Phase 1 — the single source of truth for what
 * makes a valid `organizations.url_slug` (a DNS label, not a display slug —
 * see the creating migration's docblock for why this is a separate column
 * from the existing `organizations.slug`).
 *
 * Both App\Http\Requests\UpdateOrganizationUrlSlugRequest (the real
 * enforcement point) and any future caller needing to check a candidate
 * slug without a full HTTP request cycle should go through this class —
 * never duplicate the regex/reserved-list logic elsewhere.
 */
class UrlSlugValidator
{
    public const MIN_LENGTH = 2;
    public const MAX_LENGTH = 63;

    /**
     * Lowercase letters/digits, optionally hyphen-separated; must start and
     * end with a letter or digit; no consecutive hyphens.
     */
    private const PATTERN = '/^[a-z0-9](?:[a-z0-9]|-(?!-))*[a-z0-9]$|^[a-z0-9]$/';

    /**
     * Lowercase + trim only — this is the exact normalisation applied before
     * both validation and persistence, so a slug is always compared and
     * stored in the one canonical form (making DB uniqueness effectively
     * case-insensitive without relying on collation).
     */
    public static function normalize(string $raw): string
    {
        return strtolower(trim($raw));
    }

    public static function isReserved(string $normalizedSlug): bool
    {
        return in_array($normalizedSlug, config('organisation_branding.reserved_hostnames', []), true);
    }

    public static function isValidFormat(string $normalizedSlug): bool
    {
        $length = strlen($normalizedSlug);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $normalizedSlug) === 1;
    }

    /**
     * Full validity check (format + reserved list), NOT including database
     * uniqueness — callers with DB access (the Form Request) must still
     * check that separately, case-insensitively against the already-
     * normalised value.
     */
    public static function isValid(string $rawSlug): bool
    {
        $normalized = self::normalize($rawSlug);

        return self::isValidFormat($normalized) && ! self::isReserved($normalized);
    }
}
