<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\ContractDeadline;
use App\Models\ContractDeliverable;
use App\Models\ContractNotice;
use App\Models\ContractObligation;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use Carbon\Carbon;

class ContractIntelligenceSyncService
{
    /**
     * Detect schema version from confirmed data.
     * v2 has top-level "contract_overview"; v1 has "extracted_fields".
     */
    public function detectVersion(array $data): string
    {
        return isset($data['contract_overview']) ? 'v2' : 'v1';
    }

    /**
     * Sync confirmed intelligence to contract fields and seed dedicated tables.
     *
     * Returns a summary array describing what was written.
     */
    public function sync(ContractAiAnalysis $analysis, array $confirmedData, bool $overwrite = false): array
    {
        $contract = $analysis->contract;
        $version  = $this->detectVersion($confirmedData);

        $summary = [
            'schema_version' => $version,
            'fields_synced'  => [],
            'fields_skipped' => [],
            'tables_seeded'  => [],
            'warnings'       => [],
        ];

        $this->syncContractFields($contract, $confirmedData, $version, $overwrite, $summary);
        $this->seedMilestones($contract, $confirmedData, $version, $analysis->id, $summary);

        // Only seed intelligence tables once per analysis (idempotent guard)
        $alreadySeeded = ContractDeadline::where('contract_ai_analysis_id', $analysis->id)->exists();

        if (!$alreadySeeded) {
            if ($version === 'v2') {
                $this->seedDeadlines($contract, $analysis, $confirmedData, $summary);
                $this->seedNotices($contract, $analysis, $confirmedData, $summary);
                $this->seedDeliverables($contract, $analysis, $confirmedData, $summary);
            }
            // Risks seeded for both v1 and v2
            $this->seedRisks($contract, $analysis, $confirmedData, $version, $summary);
            $this->seedObligations($contract, $analysis, $confirmedData, $version, $summary);
        } else {
            $summary['tables_seeded'][] = 'intelligence tables: already seeded for this analysis (skipped)';
        }

        return $summary;
    }

    // ─── Contract field sync ──────────────────────────────────────────────────

    private function syncContractFields(
        Contract $contract,
        array    $data,
        string   $version,
        bool     $overwrite,
        array    &$summary
    ): void {
        $updates = [];
        // $should returns true if this field should be written
        $should  = fn($current) => $overwrite || empty($current);

        if ($version === 'v2') {
            $this->applyV2Fields($contract, $data, $should, $updates, $summary);
        } else {
            $this->applyV1Fields($contract, $data, $should, $updates, $summary);
        }

        if (!empty($updates)) {
            $contract->update($updates);
        }
    }

