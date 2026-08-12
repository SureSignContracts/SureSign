<?php

namespace App\Support\Drawings;

use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\Variation;

/**
 * Drawing Phase 6B — the single allowlist mapping an external, client-
 * supplied short type string (`snag`, `rfi`, `qa_report`, `variation`) to
 * the actual Eloquent model class it may link a DrawingHotspot to. Never
 * accept a model class string directly from a request — every hotspot-link
 * write path must resolve through `modelFor()` here.
 *
 * Deliberately restricted to these four record types (Phase 6 scope) — do
 * not add a case here for any other SureSign record type without a
 * deliberate, separate decision to expand Drawing Hotspot Linking's scope.
 */
final class DrawingLinkableType
{
    public const SNAG = 'snag';

    public const RFI = 'rfi';

    public const QA_REPORT = 'qa_report';

    public const VARIATION = 'variation';

    /** @var array<string, class-string> */
    private const MAP = [
        self::SNAG => Snag::class,
        self::RFI => Rfi::class,
        self::QA_REPORT => QaReport::class,
        self::VARIATION => Variation::class,
    ];

    /** Human-readable labels for the record-type selector (Part U). */
    private const LABELS = [
        self::SNAG => 'Snag',
        self::RFI => 'RFI',
        self::QA_REPORT => 'QA Report',
        self::VARIATION => 'Variation',
    ];

    public const ALL = [self::SNAG, self::RFI, self::QA_REPORT, self::VARIATION];

    /** @return class-string|null */
    public static function modelFor(string $shortType): ?string
    {
        return self::MAP[$shortType] ?? null;
    }

    /** Reverse lookup — the short type string for an already-resolved model class (used for reverse navigation/display). */
    public static function shortTypeFor(string $modelClass): ?string
    {
        return array_search($modelClass, self::MAP, true) ?: null;
    }

    public static function labelFor(string $shortType): string
    {
        return self::LABELS[$shortType] ?? $shortType;
    }

    public static function isValid(string $shortType): bool
    {
        return isset(self::MAP[$shortType]);
    }
}
