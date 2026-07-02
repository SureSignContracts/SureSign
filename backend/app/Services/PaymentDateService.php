<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\TradePackage;
use Carbon\Carbon;

/**
 * Calculates payment application dates from contract rule fields and / or
 * confirmed AI analysis data.
 *
 * Priority order:
 *   1. Confirmed AI contract analysis (confirmed_data_json)
 *   2. Contract rule fields (payment_due_date_rule, etc.)
 *   3. payment_terms_days as final-date offset of last resort
 */
class PaymentDateService
{
    /**
     * Calculate all four payment dates from the application date.
     *
     * Returns an array with keys:
     *   due_date, final_date_for_payment,
     *   payment_notice_deadline, pay_less_notice_deadline
     *
     * Any date that cannot be determined is returned as null.
     */
    public static function calculateForApplication(
        Carbon               $applicationDate,
        Contract             $contract,
        ?ContractAiAnalysis  $aiAnalysis = null
    ): array {
        $rules = self::extractRules($contract, $aiAnalysis);

        return self::computeDates($applicationDate, $rules);
    }

    /**
     * Calculate the four payment dates for a TRADE PACKAGE (subcontract) payment
     * application, using the package's own payment rule offset columns.
     *
     * Trade packages have no AI analysis — their rules are the persisted offset
     * columns, with payment_terms_days as a final-date fallback. Returns the same
     * shape as calculateForApplication(); any date that cannot be derived is null.
     */
    public static function calculateForTradePackageApplication(
        Carbon       $applicationDate,
        TradePackage $tradePackage
    ): array {
        $rules = self::extractTradePackageRules($tradePackage);

        return self::computeDates($applicationDate, $rules);
    }

    /**
     * Shared date arithmetic — turns a set of day offsets into the four dates.
     */
    private static function computeDates(Carbon $applicationDate, array $rules): array
    {
        $dueDate    = null;
        $finalDate  = null;
        $noticeDate = null;
        $payLess    = null;

        // Due Date = application date + due_date_offset days
        if (isset($rules['due_date_offset'])) {
            $dueDate = $applicationDate->copy()->addDays($rules['due_date_offset']);
        }

        // Final Date = due date + final_date_offset (or application date if no due date rule)
        if ($dueDate && isset($rules['final_date_offset'])) {
            $finalDate = $dueDate->copy()->addDays($rules['final_date_offset']);
        } elseif (!$dueDate && isset($rules['final_date_offset'])) {
            $finalDate = $applicationDate->copy()->addDays($rules['final_date_offset']);
        }

        // Payment Notice Deadline = due date + notice_offset days
        if ($dueDate && isset($rules['notice_offset'])) {
            $noticeDate = $dueDate->copy()->addDays($rules['notice_offset']);
        }

        // Pay Less Notice Deadline = final date − pay_less_offset days (offset stored as positive)
        if ($finalDate && isset($rules['pay_less_offset'])) {
            $payLess = $finalDate->copy()->subDays(abs($rules['pay_less_offset']));
        }

        return [
            'due_date'                 => $dueDate?->format('Y-m-d'),
            'final_date_for_payment'   => $finalDate?->format('Y-m-d'),
            'payment_notice_deadline'  => $noticeDate?->format('Y-m-d'),
            'pay_less_notice_deadline' => $payLess?->format('Y-m-d'),
        ];
    }

    /**
     * Build day-offset rules from a trade package's own payment rule columns.
     */
    private static function extractTradePackageRules(TradePackage $tradePackage): array
    {
        $colMap = [
            'due_date_offset_days'        => 'due_date_offset',
            'final_date_offset_days'      => 'final_date_offset',
            'payment_notice_offset_days'  => 'notice_offset',
            'pay_less_notice_offset_days' => 'pay_less_offset',
        ];

        $rules = [];
        foreach ($colMap as $col => $ruleKey) {
            $val = $tradePackage->$col ?? null;
            if ($val !== null && $val >= 0 && $val <= 365) {
                $rules[$ruleKey] = (int) $val;
            }
        }

        if (!empty($rules)) {
            return $rules;
        }

        // Fallback: payment_terms_days as a final-date offset of last resort
        $termDays = (int) ($tradePackage->payment_terms_days ?? 0);
        if ($termDays >= 1 && $termDays <= 365) {
            return ['final_date_offset' => $termDays];
        }

        return [];
    }

    // ─── Rule extraction ──────────────────────────────────────────────────────

    private static function extractRules(Contract $contract, ?ContractAiAnalysis $aiAnalysis): array
    {
        // Priority 0: persisted integer offset columns on the contract (most authoritative)
        $colMap = [
            'due_date_offset_days'        => 'due_date_offset',
            'final_date_offset_days'      => 'final_date_offset',
            'payment_notice_offset_days'  => 'notice_offset',
            'pay_less_notice_offset_days' => 'pay_less_offset',
        ];
        $colRules = [];
        foreach ($colMap as $col => $ruleKey) {
            $val = $contract->$col ?? null;
            if ($val !== null && $val >= 0 && $val <= 365) {
                $colRules[$ruleKey] = (int) $val;
            }
        }
        if (!empty($colRules)) {
            return $colRules;
        }

        // Priority 1: confirmed AI analysis
        if ($aiAnalysis?->isConfirmed() && !empty($aiAnalysis->confirmed_data_json)) {
            $aiRules = self::parseFromAiData($aiAnalysis->confirmed_data_json);
            if (!empty($aiRules)) {
                return $aiRules;
            }
        }

        // Priority 2: contract rule fields
        $rules = [];

        $fieldMap = [
            'payment_due_date_rule'        => 'due_date_offset',
            'final_date_for_payment_rule'  => 'final_date_offset',
            'payment_notice_deadline_rule' => 'notice_offset',
            'pay_less_notice_deadline_rule'=> 'pay_less_offset',
        ];

        foreach ($fieldMap as $field => $ruleKey) {
            $ruleStr = $contract->$field ?? null;
            if (!empty($ruleStr)) {
                $offset = self::extractDaysFromString($ruleStr, $ruleKey === 'pay_less_offset');
                if ($offset !== null) {
                    $rules[$ruleKey] = $offset;
                }
            }
        }

        if (!empty($rules)) {
            return $rules;
        }

        // Priority 3: payment_terms_days as final-date offset (e.g. "30 day terms")
        $termDays = (int) ($contract->payment_terms_days ?? 0);
        if ($termDays >= 1 && $termDays <= 365) {
            return ['final_date_offset' => $termDays];
        }

        return [];
    }

