<?php

namespace App\Services;

use App\Jobs\GenerateProjectNotificationsJob;
use App\Models\CalendarEvent;
use App\Models\Contract;
use App\Models\ContractDeadline;
use App\Models\ContractDeliverable;
use App\Models\TradePackage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generates and maintains CalendarEvent records from operational intelligence.
 *
 * Idempotency guarantee: every event has a unique (source_type, source_id, source_field)
 * key. Running sync twice will update existing events rather than duplicate them.
 *
 * Responsibilities:
 *  - Resolve absolute dates for ContractDeadline and ContractDeliverable records
 *    (trigger_event + contract dates → resolved_date)
 *  - Create/update CalendarEvent records for all operational sources
 *  - Refresh status on all calendar events for a contract
 *  - Hard-delete events whose source records have been deleted
 */
class CalendarSyncService
{
    public function __construct(private OperationalIntelligenceService $intelligence) {}

    /**
     * Full sync for one contract.
     * Returns ['created' => N, 'updated' => N, 'skipped' => N, 'errors' => N]
     *
     * @param  bool $dispatchNotifications  Set false when the caller (e.g. AiController)
     *                                      will dispatch GenerateProjectNotificationsJob itself
     *                                      to avoid a duplicate dispatch.
     * @param  bool $refreshStatuses        Set false when called from syncForProject(), which
     *                                      performs a single combined status refresh at the end
     *                                      instead of once per contract/trade package.
     */
    public function syncForContract(Contract $contract, bool $dispatchNotifications = true, bool $refreshStatuses = true): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        DB::transaction(function () use ($contract, &$stats) {
            // Step 1: resolve absolute dates on deadlines and deliverables
            $this->resolveDates($contract);

            // Step 2: get all operational items (now that resolved_dates are set)
            $items = $this->intelligence->getItemsForProject($contract->project_id, $contract->id);

            $this->syncItems($items, $stats);
        });

        if ($refreshStatuses) {
            $this->refreshStatuses($contract->project_id, $contract->id);
        }

        if ($dispatchNotifications) {
            GenerateProjectNotificationsJob::dispatch($contract->project_id);
        }

