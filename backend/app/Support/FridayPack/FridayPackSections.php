<?php

namespace App\Support\FridayPack;

/**
 * Automated Friday Pack — the authoritative, code-defined catalogue of
 * sections a Friday Pack may include. Mirrors
 * App\Support\FeatureAvailability\FeatureAvailabilityRegistry's shape
 * (static code registry = catalogue; a project's own included_sections is
 * the mutable subset). A client may never submit an arbitrary JSON key —
 * every `included_sections` write is validated against ALL here.
 *
 * Deliberately flat, not weighted/ordered by anything beyond this array's
 * own order (also the recommended PDF/detail-page render order) — no
 * per-section configuration exists yet beyond on/off.
 *
 * **R1A — Friday Pack Realignment (2026-08-31).** This registry was
 * realigned from V1B's original 15 management-report-style keys
 * (executive_summary, progress, programme, toolbox_talks, site_reports,
 * risks, rfis, variations, commercial, delays_eot, meetings_actions,
 * delivery_documents, drawings, qa_snagging, upcoming_actions) to the
 * authentic construction Friday Pack structure below, based on a real
 * business-provided Friday Pack sample. The Friday Pack is now a weekly
 * SITE/WORKFORCE/H&S/COMPLIANCE document, not a cross-module management
 * report — see CLAUDE.md's AI Workflow Context / project-context.md for
 * the full realignment rationale.
 *
 * The old management-report keys are deliberately NOT retained here (a
 * client may no longer select them) — their underlying collector logic in
 * App\Services\FridayPack\FridayPackSnapshotService is preserved,
 * unmodified, and simply no longer called from the active Friday Pack
 * path. That logic is a candidate for a future, separate Weekly Project
 * Report / Client Weekly Report — see that class's own docblock.
 *
 * Only `toolbox_talks` carries over unchanged (it was never a
 * management-report section — it already reused Toolbox Talks V1A, the
 * authentic module the business reference itself calls out under
 * "4.2 Toolbox Talks / Briefings").
 *
 * As of R1A, only TOOLBOX_TALKS has a real collector in
 * FridayPackSnapshotService — every other key below currently collects as
 * an honest empty stub (`['count' => 0, 'items' => []]`) until its own
 * dedicated content-implementation phase lands (see project-context.md's
 * R1A entry for the full per-section status). This is a deliberate,
 * incremental content build-out, not a regression — no section here
 * fabricates data it doesn't have.
 */
final class FridayPackSections
{
    public const REPORT_INFORMATION  = 'report_information';
    public const WEEKLY_SUMMARY      = 'weekly_summary';
    public const WORKFORCE           = 'workforce';
    public const SITE_PHOTOGRAPHS    = 'site_photographs';
    public const RAMS                = 'rams';
    public const TOOLBOX_TALKS       = 'toolbox_talks';
    public const SITE_INDUCTIONS     = 'site_inductions';
    public const INCIDENTS           = 'incidents';
    public const HS_INSPECTIONS      = 'hs_inspections';
    public const PERMITS_INSPECTIONS = 'permits_inspections';
    public const PLANT_EQUIPMENT     = 'plant_equipment';
    public const MATERIALS_DELIVERED = 'materials_delivered';
    public const SITE_ISSUES         = 'site_issues';
    public const LOOK_AHEAD          = 'look_ahead';
    public const SIGN_OFF            = 'sign_off';

    public const ALL = [
        self::REPORT_INFORMATION,
        self::WEEKLY_SUMMARY,
        self::WORKFORCE,
        self::SITE_PHOTOGRAPHS,
        self::RAMS,
        self::TOOLBOX_TALKS,
        self::SITE_INDUCTIONS,
        self::INCIDENTS,
        self::HS_INSPECTIONS,
        self::PERMITS_INSPECTIONS,
        self::PLANT_EQUIPMENT,
        self::MATERIALS_DELIVERED,
        self::SITE_ISSUES,
        self::LOOK_AHEAD,
        self::SIGN_OFF,
    ];

    /**
     * CORE — recommended as always-on for every project (required for the
     * document to read as a genuine, complete Friday Pack). Not yet
     * enforced anywhere (no "core sections can't be disabled" validation
     * exists in R1A) — this is a classification for a future applicability
     * phase, returned here only as a documented recommendation.
     */
    public const CORE = [
        self::REPORT_INFORMATION,
        self::WEEKLY_SUMMARY,
        self::WORKFORCE,
        self::SITE_PHOTOGRAPHS,
        self::TOOLBOX_TALKS,
        self::SITE_ISSUES,
        self::LOOK_AHEAD,
        self::SIGN_OFF,
    ];

    /**
     * OPTIONAL / PROJECT-APPLICABLE — recommended as toggleable, since
     * whether a project has RAMS, inductions, incidents, H&S inspections,
     * permits, plant, or logged material deliveries genuinely varies by
     * project/trade. Not yet enforced — see CORE's docblock.
     */
    public const OPTIONAL = [
        self::RAMS,
        self::SITE_INDUCTIONS,
        self::INCIDENTS,
        self::HS_INSPECTIONS,
        self::PERMITS_INSPECTIONS,
        self::PLANT_EQUIPMENT,
        self::MATERIALS_DELIVERED,
    ];

    /**
     * V1 default: every section enabled — a comprehensive pack by default
     * that a project can trim, rather than an empty pack a project has to
     * build up. Matches how every module already available to a project
     * (Site Reports, QA, Snagging, etc.) ships visible/usable by default,
     * not opt-in.
     */
    public static function defaults(): array
    {
        return self::ALL;
    }

    /**
     * Validates a candidate included_sections array against the registry —
     * every value must be a known key; unknown keys are rejected outright
     * rather than silently dropped, so a caller gets a clear validation
     * error instead of a section quietly vanishing.
     */
    public static function isValidSet(array $sections): bool
    {
        foreach ($sections as $section) {
            if (!is_string($section) || !in_array($section, self::ALL, true)) {
                return false;
            }
        }

        return true;
    }
}
