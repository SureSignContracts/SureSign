<?php

namespace App\Console\Commands;

use App\Services\Billing\StripeReconciliationService;
use App\Support\Billing\ReconciliationFinding;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Stripe Test Mode Integration checkpoint, Part 22 — `billing:stripe:reconcile`.
 * Non-destructive inspection only; there is no `--repair` option (Part 22's
 * "optional repair must be highly constrained" is satisfied here by not
 * offering automatic repair at all — every finding this command reports
 * requires a human decision: which side is wrong, whether a manual
 * `SubscriptionLifecycleService` action or Stripe Dashboard correction is
 * appropriate). Deliberately NOT scheduled — see
 * `StripeReconciliationService`'s class docblock: reconciliation is a
 * manual/low-frequency operational tool, not a replacement for
 * webhook-driven normal operation.
 */
class ReconcileStripeSubscriptions extends Command
{
    protected $signature = 'billing:stripe:reconcile
        {--subscription= : Only reconcile this specific subscriptions.id}
        {--limit=200 : Maximum subscriptions to scan}';

    protected $description = 'Compare local and Stripe Test Mode subscription state and report drift — inspection only, never repairs';

    public function handle(StripeReconciliationService $reconciliation): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: 200));
        $subscriptionId = $this->option('subscription') !== null ? (int) $this->option('subscription') : null;

        $result = $reconciliation->reconcile($limit, $subscriptionId);

        foreach ($result['records'] as $record) {
            if ($record['finding'] === ReconciliationFinding::HEALTHY) {
                continue;
            }

            $this->line(sprintf('subscription %d: %s%s', $record['subscription_id'], $record['finding'], $record['detail'] ? " — {$record['detail']}" : ''));
        }

        $counters = $result['counters'];

        $this->info(sprintf(
            'Scanned %d: %d healthy, %d local-only, %d provider-deleted, %d mode mismatch, %d customer mismatch, %d price mismatch, %d unknown price, %d pending confirmed, %d pending stale, %d missing snapshot, %d retryable errors, %d terminal errors.',
            $counters['scanned'],
            $counters[ReconciliationFinding::HEALTHY],
            $counters[ReconciliationFinding::LOCAL_ONLY],
            $counters[ReconciliationFinding::PROVIDER_SUBSCRIPTION_DELETED],
            $counters[ReconciliationFinding::MODE_MISMATCH],
            $counters[ReconciliationFinding::CUSTOMER_MISMATCH],
            $counters[ReconciliationFinding::PRICE_MISMATCH],
            $counters[ReconciliationFinding::UNKNOWN_PRICE],
            $counters[ReconciliationFinding::PENDING_CHANGE_CONFIRMED],
            $counters[ReconciliationFinding::PENDING_CHANGE_STALE],
            $counters[ReconciliationFinding::MISSING_SNAPSHOT],
            $counters[ReconciliationFinding::RETRYABLE_ERROR],
            $counters[ReconciliationFinding::TERMINAL_ERROR],
        ));

        Log::info('billing:stripe:reconcile completed', ['counters' => $counters]);

        return ($counters[ReconciliationFinding::TERMINAL_ERROR] > 0) ? self::FAILURE : self::SUCCESS;
    }
}
