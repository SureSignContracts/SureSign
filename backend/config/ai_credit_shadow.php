<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Credit Shadow Accounting — Active Candidate (Phase G4C.3BC)
    |--------------------------------------------------------------------------
    |
    | Selects which ALREADY-CONFIGURED candidate policy from
    | config/ai_credit_simulation_policies.php is used to resolve the amount
    | reserved/settled in the G4C.3A ledger during shadow-mode workflow
    | integration.
    |
    | `null` (the default) means shadow accounting is disabled: no reservation
    | is attempted for any workflow, and every execution records
    | shadow_enforcement_result = 'unresolved' explicitly rather than skipping
    | silently. This is a deliberate default — nothing should implicitly pick
    | a candidate on your behalf.
    |
    | Must name a candidate key that actually exists under BOTH
    | 'contract_analysis' and 'trade_package_analysis' in
    | config/ai_credit_simulation_policies.php (e.g. 'candidate_a' or
    | 'candidate_b') — App\Services\AI\AiCreditSimulator::resolveShadowAmount()
    | returns null (never a guess) if the named key doesn't exist for a given
    | workflow.
    */

    'active_candidate' => env('AI_CREDIT_SHADOW_CANDIDATE', null),

    /*
    |--------------------------------------------------------------------------
    | Approved Candidate (Phase G4C.3G — Founder Approval Record)
    |--------------------------------------------------------------------------
    |
    | The ONE place a real founder approval of a candidate policy is
    | recorded — deliberately a SEPARATE value from `active_candidate`
    | above, even though they hold the same value today, because
    | "what we operationally use" and "what has actually been approved"
    | are different questions that must never be silently conflated. This
    | is the only thing `AiTelemetryReportingService::simulationSummary()`'s
    | `is_approved_policy` field reads — nothing else in this codebase treats
    | this value as authorising anything (no enforcement, no billing).
    |
    | `null` means no candidate has been approved — every candidate's
    | `is_approved_policy` reads false, as it always did before this phase.
    |
    | Approved 2026-07-27: `candidate_b` (banded) — a large document should
    | consume more of the monthly allowance than a small one. The specific
    | band thresholds/credit values remain illustrative pending real
    | calibration (see internal-docs/commercial/ai-credit-policy-and-
    | consumption-model-v1.md Part Eleven) — approving the MODEL (banded
    | over flat) is a separate decision from approving the exact numbers
    | inside it, and only the former has happened.
    */

    'approved_candidate' => env('AI_CREDIT_APPROVED_CANDIDATE', null),

    /*
    |--------------------------------------------------------------------------
    | Customer-Facing Monthly AI Usage Meter (Phase G4C.3E)
    |--------------------------------------------------------------------------
    |
    | `customer_meter_enabled` gates whether GET /billing/ai-credit-usage
    | returns real figures at all (App\Services\Intelligence\
    | AiCreditUsageService) — default false, since the meter's wording and
    | release have not been separately approved yet (see Entitlement
    | Specification v1 §4a). Independent from `active_candidate` above
    | (workflow shadow accounting) and from the AI Credit operating mode
    | below — none of these imply or control each other.
    |
    | The operating mode itself (disabled/shadow/enforced) no longer lives
    | here at all — see `suresign_settings.ai_credit_operating_mode`
    | (App\Models\SuresignSetting / App\Support\AI\AiCreditOperatingMode),
    | controlled at runtime via the Super Admin AI Credit operating mode
    | control (GET/PUT /admin/ai-credits/operating-mode, PUT is
    | `role:Super Admin` only) rather than an env var/deploy. The customer
    | meter may be shown (usage_percent visible) while the mode stays
    | `shadow` (or even `disabled`) — that is an expected combination;
    | neither setting implies the other.
    */

    'customer_meter_enabled' => (bool) env('AI_CREDIT_CUSTOMER_METER_ENABLED', false),
];
