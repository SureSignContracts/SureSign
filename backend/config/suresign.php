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

    /*
    |--------------------------------------------------------------------------
    | Marketing site base URL
    |--------------------------------------------------------------------------
    |
    | Same value CORS already trusts (config/cors.php reads MARKETING_URL
    | directly, since that's a config file too) — used here to build the
    | branded confirmation-page links appointment emails point to. env()
    | is only ever called from within a config file, never in application
    | code, per Laravel convention.
    |
    */

    'marketing_url' => env('MARKETING_URL', 'http://localhost:3001'),

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | The authenticated product app (config/cors.php's own default already
    | trusts the same value) — used to build authenticated in-app links
    | (e.g. "View Consultation") inside customer emails. Never used for
    | public/no-account destinations, which always go through marketing_url
    | above via the existing signed-link services.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Invitation & First-Time Account Setup
    |--------------------------------------------------------------------------
    |
    | Configured separately from the standard email-verification link
    | (EmailVerificationService::EXPIRES_MINUTES / AccountEmailService's
    | 60-minute constant) — a business invitation may reasonably sit
    | unopened for days, unlike a same-session verification or password
    | reset request. See InvitationLinkService.
    |
    */

    'invitation' => [
        'link_expiry_days' => env('SURESIGN_INVITATION_LINK_EXPIRY_DAYS', 7),

        // P2 Security Remediation (Bulk Invite Email-Volume Abuse) — the
        // existing 30 requests/minute throttle on POST /users/bulk-invite
        // controls REQUEST rate, not RECIPIENT volume (up to 100 recipients
        // per request). These three limits are a recipient-aware budget on
        // top of that unchanged request limiter, enforced atomically via a
        // single global Cache::lock() around the admission decision (see
        // UserController::reserveBulkInviteRecipients()) — never through a
        // customer-facing/Super-Admin-UI-editable setting, since an abuse
        // ceiling should not be raisable through the same account class it
        // protects against.
        'bulk_invite_operator_hourly_recipients'  => env('SURESIGN_BULK_INVITE_OPERATOR_HOURLY_RECIPIENTS', 500),
        'bulk_invite_operator_daily_recipients'   => env('SURESIGN_BULK_INVITE_OPERATOR_DAILY_RECIPIENTS', 2000),
        'bulk_invite_platform_hourly_recipients'  => env('SURESIGN_BULK_INVITE_PLATFORM_HOURLY_RECIPIENTS', 1500),
    ],

];
