<?php

namespace App\Services;

use App\Models\CalendarEvent;

/**
 * Formats operational intelligence into a prioritised action list for the Dashboard.
 *
 * Consumers get back an array of action items, each shaped consistently:
 * [
 *   category       => string,   // CalendarEvent::CATEGORY_*
 *   title          => string,
 *   priority       => string,   // CalendarEvent::PRIORITY_*
 *   due_date       => string,   // Y-m-d
 *   days_remaining => int,      // negative = overdue
 *   source_type    => string,
 *   source_id      => int,
 *   contract_id    => int|null,
 *   status         => string,
 *   meta           => array,
 * ]
 */
class UpcomingActionsService
{
    // How many days ahead to include in the default "upcoming" window
    public const DEFAULT_WINDOW_DAYS = 30;

    public function __construct(private OperationalIntelligenceService $intelligence) {}

    /**
     * All upcoming + overdue actions for a project, sorted by urgency.
     */
    public function getActionsForProject(int $projectId, int $days = self::DEFAULT_WINDOW_DAYS, ?int $contractId = null): array
    {
        $overdue  = $this->intelligence->getOverdue($projectId, $contractId);
        $dueToday = $this->intelligence->getDueToday($projectId, $contractId);
        $upcoming = $this->intelligence->getUpcoming($projectId, $days, $contractId)
            ->filter(fn($i) => $i['status'] === 'upcoming'); // exclude due_today (already captured)

        return collect()
            ->concat($overdue)
            ->concat($dueToday)
            ->concat($upcoming)
            ->unique(fn($i) => "{$i['source_type']}:{$i['source_id']}:{$i['source_field']}")
            ->map(fn($i) => $this->formatAction($i))
            ->sortBy(fn($a) => [$this->priorityOrder($a['priority']), $a['days_remaining']])
            ->values()
            ->all();
    }

    /**
     * Grouped summary for dashboard widgets.
     * Returns sections: overdue, due_today, upcoming_7, upcoming_30, by_category
     */
    public function getDashboardSummary(int $projectId, ?int $contractId = null): array
    {
        $all = collect($this->getActionsForProject($projectId, 30, $contractId));

        return [
            'overdue'     => $all->where('status', 'overdue')->values()->all(),
            'due_today'   => $all->where('status', 'due_today')->values()->all(),
            'upcoming_7'  => $all->filter(fn($a) => $a['status'] === 'upcoming' && $a['days_remaining'] <= 7)->values()->all(),
            'upcoming_30' => $all->filter(fn($a) => $a['status'] === 'upcoming' && $a['days_remaining'] > 7)->values()->all(),
            'by_category' => $all->groupBy('category')->map(fn($g) => $g->values()->all())->all(),
            'counts'      => [
                'overdue'     => $all->where('status', 'overdue')->count(),
                'due_today'   => $all->where('status', 'due_today')->count(),
                'upcoming_7'  => $all->filter(fn($a) => $a['status'] === 'upcoming' && $a['days_remaining'] <= 7)->count(),
                'critical'    => $all->where('priority', CalendarEvent::PRIORITY_CRITICAL)->count(),
            ],
        ];
    }

    /**
     * Critical actions only (overdue + critical priority) for compact widgets.
     */
    public function getCriticalActions(int $projectId, ?int $contractId = null): array
    {
        return collect($this->getActionsForProject($projectId, 7, $contractId))
            ->filter(fn($a) => in_array($a['priority'], [CalendarEvent::PRIORITY_CRITICAL, CalendarEvent::PRIORITY_HIGH]))
            ->values()
            ->all();
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function formatAction(array $item): array
    {
        return [
            'category'      => $item['category'],
            'title'         => $item['title'],
            'description'   => $item['description'] ?? null,
            'priority'      => $item['priority'],
            'due_date'      => $item['event_date']?->format('Y-m-d'),
            'days_remaining' => $item['days_from_today'],
            'source_type'   => $item['source_type'],
            'source_id'     => $item['source_id'],
            'source_field'  => $item['source_field'],
            'contract_id'   => $item['contract_id'],
            'trade_package_id' => $item['trade_package_id'] ?? null,
            'action_url'    => $item['action_url'] ?? null,
            'project_id'    => $item['project_id'],
            'status'        => $item['status'],
            'meta'          => $item['meta'] ?? [],
        ];
    }

    private function priorityOrder(string $priority): int
    {
        return match ($priority) {
            CalendarEvent::PRIORITY_CRITICAL => 0,
            CalendarEvent::PRIORITY_HIGH     => 1,
            CalendarEvent::PRIORITY_MEDIUM   => 2,
            CalendarEvent::PRIORITY_LOW      => 3,
            default                          => 4,
        };
    }
}
