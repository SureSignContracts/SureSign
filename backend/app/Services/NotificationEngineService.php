<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Central Operational Notification Engine.
 *
 * Consumes UpcomingActionsService (which itself consumes OperationalIntelligenceService)
 * and maps each operational action into a SuresignNotification for the relevant users.
 *
 * This service decides WHAT should be notified and WHEN.
 * It does NOT send emails. It does NOT query payment or deadline tables directly.
 *
 * Idempotency:
 *   A notification is skipped if a non-expired, non-resolved one already exists
 *   for the same (user_id, source_type, source_id, source_field, category).
 *   Dismissed notifications are also respected — they are not re-created.
 *
 * Stale resolution:
 *   Notifications for actions that no longer appear in the current operational
 *   intelligence output are marked as 'resolved' automatically.
 */
class NotificationEngineService
{
    // How far ahead to pull upcoming actions for notification generation
    private const LOOKAHEAD_DAYS = 30;

    // CalendarEvent category → SuresignNotification category
    private const CATEGORY_MAP = [
        'payment'     => SuresignNotification::CATEGORY_PAYMENT,
        'compliance'  => SuresignNotification::CATEGORY_COMPLIANCE,
        'programme'   => SuresignNotification::CATEGORY_PROGRAMME,
        'contract'    => SuresignNotification::CATEGORY_CONTRACT,
        'retention'   => SuresignNotification::CATEGORY_RETENTION,
        'risk'        => SuresignNotification::CATEGORY_RISK,
        'deliverables'=> SuresignNotification::CATEGORY_DELIVERABLE,
        'notices'     => SuresignNotification::CATEGORY_NOTICE,
        'commercial'  => SuresignNotification::CATEGORY_COMMERCIAL,
    ];

    // source_type → action URL suffix
    private const URL_MAP = [
        'payment_application'  => '/commercial',
        'pay_less_notice'      => '/commercial',
        'payment_notice'       => '/commercial',
        'retention_release'    => '/commercial',
        'contract_deadline'    => '/contracts',
        'contract_deliverable' => '/contracts',
        'contract_notice'      => '/contracts',
        'programme_milestone'  => '/programme',
        'final_account'        => '/commercial?tab=final-account',
    ];

    public function __construct(private UpcomingActionsService $upcomingActions) {}

    /**
     * Generate / update notifications for all users in the project's organisation.
     * Returns ['created' => N, 'updated' => N, 'resolved' => N, 'skipped' => N]
     */
    public function generateForProject(int $projectId): array
    {
        $project = Project::find($projectId);
        if (!$project) {
            Log::warning("NotificationEngineService: project {$projectId} not found");
            return ['created' => 0, 'updated' => 0, 'resolved' => 0, 'skipped' => 0];
        }

        $users = User::where('organization_id', $project->organization_id)->get();
        if ($users->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'resolved' => 0, 'skipped' => 0];
        }

        // Pull all actions once — shared across all users
        $actions = $this->upcomingActions->getActionsForProject($projectId, self::LOOKAHEAD_DAYS);

        $stats = ['created' => 0, 'updated' => 0, 'resolved' => 0, 'skipped' => 0];

        foreach ($users as $user) {
            $this->processForUser($user, $project, $actions, $stats);
        }

        return $stats;
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function processForUser(User $user, Project $project, array $actions, array &$stats): void
    {
        // Build the set of active source keys from current operational intelligence
        $activeKeys = collect($actions)->map(fn($a) => $this->sourceKey($a, $this->mapCategory($a['category'])));

        // Resolve stale notifications first
        $this->resolveStaleNotifications($user->id, $project->id, $activeKeys, $stats);

        // Upsert current actions
        foreach ($actions as $action) {
            try {
                $this->upsertNotification($user, $project, $action, $stats);
            } catch (\Throwable $e) {
                Log::warning('NotificationEngineService: upsert failed', [
                    'user_id'     => $user->id,
                    'source_type' => $action['source_type'] ?? 'unknown',
                    'error'       => $e->getMessage(),
                ]);
                $stats['skipped']++;
            }
        }
    }