    private function applyV2Fields(Contract $contract, array $data, callable $should, array &$updates, array &$summary): void
    {
        $overview   = $data['contract_overview'] ?? [];
        $commercial = $data['commercial'] ?? [];
        $parties    = $data['parties'] ?? [];
        $dates      = $data['dates'] ?? [];

        // ── Overview ──────────────────────────────────────────────────────────

        $this->set($updates, $summary, $contract, 'title',
            $should($contract->title), $overview['contract_title'] ?? null);

        $this->set($updates, $summary, $contract, 'type',
            $should($contract->type), $overview['contract_type'] ?? null);

        $this->set($updates, $summary, $contract, 'form_of_contract',
            $should($contract->form_of_contract), $overview['standard_form'] ?? null);

        $this->set($updates, $summary, $contract, 'standard_form_edition',
            $should($contract->standard_form_edition ?? null), $overview['standard_form_edition'] ?? null);

        $this->set($updates, $summary, $contract, 'procurement_route',
            $should($contract->procurement_route ?? null), $overview['procurement_route'] ?? null);

        $this->set($updates, $summary, $contract, 'design_responsibility',
            $should($contract->design_responsibility ?? null), $overview['design_responsibility'] ?? null);

        $this->set($updates, $summary, $contract, 'governing_law',
            $should($contract->governing_law ?? null), $overview['governing_law'] ?? null);

        // ── Parties ───────────────────────────────────────────────────────────

        $contractorName = $parties['main_contractor']['name'] ?? $parties['subcontractor']['name'] ?? null;
        $this->set($updates, $summary, $contract, 'party_name',
            $should($contract->party_name), $contractorName);

        $this->set($updates, $summary, $contract, 'employer_name',
            $should($contract->employer_name ?? null), $parties['employer']['name'] ?? null);

        $this->set($updates, $summary, $contract, 'qs_name',
            $should($contract->qs_name ?? null), $parties['quantity_surveyor']['name'] ?? null);

        $this->set($updates, $summary, $contract, 'principal_designer',
            $should($contract->principal_designer ?? null), $parties['principal_designer']['name'] ?? null);

        $this->set($updates, $summary, $contract, 'principal_contractor',
            $should($contract->principal_contractor ?? null), $parties['principal_contractor']['name'] ?? null);

        // ── Commercial — money ─────────────────────────────────────────────────

        if ($should($contract->contract_sum) && isset($commercial['contract_sum'])) {
            $sum = (float) str_replace([',', '£', '$', '€', ' '], '', (string) $commercial['contract_sum']);
            if ($sum > 0) {
                $updates['contract_sum'] = $sum;
                $summary['fields_synced'][] = 'contract_sum';
            }
        }

        if ($should($contract->currency) && isset($commercial['currency'])) {
            $project   = $contract->project ?? \App\Models\Project::find($contract->project_id);
            $validated = $project
                ? CurrencyService::validateAiExtractedCode((string) $commercial['currency'], $project)
                : null;
            if ($validated) {
                $updates['currency'] = $validated;
                $summary['fields_synced'][] = 'currency';
            }
        }

        if ($should($contract->retention_percentage) && isset($commercial['retention_percent'])) {
            $r = (float) $commercial['retention_percent'];
            if ($r >= 0 && $r <= 100) {
                $updates['retention_percentage'] = $r;
                $summary['fields_synced'][] = 'retention_percentage';
            }
        }

        if ($should($contract->retention_cap_percentage) && isset($commercial['retention_cap_percent'])) {
            $rc = (float) $commercial['retention_cap_percent'];
            if ($rc >= 0 && $rc <= 100) {
                $updates['retention_cap_percentage'] = $rc;
                $summary['fields_synced'][] = 'retention_cap_percentage';
            }
        }

        $this->set($updates, $summary, $contract, 'retention_half1_release',
            $should($contract->retention_half1_release ?? null), $commercial['retention_half_1_release_event'] ?? null);

        $this->set($updates, $summary, $contract, 'retention_half2_release',
            $should($contract->retention_half2_release ?? null), $commercial['retention_half_2_release_event'] ?? null);

        $this->set($updates, $summary, $contract, 'valuation_method',
            $should($contract->valuation_method ?? null), $commercial['valuation_method'] ?? null);

        $this->set($updates, $summary, $contract, 'fluctuations_clause',
            $should($contract->fluctuations_clause ?? null), $commercial['fluctuations_clause'] ?? null);

        if ($should($contract->vat_reverse_charge ?? null) && isset($commercial['vat_reverse_charge_applicable'])) {
            $updates['vat_reverse_charge']    = (bool) $commercial['vat_reverse_charge_applicable'];
            $summary['fields_synced'][]        = 'vat_reverse_charge';
        }

        if ($should($contract->performance_bond_required ?? null) && isset($commercial['performance_bond_required'])) {
            $updates['performance_bond_required'] = (bool) $commercial['performance_bond_required'];
            $summary['fields_synced'][]            = 'performance_bond_required';
        }

        // Build liquidated_damages string from rate + per
        if ($should($contract->liquidated_damages) && !empty($commercial['liquidated_damages_rate'])) {
            $ld = (string) $commercial['liquidated_damages_rate'];
            if (!empty($commercial['liquidated_damages_per'])) {
                $ld .= ' per ' . $commercial['liquidated_damages_per'];
            }
            $updates['liquidated_damages']  = $ld;
            $summary['fields_synced'][]     = 'liquidated_damages';
        }

        // ── Commercial — payment offsets (integer columns) ────────────────────

        $offsetMap = [
            'due_date_offset_days'        => $commercial['due_date_offset_days']        ?? null,
            'final_date_offset_days'      => $commercial['final_date_offset_days']      ?? null,
            'payment_notice_offset_days'  => $commercial['payment_notice_offset_days']  ?? null,
            'pay_less_notice_offset_days' => $commercial['pay_less_notice_offset_days'] ?? null,
        ];

        foreach ($offsetMap as $column => $raw) {
            if (!$should($contract->{$column})) continue;
            if ($raw !== null && is_numeric($raw)) {
                $val = (int) $raw;
                if ($val >= 0 && $val <= 365) {
                    $updates[$column]           = $val;
                    $summary['fields_synced'][] = $column;
                }
            }
        }

        if ($should($contract->payment_frequency) && isset($commercial['interim_payment_frequency'])) {
            $freq    = strtolower(trim((string) $commercial['interim_payment_frequency']));
            $allowed = ['weekly', 'fortnightly', 'monthly', 'manual'];
            foreach ($allowed as $a) {
                if (str_contains($freq, $a)) {
                    $updates['payment_frequency']   = $a;
                    $summary['fields_synced'][]      = 'payment_frequency';
                    break;
                }
            }
        }

        // ── Dates ──────────────────────────────────────────────────────────────

        $dateMap = [
            'commencement_date' => $dates['commencement_date'] ?? null,
            'completion_date'   => $dates['completion_date']   ?? null,
            'execution_date'    => $dates['execution_date']    ?? null,
            'possession_date'   => $dates['possession_date']   ?? null,
            'base_date'         => $dates['base_date']         ?? null,
        ];

        foreach ($dateMap as $column => $raw) {
            if (!$should($contract->{$column})) continue;
            if ($raw) {
                try {
                    $updates[$column]           = Carbon::parse($raw)->format('Y-m-d');
                    $summary['fields_synced'][] = $column;
                } catch (\Throwable) {
                    $summary['warnings'][] = "Could not parse date for {$column}: {$raw}";
                }
            }
        }

        $this->set($updates, $summary, $contract, 'defects_liability_period',
            $should($contract->defects_liability_period), $dates['defects_liability_period'] ?? null);

        if ($should($contract->defects_liability_period_months ?? null) && isset($dates['defects_liability_period_months'])) {
            $m = (int) $dates['defects_liability_period_months'];
            if ($m > 0) {
                $updates['defects_liability_period_months'] = $m;
                $summary['fields_synced'][]                  = 'defects_liability_period_months';
            }
        }
    }

