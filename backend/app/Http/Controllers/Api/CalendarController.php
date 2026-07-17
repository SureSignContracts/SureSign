<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\FinalAccount;
use App\Models\PaymentApplication;
use App\Models\Organization;
use App\Models\Project;
use App\Services\OperationalIntelligenceService;
use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    private array $colors = [
        'payment_due'     => '#facc15',
        'final_payment'   => '#4ade80',
        'pay_less_notice' => '#fb923c',
        'payment_notice'  => '#60a5fa',
        'commencement'    => '#a78bfa',
        'completion'      => '#f87171',
        'key_date'        => '#34d399',
        'obligation'      => '#e879f9',
        'milestone'       => '#94a3b8',
        'final_account'   => '#facc15',
        'delay_event'     => '#fb923c',
        'eot_request'     => '#60a5fa',
        'loss_and_expense'=> '#e879f9',
        'retention'       => '#34d399',
    ];

    public function __construct(private OperationalIntelligenceService $intelligence) {}

    public function events(Request $request, Project $project)
    {
        $user = $request->user();

        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin') && $user->organization_id !== $project->organization_id) {
            abort(403);
        }

        // Trade-package-scoped summary (Sprint 6C) — a distinct, self-contained code
        // path rather than threading a conditional through sections A-D below, since
        // OperationalIntelligenceService::getItemsForTradePackage() already returns a
        // complete, correctly-scoped item set (no per-section carve-outs needed).
        if ($request->filled('trade_package_id')) {
            $tradePackage = \App\Models\TradePackage::where('project_id', $project->id)
                ->find($request->query('trade_package_id'));

            if (!$tradePackage) {
                return response()->json(['message' => 'Trade package not found for this project.'], 404);
            }

            return $this->tradePackageEvents($project, $tradePackage);
        }

        $events = [];

        // Computed once and reused by sections A and D below — getItemsForProject()
        // runs every collector internally, so calling it twice would recompute the
        // entire operational-items aggregation redundantly (Sprint 4A Phase 8).
        $operationalItems = $this->intelligence->getItemsForProject($project->id, null);

        // ── A. Contracts — commencement, completion, key dates, key obligations ──
        //
        // Fully covered by OperationalIntelligenceService::collectContractDates()
        // since Sprint 4A — consume it directly instead of re-deriving the same
        // date/title logic a second time.

        $contractTitles = Contract::where('project_id', $project->id)->pluck('title', 'id');

        // Matches NotificationEngineService::URL_MAP's 'contract_deadline'/
        // 'contract_deliverable' suffix — one navigation destination for every
        // contract-sourced calendar entry.
        $contractActionUrl = "/app/projects/{$project->id}/contracts";

        $contractDateItems = $operationalItems->filter(fn($i) => $i['source_type'] === \App\Models\CalendarEvent::SOURCE_CONTRACT);

        foreach ($contractDateItems as $item) {
            $type = match (true) {
                $item['source_field'] === 'commencement_date'          => 'commencement',
                $item['source_field'] === 'completion_date'            => 'completion',
                str_starts_with($item['source_field'], 'key_date_')    => 'key_date',
                str_starts_with($item['source_field'], 'obligation_')  => 'obligation',
                default                                                => 'key_date',
            };

            $events[] = $this->makeEvent(
                "contract-{$item['source_id']}-{$item['source_field']}",
                $item['title'],
                $this->toDateString($item['event_date']),
                $type,
                $item['description'],
                $item['contract_id'],
                $contractTitles[$item['source_id']] ?? null,
                $item['meta'] ?? [],
                $item['category'],
                $item['priority'],
                $item['status'],
                $contractActionUrl
            );
        }

        // ── B. Payment Applications ───────────────────────────────────────────
        //
        // NOT swapped to OperationalIntelligenceService — its collector tracks
        // different fields (payment_notice_deadline/pay_less_notice_deadline)
        // and omits application_date entirely. Swapping would drop/alter events
        // shown here. Left as a direct query per Sprint 4A Phase 4/5 guidance.

        $payApps = PaymentApplication::where('project_id', $project->id)
            ->where('status', '!=', 'cancelled')
            ->with('contract:id,title')
            ->get();

        foreach ($payApps as $app) {
            $contractTitle = $app->contract->title ?? null;
            $paymentActionUrl = \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                $project->id, \App\Models\CalendarEvent::SOURCE_PAYMENT_APPLICATION, $app->id, $app->trade_package_id
            );

            if (!empty($app->application_date)) {
                $days = $this->daysFromToday($app->application_date, $project->organization);
                $events[] = $this->makeEvent(
                    "payapp-{$app->id}-application",
                    "Payment App #{$app->application_number}: {$contractTitle}",
                    $this->toDateString($app->application_date),
                    'payment_due',
                    null,
                    $app->contract_id,
                    $contractTitle,
                    [],
                    \App\Models\CalendarEvent::CATEGORY_PAYMENT,
                    \App\Models\CalendarEvent::computePriority($days, \App\Models\CalendarEvent::CATEGORY_PAYMENT),
                    \App\Models\CalendarEvent::computeStatusFromDays($days),
                    $paymentActionUrl
                );
            }

            if (!empty($app->due_date)) {
                $days = $this->daysFromToday($app->due_date, $project->organization);
                $events[] = $this->makeEvent(
                    "payapp-{$app->id}-due",
                    "Payment Due #{$app->application_number}: {$contractTitle}",
                    $this->toDateString($app->due_date),
                    'payment_due',
                    null,
                    $app->contract_id,
                    $contractTitle,
                    [],
                    \App\Models\CalendarEvent::CATEGORY_PAYMENT,
                    \App\Models\CalendarEvent::computePriority($days, \App\Models\CalendarEvent::CATEGORY_PAYMENT),
                    \App\Models\CalendarEvent::computeStatusFromDays($days),
                    $paymentActionUrl
                );
            }

            if (!empty($app->final_date_for_payment)) {
                $days = $this->daysFromToday($app->final_date_for_payment, $project->organization);
                $events[] = $this->makeEvent(
                    "payapp-{$app->id}-final",
                    "Final Payment #{$app->application_number}: {$contractTitle}",
                    $this->toDateString($app->final_date_for_payment),
                    'final_payment',
                    null,
                    $app->contract_id,
                    $contractTitle,
                    [],
                    \App\Models\CalendarEvent::CATEGORY_PAYMENT,
                    \App\Models\CalendarEvent::computePriority($days, \App\Models\CalendarEvent::CATEGORY_PAYMENT),
                    \App\Models\CalendarEvent::computeStatusFromDays($days),
                    $paymentActionUrl
                );
            }
        }

        // ── C. Programme Milestones ───────────────────────────────────────────
        //
        // NOT swapped to OperationalIntelligenceService — its collectMilestones()
        // deliberately excludes completed milestones (whereNull('actual_date'),
        // built for "still outstanding" operational actions) and prefers
        // forecast_date over actual_date. This section shows the full historical
        // record including completed ones. Swapping would drop completed
        // milestones from the calendar. Left as a direct query per Sprint 4A
        // Phase 4/5 guidance.
        $milestones = ContractProgrammeMilestone::where('project_id', $project->id)
            ->whereNotNull('planned_date')
            ->with('contract:id,title')
            ->get();

        foreach ($milestones as $m) {
            $date = $m->actual_date ?? $m->forecast_date ?? $m->planned_date;
            if (!$date) continue;
            $contractTitle = $m->contract?->title ?? null;
            $milestoneActionUrl = \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                $project->id, \App\Models\CalendarEvent::SOURCE_PROGRAMME_MILESTONE, $m->id, $m->trade_package_id
            );
            $type = in_array($m->milestone_type, ['commencement','completion'])
                ? $m->milestone_type
                : 'milestone';

            // A completed milestone is never "overdue" in the actionable sense,
            // regardless of how far in the past its date sits.
            $calendarStatus = $m->status === 'complete'
                ? \App\Models\CalendarEvent::STATUS_COMPLETED
                : \App\Models\CalendarEvent::computeStatusFromDays($this->daysFromToday($date, $project->organization));

            $events[] = $this->makeEvent(
                "milestone-{$m->id}",
                $m->name,
                $this->toDateString($date),
                $type,
                $m->source_text ?: null,
                $m->contract_id,
                $contractTitle,
                ['status' => $m->status, 'is_ai_generated' => $m->is_ai_generated],
                \App\Models\CalendarEvent::CATEGORY_PROGRAMME,
                \App\Models\CalendarEvent::computePriority($this->daysFromToday($date, $project->organization), \App\Models\CalendarEvent::CATEGORY_PROGRAMME),
                $calendarStatus,
                $milestoneActionUrl
            );
        }

        // ── D. Final Accounts ─────────────────────────────────────────────────
        //
        // Fully covered by OperationalIntelligenceService::collectFinalAccounts()
        // — consume it directly here rather than re-deriving the same date math
        // and title strings a second time (duplication introduced in Sprint 3E,
        // removed in 3F). Sections B and C remain direct queries (see notes above)
        // where the operational-intelligence collector's field coverage genuinely
        // differs from what this live feed needs to show.

        $faItems = $operationalItems->filter(fn($i) => $i['source_type'] === \App\Models\CalendarEvent::SOURCE_FINAL_ACCOUNT);

        $tradePackageIds = \App\Models\TradePackage::where('project_id', $project->id)->pluck('id');
        foreach ($tradePackageIds as $tpId) {
            $faItems = $faItems->concat(
                $this->intelligence->getItemsForTradePackage($project->id, $tpId)
                    ->filter(fn($i) => $i['source_type'] === \App\Models\CalendarEvent::SOURCE_FINAL_ACCOUNT)
            );
        }

        // One lightweight lookup for display titles — not a re-derivation of any
        // business logic (dates/status/titles already come from the item itself).
        $faTitles = FinalAccount::whereIn('id', $faItems->pluck('source_id')->unique())
            ->with(['contract:id,title', 'tradePackage:id,name'])
            ->get()
            ->keyBy('id')
            ->map(fn($fa) => $fa->contract->title ?? $fa->tradePackage->name ?? null);

        foreach ($faItems as $item) {
            // Routes to the Trade Package Workspace Commercial tab when this
            // Final Account belongs to a trade package, otherwise to the
            // project Commercial tab's Final Account card — computed by
            // OperationalIntelligenceService via WorkspaceNavigationResolver.
            $faActionUrl = $item['action_url'];

            $events[] = $this->makeEvent(
                "final-account-{$item['source_id']}-{$item['source_field']}",
                $item['title'],
                $this->toDateString($item['event_date']),
                'final_account',
                $item['description'],
                $item['contract_id'],
                $faTitles[$item['source_id']] ?? null,
                $item['meta'] ?? [],
                $item['category'],
                $item['priority'],
                $item['status'],
                $faActionUrl
            );
        }

        // Filter out any events with null dates
        $events = array_values(array_filter($events, fn($e) => !empty($e['date'])));

        return response()->json(['data' => $events]);
    }

    /**
     * GET /projects/{project}/calendar-events?trade_package_id=X
     *
     * Full calendar summary for one trade package, sourced entirely from
     * OperationalIntelligenceService::getItemsForTradePackage() — that method
     * already aggregates Payment Applications, Retention, Final Accounts,
     * Programme Milestones, Delay Events, and EOT Requests for this package.
     */
    private function tradePackageEvents(Project $project, \App\Models\TradePackage $tradePackage)
    {
        $items = $this->intelligence->getItemsForTradePackage($project->id, $tradePackage->id);

        $workspaceRoot = "/app/projects/{$project->id}/subcontracts/{$tradePackage->id}";

        $typeMap = [
            \App\Models\CalendarEvent::SOURCE_PAYMENT_APPLICATION => 'payment_due',
            \App\Models\CalendarEvent::SOURCE_RETENTION_RELEASE   => 'retention',
            \App\Models\CalendarEvent::SOURCE_FINAL_ACCOUNT       => 'final_account',
            \App\Models\CalendarEvent::SOURCE_PROGRAMME_MILESTONE => 'milestone',
            \App\Models\CalendarEvent::SOURCE_DELAY_EVENT         => 'delay_event',
            \App\Models\CalendarEvent::SOURCE_EOT_REQUEST         => 'eot_request',
            \App\Models\CalendarEvent::SOURCE_LOSS_AND_EXPENSE    => 'loss_and_expense',
        ];

        // Sprint 6D Phase 2 — route into the correct Workspace tab (and Delay & EOT
        // sub-tab) instead of always landing on the workspace root. Sprint 6E
        // consolidated this mapping into WorkspaceNavigationResolver (also used
        // by CalendarController::events() and OperationalIntelligenceService)
        // instead of keeping a local copy of the same tab map.

        $events = [];
        foreach ($items as $item) {
            $type = $typeMap[$item['source_type']] ?? 'key_date';
            if ($item['source_field'] === 'commencement_date') $type = 'commencement';
            if ($item['source_field'] === 'completion_date') $type = 'completion';

            $actionUrl = \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                $project->id, $item['source_type'], $item['source_id'], $tradePackage->id
            ) ?? $workspaceRoot;

            $events[] = $this->makeEvent(
                "tp-{$tradePackage->id}-{$item['source_type']}-{$item['source_id']}-{$item['source_field']}",
                $item['title'],
                $this->toDateString($item['event_date']),
                $type,
                $item['description'] ?? null,
                null,
                $tradePackage->name,
                $item['meta'] ?? [],
                $item['category'],
                $item['priority'],
                $item['status'],
                $actionUrl
            );
        }

        $events = array_values(array_filter($events, fn($e) => !empty($e['date'])));

        return response()->json(['data' => $events]);
    }

    private function makeEvent(
        string $id,
        string $title,
        ?string $date,
        string $type,
        ?string $description = null,
        ?int $contractId = null,
        ?string $contractTitle = null,
        array $meta = [],
        ?string $category = null,
        ?string $priority = null,
        ?string $status = null,
        ?string $actionUrl = null
    ): array {
        return [
            'id'             => $id,
            'title'          => $title,
            'date'           => $date,
            'type'           => $type,
            'color'          => $this->colors[$type] ?? '#94a3b8',
            'description'    => $description,
            'contract_id'    => $contractId,
            'contract_title' => $contractTitle,
            'category'       => $category,
            'priority'       => $priority,
            'status'         => $status,
            'action_url'     => $actionUrl,
            'meta'           => $meta,
        ];
    }

    private function toDateString(mixed $value): ?string
    {
        if (empty($value)) return null;
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Days between today (for $organization) and $value, or null if
     * unparseable/empty. Feeds CalendarEvent::computePriority()/
     * computeStatusFromDays() for the sections here that aren't sourced
     * from OperationalIntelligenceService (which already computes this
     * itself, org-aware, since Batch 4).
     *
     * $value is always a DATE-only business value here (application_date,
     * due_date, final_date_for_payment, a milestone date) — compared via
     * toDateString() against the organisation's own "today" rather than by
     * converting $value's own timezone, so a date-only field never shifts.
     */
    private function daysFromToday(mixed $value, ?Organization $organization): ?int
    {
        if (empty($value)) return null;
        try {
            $today = TimezoneResolver::today(null, $organization)->toDateString();
            return (int) Carbon::parse($today)->diffInDays(Carbon::parse($value)->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }
    }
}
