<?php

namespace App\Services\FridayPack;

use App\Models\FridayPack;
use App\Models\FridayPackSectionDeclaration;
use App\Models\User;
use App\Services\ProjectActivityService;
use App\Support\FridayPack\FridayPackReadinessMatrix;
use App\Support\FridayPack\FridayPackSchemaVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Friday Pack Realignment, R1F.2 — the ONE authoritative mutation
 * boundary for Friday Pack section declarations (approved architecture:
 * project-context.md's R1F.1 entry). No declaration mutation happens
 * anywhere else — not in the controller, not in the readiness service,
 * not in the regeneration path (which calls
 * `invalidateContradictoryDeclarations()` here, never mutates a
 * declaration row directly itself).
 *
 * Every mutating method validates, in order: (1) schema version 2 —
 * declarations/readiness are a schema-2-only concept, never retrofitted
 * onto schema-1; (2) pack status is `draft` — mirrors
 * `FridayPackController::update()`'s own explicit Draft-only philosophy
 * for manual commentary exactly, never relying on
 * `FridayPackIntegrityGuard` alone (that guard only covers
 * approved/sent); (3) the section is actually included in this pack
 * (`settings_snapshot_json.included_sections`); (4) `section_key` is an
 * authentic key `FridayPackReadinessMatrix` recognises; (5)
 * `subsection_key` is valid for that section (only `permits_inspections`
 * accepts `'permits'`/`'inspections'` — every other section only accepts
 * the top-level sentinel); (6) the requested declaration type is allowed
 * for that specific leaf per the approved matrix. Caller-side
 * authorization (project/organisation membership) is the controller's
 * job, exactly like every other Friday Pack mutation.
 *
 * `declare()` UPSERTS the single current declaration slot in place
 * (never inserts a duplicate row) — re-declaring after invalidation
 * reuses the same `(friday_pack_id, section_key, subsection_key)` slot,
 * always requiring a fresh explicit confirmation (never silently
 * reactivating a stale row). `ActivityLog` is the only change history;
 * this table is never versioned.
 */
class FridayPackSectionDeclarationService
{
    public function declare(FridayPack $pack, User $actor, string $sectionKey, string $subsectionKey, string $declarationType, ?string $note): FridayPackSectionDeclaration
    {
        $this->assertMutable($pack, $sectionKey, $subsectionKey);

        if (!FridayPackReadinessMatrix::isDeclarationAllowed($sectionKey, $subsectionKey, $declarationType)) {
            throw new RuntimeException('This declaration is not available for this section.');
        }

        return DB::transaction(function () use ($pack, $actor, $sectionKey, $subsectionKey, $declarationType, $note) {
            $declaration = FridayPackSectionDeclaration::updateOrCreate(
                ['friday_pack_id' => $pack->id, 'section_key' => $sectionKey, 'subsection_key' => $subsectionKey],
                [
                    'declaration' => $declarationType,
                    'note' => $note,
                    'confirmed_by' => $actor->id,
                    'confirmed_at' => now(),
                    'invalidated_at' => null,
                ],
            );

            $leaf = FridayPackReadinessMatrix::leaf($sectionKey, $subsectionKey);
            ProjectActivityService::record(
                $pack->project ?? \App\Models\Project::findOrFail($pack->project_id),
                $actor,
                'friday_pack_section_declared',
                "{$leaf['label']} declared \"{$declarationType}\" for Friday Pack week ending " . $pack->week_ending->toDateString(),
                null,
                $declaration,
            );

            return $declaration;
        });
    }

    public function clear(FridayPack $pack, User $actor, string $sectionKey, string $subsectionKey): void
    {
        $this->assertMutable($pack, $sectionKey, $subsectionKey);

        DB::transaction(function () use ($pack, $actor, $sectionKey, $subsectionKey) {
            $declaration = FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)
                ->where('section_key', $sectionKey)
                ->where('subsection_key', $subsectionKey)
                ->first();

            if ($declaration === null) {
                return;
            }

            $leaf = FridayPackReadinessMatrix::leaf($sectionKey, $subsectionKey);
            $declaration->delete();

            ProjectActivityService::record(
                $pack->project ?? \App\Models\Project::findOrFail($pack->project_id),
                $actor,
                'friday_pack_section_declaration_cleared',
                "{$leaf['label']} declaration cleared for Friday Pack week ending " . $pack->week_ending->toDateString(),
                null,
                $pack,
            );
        });
    }

    /**
     * Called ONLY from `FridayPackGenerationService::applySnapshot()`,
     * immediately after the regenerated `snapshot_json` has been
     * successfully persisted (never before — an invalidation must never
     * be based on partially-built source data), inside the SAME
     * transaction. `$actor` is always the REAL user who triggered the
     * regeneration — `applySnapshot()` never runs without one (the
     * scheduler's own create-only path never calls this method at all,
     * since it never regenerates an existing pack). No fabricated
     * system/automation user is ever used.
     */
    public function invalidateContradictoryDeclarations(FridayPack $pack, User $actor): void
    {
        $declarations = FridayPackSectionDeclaration::where('friday_pack_id', $pack->id)
            ->whereNull('invalidated_at')
            ->get();

        if ($declarations->isEmpty()) {
            return;
        }

        $sections = $pack->snapshot_json['sections'] ?? [];

        foreach ($declarations as $declaration) {
            if ($this->stillEmpty($pack, $sections, $declaration->section_key, $declaration->subsection_key)) {
                continue;
            }

            $leaf = FridayPackReadinessMatrix::leaf($declaration->section_key, $declaration->subsection_key);
            $declaration->update(['invalidated_at' => now()]);

            ProjectActivityService::record(
                $pack->project ?? \App\Models\Project::findOrFail($pack->project_id),
                $actor,
                'friday_pack_section_declaration_invalidated',
                ($leaf['label'] ?? $declaration->section_key) . ' declaration no longer applies — new records were found for Friday Pack week ending ' . $pack->week_ending->toDateString(),
                null,
                $declaration,
            );
        }
    }

    /**
     * Mirrors FridayPackReadinessService's own completeness checks
     * exactly — never a second definition of "is this section still
     * empty." `site_issues` is TYPE_TEXT_OR_CONFIRMED_NONE — its
     * declaration is contradicted only by the user writing a real
     * confirmed summary (`FridayPack::$site_issues_summary`), never by
     * raw DelayEvent/diary source rows alone (those never satisfy
     * readiness on their own either — see the matrix).
     */
    private function stillEmpty(FridayPack $pack, array $sections, string $sectionKey, string $subsectionKey): bool
    {
        if ($sectionKey === \App\Support\FridayPack\FridayPackSections::SITE_ISSUES) {
            $text = $pack->site_issues_summary;

            return !(is_string($text) && trim($text) !== '');
        }

        $section = $sections[$sectionKey] ?? null;
        if ($section === null) {
            return true;
        }

        $hasData = match ($sectionKey) {
            \App\Support\FridayPack\FridayPackSections::WORKFORCE => collect($section['days'] ?? [])
                ->contains(fn (array $day) => ($day['source_count'] ?? 0) > 0),
            \App\Support\FridayPack\FridayPackSections::SITE_PHOTOGRAPHS => (int) ($section['count'] ?? 0) > 0,
            \App\Support\FridayPack\FridayPackSections::PERMITS_INSPECTIONS => $subsectionKey === 'permits'
                ? count($section['permits'] ?? []) > 0
                : count($section['inspections'] ?? []) > 0,
            default => count($section['items'] ?? []) > 0,
        };

        return !$hasData;
    }

    private function assertMutable(FridayPack $pack, string $sectionKey, string $subsectionKey): void
    {
        if ((int) ($pack->snapshot_json['schema_version'] ?? 1) !== FridayPackSchemaVersion::CURRENT_VERSION) {
            throw new RuntimeException('Declarations are only available for the current Friday Pack report format.');
        }

        if ($pack->status !== 'draft') {
            throw new RuntimeException('Declarations can only be made while the Friday Pack is a draft.');
        }

        if (!FridayPackReadinessMatrix::isValidSectionKey($sectionKey)) {
            throw new RuntimeException('Unknown Friday Pack section.');
        }

        $includedSections = $pack->settings_snapshot_json['included_sections'] ?? [];
        if (!in_array($sectionKey, $includedSections, true)) {
            throw new RuntimeException('This section is not included in this Friday Pack.');
        }

        if (!FridayPackReadinessMatrix::isValidSubsectionKey($sectionKey, $subsectionKey)) {
            throw new RuntimeException('This section does not accept a declaration here.');
        }
    }
}