    private function applyV1Fields(Contract $contract, array $data, callable $should, array &$updates, array &$summary): void
    {
        $fields = $data['extracted_fields'] ?? $data;

        // Party / identity
        if ($should($contract->party_name)) {
            $party = $fields['contractor'] ?? $fields['contracting_party'] ?? null;
            if ($party) { $updates['party_name'] = (string) $party; $summary['fields_synced'][] = 'party_name'; }
        }
        $this->set($updates, $summary, $contract, 'form_of_contract',
            $should($contract->form_of_contract), $fields['form_of_contract'] ?? null);

        // Financial
        if ($should($contract->contract_sum) && isset($fields['contract_sum'])) {
            $sum = (float) str_replace([',', '£', '$', '€'], '', (string) $fields['contract_sum']);
            if ($sum > 0) { $updates['contract_sum'] = $sum; $summary['fields_synced'][] = 'contract_sum'; }
        }

        if ($should($contract->currency) && isset($fields['currency'])) {
            $project   = $contract->project ?? \App\Models\Project::find($contract->project_id);
            $validated = $project ? CurrencyService::validateAiExtractedCode((string) $fields['currency'], $project) : null;
            if ($validated) { $updates['currency'] = $validated; $summary['fields_synced'][] = 'currency'; }
        }

        if ($should($contract->retention_percentage) && isset($fields['retention_percent'])) {
            $r = (float) $fields['retention_percent'];
            if ($r >= 0 && $r <= 100) { $updates['retention_percentage'] = $r; $summary['fields_synced'][] = 'retention_percentage'; }
        }

        if ($should($contract->retention_cap_percentage) && isset($fields['retention_cap_percent'])) {
            $rc = (float) $fields['retention_cap_percent'];
            if ($rc >= 0 && $rc <= 100) { $updates['retention_cap_percentage'] = $rc; $summary['fields_synced'][] = 'retention_cap_percentage'; }
        }

        // Dates
        foreach (['commencement_date', 'completion_date'] as $field) {
            if ($should($contract->{$field}) && isset($fields[$field])) {
                try {
                    $updates[$field]            = Carbon::parse($fields[$field])->format('Y-m-d');
                    $summary['fields_synced'][] = $field;
                } catch (\Throwable) {}
            }
        }

        // Commercial clauses
        $this->set($updates, $summary, $contract, 'defects_liability_period',
            $should($contract->defects_liability_period), $fields['defects_period'] ?? null);

        $this->set($updates, $summary, $contract, 'liquidated_damages',
            $should($contract->liquidated_damages), $fields['liquidated_damages'] ?? null);

        $this->set($updates, $summary, $contract, 'notice_requirements',
            $should($contract->notice_requirements), $fields['notice_requirements'] ?? null);

        $this->set($updates, $summary, $contract, 'variation_procedure',
            $should($contract->variation_procedure), $fields['variation_procedure'] ?? null);

        // Payment offsets
        $offsetMap = [
            'due_date_offset_days'        => ['due_date_offset_days', 'due_date_offset'],
            'final_date_offset_days'      => ['final_date_offset_days', 'final_date_offset'],
            'payment_notice_offset_days'  => ['payment_notice_offset_days', 'payment_notice_offset'],
            'pay_less_notice_offset_days' => ['pay_less_notice_offset_days', 'pay_less_notice_offset'],
        ];
        foreach ($offsetMap as $column => $keys) {
            if (!$should($contract->{$column})) continue;
            foreach ($keys as $key) {
                if (isset($fields[$key]) && is_numeric($fields[$key])) {
                    $val = (int) $fields[$key];
                    if ($val >= 0 && $val <= 365) {
                        $updates[$column]           = $val;
                        $summary['fields_synced'][] = $column;
                        break;
                    }
                }
            }
        }

        if ($should($contract->payment_terms_days) && isset($fields['payment_terms_days'])) {
            $raw  = $fields['payment_terms_days'];
            $days = is_numeric($raw) ? (int) $raw
                : (preg_match('/\b(\d+)\s+days?\b/i', (string) $raw, $m) ? (int) $m[1] : 0);
            if ($days >= 1 && $days <= 365) {
                $updates['payment_terms_days']  = $days;
                $summary['fields_synced'][]      = 'payment_terms_days';
            }
        }

        if ($should($contract->payment_frequency) && isset($fields['payment_frequency'])) {
            $freq = strtolower(trim((string) $fields['payment_frequency']));
            foreach (['weekly', 'fortnightly', 'monthly', 'manual'] as $a) {
                if (str_contains($freq, $a)) {
                    $updates['payment_frequency']   = $a;
                    $summary['fields_synced'][]      = 'payment_frequency';
                    break;
                }
            }
        }

        // Payment rule text fields
        $ruleText = (string) ($fields['payment_application_rules'] ?? '');

        $ruleFields = [
            'payment_due_date_rule'          => ['due_date_rule', 'payment_due_date_rule'],
            'final_date_for_payment_rule'    => ['final_date_rule', 'final_date_for_payment_rule'],
            'payment_notice_deadline_rule'   => ['payment_notice_rule', 'payment_notice_deadline_rule'],
            'pay_less_notice_deadline_rule'  => ['pay_less_notice_rule', 'pay_less_notice_deadline_rule'],
        ];
        foreach ($ruleFields as $column => $keys) {
            if (!$should($contract->{$column} ?? null)) continue;
            $found = null;
            foreach ($keys as $key) {
                if (!empty($fields[$key])) { $found = (string) $fields[$key]; break; }
            }
            if ($found) {
                $updates[$column]           = $found;
                $summary['fields_synced'][] = $column;
            }
        }

        // JSON blobs (v1 only — v2 goes to dedicated tables)
        if ($should($contract->key_dates) && !empty($data['key_dates']) && is_array($data['key_dates'])) {
            $updates['key_dates']           = $data['key_dates'];
            $summary['fields_synced'][]      = 'key_dates';
        }
        if ($should($contract->key_obligations) && !empty($data['key_obligations']) && is_array($data['key_obligations'])) {
            $updates['key_obligations']     = $data['key_obligations'];
            $summary['fields_synced'][]      = 'key_obligations';
        }
        if ($should($contract->risks) && !empty($data['risks']) && is_array($data['risks'])) {
            $updates['risks']               = $data['risks'];
            $summary['fields_synced'][]      = 'risks';
        }
    }

