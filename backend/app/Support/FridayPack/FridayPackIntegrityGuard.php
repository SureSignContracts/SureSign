<?php

namespace App\Support\FridayPack;

use Illuminate\Database\Eloquent\Model;

/**
 * Automated Friday Pack, V1B — mirrors
 * App\Support\AI\AiTelemetryIntegrityGuard's exact pattern (this
 * codebase's established "once a record reaches a terminal state, treat a
 * named list of fields as immutable historical evidence" convention).
 *
 * V1B does not yet expose the actions that would move a pack to
 * `approved`/`sent` (Review/Approve/Send land in a later phase) — this
 * guard exists now, ahead of that, so the rule is enforced the moment
 * those actions do land, rather than being retrofitted then. A `draft`
 * pack remains fully editable/regeneratable; a `failed` pack is NOT
 * protected by this guard (only `approved`/`sent` are terminal here, per
 * the V1B spec) — a failed generation attempt should remain correctable.
 */
class FridayPackIntegrityGuard
{
    private const TERMINAL_STATUSES = ['approved', 'sent'];

    /**
     * Once approved/sent, a Friday Pack's frozen report content — the
     * snapshot itself, the exact period it covers, and the manual
     * commentary that was reviewed alongside it — must never silently
     * change. Workflow/lifecycle fields (status, reviewed_at/by,
     * approved_at/by, sent_at/by, pdf_document_id, failure_reason) are
     * deliberately NOT protected — those are expected to keep changing as
     * the pack moves through later lifecycle phases.
     */
    public const PROTECTED_FIELDS = [
        'snapshot_json',
        'settings_snapshot_json',
        'week_ending',
        'period_start',
        'period_end',
        'executive_summary',
        'progress_commentary',
        'key_concerns',
        'next_week_priorities',
        'weekly_summary',
        'site_issues_summary',
        'look_ahead',
        'report_number',
    ];

    /**
     * Call from FridayPack's `updating` event. Throws
     * FridayPackImmutableException if the model's status was already
     * terminal before this save AND a protected field is being changed. A
     * no-op for a fresh transition into approved/sent (original status was
     * still draft/ready_for_review/failed) or for any change that doesn't
     * touch a protected field.
     */
    public static function assertMutable(Model $model): void
    {
        $originalStatus = $model->getOriginal('status');

        if (!in_array($originalStatus, self::TERMINAL_STATUSES, true)) {
            return;
        }

        foreach (self::PROTECTED_FIELDS as $field) {
            if ($model->isDirty($field)) {
                throw new FridayPackImmutableException($model, $field);
            }
        }
    }
}
