<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Friday Pack Realignment, R1F.2 — Content Readiness + Weekly
 * Declarations (approved architecture: project-context.md's R1F.1
 * entry). ONE ROW = the CURRENT declaration slot for a
 * (FridayPack, section, subsection) triple — always overwritten in
 * place on re-declaration, NEVER duplicated and NEVER versioned here;
 * `App\Models\ActivityLog` (via `App\Services\ProjectActivityService`)
 * is the declaration CHANGE HISTORY. No SoftDeletes — a declaration is
 * either the current slot's live state or, once superseded by a new
 * explicit declaration, simply overwritten; "cleared" is represented by
 * deleting the row entirely (see
 * App\Services\FridayPack\FridayPackSectionDeclarationService::clear()),
 * not a tombstone state.
 *
 * `subsection_key` is always a real string — `''` for an ordinary
 * top-level section, `'permits'`/`'inspections'` for the two
 * independent children of the `permits_inspections` section. Never
 * compare against `null` for "no subsection" — always compare against
 * `''`.
 *
 * `invalidated_at` distinguishes an EFFECTIVE declaration (`null`) from
 * a STALE one (set) — see
 * App\Services\FridayPack\FridayPackSectionDeclarationService::invalidateContradictoryDeclarations()
 * for the only place this is set, always inside the SAME transaction as
 * the regeneration that made it stale, always attributed to the real
 * regeneration actor (never a fabricated system user), and never
 * deleting the row.
 */
class FridayPackSectionDeclaration extends Model
{
    protected $table = 'friday_pack_section_declarations';

    public const DECLARATION_CONFIRMED_NONE = 'confirmed_none';
    public const DECLARATION_NOT_APPLICABLE = 'not_applicable';

    public const DECLARATIONS = [
        self::DECLARATION_CONFIRMED_NONE,
        self::DECLARATION_NOT_APPLICABLE,
    ];

    protected $fillable = [
        'friday_pack_id', 'section_key', 'subsection_key',
        'declaration', 'note',
        'confirmed_by', 'confirmed_at', 'invalidated_at',
    ];

    protected $casts = [
        'confirmed_at'   => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function fridayPack()  { return $this->belongsTo(FridayPack::class, 'friday_pack_id'); }
    public function confirmedBy() { return $this->belongsTo(User::class, 'confirmed_by'); }

    /** True only while this declaration has not been superseded by contradictory new source data. */
    public function isEffective(): bool
    {
        return $this->invalidated_at === null;
    }
}