    // ─── Programme milestones ─────────────────────────────────────────────────

    private function seedMilestones(Contract $contract, array $data, string $version, int $analysisId, array &$summary): void
    {
        $milestones = [];

        if ($version === 'v2') {
            $dates = $data['dates'] ?? [];

            foreach ($dates['key_milestones'] ?? [] as $m) {
                if (empty($m['name'])) continue;
                $milestones[] = [
                    'name'           => $m['name'],
                    'milestone_type' => 'key_milestone',
                    'planned_date'   => $this->parseDate($m['date'] ?? null),
                    'notes'          => $m['notes'] ?? null,
                    'source_text'    => $m['clause'] ?? null,
                ];
            }

            foreach ($dates['sectional_completions'] ?? [] as $s) {
                $label = $s['section'] ?? $s['description'] ?? null;
                if (empty($label)) continue;
                $milestones[] = [
                    'name'           => $label,
                    'milestone_type' => 'sectional_completion',
                    'planned_date'   => $this->parseDate($s['completion_date'] ?? null),
                    'notes'          => $s['description'] ?? null,
                    'source_text'    => null,
                ];
            }
        } else {
            foreach ($data['key_dates'] ?? [] as $d) {
                if (empty($d['name'])) continue;
                $milestones[] = [
                    'name'           => $d['name'],
                    'milestone_type' => 'key_milestone',
                    'planned_date'   => $this->parseDate($d['date'] ?? null),
                    'notes'          => null,
                    'source_text'    => $d['source'] ?? null,
                ];
            }
        }

        if (empty($milestones)) return;

        // Delete + re-seed milestones from this analysis (idempotent)
        ContractProgrammeMilestone::where('contract_id', $contract->id)
            ->where('analysis_id', $analysisId)
            ->delete();

        foreach ($milestones as $ms) {
            ContractProgrammeMilestone::create([
                'contract_id'     => $contract->id,
                'project_id'      => $contract->project_id,
                'analysis_id'     => $analysisId,
                'name'            => $ms['name'],
                'milestone_type'  => $ms['milestone_type'],
                'planned_date'    => $ms['planned_date'],
                'notes'           => $ms['notes'],
                'source_text'     => $ms['source_text'],
                'is_ai_generated' => true,
                'status'          => 'not_started',
            ]);
        }

        $summary['tables_seeded'][] = 'contract_programme_milestones (' . count($milestones) . ')';
    }

