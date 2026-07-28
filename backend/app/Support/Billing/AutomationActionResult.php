<?php

namespace App\Support\Billing;

/**
 * One row of explainable output from `App\Services\Billing\
 * SubscriptionAutomationService` — Subscription Commercial State
 * Automation checkpoint, Part 13. Every automated action (or deliberate
 * non-action) produces exactly one of these, so a future support engineer
 * can answer "why did/didn't this subscription's automation run do
 * something" from structured data instead of prose logs alone.
 */
final class AutomationActionResult
{
    public function __construct(
        public readonly string $category,
        public readonly int $subscriptionId,
        public readonly string $outcome,
        public readonly ?string $previousStatus,
        public readonly ?string $newStatus,
        public readonly ?string $effectiveAt,
        public readonly string $snapshotOutcome,
        public readonly string $reason,
    ) {
    }

    public static function transitioned(
        string $category,
        int $subscriptionId,
        string $previousStatus,
        string $newStatus,
        ?string $effectiveAt,
        string $reason,
        string $snapshotOutcome = 'not_applicable',
    ): self {
        return new self($category, $subscriptionId, AutomationOutcome::TRANSITIONED, $previousStatus, $newStatus, $effectiveAt, $snapshotOutcome, $reason);
    }

    public static function skipped(string $category, int $subscriptionId, string $outcome, string $reason): self
    {
        return new self($category, $subscriptionId, $outcome, null, null, null, 'not_applicable', $reason);
    }

    public static function conflicted(string $category, int $subscriptionId, string $reason): self
    {
        return new self($category, $subscriptionId, AutomationOutcome::CONFLICTED, null, null, null, 'not_applicable', $reason);
    }

    public static function terminalFailure(string $category, int $subscriptionId, string $reason): self
    {
        return new self($category, $subscriptionId, AutomationOutcome::TERMINAL_FAILURE, null, null, null, 'not_applicable', $reason);
    }

    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'subscription_id' => $this->subscriptionId,
            'outcome' => $this->outcome,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'effective_at' => $this->effectiveAt,
            'snapshot_outcome' => $this->snapshotOutcome,
            'reason' => $this->reason,
        ];
    }
}
