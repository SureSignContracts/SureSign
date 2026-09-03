<?php

namespace App\Services\FridayPack;

use App\Models\FridayPack;
use App\Models\FridayPackSectionDeclaration;
use App\Support\FridayPack\FridayPackReadinessMatrix;
use App\Support\FridayPack\FridayPackSchemaVersion;
use App\Support\FridayPack\FridayPackSections;
use Illuminate\Support\Collection;

/**
 * Friday Pack Realignment, R1F.2 — the ONE authoritative, read-only
 * readiness evaluator (approved architecture: project-context.md's
 * R1F.1 entry). Never mutates anything, never produces an ActivityLog
 * entry. Evaluated from:
 *
 *  1. the pack's own already-frozen `snapshot_json` for every
 *     SOURCE-typed section (never a live re-query of source tables — the
 *     frozen snapshot IS the report's source state; a user who has
 *     added new source data since generation must regenerate the Draft
 *     to refresh it, exactly like every other Friday Pack content
 *     change);
 *  2. the pack's own LIVE `weekly_summary`/`site_issues_summary`/
 *     `look_ahead` columns for every TEXT-typed section — these are
 *     manual confirmed fields the current architecture stores directly
 *     on `FridayPack` itself, editable independently of regeneration
 *     (see `FridayPackController::update()`), so reading them from the
 *     (possibly stale, pre-edit) frozen snapshot copy would be wrong —
 *     never read `snapshot_json.sections.*.text` for these;
 *  3. the pack's own `settings_snapshot_json.included_sections` (a
 *     section absent from this list is structurally excluded from
 *     evaluation entirely — never a blocker, never a declaration);
 *  4. this pack's own current, EFFECTIVE (`invalidated_at IS NULL`)
 *     `FridayPackSectionDeclaration` rows.
 *
 * Schema-1 packs are never evaluated meaningfully — `evaluate()` returns
 * a vacuous `ready: true`, empty blockers/sections for any pack whose
 * `snapshot_json.schema_version` isn't 2 (defense in depth; the caller,
 * `FridayPackLifecycleService::submitForReview()`, independently skips
 * calling this at all for a schema-1 pack).
 */
class FridayPackReadinessService
{
    public function evaluate(FridayPack $pack): array
    {
        $snapshot = $pack->snapshot_json ?? [];

        if ((int) ($snapshot['schema_version'] ?? 1) !== FridayPackSchemaVersion::CURRENT_VERSION) {
            return ['ready' => true, 'blockers' => [], 'sections' => []];
        }

        $sections = $snapshot['sections'] ?? [];
        $includedSections = $pack->settings_snapshot_json['included_sections'] ?? FridayPackSections::defaults();

        $declarations = $this->effectiveDeclarationsByKey($pack);

        $blockers = [];
        $sectionStates = [];

        foreach (FridayPackReadinessMatrix::all() as $sectionKey => $rule) {
            if (!in_array($sectionKey, $includedSections, true)) {
                continue;
            }
            if (!array_key_exists($sectionKey, $sections)) {
                // Structurally absent from the snapshot (disabled at
                // collection time despite still being nominally
                // included — defensive, should not normally happen).
                continue;
            }

            if (isset($rule['subsections'])) {
                $subsectionStates = [];
                foreach ($rule['subsections'] as $subsectionKey => $leaf) {
                    $result = $this->evaluateLeaf($pack, $sectionKey, $subsectionKey, $leaf, $sections, $declarations);
                    $subsectionStates[$subsectionKey] = $result['state_payload'];
                    if ($result['blocker'] !== null) {
                        $blockers[] = $result['blocker'];
                    }
                }
                $sectionStates[$sectionKey] = ['label' => $rule['label'], 'subsections' => $subsectionStates];
                continue;
            }

            if ($rule['type'] === FridayPackReadinessMatrix::TYPE_EXCLUDED) {
                continue;
            }

            if ($rule['type'] === FridayPackReadinessMatrix::TYPE_ALWAYS) {
                $sectionStates[$sectionKey] = [
                    'state' => $this->reportInformationComplete($sections) ? 'complete' : 'missing',
                    'label' => $rule['label'],
                ];
                continue;
            }

            $result = $this->evaluateLeaf($pack, $sectionKey, FridayPackReadinessMatrix::TOP_LEVEL, $rule, $sections, $declarations);
            $sectionStates[$sectionKey] = $result['state_payload'];
            if ($result['blocker'] !== null) {
                $blockers[] = $result['blocker'];
            }
        }

        return [
            'ready' => count($blockers) === 0,
            'blockers' => $blockers,
            'sections' => $sectionStates,
        ];
    }

