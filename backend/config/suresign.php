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

    /*
    |--------------------------------------------------------------------------
    | Deadline Reminders — Worldwide Scheduling (Batch 7)
    |--------------------------------------------------------------------------
    |
    | Platform-wide default local hour at which `suresign:send-deadline-reminders`
    | processes an organisation, in THAT organisation's own timezone — not a
    | UTC hour. No per-organisation override exists (no genuine product need
    | for one yet — see Batch 7 report); every organisation uses this same
    | default hour, each in its own local time.
    |
    */

    'deadline_reminder_local_hour' => env('SURESIGN_DEADLINE_REMINDER_LOCAL_HOUR', 8),

];