    // ─── Intelligence table seeding ───────────────────────────────────────────

    private function seedDeadlines(Contract $contract, ContractAiAnalysis $analysis, array $data, array &$summary): void
    {
        $items = $data['deadlines'] ?? [];
        if (empty($items)) return;

        $count = 0;
        foreach ($items as $item) {
            if (empty($item['name'])) continue;
            ContractDeadline::create([
                'organization_id'               => $contract->organization_id,
                'project_id'                    => $contract->project_id,
                'contract_id'                   => $contract->id,
                'contract_ai_analysis_id'       => $analysis->id,
                'name'                          => $item['name'],
                'category'                      => $item['category']          ?? 'other',
                'responsible_party'             => $item['responsible_party'] ?? null,
                'time_period_text'              => $item['time_period_text']  ?? null,
                'time_period_days'              => $this->toInt($item['time_period_days'] ?? null),
                'time_direction'                => $item['time_direction']    ?? null,
                'trigger_event'                 => $item['trigger_event']     ?? null,
                'recipient'                     => $item['recipient']         ?? null,
                'clause_reference'              => $item['clause_reference']  ?? null,
                'consequence_of_non_compliance' => $item['consequence_of_non_compliance'] ?? null,
                'is_statutory'                  => (bool) ($item['is_statutory']  ?? false),
                'is_recurring'                  => (bool) ($item['is_recurring']  ?? false),
                'recurrence_description'        => $item['recurrence_description'] ?? null,
                'notes'                         => $item['notes']            ?? null,
                'generates_calendar_event'      => (bool) ($item['generates_calendar_event'] ?? true),
                'generates_notification'        => (bool) ($item['generates_notification']   ?? true),
                'is_ai_generated'               => true,
                'confirmed_at'                  => now(),
            ]);
            $count++;
        }

        $summary['tables_seeded'][] = "contract_deadlines ({$count})";
    }