    private function upsertNotification(User $user, Project $project, array $action, array &$stats): void
    {
        $category  = $this->mapCategory($action['category']);
        $priority  = $this->mapPriority($action);
        $actionUrl = $this->resolveActionUrl($action, $project);

        // Idempotency: skip if an active or dismissed notification already exists
        $existing = SuresignNotification::where('user_id', $user->id)
            ->where('source_type', $action['source_type'])
            ->where('source_id', $action['source_id'])
            ->where('source_field', $action['source_field'])
            ->where('category', $category)
            ->whereNotIn('status', [SuresignNotification::STATUS_RESOLVED, SuresignNotification::STATUS_EXPIRED])
            ->first();

        if ($existing) {
            // Escalate priority if urgency has increased, but only for unread notifications
            if ($existing->status === SuresignNotification::STATUS_UNREAD
                && $this->priorityOrder($priority) < $this->priorityOrder($existing->priority)
            ) {
                $existing->update(['priority' => $priority]);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
            return;
        }

        SuresignNotification::create([
            'user_id'         => $user->id,
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'type'            => 'operational_' . $action['source_type'],
            'category'        => $category,
            'priority'        => $priority,
            'status'          => SuresignNotification::STATUS_UNREAD,
            'title'           => $this->buildTitle($action),
            'message'         => $this->buildMessage($action),
            'source_type'     => $action['source_type'],
            'source_id'       => $action['source_id'],
            'source_field'    => $action['source_field'],
            'action_url'      => $actionUrl,
            'data'            => [
                'action_status'  => $action['status'],
                'days_remaining' => $action['days_remaining'],
                'due_date'       => $action['due_date'],
                'contract_id'    => $action['contract_id'],
                'meta'           => $action['meta'] ?? [],
            ],
            'is_read'         => false,
        ]);

        $stats['created']++;
    }

    /**
     * Mark resolved: notifications for actions no longer in the current intelligence output.
     */
    private function resolveStaleNotifications(int $userId, int $projectId, Collection $activeKeys, array &$stats): void
    {
        SuresignNotification::where('user_id', $userId)
            ->where('project_id', $projectId)
            ->whereNotNull('source_type')
            ->whereNotIn('status', [
                SuresignNotification::STATUS_DISMISSED,
                SuresignNotification::STATUS_RESOLVED,
                SuresignNotification::STATUS_EXPIRED,
            ])
            ->each(function (SuresignNotification $notif) use ($activeKeys, &$stats) {
                $key = $this->sourceKey([
                    'source_type'  => $notif->source_type,
                    'source_id'    => $notif->source_id,
                    'source_field' => $notif->source_field,
                ], $notif->category);

                if (!$activeKeys->contains($key)) {
                    $notif->resolve();
                    $stats['resolved']++;
                }
            });
    }

    // ── Mapping helpers ───────────────────────────────────────────────────────

    private function mapCategory(string $calendarCategory): string
    {
        return self::CATEGORY_MAP[$calendarCategory] ?? SuresignNotification::CATEGORY_GENERAL;
    }

    private function mapPriority(array $action): string
    {
        $category = $action['category'];
        $status   = $action['status'];
        $days     = $action['days_remaining'];

        $isHighStakes = in_array($category, ['payment', 'notices', 'compliance', 'commercial']);

        if ($status === 'overdue' && $isHighStakes) {
            return SuresignNotification::PRIORITY_CRITICAL;
        }
        if ($status === 'overdue') {
            return SuresignNotification::PRIORITY_WARNING;
        }
        if ($status === 'due_today') {
            return SuresignNotification::PRIORITY_WARNING;
        }
        if ($days !== null && $days <= 3) {
            return SuresignNotification::PRIORITY_WARNING;
        }
        if ($days !== null && $days <= 7) {
            return SuresignNotification::PRIORITY_REMINDER;
        }

        return SuresignNotification::PRIORITY_INFO;
    }

    private function resolveActionUrl(array $action, Project $project): string
    {
        $base   = "/app/projects/{$project->id}";
        $suffix = self::URL_MAP[$action['source_type']] ?? '';

        // Final Account deep-links carry the record id so the frontend can
        // auto-expand the matching card, not just land on the tab.
        if ($action['source_type'] === 'final_account') {
            $suffix .= "&fa={$action['source_id']}";
        }

        return $base . $suffix;
    }

    private function buildTitle(array $action): string
    {
        $status = $action['status'];
        $title  = $action['title'];

        return match ($status) {
            'overdue'   => "Overdue: {$title}",
            'due_today' => "Due Today: {$title}",
            default     => $title,
        };
    }

    private function buildMessage(array $action): string
    {
        $days    = $action['days_remaining'];
        $dueDate = $action['due_date'];

        if ($action['status'] === 'overdue') {
            $abs = abs((int) $days);
            return "This action is overdue by {$abs} day" . ($abs !== 1 ? 's' : '') . '.';
        }
        if ($action['status'] === 'due_today') {
            return 'This action is due today.';
        }
        if ($dueDate && $days !== null) {
            return "Due in {$days} day" . ($days !== 1 ? 's' : '') . " ({$dueDate}).";
        }

        return $action['description'] ?? '';
    }

    private function sourceKey(array $action, string $category): string
    {
        return "{$action['source_type']}:{$action['source_id']}:{$action['source_field']}:{$category}";
    }

    private function priorityOrder(?string $priority): int
    {
        return match ($priority) {
            SuresignNotification::PRIORITY_CRITICAL => 0,
            SuresignNotification::PRIORITY_WARNING  => 1,
            SuresignNotification::PRIORITY_REMINDER => 2,
            SuresignNotification::PRIORITY_INFO     => 3,
            default                                  => 4,
        };
    }
}
