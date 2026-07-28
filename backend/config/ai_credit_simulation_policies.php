<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Credit Simulation — Candidate Policies (Phase G4C.2C-2)
    |--------------------------------------------------------------------------
    |
    | NON-ENFORCING. These candidates are provisional, internal-only
    | calibration inputs for App\Services\AI\AiCreditSimulator — they are
    | NEVER read by any customer-facing code path, never deduct or reserve
    | anything, and NONE of them is the approved V1 AI Credit policy. See
    | internal-docs/commercial/ai-credit-policy-and-consumption-model-v1.md
    | Part Two for the full policy discussion these candidates let us test
    | against real telemetry.
    |
    | Shape mirrors config/ai_pricing.php / App\Services\AI\AiPricingSchedule
    | exactly: effective-dated periods, resolved against an explicit instant
    | (never "now"), fails to null/unresolved rather than guessing.
    |
    | `strategy` is one of:
    |   'flat'    — flat_credits applies regardless of input size.
    |   'banded'  — bands (ordered, each an upper bound in normalized chars;
    |               the last band's upper bound is null, meaning "and above").
    |   'unresolved' — no policy exists yet; simulation must record this
    |               explicitly, never invent a number.
    |
    | Both candidates below exist ONLY to be compared against real
    | telemetry (Section 6.4 of the AI Credit policy doc's calibration
    | method) — neither is a recommendation, and the band thresholds/
    | credit values are illustrative examples, not derived from any real
    | measurement (this environment has one real large-document sample and
    | zero small/medium samples — see that document's telemetry-limitations
    | section).
    */

    'contract_analysis' => [
        'candidate_a' => [
            [
                'policy_version'  => 1,
                'effective_from'  => '2026-01-01',
                'effective_until' => null,
                'strategy'        => 'flat',
                'flat_credits'    => 1,
                'bands'           => null,
                'label'           => 'Candidate A — flat 1 credit per analysis (the superseded G4C.2A–B default, kept as a live comparison baseline)',
            ],
        ],
        'candidate_b' => [
            [
                'policy_version'  => 1,
                'effective_from'  => '2026-01-01',
                'effective_until' => null,
                'strategy'        => 'banded',
                'flat_credits'    => null,
                // Illustrative example thresholds only — NOT calibrated from
                // representative telemetry. Bounds are normalized character
                // counts (see App\Services\AI\AiInputNormalizer).
                'bands' => [
                    ['label' => 'small',      'max_chars' => 50_000,  'credits' => 2],
                    ['label' => 'medium',     'max_chars' => 150_000, 'credits' => 5],
                    ['label' => 'large',      'max_chars' => 300_000, 'credits' => 9],
                    ['label' => 'very_large', 'max_chars' => null,    'credits' => 15],
                ],
                'label' => 'Candidate B — illustrative input-size bands (example thresholds only, not calibrated)',
            ],
        ],
    ],

    'trade_package_analysis' => [
        'candidate_a' => [
            [
                'policy_version'  => 1,
                'effective_from'  => '2026-01-01',
                'effective_until' => null,
                'strategy'        => 'flat',
                'flat_credits'    => 1,
                'bands'           => null,
                'label'           => 'Candidate A — flat 1 credit per analysis',
            ],
        ],
        'candidate_b' => [
            [
                'policy_version'  => 1,
                'effective_from'  => '2026-01-01',
                'effective_until' => null,
                'strategy'        => 'banded',
                'flat_credits'    => null,
                // Same illustrative shape as contract_analysis — no evidence
                // yet that Trade Package's size profile differs (Part One,
                // §4.2: zero real Trade Package documents analysed to date).
                'bands' => [
                    ['label' => 'small',      'max_chars' => 50_000,  'credits' => 2],
                    ['label' => 'medium',     'max_chars' => 150_000, 'credits' => 5],
                    ['label' => 'large',      'max_chars' => 300_000, 'credits' => 9],
                    ['label' => 'very_large', 'max_chars' => null,    'credits' => 15],
                ],
                'label' => 'Candidate B — illustrative input-size bands (example thresholds only, not calibrated)',
            ],
        ],
    ],
];
