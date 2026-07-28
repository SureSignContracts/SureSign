<?php

namespace App\Support\Billing;

/**
 * The controlled vocabulary of per-subscription automation outcomes —
 * Subscription Commercial State Automation checkpoint, Part 12/13. Used
 * both for `AutomationActionResult::$outcome` and for the aggregate
 * counters `SubscriptionAutomationService::processDue()` returns.
 */
class AutomationOutcome
{
    public const TRANSITIONED = 'transitioned';
    public const SKIPPED_NOT_DUE = 'skipped_not_due';
    public const SKIPPED_ALREADY_APPLIED = 'skipped_already_applied';
    public const NO_LONGER_APPLICABLE = 'no_longer_applicable';
    public const CONFLICTED = 'conflicted';
    public const RETRYABLE_FAILURE = 'retryable_failure';
    public const TERMINAL_FAILURE = 'terminal_failure';

    public const SNAPSHOT_CREATED = 'snapshot_created';
    public const SNAPSHOT_REUSED = 'snapshot_reused';
    public const SNAPSHOT_NOT_APPLICABLE = 'not_applicable';
}
