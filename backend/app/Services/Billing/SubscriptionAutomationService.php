<?php

namespace App\Services\Billing;

use App\Models\BillingPlanChange;
use App\Models\Subscription;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Support\Billing\AutomationActionResult;
use App\Support\Billing\AutomationOutcome;
use App\Support\Billing\PlanChangeState;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\SubscriptionTransitions;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Subscription Commercial State Automation checkpoint — the dedicated
 * orchestration service that turns DUE commercial state (a grace period
 * that should start/expire, a trial that has lapsed, a cancellation whose
 * scheduled date has arrived) into real lifecycle transitions.
 *
 * This class NEVER writes `subscriptions.status` (or any other commercial
 * field) itself and never bypasses `SubscriptionLifecycleService` — every
 * transition below is a call to an existing, already-reviewed named
 * method on that service. This class only:
 *
 *   - discovers subscriptions whose commercial state is due for a change;
 *   - evaluates whether an authoritative effective date has actually
 *     arrived (never early);
 *   - calls the correct named `SubscriptionLifecycleService` method;
 *   - reports a structured, explainable result for every row it touches
 *     (including rows it deliberately skips or fails on).
 *
 * ─── What this checkpoint automates, and what it deliberately does NOT ──
 *
 * Automated (each destination is an already-valid, unambiguous transition
 * per `SubscriptionTransitions::MAP` — see the checkpoint report for the
 * full repository-review reasoning):
 *
 *   - Grace period START: `past_due` with no `grace_period_ends_at` yet
 *     recorded → `startGracePeriod()`. Closes the automation gap
 *     checkpoint 13 documented ("nothing calls startGracePeriod()").
 *   - Grace period EXPIRY: `past_due` whose `grace_period_ends_at` has
 *     passed → `markUnpaid()`. `unpaid` is chosen over `suspend()`
 *     deliberately: `suspend()` requires an operator-supplied reason and
 *     is documented elsewhere as "a deliberate business decision,
 *     separate from raw payment failure" — not something automation
 *     should ever manufacture a reason for. `unpaid` is the strictly
 *     worse, reason-free collection state `SubscriptionTransitions::MAP`
 *     already allows from `past_due`, and needs no invented judgement.
 *   - Trial EXPIRY: `trialing` whose `trial_ends_at` has passed, never
 *     converted to `pending_payment` → `expire()` (an explicitly valid
 *     `trialing → expired` transition; never inferred).
 *   - Scheduled CANCELLATION: `active` with `cancel_at_period_end = true`
 *     whose `current_period_ends_at` has passed → `confirmCancellation()`.
 *   - Scheduled SUSPENSION (Subscription Suspension Completion checkpoint):
 *     any status with `pending_suspension_effective_at <= now()` →
 *     `suspend()`, using `pending_suspension_reason` as the actual
 *     suspension reason. Before this checkpoint, `scheduleSuspension()`
 *     recorded intent with no effective-date field at all; it now records
 *     an authoritative `pending_suspension_effective_at`, closing that
 *     gap. If the subscription's status no longer permits a transition to
 *     `suspended` by the time this runs (e.g. it was cancelled in the
 *     meantime), the pending request is discarded via
 *     `cancelScheduledSuspension()` with an explanatory audit reason
 *     rather than left to retry forever or forced through an invalid
 *     transition — reported as `no_longer_applicable`, never a silent
 *     drop.
 *
 *   - Scheduled PLAN CHANGE SEND (Stripe Test Mode Integration checkpoint):
 *     `processDuePlanChanges()` sends the outbound Stripe Price update for
 *     every `BillingPlanChange` row whose `requested_effective_at` has
 *     passed, via `SubscriptionPlanChangeService::send()`. This closes the
 *     gap the previous checkpoint left open ("no provider-side Stripe
 *     Price update is currently executed") — but sending is NOT the same
 *     as applying: the local plan/entitlement snapshot only changes once a
 *     verified webhook confirms the new Price
 *     (`WebhookEventProcessor::reconcilePlanChangeIfPending()` →
 *     `SubscriptionPlanChangeService::confirmFromProvider()`), never from
 *     this send call's own success alone (Non-negotiable Principle 11).
 *
 * ─── Concurrency & idempotency ──────────────────────────────────────────
 *
 * Discovery queries here take no lock — the actual correctness boundary is
 * `SubscriptionLifecycleService::transition()`'s own `lockForUpdate()` plus
 * its same-status no-op short circuit. Two overlapping runs (or a retried
 * job) can safely discover and attempt the same row twice: the second
 * attempt finds the subscription already at its target status and returns
 * a safe no-op, never a duplicate transition, duplicate ActivityLog entry,
 * or duplicate snapshot (snapshot idempotency is `EntitlementSnapshotService`'s
 * own unique-index boundary). Scheduler-level `withoutOverlapping()` is
 * still applied (routes/console.php) as the primary safeguard, matching
 * this codebase's existing convention, not as the only one.
 */
class SubscriptionAutomationService
{
    private const DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionPlanChangeService $planChanges,
    ) {
    }

    /**
     * Runs every automated category once, in a deterministic order, and
     * returns a flat list of per-subscription results plus a few
     * informational (never "blocked" — see class docblock update in the
     * Stripe Test Mode Integration checkpoint) counts. Safe to call
     * repeatedly (idempotent — see class docblock).
     *
     * @return array{
     *     results: AutomationActionResult[],
     *     counters: array<string, int>,
     *     scheduled_suspensions_future: int,
     *     plan_changes_pending_future: int,
     * }
     */
    public function processDue(int $limitPerCategory = self::DEFAULT_LIMIT): array
    {
        $results = [
            ...$this->processGracePeriodStarts($limitPerCategory),
            ...$this->processGracePeriodExpiries($limitPerCategory),
            ...$this->processTrialExpiries($limitPerCategory),
            ...$this->processScheduledCancellations($limitPerCategory),
            ...$this->processScheduledSuspensions($limitPerCategory),
            ...$this->processDuePlanChanges($limitPerCategory),
        ];

        return [
            'results' => $results,
            'counters' => $this->tally($results),
            'scheduled_suspensions_future' => $this->countScheduledSuspensions(due: false),
            'plan_changes_pending_future' => $this->countPendingPlanChanges(due: false),
        ];
    }

    // ─── Scheduled plan changes (Stripe Test Mode Integration checkpoint) ─

    /**
     * Sends the outbound Stripe Price update for every `REQUESTED`
     * `BillingPlanChange` whose `requested_effective_at` has passed —
     * covers scheduled downgrades reaching their period-end boundary, and
     * any explicitly-scheduled upgrade (immediate upgrades are sent
     * synchronously by `SubscriptionPlanChangeService::requestUpgrade()`'s
     * caller, not by this tick, but a row that failed to send
     * synchronously and is still `REQUESTED` past its effective moment is
     * picked up here too — see `SubscriptionPlanChangeService::send()`'s
     * own idempotency).
     *
     * @return AutomationActionResult[]
     */
    public function processDuePlanChanges(int $limit = self::DEFAULT_LIMIT): array
    {
        return BillingPlanChange::query()
            ->where('state', PlanChangeState::REQUESTED)
            ->where('requested_effective_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (BillingPlanChange $planChange) {
                $subscriptionId = $planChange->subscription_id;

                try {
                    $sent = $this->planChanges->send($planChange);

                    return match ($sent->state) {
                        PlanChangeState::SENT => AutomationActionResult::transitioned(
                            'plan_change_send',
                            $subscriptionId,
                            'requested',
                            'sent',
                            optional($sent->requested_effective_at)->toIso8601String(),
                            "Sent {$sent->change_type} Price update to the provider — awaiting webhook confirmation.",
                        ),
                        PlanChangeState::FAILED => AutomationActionResult::terminalFailure(
                            'plan_change_send',
                            $subscriptionId,
                            $sent->failure_message ?? 'Plan change send failed.',
                        ),
                        default => AutomationActionResult::skipped(
                            'plan_change_send',
                            $subscriptionId,
                            AutomationOutcome::SKIPPED_ALREADY_APPLIED,
                            "Plan change {$sent->id} was already in state \"{$sent->state}\".",
                        ),
                    };
                } catch (Throwable $e) {
                    Log::warning('SubscriptionAutomationService: plan_change_send retryable failure', [
                        'plan_change_id' => $planChange->id,
                        'subscription_id' => $subscriptionId,
                        'exception' => $e->getMessage(),
                    ]);

                    return AutomationActionResult::conflicted('plan_change_send', $subscriptionId, $e->getMessage());
                }
            })
            ->all();
    }

    // ─── Grace period start ──────────────────────────────────────────────

    /**
     * @return AutomationActionResult[]
     */
    public function processGracePeriodStarts(int $limit = self::DEFAULT_LIMIT): array
    {
        $graceDays = (int) config('billing.grace_period_days', 7);

        return Subscription::query()
            ->where('status', SubscriptionStatus::PAST_DUE)
            ->whereNull('grace_period_ends_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(function (Subscription $subscription) use ($graceDays) {
                // Anchored on the authoritative moment the subscription
                // actually became past_due, not "now" — so re-running this
                // discovery on a later tick always computes the SAME grace
                // deadline rather than pushing it forward each time.
                $anchor = $subscription->last_transition_occurred_at ?? CarbonImmutable::now();
                $graceEndsAt = CarbonImmutable::instance($anchor)->addDays($graceDays);

                return $this->attempt('grace_start', $subscription, function () use ($subscription, $graceEndsAt) {
                    $context = $this->context('Automated grace period start');
                    $updated = $this->lifecycle->startGracePeriod($subscription, $graceEndsAt, $context);

                    return AutomationActionResult::transitioned(
                        'grace_start',
                        $subscription->id,
                        SubscriptionStatus::PAST_DUE,
                        SubscriptionStatus::PAST_DUE,
                        $graceEndsAt->toIso8601String(),
                        "Started grace period ending {$graceEndsAt->toIso8601String()}.",
                    );
                }, fn () => $subscription->fresh()?->grace_period_ends_at !== null);
            })
            ->all();
    }

    // ─── Grace period expiry ─────────────────────────────────────────────

    /**
     * @return AutomationActionResult[]
     */
    public function processGracePeriodExpiries(int $limit = self::DEFAULT_LIMIT): array
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::PAST_DUE)
            ->whereNotNull('grace_period_ends_at')
            ->where('grace_period_ends_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription) => $this->attempt('grace_expiry', $subscription, function () use ($subscription) {
                $context = $this->context('Automated grace period expiry — grace_period_ends_at has passed');
                $this->lifecycle->markUnpaid($subscription, $context);

                return AutomationActionResult::transitioned(
                    'grace_expiry',
                    $subscription->id,
                    SubscriptionStatus::PAST_DUE,
                    SubscriptionStatus::UNPAID,
                    $subscription->grace_period_ends_at?->toIso8601String(),
                    'Grace period expired — marked unpaid.',
                );
            }))
            ->all();
    }

    // ─── Trial expiry ────────────────────────────────────────────────────

    /**
     * @return AutomationActionResult[]
     */
    public function processTrialExpiries(int $limit = self::DEFAULT_LIMIT): array
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::TRIALING)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription) => $this->attempt('trial_expiry', $subscription, function () use ($subscription) {
                $context = $this->context('Automated trial expiry — trial_ends_at has passed without conversion');
                $this->lifecycle->expire($subscription, $context);

                return AutomationActionResult::transitioned(
                    'trial_expiry',
                    $subscription->id,
                    SubscriptionStatus::TRIALING,
                    SubscriptionStatus::EXPIRED,
                    $subscription->trial_ends_at?->toIso8601String(),
                    'Trial expired without conversion — expired.',
                );
            }))
            ->all();
    }

    // ─── Scheduled cancellation ──────────────────────────────────────────

    /**
     * @return AutomationActionResult[]
     */
    public function processScheduledCancellations(int $limit = self::DEFAULT_LIMIT): array
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE)
            ->where('cancel_at_period_end', true)
            ->whereNotNull('current_period_ends_at')
            ->where('current_period_ends_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription) => $this->attempt('scheduled_cancellation', $subscription, function () use ($subscription) {
                $context = $this->context('Automated scheduled cancellation — current_period_ends_at has passed', [
                    'effective_at' => $subscription->current_period_ends_at,
                ]);
                $this->lifecycle->confirmCancellation($subscription, $context);

                return AutomationActionResult::transitioned(
                    'scheduled_cancellation',
                    $subscription->id,
                    SubscriptionStatus::ACTIVE,
                    SubscriptionStatus::CANCELLED,
                    $subscription->current_period_ends_at?->toIso8601String(),
                    'Scheduled cancellation effective date reached — confirmed.',
                );
            }))
            ->all();
    }

    // ─── Scheduled suspension ────────────────────────────────────────────

    /**
     * @return AutomationActionResult[]
     */
    public function processScheduledSuspensions(int $limit = self::DEFAULT_LIMIT): array
    {
        return Subscription::query()
            ->whereNotNull('pending_suspension_effective_at')
            ->where('pending_suspension_effective_at', '<=', CarbonImmutable::now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Subscription $subscription) => $this->attempt('scheduled_suspension', $subscription, function () use ($subscription) {
                if (!SubscriptionTransitions::canTransition($subscription->status, SubscriptionStatus::SUSPENDED)) {
                    // The subscription's status changed since the suspension
                    // was scheduled (e.g. cancelled/expired in the meantime)
                    // — the pending request is no longer applicable. Discard
                    // it explicitly rather than retrying it forever or
                    // forcing an invalid transition (Part 8's precedence
                    // rule: the more restrictive/terminal state already
                    // reached wins).
                    $discardContext = $this->context("Discarding pending suspension — status is now \"{$subscription->status}\", no longer a valid suspension source");
                    $this->lifecycle->cancelScheduledSuspension(
                        $subscription,
                        $discardContext,
                        "Automatically discarded — status changed to \"{$subscription->status}\" before the suspension became effective",
                    );

                    return AutomationActionResult::skipped(
                        'scheduled_suspension',
                        $subscription->id,
                        AutomationOutcome::NO_LONGER_APPLICABLE,
                        "Subscription status is now \"{$subscription->status}\" — pending suspension discarded.",
                    );
                }

                $previousStatus = $subscription->status;
                $reason = $subscription->pending_suspension_reason ?? 'Automated scheduled suspension';
                $context = $this->context('Automated scheduled suspension — pending_suspension_effective_at has passed');
                $this->lifecycle->suspend($subscription, $reason, $context);

                return AutomationActionResult::transitioned(
                    'scheduled_suspension',
                    $subscription->id,
                    $previousStatus,
                    SubscriptionStatus::SUSPENDED,
                    $subscription->pending_suspension_effective_at?->toIso8601String(),
                    'Scheduled suspension effective date reached — suspended.',
                );
            }, fn () => $subscription->fresh()?->status === SubscriptionStatus::SUSPENDED))
            ->all();
    }

    // ─── Observability ────────────────────────────────────────────────────

    public function countScheduledSuspensions(bool $due): int
    {
        return Subscription::query()
            ->whereNotNull('pending_suspension_effective_at')
            ->where('pending_suspension_effective_at', $due ? '<=' : '>', CarbonImmutable::now())
            ->count();
    }

    /**
     * Observability only — `$due = true` should normally be ~0 right after
     * `processDuePlanChanges()` runs (anything still `requested` past its
     * effective moment means the last send attempt failed and is awaiting
     * retry); `$due = false` reports how many are scheduled but not yet
     * due (informational, not "blocked" — plan changes are fully automated
     * as of this checkpoint).
     */
    public function countPendingPlanChanges(bool $due): int
    {
        return BillingPlanChange::query()
            ->where('state', PlanChangeState::REQUESTED)
            ->where('requested_effective_at', $due ? '<=' : '>', CarbonImmutable::now())
            ->count();
    }

    // ─── Shared internals ────────────────────────────────────────────────

    private function attempt(string $category, Subscription $subscription, callable $run, ?callable $alreadyApplied = null): AutomationActionResult
    {
        try {
            return $run();
        } catch (SubscriptionLifecycleConflictException|InvalidSubscriptionTransitionException $e) {
            if ($alreadyApplied !== null && $alreadyApplied()) {
                return AutomationActionResult::skipped($category, $subscription->id, AutomationOutcome::SKIPPED_ALREADY_APPLIED, $e->getMessage());
            }

            Log::warning("SubscriptionAutomationService: {$category} conflicted for subscription {$subscription->id}", [
                'subscription_id' => $subscription->id,
                'category' => $category,
                'message' => $e->getMessage(),
            ]);

            return AutomationActionResult::conflicted($category, $subscription->id, $e->getMessage());
        } catch (Throwable $e) {
            Log::error("SubscriptionAutomationService: {$category} failed for subscription {$subscription->id}", [
                'subscription_id' => $subscription->id,
                'category' => $category,
                'exception' => $e->getMessage(),
            ]);

            return AutomationActionResult::terminalFailure($category, $subscription->id, $e->getMessage());
        }
    }

    private function context(string $reason, array $metadata = []): TransitionContext
    {
        return TransitionContext::make([
            'source' => TransitionSource::SCHEDULED_COMMAND,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /**
     * @param AutomationActionResult[] $results
     * @return array<string, int>
     */
    private function tally(array $results): array
    {
        $counters = [
            'discovered' => count($results),
            AutomationOutcome::TRANSITIONED => 0,
            AutomationOutcome::SKIPPED_NOT_DUE => 0,
            AutomationOutcome::SKIPPED_ALREADY_APPLIED => 0,
            AutomationOutcome::NO_LONGER_APPLICABLE => 0,
            AutomationOutcome::CONFLICTED => 0,
            AutomationOutcome::TERMINAL_FAILURE => 0,
        ];

        foreach ($results as $result) {
            $counters[$result->outcome] = ($counters[$result->outcome] ?? 0) + 1;
        }

        return $counters;
    }
}
