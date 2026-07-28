<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider Pricing Schedule (Phase G4C.1A.1)
    |--------------------------------------------------------------------------
    |
    | Effective-dated, per-model pricing in USD per million tokens. Rate
    | selection MUST use the provider-call timestamp
    | (App\Services\AI\AiPricingSchedule::rateFor()), never the current
    | date/time — a historical analysis's cost must always be computed with
    | the rate that was actually in effect when the provider call happened,
    | never "today's" rate. This is what lets G4C.1A's earlier fix (the
    | hardcoded $3/$15 rate was wrong during the $2/$10 introductory window)
    | stay correct automatically once the rate reverts on 2026-09-01,
    | without another manual edit or another silent 50% miscalculation.
    |
    | `effective_from`/`effective_until` are inclusive whole-day boundaries
    | (start-of-day / end-of-day). `effective_until: null` means "still in
    | effect, no known end date."
    |
    | Confirmed against https://platform.claude.com/docs/en/about-claude/pricing
    | (fetched 2026-07-26). Add a new period when Anthropic changes pricing —
    | never mutate an existing period's dates/rates once real analyses have
    | been priced under it; that would silently rewrite history for any
    | future recalculation.
    */

    'claude-sonnet-5' => [
        [
            'effective_from'     => '2026-01-01',
            'effective_until'    => '2026-08-31',
            'input_per_million'  => 2.00,
            'output_per_million' => 10.00,
        ],
        [
            'effective_from'     => '2026-09-01',
            'effective_until'    => null,
            'input_per_million'  => 3.00,
            'output_per_million' => 15.00,
        ],
    ],
];