    private function seedNotices(Contract $contract, ContractAiAnalysis $analysis, array $data, array &$summary): void
    {
        $items = $data['notices'] ?? [];
        if (empty($items)) return;

        $count = 0;
        foreach ($items as $item) {
            if (empty($item['name'])) continue;
            ContractNotice::create([
                'organization_id'         => $contract->organization_id,
                'project_id'              => $contract->project_id,
                'contract_id'             => $contract->id,
                'contract_ai_analysis_id' => $analysis->id,
                'name'                    => $item['name'],
                'notice_type'             => $item['notice_type']       ?? 'other',
                'is_statutory'            => (bool) ($item['is_statutory'] ?? false),
                'responsible_party'       => $item['responsible_party'] ?? null,
                'trigger'                 => $item['trigger']           ?? null,
                'time_limit_days'         => $this->toInt($item['time_limit_days'] ?? null),
                'time_direction'          => $item['time_direction']    ?? null,
                'time_reference_point'    => $item['time_reference_point'] ?? null,
                'recipient'               => $item['recipient']         ?? null,
                'clause_reference'        => $item['clause_reference']  ?? null,
                'required_content'        => $item['required_content']  ?? [],
                'consequence_if_missed'   => $item['consequence_if_missed'] ?? null,
                'can_be_withheld'         => (bool) ($item['can_be_withheld'] ?? false),
                'notes'                   => $item['notes']             ?? null,
                'is_ai_generated'         => true,
                'confirmed_at'            => now(),
            ]);
            $count++;
        }

        $summary['tables_seeded'][] = "contract_notices ({$count})";
    }

    private function seedDeliverables(Contract $contract, ContractAiAnalysis $analysis, array $data, array &$summary): void
    {
        $items = $data['deliverables'] ?? [];
        if (empty($items)) return;

        $count = 0;
        foreach ($items as $item) {
            if (empty($item['name'])) continue;
            ContractDeliverable::create([
                'organization_id'             => $contract->organization_id,
                'project_id'                  => $contract->project_id,
                'contract_id'                 => $contract->id,
                'contract_ai_analysis_id'     => $analysis->id,
                'name'                        => $item['name'],
                'category'                    => $item['category']          ?? 'other',
                'required'                    => (bool) ($item['required']  ?? true),
                'responsible_party'           => $item['responsible_party'] ?? null,
                'due_event'                   => $item['due_event']         ?? null,
                'due_days_before_after_event' => $this->toInt($item['due_days_before_after_event'] ?? null),
                'format'                      => $item['format']            ?? null,
                'copies_required'             => $item['copies_required']   ?? null,
                'clause_reference'            => $item['clause_reference']  ?? null,
                'recipient'                   => $item['recipient']         ?? null,
                'consequence_if_late'         => $item['consequence_if_late'] ?? null,
                'notes'                       => $item['notes']             ?? null,
                'status'                      => 'pending',
                'is_ai_generated'             => true,
                'confirmed_at'                => now(),
            ]);
            $count++;
        }

        $summary['tables_seeded'][] = "contract_deliverables ({$count})";
    }