    private static function parseFromAiData(array $data): array
    {
        $rules = [];

        // Search in common nesting paths used by AI analysis output
        // Include extracted_fields (standard AI analysis schema) and payment sub-keys
        $sections = array_filter([
            $data['extracted_fields']   ?? null,
            $data['payment_terms']      ?? null,
            $data['payment_provisions'] ?? null,
            $data['commercial_terms']   ?? null,
            $data['payment']            ?? null,
            $data,
        ], 'is_array');

        $intKeys = [
            'due_date_offset_days'              => 'due_date_offset',
            'due_date_days'                     => 'due_date_offset',
            'final_date_offset_days'            => 'final_date_offset',
            'final_date_days'                   => 'final_date_offset',
            'final_date_for_payment_days'       => 'final_date_offset',
            'payment_notice_offset_days'        => 'notice_offset',
            'payment_notice_days'               => 'notice_offset',
            'pay_less_notice_offset_days'       => 'pay_less_offset',
            'pay_less_notice_days'              => 'pay_less_offset',
        ];

        $strKeys = [
            'due_date_rule'                => 'due_date_offset',
            'payment_due_date_rule'        => 'due_date_offset',
            'final_date_rule'              => 'final_date_offset',
            'final_date_for_payment_rule'  => 'final_date_offset',
            'payment_notice_rule'          => 'notice_offset',
            'payment_notice_deadline_rule' => 'notice_offset',
            'pay_less_notice_rule'         => 'pay_less_offset',
            'pay_less_notice_deadline_rule'=> 'pay_less_offset',
            // AI analysis schema fields — combined free-text rules strings
            'payment_application_rules'    => '_combined',
            'payment_terms_days'           => '_combined',
        ];

        foreach ($sections as $section) {
            foreach ($intKeys as $aiKey => $ruleKey) {
                if (!isset($rules[$ruleKey]) && isset($section[$aiKey]) && is_numeric($section[$aiKey])) {
                    $rules[$ruleKey] = (int) $section[$aiKey];
                }
            }
            foreach ($strKeys as $aiKey => $ruleKey) {
                if (!isset($section[$aiKey]) || !is_string($section[$aiKey])) continue;
                $text = $section[$aiKey];

                if ($ruleKey === '_combined') {
                    // Parse combined rules text (e.g. "Due Date is 7 days after Interim Valuation Date.
                    // Final Date for Payment is 21 days from Due Date. Pay Less Notice 5 days before.")
                    if (!isset($rules['due_date_offset'])) {
                        if (preg_match('/due\s+date[^.]*?(\d+)\s+days?/i', $text, $m)) {
                            $d = (int) $m[1];
                            if ($d >= 1 && $d <= 365) $rules['due_date_offset'] = $d;
                        }
                    }
                    if (!isset($rules['final_date_offset'])) {
                        if (preg_match('/final\s+date[^.]*?(\d+)\s+days?/i', $text, $m)) {
                            $d = (int) $m[1];
                            if ($d >= 1 && $d <= 365) $rules['final_date_offset'] = $d;
                        }
                    }
                    if (!isset($rules['notice_offset'])) {
                        if (preg_match('/payment\s+notice[^.]*?(\d+)\s+days?/i', $text, $m)) {
                            $d = (int) $m[1];
                            if ($d >= 1 && $d <= 365) $rules['notice_offset'] = $d;
                        }
                    }
                    if (!isset($rules['pay_less_offset'])) {
                        if (preg_match('/pay\s+less[^.]*?(\d+)\s+days?/i', $text, $m)) {
                            $d = (int) $m[1];
                            if ($d >= 1 && $d <= 365) $rules['pay_less_offset'] = $d;
                        }
                    }
                } else {
                    if (!isset($rules[$ruleKey])) {
                        $isPayLess = ($ruleKey === 'pay_less_offset');
                        $offset    = self::extractDaysFromString($text, $isPayLess);
                        if ($offset !== null) {
                            $rules[$ruleKey] = $offset;
                        }
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Parse a rule string and return an integer day offset.
     *
     * "7 days after ..."         → 7
     * "21 days from the due date"→ 21
     * "5 days before ..."        → 5 (caller decides sign based on $negate)
     * "within 5 days of ..."     → 5
     */
    private static function extractDaysFromString(string $rule, bool $negate = false): ?int
    {
        if (preg_match('/(\d+)\s+days?/i', $rule, $m)) {
            $days = (int) $m[1];
            if ($days < 1 || $days > 365) return null;
            return $days;
        }
        return null;
    }
}
