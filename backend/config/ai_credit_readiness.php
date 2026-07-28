<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Credit G4C.3 Readiness — Process/Documentation State (Phase G4C.2E)
    |--------------------------------------------------------------------------
    |
    | The G4C.3 Readiness Gate (App\Support\AI\AiCreditReadinessGate) evaluates
    | ten requirements. Six are computed live from telemetry/simulation data
    | (representative telemetry, telemetry health, simulation coverage, Trade
    | Package coverage, organisation diversity, commercial confidence) and
    | need no config — they change automatically as real usage accrues.
    |
    | The four requirements below are NOT derivable from telemetry — they are
    | facts about process/business state (has a founder actually approved a
    | rate? has the entitlement migration run? is documentation current? has a
    | formal observation period been run?). This file is the single place
    | that state is recorded, mirroring how config/ai_pricing.php and
    | config/ai_credit_simulation_policies.php are the authoritative external
    | truth for their own domains rather than being computed at runtime.
    |
    | MUST be updated manually as the process advances — nothing in this
    | codebase updates these values automatically, and nothing should: an
    | automatic "founder_approval: ready" would be exactly the kind of
    | invented/implied approval this entire architecture exists to prevent.
    |
    | Each entry's `status` must be one of: ready | not_ready | blocked |
    | unknown (see AiCreditReadinessGate::VALID_STATUSES).
    */

    'founder_approval' => [
        'status' => 'ready',
        'notes' => 'Approved 2026-07-27: banded charging model (candidate_b) over flat, with provisional monthly allowances Essential=100, Professional=1000, Enterprise=custom. Band thresholds/credit values themselves remain illustrative pending real calibration — see internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md Part Eleven (G4C.3G).',
    ],

    'entitlement_migration_readiness' => [
        'status' => 'ready',
        'notes' => 'Migration executed 2026-07-27 per G4C.2D §46.5\'s sequencing: ai_credits_per_month is the live customer-facing entitlement (see AiCreditUsageService); ai_analyses_per_month is deprecated in favour of it (Feature::isDeprecated()), not deleted — historical rows/snapshots under the old key remain valid.',
    ],

    'documentation' => [
        'status' => 'ready',
        'notes' => 'AI Credit Policy, telemetry, calibration, and readiness documentation are current as of Phase G4C.2E.',
    ],

    'operational_readiness' => [
        'status' => 'not_ready',
        'notes' => 'No formal production observation period (see the Production Observation Runbook, AI Credit Policy Part Four §48) has been started or completed yet.',
    ],
];
