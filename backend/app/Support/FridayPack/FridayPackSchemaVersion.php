<?php

namespace App\Support\FridayPack;

/**
 * Automated Friday Pack, R1A.1 — Snapshot Schema Version Boundary
 * (2026-08-31). Mirrors App\Support\AI\AiTelemetrySchema's exact
 * "one authoritative CURRENT_VERSION constant" pattern.
 *
 * `snapshot_json['schema_version']` is a value INSIDE the JSON blob
 * itself (there is no separate `friday_packs.schema_version` database
 * column) — this class is the single place that value is defined and
 * compared against, so no caller ever hardcodes the literal `1` or `2`.
 *
 * LEGACY (1): the original V1B management-report snapshot contract
 * (executive_summary/progress/programme/toolbox_talks/site_reports/
 * risks/rfis/variations/commercial/delays_eot/meetings_actions/
 * delivery_documents/drawings/qa_snagging/upcoming_actions). Frozen —
 * an existing schema-1 row's meaning never changes, and no new schema-1
 * snapshot is ever written after R1A.1.
 *
 * CURRENT (2): the R1A-realigned authentic construction Friday Pack
 * contract (report_information/weekly_summary/workforce/
 * site_photographs/rams/toolbox_talks/site_inductions/incidents/
 * hs_inspections/permits_inspections/plant_equipment/
 * materials_delivered/site_issues/look_ahead/sign_off — see
 * App\Support\FridayPack\FridayPackSections). Every section collector
 * for this contract is not yet fully built (see
 * FridayPackSnapshotService's own docblock) — CURRENT_VERSION already
 * moved to 2 regardless, because the CONTRACT (its shape) changed at
 * R1A, not because every section within it is content-complete yet.
 *
 * `FridayPackPdfService` uses this to explicitly refuse PDF generation
 * for anything other than LEGACY_VERSION until a schema-2-aware PDF
 * template exists (see FridayPackUnsupportedSchemaVersionException) —
 * never a silent pass-through of schema 2 content into the schema-1-only
 * `pdfs.friday-pack` Blade template.
 */
final class FridayPackSchemaVersion
{
    public const LEGACY_VERSION = 1;
    public const CURRENT_VERSION = 2;

    public static function isLegacy(?int $version): bool
    {
        return $version === self::LEGACY_VERSION;
    }

    public static function isCurrent(?int $version): bool
    {
        return $version === self::CURRENT_VERSION;
    }
}
