<?php

namespace App\Support\FeatureAvailability;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The one place a Feature Availability write invalidates
 * FeatureAvailabilityService's cached "all effective overrides" lookup —
 * mirrors App\Support\Organizations\BrandingCacheInvalidator's contract
 * exactly: best-effort, never throws, a cache-invalidation failure must
 * never fail the underlying status update.
 *
 * A single cache key is used (there are only ever a handful of override
 * rows at this scale — see discovery's cache-design note) rather than one
 * key per feature, so a write always invalidates the one true key; no
 * per-feature key drift is possible.
 */
final class FeatureAvailabilityCacheInvalidator
{
    public const CACHE_KEY = 'feature-availability:all';

    public static function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            Log::warning('FeatureAvailabilityCacheInvalidator: failed to invalidate feature availability cache: ' . $e->getMessage());
        }
    }
}
