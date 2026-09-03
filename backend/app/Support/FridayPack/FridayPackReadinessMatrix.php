<?php

namespace App\Support\FridayPack;

use App\Models\FridayPackSectionDeclaration;

/**
 * Friday Pack Realignment, R1F.2 — the ONE authoritative, code-defined
 * readiness rule catalogue, transcribed exactly from the approved R1F.1
 * design (project-context.md's R1F.1 entry — its own section-by-section
 * matrix is the canonical source of truth this class implements
 * verbatim, never re-audited or re-decided here). Consulted by BOTH
 * `App\Services\FridayPack\FridayPackReadinessService` (evaluation) and
 * `App\Services\FridayPack\FridayPackSectionDeclarationService`
 * (mutation validation) — the smallest safe shared helper, deliberately
 * NOT a generic rules DSL (the rules are domain-specific, per section).
 *
 * `TYPE_TEXT` — completeness is a non-empty (post-trim) confirmed manual
 * text field on the FridayPack itself; no declaration shortcut, no
 * source-row check.
 *
 * `TYPE_SOURCE` — completeness is "at least one real source row/item exists
 * in the frozen snapshot for this section," OR an effective declaration.
 *
 * `TYPE_TEXT_OR_CONFIRMED_NONE` — Site Issues' own hybrid rule: a
 * non-empty confirmed summary OR an explicit confirmed_none declaration
 * satisfies it; raw source rows (DelayEvent references) never bypass the
 * summary requirement on their own.
 *
 * `TYPE_ALWAYS` — Report Information: every genuinely required field is
 * unconditionally populated by the generation pipeline itself (see
 * `FridayPackGenerationService::applySnapshot()`), so this section can
 * never actually be incomplete — no check is meaningful here.
 *
 * `TYPE_EXCLUDED` — Sign Off: never evaluated for content readiness at
 * all (would be circular — see this class's own docblock precedent in
 * project-context.md's R1F.1 entry).
 *
 * `subsections` — ONLY `permits_inspections` has real subsections
 * (`permits`/`inspections`, matching the snapshot's own two array keys
 * exactly) — every other section's declarations always use the empty
 * string sentinel `''` (see FridayPackSectionDeclaration's own
 * docblock). A section with `subsections` defined has NO top-level
 * declaration slot of its own — only its children may be declared.
 */
final class FridayPackReadinessMatrix
{
    public const TYPE_TEXT = 'text';
    public const TYPE_SOURCE = 'source';
    public const TYPE_TEXT_OR_CONFIRMED_NONE = 'text_or_confirmed_none';
    public const TYPE_ALWAYS = 'always';
    public const TYPE_EXCLUDED = 'excluded';

    /** No top-level declaration — only real subsections use a non-empty subsection_key. */
    public const TOP_LEVEL = '';

    private static array $rules;