    private function seedRisks(Contract $contract, ContractAiAnalysis $analysis, array $data, string $version, array &$summary): void
    {
        $items = $data['risks'] ?? [];
        if (empty($items)) return;

        $count = 0;
        foreach ($items as $item) {
            if (empty($item['title'])) continue;
            ContractRisk::create([
                'organization_id'           => $contract->organization_id,
                'project_id'                => $contract->project_id,
                'contract_id'               => $contract->id,
                'contract_ai_analysis_id'   => $analysis->id,
                'title'                     => $item['title'],
                'description'               => $item['description']       ?? null,
                'severity'                  => $item['severity']          ?? 'medium',
                'category'                  => $item['category']          ?? 'other',
                // v1 uses 'source', v2 uses 'clause_reference'
                'clause_reference'          => $item['clause_reference']  ?? ($item['source'] ?? null),
                'commercial_impact'         => $item['commercial_impact'] ?? null,
                'programme_impact'          => $item['programme_impact']  ?? null,
                'compliance_impact'         => $item['compliance_impact'] ?? null,
                'urgency'                   => $item['urgency']           ?? 'monitor',
                'recommended_action'        => $item['recommended_action'] ?? null,
                'risk_owner'                => $item['risk_owner']        ?? null,
                'is_non_standard_amendment' => (bool) ($item['is_non_standard_amendment'] ?? false),
                'status'                    => 'open',
                'is_ai_generated'           => true,
                'confirmed_at'              => now(),
            ]);
            $count++;
        }

        $summary['tables_seeded'][] = "contract_risks ({$count})";
    }

    private function seedObligations(Contract $contract, ContractAiAnalysis $analysis, array $data, string $version, array &$summary): void
    {
        $count = 0;

        if ($version === 'v2') {
            $obligations = $data['obligations'] ?? [];
            foreach ($obligations as $party => $items) {
                if (!is_array($items)) continue;
                foreach ($items as $item) {
                    if (empty($item['title'])) continue;
                    ContractObligation::create([
                        'organization_id'         => $contract->organization_id,
                        'project_id'              => $contract->project_id,
                        'contract_id'             => $contract->id,
                        'contract_ai_analysis_id' => $analysis->id,
                        'party'                   => $party,
                        'title'                   => $item['title'],
                        'description'             => $item['description']        ?? null,
                        'clause_reference'        => $item['clause_reference']   ?? null,
                        'time_period_text'        => $item['time_period_text']    ?? null,
                        'time_period_days'        => $this->toInt($item['time_period_days'] ?? null),
                        'trigger_event'           => $item['trigger_event']       ?? null,
                        'consequence_if_missed'   => $item['consequence_if_missed'] ?? null,
                        'generates_deadline'      => (bool) ($item['generates_deadline'] ?? false),
                        'category'                => $item['category']            ?? 'other',
                        'is_ai_generated'         => true,
                        'confirmed_at'            => now(),
                    ]);
                    $count++;
                }
            }
        } else {
            // v1: flat key_obligations array
            foreach ($data['key_obligations'] ?? [] as $item) {
                if (empty($item['title'])) continue;
                ContractObligation::create([
                    'organization_id'         => $contract->organization_id,
                    'project_id'              => $contract->project_id,
                    'contract_id'             => $contract->id,
                    'contract_ai_analysis_id' => $analysis->id,
                    'party'                   => $item['responsible_party'] ?? 'both',
                    'title'                   => $item['title'],
                    'description'             => $item['description']      ?? null,
                    'clause_reference'        => $item['source']           ?? null,
                    'time_period_days'        => $this->toInt($item['due_days_from_commencement'] ?? null),
                    'generates_deadline'      => false,
                    'category'                => 'other',
                    'is_ai_generated'         => true,
                    'confirmed_at'            => now(),
                ]);
                $count++;
            }
        }

        if ($count > 0) {
            $summary['tables_seeded'][] = "contract_obligations ({$count})";
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function set(array &$updates, array &$summary, Contract $contract, string $column, bool $shouldSet, mixed $value): void
    {
        if (!$shouldSet || empty($value)) return;
        $updates[$column]           = (string) $value;
        $summary['fields_synced'][] = $column;
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
