<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Final Account — Operational Intelligence SLAs
    |--------------------------------------------------------------------------
    |
    | Single source of truth for Final Account business-rule thresholds used
    | to compute overdue status. Referenced by FinalAccount (model helpers),
    | OperationalIntelligenceService (calendar/notification due dates),
    | ProjectHealthService (commercial health deductions — via the model
    | helpers, not directly), and CalendarController (live calendar feed).
    |
    */

    'final_account' => [
        // Days allowed in 'under_review' before the review is flagged overdue.
        'review_sla_days' => env('SURESIGN_FA_REVIEW_SLA_DAYS', 14),

        // Days allowed after the Final Certificate is issued before commercial
        // close-out is flagged overdue.
        'closeout_grace_days' => env('SURESIGN_FA_CLOSEOUT_GRACE_DAYS', 30),
    ],

];