        return $stats;
    }

    /**
     * Full sync for one trade package.
     *
     * Mirrors syncForContract(), but trade packages have no equivalent of
     * ContractDeadline/ContractProgrammeMilestone/ContractDeliverable (contract-only
     * concepts — verified against the schema, not assumed), so there are no dates
     * to resolve first. Only payment applications, retention, and Final Accounts
     * apply.
     */
    public function syncForTradePackage(TradePackage $tradePackage, bool $dispatchNotifications = true, bool $refreshStatuses = true): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        DB::transaction(function () use ($tradePackage, &$stats) {
            $items = $this->intelligence->getItemsForTradePackage($tradePackage->project_id, $tradePackage->id);

            $this->syncItems($items, $stats);
        });

        if ($refreshStatuses) {
            // Trade-package events carry contract_id = null, so there is no column
            // to scope a refresh to "just this trade package" — refresh project-wide.
            $this->refreshStatuses($tradePackage->project_id);
        }

        if ($dispatchNotifications) {
            GenerateProjectNotificationsJob::dispatch($tradePackage->project_id);
        }

        return $stats;
    }

    /**
     * Sync all contracts AND trade packages in a project.
     * Status refresh runs once at the end (not per contract/trade package) to
     * avoid redundant passes over the same CalendarEvent rows.
     */
    public function syncForProject(int $projectId): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $accumulate = function (array $result) use (&$totals) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $result[$key];
            }
        };

        Contract::where('project_id', $projectId)->each(
            fn (Contract $contract) => $accumulate($this->syncForContract($contract, false, false))
        );

        TradePackage::where('project_id', $projectId)->each(
            fn (TradePackage $tp) => $accumulate($this->syncForTradePackage($tp, false, false))
        );

        // Single combined status refresh for the whole project.
        $this->refreshStatuses($projectId);

        GenerateProjectNotificationsJob::dispatch($projectId);

        return $totals;
    }

    /**
     * Create/update CalendarEvent rows for a set of normalized operational items.
     * Shared by syncForContract() and syncForTradePackage() to avoid duplicating
     * the idempotent upsert logic.
     */
    private function syncItems(Collection $items, array &$stats): void
    {
        foreach ($items as $item) {
            try {
                $existing = CalendarEvent::where('source_type', $item['source_type'])
                    ->where('source_id', $item['source_id'])
                    ->where('source_field', $item['source_field'])
                    ->first();

                $payload = [
                    'organization_id'         => $item['organization_id'],
                    'project_id'              => $item['project_id'],
                    'contract_id'             => $item['contract_id'],
                    'title'                   => $item['title'],
                    'description'             => $item['description'],
                    'category'                => $item['category'],
                    'event_date'              => $item['event_date']->format('Y-m-d'),
                    'status'                  => $item['status'],
                    'priority'                => $item['priority'],
                    'generated_from_contract' => $item['contract_id'] !== null,
                    'metadata'                => $item['meta'] ?? null,
                ];

                if ($existing) {
                    // Only update if event_date or status changed
                    if ($existing->event_date?->format('Y-m-d') !== $payload['event_date']
                        || $existing->status !== $payload['status']
                    ) {
                        $existing->update($payload);
                        $stats['updated']++;
                    } else {
                        $stats['skipped']++;
                    }
                } else {
                    CalendarEvent::create(array_merge($payload, [
                        'source_type'  => $item['source_type'],
                        'source_id'    => $item['source_id'],
                        'source_field' => $item['source_field'],
                    ]));
                    $stats['created']++;
                }
            } catch (\Throwable $e) {
                Log::warning("CalendarSyncService: failed to sync item", [
                    'source_type'  => $item['source_type'] ?? 'unknown',
                    'source_id'    => $item['source_id'] ?? 'unknown',
                    'error'        => $e->getMessage(),
                ]);
                $stats['errors']++;
            }
        }
    }

    /**
     * Refresh statuses on all calendar events for a project/contract.
     * Run daily (or on demand) to move upcoming → due_today → overdue.
     */
    public function refreshStatuses(int $projectId, ?int $contractId = null): int
    {
        $events = CalendarEvent::where('project_id', $projectId)
            ->when($contractId, fn($q) => $q->where('contract_id', $contractId))
            ->whereNotIn('status', [
                CalendarEvent::STATUS_COMPLETED,
                CalendarEvent::STATUS_MISSED,
                CalendarEvent::STATUS_CANCELLED,
            ])
            ->get();

        $updated = 0;
        foreach ($events as $event) {
            $days = $event->daysFromToday();

            $newStatus = match (true) {
                $days === null  => CalendarEvent::STATUS_PENDING,
                $days < 0       => CalendarEvent::STATUS_OVERDUE,
                $days === 0     => CalendarEvent::STATUS_DUE_TODAY,
                $days <= 30     => CalendarEvent::STATUS_UPCOMING,
                default         => CalendarEvent::STATUS_PENDING,
            };

            if ($newStatus !== $event->status) {
                $event->update(['status' => $newStatus]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Remove CalendarEvent rows that no longer correspond to a currently-valid
     * operational item — covers both fully-deleted source records AND records
     * that still exist but no longer produce that specific item (e.g. a
     * key_dates JSON entry removed from a Contract, or a Final Account moved
     * past a status that emits a given source_field).
     *
     * Safe by construction: it re-derives the complete, authoritative set of
     * item keys for the project via OperationalIntelligenceService (the same
     * collectors syncForProject() just used) and soft-deletes anything NOT in
     * that set — no per-source_type special-casing, no separate existence
     * checks that could drift out of sync with the collectors themselves.
     *
     * Deliberately NOT called from syncForContract()/syncForTradePackage() —
     * it re-runs every collector for the whole project, so chaining it into
     * every contract/trade-package sync would multiply that cost. Call it
     * once, after a full syncForProject() pass (see calendar:sync command).
     */
    public function pruneOrphanedEvents(int $projectId): int
    {
        $seenKeys = collect();

        Contract::where('project_id', $projectId)->get()->each(function (Contract $contract) use (&$seenKeys) {
            $items = $this->intelligence->getItemsForProject($contract->project_id, $contract->id);
            $seenKeys = $seenKeys->concat($items->map(fn($i) => "{$i['source_type']}:{$i['source_id']}:{$i['source_field']}"));
        });

        TradePackage::where('project_id', $projectId)->get()->each(function (TradePackage $tp) use (&$seenKeys) {
            $items = $this->intelligence->getItemsForTradePackage($tp->project_id, $tp->id);
            $seenKeys = $seenKeys->concat($items->map(fn($i) => "{$i['source_type']}:{$i['source_id']}:{$i['source_field']}"));
        });

        $seenKeys = $seenKeys->unique();

        $orphans = CalendarEvent::where('project_id', $projectId)
            ->get()
            ->reject(fn($e) => $seenKeys->contains("{$e->source_type}:{$e->source_id}:{$e->source_field}"));

        foreach ($orphans as $orphan) {
            $orphan->delete(); // soft delete — reversible, matches CalendarEvent's SoftDeletes trait
        }

        return $orphans->count();
    }

    // ── Date resolution ───────────────────────────────────────────────────────

    /**
     * Resolve absolute dates for all ContractDeadlines and ContractDeliverables
     * on this contract that don't yet have a resolved_date.
     */
    private function resolveDates(Contract $contract): void
    {
        // Deadlines
        ContractDeadline::where('contract_id', $contract->id)
            ->whereNull('resolved_date')
            ->each(function (ContractDeadline $d) use ($contract) {
                $resolved = $d->resolveDate($contract);
                if ($resolved) {
                    $d->update(['resolved_date' => $resolved->format('Y-m-d')]);
                }
            });

        // Deliverables
        ContractDeliverable::where('contract_id', $contract->id)
            ->whereNull('resolved_date')
            ->each(function (ContractDeliverable $d) use ($contract) {
                $resolved = $this->resolveDeliverableDate($d, $contract);
                if ($resolved) {
                    $d->update(['resolved_date' => $resolved->format('Y-m-d')]);
                }
            });
    }

    /**
     * Resolve deliverable due date from due_event + contract dates.
     * Mirrors the logic in ContractDeadline::resolveDate().
     */
    private function resolveDeliverableDate(ContractDeliverable $d, Contract $contract): ?\Carbon\Carbon
    {
        $event = strtolower($d->due_event ?? '');
        $days  = (int) ($d->due_days_before_after_event ?? 0);

        $baseDate = null;

        if (str_contains($event, 'commencement') || str_contains($event, 'start')) {
            $baseDate = $contract->commencement_date;
        } elseif (str_contains($event, 'completion') || str_contains($event, 'practical')) {
            $baseDate = $contract->completion_date;
        } elseif (str_contains($event, 'possession')) {
            $baseDate = $contract->possession_date ?? $contract->commencement_date;
        }

        if (!$baseDate) return null;

        return $days < 0
            ? \Carbon\Carbon::parse($baseDate)->subDays(abs($days))
            : \Carbon\Carbon::parse($baseDate)->addDays($days);
    }
}
