<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Contract;
use App\Models\ContractDeadline;
use App\Models\ContractDeliverable;
use App\Models\ContractProgrammeMilestone;
use App\Models\FinalAccount;
use App\Models\PaymentApplication;
use App\Models\RetentionRelease;
use App\Models\TradePackage;
use App\Services\FinalAccountService;
use Illuminate\Support\Collection;

/**
 * Central aggregation layer for all operational intelligence.
 *
 * This service does NOT own data — it queries existing records and normalises them
 * into a consistent shape that Calendar, Dashboard, and Notifications can consume.
 *
 * Every normalized item has this shape:
 * [
 *   source_type     => string,     // CalendarEvent::SOURCE_* constant
 *   source_id       => int,
 *   source_field    => string,     // distinguishes multiple events from the same source
 *   title           => string,
 *   description     => string|null,
 *   category        => string,     // CalendarEvent::CATEGORY_* constant
 *   priority        => string,     // CalendarEvent::PRIORITY_* constant
 *   event_date      => Carbon|null,
 *   status          => string,     // upcoming|due_today|overdue|pending|unscheduled
 *   days_from_today => int|null,
 *   contract_id     => int|null,
 *   project_id      => int,
 *   organization_id => int,
 *   meta            => array,      // any extra data for display
 * ]
 */
class OperationalIntelligenceService
{
    public function __construct(private FinalAccountService $finalAccountService) {}

    /**
     * All active items for a project, sorted by event_date ascending.
     * Pass $contractId to scope to a single contract.
     *
     * Note: contract-only sources (deadlines, milestones, deliverables) are
     * skipped when the project has no contracts, but trade-package-capable
     * sources (payment applications, retention, Final Accounts) still run —
     * a trade-package-only project should not return an empty set.
     */
    public function getItemsForProject(int $projectId, ?int $contractId = null): Collection
    {
        // Fetch full Contract rows once — collectContractDates() needs the actual
        // commencement_date/completion_date/key_dates/key_obligations columns, so
        // this replaces what used to be a separate ->pluck('id') query entirely
        // rather than adding a second query alongside it.
        $contracts   = Contract::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('id', $contractId))
            ->get();
        $contractIds = $contracts->pluck('id');

        $organizationId = $contracts->first()?->organization_id
            ?? \App\Models\Project::find($projectId)?->organization_id;

        $items = collect()
            ->concat($this->collectPaymentApplications($projectId, $contractId, $organizationId))
            ->concat($this->collectRetentions($projectId, $contractId, $organizationId))
            ->concat($this->collectFinalAccounts($projectId, $contractId, $organizationId));

        if ($contracts->isNotEmpty()) {
            $items = $items
                ->concat($this->collectDeadlines($contractIds, $projectId, $organizationId))
                ->concat($this->collectMilestones($contractIds, $projectId, $organizationId))
                ->concat($this->collectDeliverables($projectId, $contractId, $organizationId))
                ->concat($this->collectContractDates($contracts, $projectId, $organizationId));
        }