    /**
     * @return array{state_payload: array, blocker: ?array}
     */
    private function evaluateLeaf(FridayPack $pack, string $sectionKey, string $subsectionKey, array $leaf, array $sections, Collection $declarations): array
    {
        $declaration = $declarations->get("{$sectionKey}|{$subsectionKey}");

        $isComplete = match ($leaf['type']) {
            FridayPackReadinessMatrix::TYPE_TEXT => $this->hasText($pack, $sectionKey),
            FridayPackReadinessMatrix::TYPE_TEXT_OR_CONFIRMED_NONE => $this->hasText($pack, $sectionKey),
            FridayPackReadinessMatrix::TYPE_SOURCE => $this->hasSourceData($sections, $sectionKey, $subsectionKey),
            default => false,
        };

        if ($isComplete) {
            return [
                'state_payload' => ['state' => 'complete', 'label' => $leaf['label']],
                'blocker' => null,
            ];
        }

        // Source data (or confirmed text) is absent — an EFFECTIVE
        // declaration may satisfy readiness instead. A declaration whose
        // own type is genuinely not allowed for this leaf is never
        // trusted here even if one somehow exists (defensive — the
        // mutation service is the real gate against that).
        if ($declaration !== null && FridayPackReadinessMatrix::isDeclarationAllowed($sectionKey, $subsectionKey, $declaration->declaration)) {
            return [
                'state_payload' => [
                    'state' => $declaration->declaration,
                    'label' => $leaf['label'],
                    'declaration' => $this->presentDeclaration($declaration, $sectionKey, $subsectionKey),
                ],
                'blocker' => null,
            ];
        }

        $reason = $leaf['missing_reason'] ?? $leaf['text_reason'] ?? 'Content required.';

        // Never invented client-side — the frontend must not define its
        // own policy about which declaration buttons to offer (R1F.2's
        // own explicit instruction); this is the exact same
        // FridayPackReadinessMatrix allow-flags the mutation service
        // itself enforces.
        $allowedDeclarations = array_values(array_filter([
            ($leaf['allow_confirmed_none'] ?? false) ? FridayPackSectionDeclaration::DECLARATION_CONFIRMED_NONE : null,
            ($leaf['allow_not_applicable'] ?? false) ? FridayPackSectionDeclaration::DECLARATION_NOT_APPLICABLE : null,
        ]));
        $declarationStatements = [];
        foreach ($allowedDeclarations as $declarationType) {
            $declarationStatements[$declarationType] = FridayPackReadinessMatrix::statementFor($sectionKey, $subsectionKey, $declarationType);
        }

        return [
            'state_payload' => ['state' => 'missing', 'label' => $leaf['label']],
            'blocker' => [
                'section_key' => $sectionKey,
                'subsection_key' => $subsectionKey !== FridayPackReadinessMatrix::TOP_LEVEL ? $subsectionKey : null,
                'label' => $leaf['label'],
                'reason' => $reason,
                'allowed_declarations' => $allowedDeclarations,
                'declaration_statements' => $declarationStatements,
            ],
        ];
    }

    /**
     * Reads the LIVE FridayPack column, never the frozen snapshot's own
     * (possibly stale, pre-edit) `text` copy — see this class's own
     * docblock for why.
     */
    private function hasText(FridayPack $pack, string $sectionKey): bool
    {
        $text = match ($sectionKey) {
            FridayPackSections::WEEKLY_SUMMARY => $pack->weekly_summary,
            FridayPackSections::SITE_ISSUES => $pack->site_issues_summary,
            FridayPackSections::LOOK_AHEAD => $pack->look_ahead,
            default => null,
        };

        return is_string($text) && trim($text) !== '';
    }

    private function hasSourceData(array $sections, string $sectionKey, string $subsectionKey): bool
    {
        $section = $sections[$sectionKey] ?? null;
        if ($section === null) {
            return false;
        }

        return match ($sectionKey) {
            FridayPackSections::WORKFORCE => collect($section['days'] ?? [])
                ->contains(fn (array $day) => ($day['source_count'] ?? 0) > 0),
            FridayPackSections::SITE_PHOTOGRAPHS => (int) ($section['count'] ?? 0) > 0,
            FridayPackSections::PERMITS_INSPECTIONS => $subsectionKey === 'permits'
                ? count($section['permits'] ?? []) > 0
                : count($section['inspections'] ?? []) > 0,
            default => count($section['items'] ?? []) > 0,
        };
    }

    /**
     * Minimum required Report Information fields per the R1F.1 approved
     * matrix — every one of these is unconditionally populated by
     * `FridayPackGenerationService::applySnapshot()`, so this should
     * never actually evaluate false in practice; this is a real,
     * deterministic check of the frozen snapshot contract, not a blind
     * `true`. Honest-null fields (site address parts, principal
     * contractor, sub-contract order no., scope of works) are
     * deliberately NOT checked here — they must never block.
     */
    private function reportInformationComplete(array $sections): bool
    {
        $info = $sections[FridayPackSections::REPORT_INFORMATION] ?? [];

        foreach (['project_name', 'week_commencing', 'week_ending', 'prepared_by', 'prepared_at', 'report_number'] as $field) {
            $value = $info[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    private function effectiveDeclarationsByKey(FridayPack $pack): Collection
    {
        return FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)
            ->whereNull('invalidated_at')
            ->with('confirmedBy:id,name')
            ->get()
            ->keyBy(fn (FridayPackSectionDeclaration $d) => "{$d->section_key}|{$d->subsection_key}");
    }

    /** Never exposes the row id, timestamps beyond confirmed_at, or invalidated_at — a safe, minimal representation. */
    private function presentDeclaration(FridayPackSectionDeclaration $declaration, string $sectionKey, string $subsectionKey): array
    {
        return [
            'declaration' => $declaration->declaration,
            'statement' => FridayPackReadinessMatrix::statementFor($sectionKey, $subsectionKey, $declaration->declaration),
            'note' => $declaration->note,
            'confirmed_by' => $declaration->confirmedBy ? [
                'id' => $declaration->confirmedBy->id,
                'name' => $declaration->confirmedBy->name,
            ] : null,
            'confirmed_at' => optional($declaration->confirmed_at)->toIso8601String(),
        ];
    }
}