    public static function all(): array
    {
        return self::$rules ??= [
            FridayPackSections::REPORT_INFORMATION => [
                'label' => 'Report Information',
                'type' => self::TYPE_ALWAYS,
            ],
            FridayPackSections::WEEKLY_SUMMARY => [
                'label' => 'Weekly Summary',
                'type' => self::TYPE_TEXT,
                'text_reason' => 'Weekly summary text is required.',
            ],
            FridayPackSections::WORKFORCE => [
                'label' => 'Workforce on Site',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => false,
                'confirmed_none_statement' => 'No workforce was recorded on site during this reporting period.',
                'missing_reason' => 'Add workforce records or confirm none were on site.',
            ],
            FridayPackSections::SITE_PHOTOGRAPHS => [
                'label' => 'Site Photographs',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No suitable site photographs are available for this reporting period.',
                'not_applicable_statement' => 'Site photography not applicable for this reporting period.',
                'missing_reason' => 'Select evidence or confirm no photographs are available.',
            ],
            FridayPackSections::RAMS => [
                'label' => 'RAMS',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No RAMS records were recorded for this reporting period.',
                'not_applicable_statement' => 'No RAMS were applicable to this reporting period.',
                'missing_reason' => 'Add RAMS records, confirm none, or mark not applicable.',
            ],
            FridayPackSections::TOOLBOX_TALKS => [
                'label' => 'Toolbox Talks / Briefings',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No toolbox talks were delivered during this reporting period.',
                'not_applicable_statement' => 'Toolbox talks were not applicable to this reporting period.',
                'missing_reason' => 'Add toolbox talk records, confirm none, or mark not applicable.',
            ],
            FridayPackSections::SITE_INDUCTIONS => [
                'label' => 'Site Inductions',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No site inductions were required during this reporting period.',
                'not_applicable_statement' => 'Site inductions were not applicable to this reporting period.',
                'missing_reason' => 'Add induction records, confirm none, or mark not applicable.',
            ],
            FridayPackSections::INCIDENTS => [
                'label' => 'Accidents / Incidents / Near Misses',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => false,
                'confirmed_none_statement' => 'No incidents to report for this reporting period.',
                'missing_reason' => 'Add incident records or confirm none to report.',
            ],
            FridayPackSections::HS_INSPECTIONS => [
                'label' => 'H&S Inspections',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No H&S inspection was undertaken during this reporting period.',
                'not_applicable_statement' => 'H&S inspection was not applicable to this reporting period.',
                'missing_reason' => 'Add inspection records, confirm none, or mark not applicable.',
            ],
            FridayPackSections::PERMITS_INSPECTIONS => [
                'label' => 'Permits / Statutory Inspections',
                // No top-level declaration slot — independent children only.
                'subsections' => [
                    'permits' => [
                        'label' => 'Permits',
                        'type' => self::TYPE_SOURCE,
                        'allow_confirmed_none' => true,
                        'allow_not_applicable' => true,
                        'confirmed_none_statement' => 'No permit records were recorded for this reporting period.',
                        'not_applicable_statement' => 'No permit requirement applied during this reporting period.',
                        'missing_reason' => 'Add permit records, confirm none, or mark not applicable.',
                    ],
                    'inspections' => [
                        'label' => 'Statutory Inspections',
                        'type' => self::TYPE_SOURCE,
                        'allow_confirmed_none' => true,
                        'allow_not_applicable' => true,
                        'confirmed_none_statement' => 'No statutory inspection was undertaken during this reporting period.',
                        'not_applicable_statement' => 'Statutory inspection was not applicable to this reporting period.',
                        'missing_reason' => 'Add statutory inspection records, confirm none, or mark not applicable.',
                    ],
                ],
            ],
            FridayPackSections::PLANT_EQUIPMENT => [
                'label' => 'Plant & Equipment',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No plant or equipment was on site during this reporting period.',
                'not_applicable_statement' => 'Plant and equipment were not applicable to this reporting period.',
                'missing_reason' => 'Add plant/equipment site presence, confirm none, or mark not applicable.',
            ],
            FridayPackSections::MATERIALS_DELIVERED => [
                'label' => 'Materials Delivered',
                'type' => self::TYPE_SOURCE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => true,
                'confirmed_none_statement' => 'No materials were delivered during this reporting period.',
                'not_applicable_statement' => 'Materials deliveries were not applicable to this reporting period.',
                'missing_reason' => 'Add material delivery records, confirm none, or mark not applicable.',
            ],
            FridayPackSections::SITE_ISSUES => [
                'label' => 'Site Issues, Delays & Risks',
                'type' => self::TYPE_TEXT_OR_CONFIRMED_NONE,
                'allow_confirmed_none' => true,
                'allow_not_applicable' => false,
                'confirmed_none_statement' => 'No site issues, delays, or risks to report for this reporting period.',
                'text_reason' => 'Add a site issues summary or confirm none to report.',
            ],
            FridayPackSections::LOOK_AHEAD => [
                'label' => 'Look Ahead',
                'type' => self::TYPE_TEXT,
                'text_reason' => 'Look Ahead text is required.',
            ],
            FridayPackSections::SIGN_OFF => [
                'label' => 'Sign Off',
                'type' => self::TYPE_EXCLUDED,
            ],
        ];
    }

    public static function rule(string $sectionKey): ?array
    {
        return self::all()[$sectionKey] ?? null;
    }

    /** The evaluable leaf rule for a (section, subsection) pair — the section's own rule, or one of its subsection rules. Null when invalid. */
    public static function leaf(string $sectionKey, string $subsectionKey): ?array
    {
        $rule = self::rule($sectionKey);
        if ($rule === null) {
            return null;
        }

        if (isset($rule['subsections'])) {
            return $rule['subsections'][$subsectionKey] ?? null;
        }

        return $subsectionKey === self::TOP_LEVEL ? $rule : null;
    }

    public static function isValidSectionKey(string $sectionKey): bool
    {
        return self::rule($sectionKey) !== null;
    }

    public static function isValidSubsectionKey(string $sectionKey, string $subsectionKey): bool
    {
        return self::leaf($sectionKey, $subsectionKey) !== null;
    }

    public static function isDeclarationAllowed(string $sectionKey, string $subsectionKey, string $declaration): bool
    {
        $leaf = self::leaf($sectionKey, $subsectionKey);
        if ($leaf === null) {
            return false;
        }

        return match ($declaration) {
            FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE => (bool) ($leaf['allow_confirmed_none'] ?? false),
            FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE => (bool) ($leaf['allow_not_applicable'] ?? false),
            default => false,
        };
    }

    public static function statementFor(string $sectionKey, string $subsectionKey, string $declaration): ?string
    {
        $leaf = self::leaf($sectionKey, $subsectionKey);
        if ($leaf === null) {
            return null;
        }

        return match ($declaration) {
            FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE => $leaf['confirmed_none_statement'] ?? null,
            FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE => $leaf['not_applicable_statement'] ?? null,
            default => null,
        };
    }
}
