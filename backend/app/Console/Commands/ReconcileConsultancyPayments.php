<?php

namespace App\Console\Commands;

use App\Models\ConsultancyPayment;
use App\Services\Consultancy\ConsultancyPaymentConversionService;
use App\Services\Consultancy\Exceptions\ConsultancyConversionRetryableException;
use App\Services\Consultancy\Exceptions\ConsultancyManualReviewRequiredException;
use Illuminate\Console\Command;

/**
 * Consultancy Live Booking Upgrade, Stage 3 — introduced manual/on-demand
 * only. As of the Consultancy Live Booking Activation Hardening pass, this
 * is ALSO scheduled (see routes/console.php) — the correct precedent was
 * always App\Console\Commands\RecoverBillingWebhookEvents
 * (billing:webhooks:recover), not billing:stripe:reconcile: both this
 * command and that one retry a specific, already-known recoverable local
 * state left behind after an upstream provider event was already
 * confirmed, whereas billing:stripe:reconcile is read-only drift
 * *detection* across the whole dataset and has no analogous "retry" step.
 * Manual/on-demand execution (including --dry-run) remains fully
 * supported and safe — scheduling is additive, not a replacement.
 *
 * Retries local Appointment conversion for every 'conversion_pending'
 * payment — the expected distributed-systems recovery case where Stripe
 * already confirmed payment but local conversion previously failed. Never
 * touches 'manual_review' rows automatically — those require a human
 * decision (an Admin-visible retry action exists instead, see
 * ConsultancySettingsController::retryConversion()). Admin retry remains
 * the final recovery layer for a row this command cannot resolve, never
 * the primary mechanism.
 */
class ReconcileConsultancyPayments extends Command
{
    protected $signature = 'consultancy:payments:reconcile
        {--dry-run : Report what would be retried without retrying anything}';

    protected $description = 'Retry local Appointment conversion for Consultancy payments stuck in conversion_pending';

    public function handle(ConsultancyPaymentConversionService $conversionService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pending = ConsultancyPayment::where('status', 'conversion_pending')->get();

        if ($pending->isEmpty()) {
            $this->info('No Consultancy payments awaiting conversion retry.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line('Would retry conversion for ' . $pending->count() . ' payment(s): ' . $pending->pluck('id')->implode(', '));
            return self::SUCCESS;
        }

        $succeeded = 0;
        $stillPending = 0;
        $manualReview = 0;

        foreach ($pending as $payment) {
            if (!$payment->confirming_stripe_event_id) {
                $this->warn("Payment {$payment->id} has no confirming Stripe event — skipping.");
                continue;
            }

            try {
                $conversionService->convert($payment, $payment->confirming_stripe_event_id);
                $succeeded++;
            } catch (ConsultancyConversionRetryableException) {
                $stillPending++;
            } catch (ConsultancyManualReviewRequiredException) {
                $manualReview++;
            }
        }

        $this->info("Reconciled {$pending->count()} payment(s): {$succeeded} converted, {$stillPending} still pending, {$manualReview} moved to manual review.");

        return self::SUCCESS;
    }
}
