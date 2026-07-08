<?php

namespace App\Services\TradePackages;

use App\Models\ActivityLog;
use App\Models\DelayEvent;
use App\Models\EotRequest;
use App\Models\ContractRisk;
use App\Models\DeliveryDocument;
use App\Models\LossAndExpenseClaim;
use App\Models\PaymentApplication;
use App\Models\ProjectActivity;
use App\Models\TradePackage;
use App\Models\Variation;
use Illuminate\Support\Collection;

/**
 * Aggregates "all activity for one Trade Package" across the platform's two
 * separate, non-overlapping audit systems (Sprint 6C review finding):
 *
 *  - `activity_logs` (ActivityLog::record()) — used for direct TradePackage
 *    actions (AI analysis confirm/cancel) and for PaymentApplication/Variation
 *    actions, keyed to their own model as subject.
 *  - `project_activities` (ProjectActivityService::record()) — used for
 *    Delay Event / EOT Request / Loss & Expense Claim actions, keyed to
 *    their own model as the "related" record.
 *
 * Neither table has a `trade_package_id` column of its own, so this service
 * pre-fetches the IDs of trade-package-scoped child records first, then
 * queries both tables and merges the results in PHP.
 */
class TradePackageActivityService
{
    /**
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function forTradePackage(TradePackage $tradePackage, int $perPage = 50, int $page = 1): array
    {
        $delayEventIds = DelayEvent::where('trade_package_id', $tradePackage->id)->pluck('id');
        $eotRequestIds = EotRequest::where('trade_package_id', $tradePackage->id)->pluck('id');
        $lossAndExpenseIds = LossAndExpenseClaim::where('trade_package_id', $tradePackage->id)->pluck('id');
        $paymentApplicationIds = PaymentApplication::where('trade_package_id', $tradePackage->id)->pluck('id');
        $variationIds = Variation::where('trade_package_id', $tradePackage->id)->pluck('id');
        $riskIds = ContractRisk::where('trade_package_id', $tradePackage->id)->pluck('id');
        $deliveryDocumentIds = DeliveryDocument::where('trade_package_id', $tradePackage->id)->pluck('id');

        $activityLogRows = ActivityLog::with('user:id,name')
            ->where(function ($q) use ($tradePackage, $paymentApplicationIds, $variationIds) {
                $q->where(fn ($q2) => $q2->where('subject_type', TradePackage::class)->where('subject_id', $tradePackage->id))
                  ->orWhere(fn ($q2) => $q2->where('subject_type', PaymentApplication::class)->whereIn('subject_id', $paymentApplicationIds))
                  ->orWhere(fn ($q2) => $q2->where('subject_type', Variation::class)->whereIn('subject_id', $variationIds));
            })
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id'          => "activity_log-{$log->id}",
                'action'      => $log->action,
                'description' => $log->description,
                'user'        => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                'metadata'    => $log->metadata,
                'created_at'  => $log->created_at,
                // Sprint 6D Phase 1 — lets the frontend navigate back to the
                // originating record/tab without its own type-to-tab mapping.
                'source'      => $this->classifyActivityLog($log),
            ]);

        $projectActivityRows = ProjectActivity::with('user:id,name')
            ->where(fn ($q) => $q
                ->where(fn ($q2) => $q2->where('related_type', DelayEvent::class)->whereIn('related_id', $delayEventIds))
                ->orWhere(fn ($q2) => $q2->where('related_type', EotRequest::class)->whereIn('related_id', $eotRequestIds))
                ->orWhere(fn ($q2) => $q2->where('related_type', LossAndExpenseClaim::class)->whereIn('related_id', $lossAndExpenseIds))
                ->orWhere(fn ($q2) => $q2->where('related_type', ContractRisk::class)->whereIn('related_id', $riskIds))
                ->orWhere(fn ($q2) => $q2->where('related_type', DeliveryDocument::class)->whereIn('related_id', $deliveryDocumentIds))
            )
            ->get()
            ->map(fn (ProjectActivity $a) => [
                'id'          => "project_activity-{$a->id}",
                'action'      => $a->activity_type,
                'description' => $a->title ?: $a->description,
                'user'        => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                'metadata'    => $a->metadata,
                'created_at'  => $a->created_at,
                'source'      => $this->classifyProjectActivity($a),
            ]);

        /** @var Collection $merged */
        $merged = $activityLogRows->concat($projectActivityRows)
            ->sortByDesc(fn ($row) => $row['created_at'])
            ->values();

        $total = $merged->count();
        $page  = max(1, $page);
        $slice = $merged->slice(($page - 1) * $perPage, $perPage)->values();

        return ['data' => $slice->all(), 'total' => $total];
    }

    /**
     * Maps an activity_logs row's subject to a workspace tab/record reference.
     * Returns null if the subject isn't one this workspace can navigate to.
     */
    private function classifyActivityLog(ActivityLog $log): ?array
    {
        if ($log->subject_type === TradePackage::class) {
            // Only AI confirm/cancel actions log TradePackage itself as subject today.
            $tab = str_starts_with((string) $log->action, 'trade_package_ai_analysis.') ? 'ai-analysis' : 'overview';
            return ['type' => 'trade_package', 'id' => $log->subject_id, 'tab' => $tab, 'subtab' => null];
        }
        if ($log->subject_type === PaymentApplication::class) {
            return ['type' => 'payment_application', 'id' => $log->subject_id, 'tab' => 'commercial', 'subtab' => null];
        }
        if ($log->subject_type === Variation::class) {
            return ['type' => 'variation', 'id' => $log->subject_id, 'tab' => 'commercial', 'subtab' => null];
        }
        return null;
    }

    /**
     * Maps a project_activities row's related record to a workspace tab/subtab.
     */
    private function classifyProjectActivity(ProjectActivity $activity): ?array
    {
        return match ($activity->related_type) {
            DelayEvent::class => ['type' => 'delay_event', 'id' => $activity->related_id, 'tab' => 'delay-eot', 'subtab' => 'delay'],
            EotRequest::class => ['type' => 'eot_request', 'id' => $activity->related_id, 'tab' => 'delay-eot', 'subtab' => 'eot'],
            LossAndExpenseClaim::class => ['type' => 'loss_and_expense_claim', 'id' => $activity->related_id, 'tab' => 'delay-eot', 'subtab' => 'loss-expense'],
            ContractRisk::class => ['type' => 'contract_risk', 'id' => $activity->related_id, 'tab' => 'compliance', 'subtab' => 'risks'],
            DeliveryDocument::class => ['type' => 'delivery_document', 'id' => $activity->related_id, 'tab' => 'compliance', 'subtab' => 'delivery-documents'],
            default => null,
        };
    }
}
