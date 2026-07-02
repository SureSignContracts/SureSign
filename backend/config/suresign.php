<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Local Document Mirror
    |--------------------------------------------------------------------------
    |
    | When enabled, SureSign will copy every uploaded/generated file to a
    | local directory in addition to primary Laravel storage.
    | Laravel storage remains the source of truth — the mirror is a copy.
    |
    | For Docker, mount a host volume so the mirror appears on your PC:
    |
    |   Windows — docker-compose.yml volumes:
    |     - "C:/Users/Admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"
    |
    |   Mac/Linux:
    |     - "/Users/admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"
    |
    |   Then set SURESIGN_LOCAL_MIRROR_PATH to the container-side path:
    |     SURESIGN_LOCAL_MIRROR_PATH=/var/www/html/storage/app/local-mirror/SureSign
    |
    | For non-Docker deployments, set to the absolute path on the server:
    |     SURESIGN_LOCAL_MIRROR_PATH=/home/admin/Documents/SureSign
    |
    | Settings stored in suresign_settings DB row take precedence over these
    | env values, allowing Super Admin to change them from the UI at runtime.
    |
    */

    'local_mirror_enabled' => env('SURESIGN_LOCAL_MIRROR_ENABLED', false),
    'local_mirror_path'    => env('SURESIGN_LOCAL_MIRROR_PATH', ''),

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
