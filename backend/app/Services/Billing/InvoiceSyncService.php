<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Support\Billing\BillingReferenceType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stripe Test Mode Integration checkpoint, Part 18/19 — the invoice and
 * payment-history foundation. Persists from verified webhook payloads only
 * (never a live Stripe read triggered ad hoc from a request) — chosen over
 * "retrieve live from Stripe through an abstraction" because persistence
 * is resilient to Stripe outages/rate limits and gives a durable audit
 * trail consistent with every other billing record in this codebase.
 *
 * Idempotent by construction: both `billing_invoices` and `billing_payments`
 * already carry unique provider-id constraints (from the Phase 1–4
 * foundation) — `updateOrCreate()` on those keys means a redelivered
 * webhook (or an `invoice.paid` arriving after an earlier
 * `invoice.payment_failed` for the same invoice) always upserts the same
 * row rather than duplicating it.
 *
 * Never stores card details or full provider payment-method objects — only
 * the normalized array `BillingProviderInterface::normalizeInvoiceFromWebhookPayload()`
 * already produced, which contains no such data. `provider_payload_json`
 * would only ever hold this same normalized array if ever populated — this
 * checkpoint does not persist a second, larger raw payload copy (the
 * verified `billing_webhook_events.payload_json` row already IS that
 * durable raw copy — see WebhookIngestionService — so duplicating it again
 * per-invoice would be redundant, not "more auditable").
 */
class InvoiceSyncService
{
    public function __construct(
        private readonly BillingReferenceService $referenceService,
    ) {
    }

    /**
     * @param array{id: string, status: string, customer_id: ?string, subscription_id: ?string, livemode: bool, currency: string, subtotal: ?int, tax: ?int, total: ?int, amount_due: ?int, amount_paid: ?int, amount_remaining: ?int, hosted_invoice_url: ?string, invoice_pdf: ?string, billing_reason: ?string, period_start: ?int, period_end: ?int, due_date: ?int, payment_intent_id: ?string, metadata: array} $normalizedInvoice
     */
    public function syncFromWebhook(array $normalizedInvoice, Subscription $subscription): BillingInvoice
    {
        return DB::transaction(function () use ($normalizedInvoice, $subscription) {
            $invoice = BillingInvoice::query()
                ->where('provider', 'stripe')
                ->where('provider_invoice_id', $normalizedInvoice['id'])
                ->lockForUpdate()
                ->first();

            $attributes = [
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'billing_customer_id' => $subscription->billing_customer_id,
                'provider' => 'stripe',
                'provider_invoice_id' => $normalizedInvoice['id'],
                'provider_invoice_number' => $normalizedInvoice['number'],
                'status' => $normalizedInvoice['status'],
                'currency' => $normalizedInvoice['currency'],
                'subtotal_amount' => $normalizedInvoice['subtotal'],
                'tax_amount' => $normalizedInvoice['tax'],
                'total_amount' => $normalizedInvoice['total'],
                'amount_due' => $normalizedInvoice['amount_due'],
                'amount_paid' => $normalizedInvoice['amount_paid'],
                'amount_remaining' => $normalizedInvoice['amount_remaining'],
                'hosted_invoice_url' => $normalizedInvoice['hosted_invoice_url'],
                'invoice_pdf_url' => $normalizedInvoice['invoice_pdf'],
                'billing_reason' => $normalizedInvoice['billing_reason'],
                'period_starts_at' => $this->fromTimestamp($normalizedInvoice['period_start']),
                'period_ends_at' => $this->fromTimestamp($normalizedInvoice['period_end']),
                'due_at' => $this->fromTimestamp($normalizedInvoice['due_date']),
                'metadata_json' => $normalizedInvoice['metadata'],
            ];

            if ($normalizedInvoice['status'] === 'paid') {
                $attributes['paid_at'] = $invoice?->paid_at ?? CarbonImmutable::now();
            }

            if ($invoice === null) {
                $attributes['invoice_number'] = $this->referenceService->generate(BillingReferenceType::INVOICE);
                $invoice = BillingInvoice::create($attributes);
            } else {
                $invoice->update($attributes);
            }

            if ($normalizedInvoice['status'] === 'paid' && $normalizedInvoice['payment_intent_id'] !== null) {
                $this->syncPayment($invoice, $subscription, $normalizedInvoice);
            }

            return $invoice->fresh();
        });
    }

    private function syncPayment(BillingInvoice $invoice, Subscription $subscription, array $normalizedInvoice): void
    {
        $payment = BillingPayment::query()
            ->where('provider', 'stripe')
            ->where('provider_payment_intent_id', $normalizedInvoice['payment_intent_id'])
            ->lockForUpdate()
            ->first();

        $attributes = [
            'organization_id' => $subscription->organization_id,
            'subscription_id' => $subscription->id,
            'invoice_id' => $invoice->id,
            'provider' => 'stripe',
            'provider_payment_intent_id' => $normalizedInvoice['payment_intent_id'],
            'status' => 'succeeded',
            'currency' => $normalizedInvoice['currency'],
            'amount' => $normalizedInvoice['amount_paid'] ?? 0,
            'paid_at' => $payment?->paid_at ?? CarbonImmutable::now(),
        ];

        if ($payment === null) {
            $attributes['internal_reference'] = $this->referenceService->generate(BillingReferenceType::PAYMENT);
            BillingPayment::create($attributes);
        } else {
            $payment->update($attributes);
        }
    }

    private function fromTimestamp(?int $timestamp): ?CarbonImmutable
    {
        return $timestamp !== null ? CarbonImmutable::createFromTimestampUTC($timestamp) : null;
    }
}
