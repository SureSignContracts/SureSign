<?php

namespace App\Services\Intelligence;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\Subscription;

/**
 * Phase G3, Stage 9 — the commercial activity timeline. No new tracking
 * table: `SubscriptionLifecycleService` already logs every real transition
 * (trial started, activated, renewed, plan changed, suspended, cancelled,
 * reactivated, etc. — see its private `log()` helper) to `ActivityLog`
 * with `subject_type = Subscription::class` and `organization_id` set.
 * This service only ever reads that existing, authoritative record —
 * zero duplicated calculation, per Stage 1's instruction.
 */
class SubscriptionTimelineService
{
    public function timelineForOrganization(Organization $organization, int $limit = 25): array
    {
        return ActivityLog::query()
            ->where('organization_id', $organization->id)
            ->where('subject_type', Subscription::class)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'action', 'description', 'created_at'])
            ->map(fn (ActivityLog $entry) => [
                'action' => $entry->action,
                'description' => $entry->description,
                'occurred_at' => $entry->created_at,
            ])
            ->values()
            ->all();
    }
}
