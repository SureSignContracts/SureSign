<?php

namespace App\Services\TradePackages;

use App\Models\ContractProgrammeMilestone;
use App\Models\TradePackage;
use App\Models\TradePackageAiAnalysis;
use Carbon\Carbon;

/**
 * Sibling to ContractIntelligenceSyncService, scoped to Trade Packages.
 *
 * Deliberately does NOT extend/modify ContractIntelligenceSyncService — see
 * Sprint 6B review: that service is hard-wired to the Contract model, and six
 * of its seven write targets have no trade-package equivalent. This service
 * only writes to destinations that genuinely exist today: TradePackage
 * columns and ContractProgrammeMilestone (which already has trade_package_id).
 */
class TradePackageIntelligenceSyncService
{
    /**
     * Sync confirmed subcontract intelligence to TradePackage fields and
     * seed programme milestones. Returns a summary of what was written.
     */
    public function sync(TradePackageAiAnalysis $analysis, array $confirmedData, bool $overwrite = false): array
    {
        $tradePackage = $analysis->tradePackage;

        $summary = [
            'fields_synced'  => [],
            'fields_skipped' => [],
            'tables_seeded'  => [],
        ];

        $this->syncTradePackageFields($tradePackage, $confirmedData, $overwrite, $summary);
        $this->seedMilestones($tradePackage, $confirmedData, $summary);

        return $summary;
    }

    // ─── TradePackage field sync ──────────────────────────────────────────────

    private function syncTradePackageFields(TradePackage $tradePackage, array $data, bool $overwrite, array &$summary): void
    {
        $contractor = $data['contractor'] ?? [];
        $commercial = $data['commercial'] ?? [];
        $dates      = $data['key_dates'] ?? [];

        $updates = [];
        $should  = fn ($current) => $overwrite || empty($current);

        $this->set($updates, $should, $tradePackage->contractor_name, 'contractor_name', $contractor['name'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_contact_name, 'contractor_contact_name', $contractor['contact_name'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_email, 'contractor_email', $contractor['email'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_phone, 'contractor_phone', $contractor['phone'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_address, 'contractor_address', $contractor['address'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_company_reg_no, 'contractor_company_reg_no', $contractor['company_registration_number'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->contractor_vat_number, 'contractor_vat_number', $contractor['vat_number'] ?? null, $summary);

        $this->set($updates, $should, $tradePackage->contract_value, 'contract_value', $commercial['subcontract_sum'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->retention_percentage, 'retention_percentage', $commercial['retention_percentage'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->liquidated_damages, 'liquidated_damages', $commercial['liquidated_damages'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->payment_terms_days, 'payment_terms_days', $this->toInt($commercial['payment_terms_days'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->payment_frequency, 'payment_frequency', $commercial['payment_frequency'] ?? null, $summary);
        $this->set($updates, $should, $tradePackage->due_date_offset_days, 'due_date_offset_days', $this->toInt($commercial['due_date_offset_days'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->final_date_offset_days, 'final_date_offset_days', $this->toInt($commercial['final_date_offset_days'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->payment_notice_offset_days, 'payment_notice_offset_days', $this->toInt($commercial['payment_notice_offset_days'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->pay_less_notice_offset_days, 'pay_less_notice_offset_days', $this->toInt($commercial['pay_less_notice_offset_days'] ?? null), $summary);

        $this->set($updates, $should, $tradePackage->letter_of_intent_date, 'letter_of_intent_date', $this->parseDate($dates['letter_of_intent_date'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->award_date, 'award_date', $this->parseDate($dates['award_date'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->execution_date, 'execution_date', $this->parseDate($dates['execution_date'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->commencement_date, 'commencement_date', $this->parseDate($dates['commencement_date'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->completion_date, 'completion_date', $this->parseDate($dates['completion_date'] ?? null), $summary);
        $this->set($updates, $should, $tradePackage->defects_liability_end_date, 'defects_liability_end_date', $this->parseDate($dates['defects_liability_end_date'] ?? null), $summary);

        if (!empty($updates)) {
            $tradePackage->update($updates);
        }
    }

    private function set(array &$updates, callable $should, mixed $current, string $field, mixed $value, array &$summary): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (!$should($current)) {
            $summary['fields_skipped'][] = $field;
            return;
        }
        $updates[$field] = $value;
        $summary['fields_synced'][] = $field;
    }

    // ─── Programme milestones ─────────────────────────────────────────────────

    private function seedMilestones(TradePackage $tradePackage, array $data, array &$summary): void
    {
        $milestones = [];

        foreach ($data['programme_milestones'] ?? [] as $m) {
            if (empty($m['name'])) continue;
            $milestones[] = [
                'name'         => $m['name'],
                'planned_date' => $this->parseDate($m['date'] ?? null),
                'notes'        => $m['notes'] ?? null,
            ];
        }

        if (empty($milestones)) return;

        // Delete + re-seed AI-generated milestones for this package (idempotent).
        // No analysis_id link here — that FK is constrained to contract_ai_analyses,
        // not trade_package_ai_analyses — so re-runs are scoped by trade_package_id
        // + is_ai_generated instead.
        ContractProgrammeMilestone::where('trade_package_id', $tradePackage->id)
            ->where('is_ai_generated', true)
            ->delete();

        foreach ($milestones as $ms) {
            ContractProgrammeMilestone::create([
                'trade_package_id' => $tradePackage->id,
                'project_id'       => $tradePackage->project_id,
                'name'             => $ms['name'],
                'milestone_type'   => 'key_milestone',
                'planned_date'     => $ms['planned_date'],
                'notes'            => $ms['notes'],
                'is_ai_generated'  => true,
                'status'           => 'not_started',
            ]);
        }

        $summary['tables_seeded'][] = 'contract_programme_milestones (' . count($milestones) . ')';
    }

    private function parseDate(?string $raw): ?string
    {
        if (empty($raw)) return null;
        try { return Carbon::parse($raw)->format('Y-m-d'); }
        catch (\Throwable) { return null; }
    }

    private function toInt(mixed $val): ?int
    {
        if ($val === null || !is_numeric($val)) return null;
        return (int) $val;
    }
}