        return $items
            ->filter(fn($i) => $i['event_date'] !== null)
            ->sortBy('event_date')
            ->values();
    }

    /**
     * Items scoped to a single trade package.
     *
     * Trade packages have no equivalent of ContractDeadline/ContractProgrammeMilestone/
     * ContractDeliverable in the schema (those models are contract-only — verified,
     * not assumed), so only the sources that carry a trade_package_id column
     * (payment applications, retention releases, Final Accounts) are collected here.
     */
    public function getItemsForTradePackage(int $projectId, int $tradePackageId): Collection
    {
        $tradePackage = TradePackage::find($tradePackageId);
        if (!$tradePackage) {
            return collect();
        }

        $organizationId = $tradePackage->organization_id;

        return collect()
            ->concat($this->collectPaymentApplications($projectId, null, $organizationId, $tradePackageId))
            ->concat($this->collectRetentions($projectId, null, $organizationId, $tradePackageId))
            ->concat($this->collectFinalAccounts($projectId, null, $organizationId, $tradePackageId))
            ->filter(fn($i) => $i['event_date'] !== null)
            ->sortBy('event_date')
            ->values();
    }

    /**
     * Items due within the next $days calendar days (inclusive of today).
     */
    public function getUpcoming(int $projectId, int $days = 30, ?int $contractId = null): Collection
    {
        $cutoff = now()->addDays($days)->endOfDay();

        return $this->getItemsForProject($projectId, $contractId)
            ->filter(fn($i) => $i['days_from_today'] !== null && $i['days_from_today'] >= 0 && $i['event_date']->lte($cutoff));
    }

    /**
     * Items where event_date is in the past and status is not completed/cancelled.
     */
    public function getOverdue(int $projectId, ?int $contractId = null): Collection
    {
        return $this->getItemsForProject($projectId, $contractId)
            ->filter(fn($i) => $i['status'] === 'overdue');
    }

    /**
     * Items due today.
     */
    public function getDueToday(int $projectId, ?int $contractId = null): Collection
    {
        return $this->getItemsForProject($projectId, $contractId)
            ->filter(fn($i) => $i['status'] === 'due_today');
    }

    /**
     * Counts by status for dashboard widgets.
     */
    public function getSummary(int $projectId, ?int $contractId = null): array
    {
        $items = $this->getItemsForProject($projectId, $contractId);

        return [
            'total'       => $items->count(),
            'overdue'     => $items->where('status', 'overdue')->count(),
            'due_today'   => $items->where('status', 'due_today')->count(),
            'upcoming_7'  => $items->filter(fn($i) => $i['days_from_today'] !== null && $i['days_from_today'] >= 0 && $i['days_from_today'] <= 7)->count(),
            'upcoming_30' => $items->filter(fn($i) => $i['days_from_today'] !== null && $i['days_from_today'] >= 0 && $i['days_from_today'] <= 30)->count(),
            'critical'    => $items->where('priority', CalendarEvent::PRIORITY_CRITICAL)->count(),
            'by_category' => $items->groupBy('category')->map->count()->toArray(),
        ];
    }

    // ── Collectors ────────────────────────────────────────────────────────────

    private function collectDeadlines(Collection $contractIds, int $projectId, ?int $organizationId): Collection
    {
        return ContractDeadline::whereIn('contract_id', $contractIds)
            ->whereNotNull('resolved_date')
            ->whereNotIn('status', [ContractDeadline::STATUS_COMPLETED, ContractDeadline::STATUS_WAIVED, ContractDeadline::STATUS_CANCELLED])
            ->get()
            ->map(fn($d) => $this->normalizeDeadline($d, $projectId, $organizationId));
    }

    private function collectPaymentApplications(int $projectId, ?int $contractId, ?int $organizationId, ?int $tradePackageId = null): Collection
    {
        $apps = PaymentApplication::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->when($tradePackageId, fn($q) => $q->where('trade_package_id', $tradePackageId))
            ->whereNotIn('status', ['paid', 'cancelled'])
            ->get();

        $items = collect();
        foreach ($apps as $app) {
            if ($app->payment_notice_deadline) {
                $items->push($this->normalizePaymentDate($app, 'payment_notice_deadline', 'Payment Notice Deadline', $projectId, $organizationId));
            }
            if ($app->pay_less_notice_deadline) {
                $items->push($this->normalizePaymentDate($app, 'pay_less_notice_deadline', 'Pay Less Notice Deadline', $projectId, $organizationId));
            }
            if ($app->due_date) {
                $items->push($this->normalizePaymentDate($app, 'due_date', 'Payment Due', $projectId, $organizationId));
            }
            if ($app->final_date_for_payment) {
                $items->push($this->normalizePaymentDate($app, 'final_date_for_payment', 'Final Date for Payment', $projectId, $organizationId));
            }
        }

        return $items;
    }

    private function collectMilestones(Collection $contractIds, int $projectId, ?int $organizationId): Collection
    {
        return ContractProgrammeMilestone::whereIn('contract_id', $contractIds)
            ->whereNull('actual_date')
            ->whereNotNull('planned_date')
            ->get()
            ->map(fn($m) => $this->normalizeMilestone($m, $projectId, $organizationId));
    }

    private function collectRetentions(int $projectId, ?int $contractId, ?int $organizationId, ?int $tradePackageId = null): Collection
    {
        return RetentionRelease::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->when($tradePackageId, fn($q) => $q->where('trade_package_id', $tradePackageId))
            ->whereNotNull('release_date')
            ->get()
            ->map(fn($r) => $this->normalizeRetention($r, $projectId, $organizationId));
    }

    private function collectDeliverables(int $projectId, ?int $contractId, ?int $organizationId): Collection
    {
        return ContractDeliverable::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->whereNotIn('status', [ContractDeliverable::STATUS_ACCEPTED, ContractDeliverable::STATUS_CANCELLED])
            ->whereNotNull('resolved_date')
            ->get()
            ->map(fn($d) => $this->normalizeDeliverable($d, $projectId, $organizationId));
    }

    /**
     * Final Account lifecycle items. Unlike other sources, one Final Account can
     * yield zero, one, or several items — each keyed by a distinct source_field
     * so CalendarSyncService/NotificationEngineService treat them independently.
     *
     * draft/commercially_closed Final Accounts require no operational action.
     */
    private function collectFinalAccounts(int $projectId, ?int $contractId, ?int $organizationId, ?int $tradePackageId = null): Collection
    {
        $accounts = FinalAccount::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->when($tradePackageId, fn($q) => $q->where('trade_package_id', $tradePackageId))
            ->whereNotIn('status', [FinalAccount::STATUS_DRAFT, FinalAccount::STATUS_COMMERCIALLY_CLOSED])
            ->get();

        $items = collect();
        foreach ($accounts as $fa) {
            $items = $items->concat($this->normalizeFinalAccount($fa, $projectId, $organizationId));
        }

        return $items;
    }

    /**
     * Contract-level date sources that previously only existed inside
     * CalendarController's live-computed feed: commencement, completion,
     * key_dates (JSON), key_obligations (JSON). Reuses exactly the same
     * columns/shape CalendarController already reads — no new concepts.
     */
    private function collectContractDates(Collection $contracts, int $projectId, ?int $organizationId): Collection
    {
        $items = collect();

        foreach ($contracts as $contract) {
            if ($contract->commencement_date) {
                $item = $this->normalizeContractMilestoneDate(
                    $contract, 'commencement_date', "Commencement: {$contract->title}", $contract->commencement_date, $projectId, $organizationId
                );
                if ($item) $items->push($item);
            }

            if ($contract->completion_date) {
                $item = $this->normalizeContractMilestoneDate(
                    $contract, 'completion_date', "Completion: {$contract->title}", $contract->completion_date, $projectId, $organizationId
                );
                if ($item) $items->push($item);
            }

            $keyDates = is_array($contract->key_dates) ? $contract->key_dates : [];
            foreach ($keyDates as $i => $kd) {
                if (empty($kd['date'])) continue;
                $item = $this->normalizeKeyDate($contract, $i, $kd, $projectId, $organizationId);
                if ($item) $items->push($item);
            }

            $obligations = is_array($contract->key_obligations) ? $contract->key_obligations : [];
            foreach ($obligations as $i => $ob) {
                $item = $this->normalizeKeyObligation($contract, $i, $ob, $projectId, $organizationId);
                if ($item) $items->push($item);
            }
        }

        return $items;
    }

    // ── Normalizers ───────────────────────────────────────────────────────────

    private function normalizeDeadline(ContractDeadline $d, int $projectId, ?int $organizationId): array
    {
        $date  = $d->resolved_date;
        $days  = $date ? (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
        $cat   = $this->deadlineCategoryToCalendarCategory($d->category);

        return [
            'source_type'     => CalendarEvent::SOURCE_CONTRACT_DEADLINE,
            'source_id'       => $d->id,
            'source_field'    => 'resolved_date',
            'title'           => $d->name,
            'description'     => $d->consequence_of_non_compliance,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $d->contract_id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'is_statutory'     => $d->is_statutory,
                'clause_reference' => $d->clause_reference,
                'responsible_party' => $d->responsible_party,
            ],
        ];
    }

    private function normalizePaymentDate(PaymentApplication $app, string $field, string $label, int $projectId, ?int $organizationId): array
    {
        $date = $app->{$field};
        $days = $date ? (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
        $cat  = CalendarEvent::CATEGORY_PAYMENT;
        $ref  = $app->reference ?? "App #{$app->application_number}";

        return [
            'source_type'     => CalendarEvent::SOURCE_PAYMENT_APPLICATION,
            'source_id'       => $app->id,
            'source_field'    => $field,
            'title'           => "{$label} — {$ref}",
            'description'     => null,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $app->contract_id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'application_number' => $app->application_number,
                'application_status' => $app->status,
            ],
        ];
    }

    private function normalizeMilestone(ContractProgrammeMilestone $m, int $projectId, ?int $organizationId): array
    {
        $date = $m->forecast_date ?? $m->planned_date;
        $days = $date ? (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
        $cat  = CalendarEvent::CATEGORY_PROGRAMME;

        return [
            'source_type'     => CalendarEvent::SOURCE_PROGRAMME_MILESTONE,
            'source_id'       => $m->id,
            'source_field'    => 'planned_date',
            'title'           => $m->name,
            'description'     => null,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $m->contract_id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'milestone_type'   => $m->milestone_type ?? null,
                'responsible_party' => $m->responsible_party ?? null,
                'planned_date'     => $m->planned_date?->format('Y-m-d'),
                'forecast_date'    => $m->forecast_date?->format('Y-m-d'),
            ],
        ];
    }

    private function normalizeRetention(RetentionRelease $r, int $projectId, ?int $organizationId): array
    {
        $date   = $r->release_date;
        $days   = $date ? (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
        $cat    = CalendarEvent::CATEGORY_RETENTION;
        $moiety = $r->moiety === RetentionRelease::MOIETY_HALF_1 ? 'Half 1 (Practical Completion)' : 'Half 2 (Making Good Defects)';

        return [
            'source_type'     => CalendarEvent::SOURCE_RETENTION_RELEASE,
            'source_id'       => $r->id,
            'source_field'    => 'release_date',
            'title'           => "Retention Release — {$moiety}",
            'description'     => $r->release_reason,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $r->contract_id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'moiety'         => $r->moiety,
                'release_amount' => $r->release_amount,
            ],
        ];
    }

    private function normalizeDeliverable(ContractDeliverable $d, int $projectId, ?int $organizationId): array
    {
        $date  = $d->resolved_date;
        $days  = $date ? (int) now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false) : null;
        $cat   = CalendarEvent::CATEGORY_DELIVERABLES;

        return [
            'source_type'     => CalendarEvent::SOURCE_CONTRACT_DELIVERABLE,
            'source_id'       => $d->id,
            'source_field'    => 'resolved_date',
            'title'           => $d->name,
            'description'     => $d->consequence_if_late,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $d->contract_id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'deliverable_status' => $d->status,
                'responsible_party'  => $d->responsible_party,
                'clause_reference'   => $d->clause_reference,
            ],
        ];
    }

    /**
     * A Final Account can contribute several distinct operational items at once
     * (e.g. certificate just issued AND dispute window still counting down).
     * Returns a Collection of normalized items (may be empty).
     */
    private function normalizeFinalAccount(FinalAccount $fa, int $projectId, ?int $organizationId): Collection
    {
        $cat = CalendarEvent::CATEGORY_COMMERCIAL;
        $items = collect();

        $push = function (string $field, string $title, $date, ?string $description = null) use (&$items, $fa, $cat, $projectId, $organizationId) {
            if (!$date) return;
            $date = \Carbon\Carbon::parse($date)->startOfDay();
            $days = (int) now()->startOfDay()->diffInDays($date, false);

            $items->push([
                'source_type'     => CalendarEvent::SOURCE_FINAL_ACCOUNT,
                'source_id'       => $fa->id,
                'source_field'    => $field,
                'title'           => $title,
                'description'     => $description,
                'category'        => $cat,
                'priority'        => CalendarEvent::computePriority($days, $cat),
                'event_date'      => $date,
                'status'          => $this->computeStatus($days),
                'days_from_today' => $days,
                'contract_id'     => $fa->contract_id,
                'project_id'      => $projectId,
                'organization_id' => $organizationId,
                'meta'            => [
                    'final_account_id'        => $fa->id,
                    'final_account_reference' => $fa->reference,
                    'final_account_status'    => $fa->status,
                ],
            ]);
        };

        if ($fa->status === FinalAccount::STATUS_SUBMITTED) {
            $push('submitted_review_pending', "Final Account Submitted — Review Required ({$fa->reference})", $fa->submitted_at);
        }

        if ($fa->status === FinalAccount::STATUS_UNDER_REVIEW && $fa->reviewed_at) {
            $push('review_due', "Final Account Review ({$fa->reference})", $fa->reviewed_at->copy()->addDays(FinalAccount::reviewSlaDays()));
        }

        if ($fa->status === FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED) {
            $push('certificate_issued_closeout_ready', "Final Certificate Issued — Ready for Commercial Close-Out ({$fa->reference})", $fa->final_certificate_issued_at);

            $push('close_out_overdue', "Commercial Close-Out ({$fa->reference})", $fa->final_certificate_issued_at?->copy()->addDays(FinalAccount::closeoutGraceDays()));

            $push('dispute_window_expiry', "Final Account Dispute Window Expiry ({$fa->reference})", $fa->dispute_window_expires_at);

            if (!$this->finalAccountService->hasHalf2RetentionReleased($fa)) {
                $push('half2_retention_available', "Half 2 Retention Available for Release ({$fa->reference})", $fa->final_certificate_issued_at);
            }
        }

        return $items;
    }

    private function normalizeContractMilestoneDate(Contract $contract, string $field, string $title, $rawDate, int $projectId, ?int $organizationId): ?array
    {
        // AI-extracted contract data can carry non-parseable values (e.g. dates
        // left as free text in the source document) — skip rather than crash.
        try {
            $date = \Carbon\Carbon::parse($rawDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($date, false);
        $cat  = CalendarEvent::CATEGORY_CONTRACT;

        return [
            'source_type'     => CalendarEvent::SOURCE_CONTRACT,
            'source_id'       => $contract->id,
            'source_field'    => $field,
            'title'           => $title,
            'description'     => null,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $contract->id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => ['contract_title' => $contract->title],
        ];
    }

    /**
     * @param  array  $kd  One entry from Contract.key_dates (JSON): ['name', 'date', 'source']
     */
    private function normalizeKeyDate(Contract $contract, int $index, array $kd, int $projectId, ?int $organizationId): ?array
    {
        // key_dates is AI-extracted and can carry non-parseable free text
        // (e.g. "End of June 2026 (exact date not specified)") when the
        // source document didn't give an exact date — skip rather than crash.
        try {
            $date = \Carbon\Carbon::parse($kd['date'])->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($date, false);
        $cat  = CalendarEvent::CATEGORY_CONTRACT;

        return [
            'source_type'     => CalendarEvent::SOURCE_CONTRACT,
            'source_id'       => $contract->id,
            'source_field'    => "key_date_{$index}",
            'title'           => $kd['name'] ?? 'Key Date',
            'description'     => $kd['source'] ?? null,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $contract->id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => ['contract_title' => $contract->title],
        ];
    }

    /**
     * @param  array  $ob  One entry from Contract.key_obligations (JSON):
     *                     ['title', 'description', 'due_date' | 'due_days_from_commencement', 'responsible_party']
     */
    private function normalizeKeyObligation(Contract $contract, int $index, array $ob, int $projectId, ?int $organizationId): ?array
    {
        $rawDate = null;

        if (!empty($ob['due_date'])) {
            $rawDate = $ob['due_date'];
        } elseif (!empty($ob['due_days_from_commencement']) && !empty($contract->commencement_date)) {
            try {
                $rawDate = \Carbon\Carbon::parse($contract->commencement_date)->addDays((int) $ob['due_days_from_commencement']);
            } catch (\Throwable) {
                return null;
            }
        }

        if (!$rawDate) return null;

        try {
            $date = \Carbon\Carbon::parse($rawDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $days = (int) now()->startOfDay()->diffInDays($date, false);
        $cat  = CalendarEvent::CATEGORY_CONTRACT;

        return [
            'source_type'     => CalendarEvent::SOURCE_CONTRACT,
            'source_id'       => $contract->id,
            'source_field'    => "obligation_{$index}",
            'title'           => $ob['title'] ?? 'Obligation',
            'description'     => $ob['description'] ?? null,
            'category'        => $cat,
            'priority'        => CalendarEvent::computePriority($days, $cat),
            'event_date'      => $date,
            'status'          => $this->computeStatus($days),
            'days_from_today' => $days,
            'contract_id'     => $contract->id,
            'project_id'      => $projectId,
            'organization_id' => $organizationId,
            'meta'            => [
                'contract_title'    => $contract->title,
                'responsible_party' => $ob['responsible_party'] ?? null,
            ],
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function computeStatus(?int $daysFromToday): string
    {
        return CalendarEvent::computeStatusFromDays($daysFromToday);
    }

    private function deadlineCategoryToCalendarCategory(string $deadlineCategory): string
    {
        return match ($deadlineCategory) {
            'payment', 'pay_less_notice', 'payment_notice' => CalendarEvent::CATEGORY_PAYMENT,
            'programme', 'eot', 'extension_of_time'        => CalendarEvent::CATEGORY_PROGRAMME,
            'compliance', 'cdm', 'health_safety'           => CalendarEvent::CATEGORY_COMPLIANCE,
            'notice', 'statutory_notice'                   => CalendarEvent::CATEGORY_NOTICES,
            'retention'                                    => CalendarEvent::CATEGORY_RETENTION,
            'risk'                                         => CalendarEvent::CATEGORY_RISK,
            default                                        => CalendarEvent::CATEGORY_CONTRACT,
        };
    }
}
