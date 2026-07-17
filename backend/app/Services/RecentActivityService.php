<?php

namespace App\Services;

use App\Models\ProjectActivity;
use App\Models\User;

/**
 * Resolves a small, safe snapshot of the authenticated user's own
 * organization's recent project activity for an opt-in support-ticket
 * attachment — this is "recent actions inside SureSign," never server/
 * application logs, stack traces, SQL, or raw request/response payloads.
 *
 * Deliberately sources only from `project_activities`
 * (ProjectActivityService::record()) — the same table the existing
 * client-visible feed (GET /projects/{project}/activities) already exposes
 * to Client users — not `activity_logs` (carries an `ip_address` column and
 * has no existing client-facing precedent) and not
 * TradePackageActivityService (merges two audit tables per trade package;
 * unnecessary complexity for a general "what did I recently do" snapshot).
 * `metadata` is never read from either table, regardless of what it holds.
 */
class RecentActivityService
{
    public const MAX_ENTRIES = 20;

    /**
     * Scoped by organization_id, matching ProjectActivityController's own
     * authorization (Client users see activity across their organization's
     * projects, not a stricter per-user assignment — this codebase has no
     * per-user project membership model to narrow it further).
     */
    public static function recentFor(User $user): array
    {
        return ProjectActivity::where('organization_id', $user->organization_id)
            ->with('project:id,name')
            ->latest()
            ->limit(self::MAX_ENTRIES)
            ->get()
            ->map(fn (ProjectActivity $activity) => [
                'timestamp'   => $activity->created_at?->toIso8601String(),
                'module'      => self::moduleFor($activity->activity_type),
                'action_type' => $activity->activity_type,
                'project'     => $activity->project?->name,
                'route'       => $activity->project_id ? "/app/projects/{$activity->project_id}" : null,
                'description' => $activity->title,
            ])
            ->all();
    }

    private static function moduleFor(string $activityType): string
    {
        $prefix = explode('_', $activityType)[0];

        return match ($prefix) {
            'document', 'contract' => 'Documents',
            'variation'            => 'Variations',
            'payment'              => 'Commercial',
            'trade'                => 'Trade Packages',
            'ai'                   => 'AI Analysis',
            default                => 'General',
        };
    }
}
