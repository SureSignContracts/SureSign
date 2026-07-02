<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\FinalAccount;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Services\OperationalIntelligenceService;
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
    ];

    public function __construct(private OperationalIntelligenceService $intelligence) {}

    public function events(Request $request, Project $project)
    {
        $user = $request->user();

        if (!$user->hasRole('Super Admin') && !$user->hasRole('Admin') && $user->organization_id !== $project->organization_id) {
            abort(403);
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

        $paymentActionUrl = "/app/projects/{$project->id}/commercial?tab=applications";

        foreach ($payApps as $app) {
            $contractTitle = $app->contract->title ?? null;

            if (!empty($app->application_date)) {
                $days = $this->daysFromToday($app->application_date);
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
                $days = $this->daysFromToday($app->due_date);
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
                $days = $this->daysFromToday($app->final_date_for_payment);
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

        $milestoneActionUrl = "/app/projects/{$project->id}/programme";

        foreach ($milestones as $m) {
            $date = $m->actual_date ?? $m->forecast_date ?? $m->planned_date;
            if (!$date) continue;
            $contractTitle = $m->contract?->title ?? null;
            $type = in_array($m->milestone_type, ['commencement','completion'])
                ? $m->milestone_type
                : 'milestone';

            // A completed milestone is never "overdue" in the actionable sense,
            // regardless of how far in the past its date sits.
            $calendarStatus = $m->status === 'complete'
                ? \App\Models\CalendarEvent::STATUS_COMPLETED
                : \App\Models\CalendarEvent::computeStatusFromDays($this->daysFromToday($date));

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
                \App\Models\CalendarEvent::computePriority($this->daysFromToday($date), \App\Models\CalendarEvent::CATEGORY_PROGRAMME),
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
            // Deep-links to the specific Final Account card — mirrors
            // NotificationEngineService's 'final_account' URL + &fa={id} pattern
            // so calendar, notification, and dashboard consumers land in the same place.
            $faActionUrl = "/app/projects/{$project->id}/commercial?tab=final-account&fa={$item['source_id']}";

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
     * Days between today and $value, or null if unparseable/empty.
     * Feeds CalendarEvent::computePriority()/computeStatusFromDays() for the
     * sections here that aren't sourced from OperationalIntelligenceService
     * (which already computes this itself).
     */
    private function daysFromToday(mixed $value): ?int
    {
        if (empty($value)) return null;
        try {
            return (int) now()->startOfDay()->diffInDays(Carbon::parse($value)->startOfDay(), false);
        } catch (\Throwable) {
            return null;
        }
    }
}
