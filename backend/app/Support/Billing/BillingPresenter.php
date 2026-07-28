<?php

namespace App\Support\Billing;

use App\Models\BillingCheckoutSession;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlanChange;
use App\Models\BillingWebhookEvent;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Support\Entitlements\SubscriptionAccessDecision;

/**
 * Explicit, hand-whitelisted array shaping for every billing model that may
 * ever reach an API response — the same discipline
 * PricingManagementService::publicPayload() already applies for Pricing,
 * applied here deliberately rather than left implicit, because billing
 * models carry fields that must never round-trip to the frontend verbatim:
 * provider_payload_json (raw Stripe payloads), and any other organisation's
 * identifiers. This codebase has no app/Http/Resources layer — these stay
 * plain static methods rather than introducing framework Resource classes
 * solely for billing.
 *
 * Never used yet by a controller in this checkpoint (no billing endpoints
 * exist), but the boundary is defined now so the first controller that
 * needs it doesn't invent its own ad hoc whitelist.
 */
class BillingPresenter
{
    public static function subscription(Subscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'internal_reference' => $subscription->internal_reference,
            'status' => $subscription->status,
            'billing_interval' => $subscription->billing_interval,
            'currency' => $subscription->currency,
            'unit_amount' => $subscription->unit_amount,
            'quantity' => $subscription->quantity,
            'subtotal_amount' => $subscription->subtotal_amount,
            'tax_amount' => $subscription->tax_amount,
            'total_amount' => $subscription->total_amount,
            'starts_at' => $subscription->starts_at,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_starts_at' => $subscription->current_period_starts_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'cancelled_at' => $subscription->cancelled_at,
            'ended_at' => $subscription->ended_at,
            'grace_period_ends_at' => $subscription->grace_period_ends_at,
            'plan_code_snapshot' => $subscription->plan_code_snapshot,
            'plan_name_snapshot' => $subscription->plan_name_snapshot,
            // Phase E6 — the one field that distinguishes a subscription
            // that genuinely went live at some point from one cancelled/
            // expired while still pending_payment. Never inferred from
            // status alone: cancelled/expired is reached by both paths.
            'activated_at' => $subscription->activated_at,
        ];
    }

    public static function invoice(BillingInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            // Stripe's own invoice number (printed on the hosted invoice
            // page/PDF) — distinct from invoice_number above, which is
            // SureSign's own internal correlation reference. Nullable:
            // Stripe does not always number a draft/void invoice.
            'provider_invoice_number' => $invoice->provider_invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'subtotal_amount' => $invoice->subtotal_amount,
            'tax_amount' => $invoice->tax_amount,
            'discount_amount' => $invoice->discount_amount,
            'total_amount' => $invoice->total_amount,
            'amount_due' => $invoice->amount_due,
            'amount_paid' => $invoice->amount_paid,
            'amount_remaining' => $invoice->amount_remaining,
            'hosted_invoice_url' => $invoice->hosted_invoice_url,
            'invoice_pdf_url' => $invoice->invoice_pdf_url,
            'period_starts_at' => $invoice->period_starts_at,
            'period_ends_at' => $invoice->period_ends_at,
            'due_at' => $invoice->due_at,
            'paid_at' => $invoice->paid_at,
            'voided_at' => $invoice->voided_at,
            // provider_payload_json deliberately omitted.
        ];
    }

    public static function payment(BillingPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'internal_reference' => $payment->internal_reference,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'amount_refunded' => $payment->amount_refunded,
            'payment_method_type' => $payment->payment_method_type,
            'failure_message' => $payment->failure_message,
            'paid_at' => $payment->paid_at,
            'refunded_at' => $payment->refunded_at,
            // failure_code, provider_payload_json deliberately omitted.
        ];
    }

    public static function checkoutSession(BillingCheckoutSession $session): array
    {
        return [
            'id' => $session->id,
            'internal_reference' => $session->internal_reference,
            'status' => $session->status,
            'billing_interval' => $session->billing_interval,
            'currency' => $session->currency,
            'amount' => $session->amount,
            // The Stripe-hosted Checkout page itself — safe to expose (it's
            // where the browser is meant to go next), unlike
            // provider_checkout_session_id, which stays internal.
            'checkout_url' => $session->checkout_url,
            'expires_at' => $session->expires_at,
            'completed_at' => $session->completed_at,
        ];
    }

    public static function billingCustomer(BillingCustomer $customer): array
    {
        return [
            'id' => $customer->id,
            'billing_email' => $customer->billing_email,
            'billing_name' => $customer->billing_name,
            'tax_id' => $customer->tax_id,
            'currency' => $customer->currency,
            // provider_customer_id, billing_address_json deliberately
            // omitted from the default presenter — a Super Admin detail
            // view may add provider_customer_id explicitly later, but never
            // by default.
        ];
    }

    /**
     * Super Admin diagnostic view of a webhook event — deliberately never
     * includes payload_json (the raw provider payload). A future "sanitized
     * detail view" (Phase 7) may add specific, safe fields from it, but
     * never the whole payload.
     */
    public static function webhookEvent(BillingWebhookEvent $event): array
    {
        return [
            'id' => $event->id,
            'provider' => $event->provider,
            'event_type' => $event->event_type,
            'livemode' => $event->livemode,
            'processing_status' => $event->processing_status,
            'attempt_count' => $event->attempt_count,
            'received_at' => $event->received_at,
            'processed_at' => $event->processed_at,
            'failed_at' => $event->failed_at,
            'failure_message' => $event->failure_message,
        ];
    }

    /**
     * The current commercial-access resolution — see
     * App\Services\Entitlements\SubscriptionAccessPolicy. Deliberately
     * exposes $reason as prose (already customer-safe, provider-independent
     * text written into that policy) rather than inventing a second
     * translation layer here.
     */
    public static function accessDecision(SubscriptionAccessDecision $decision): array
    {
        return [
            'mode' => $decision->mode,
            'subscription_status' => $decision->subscriptionStatus,
            'reason_code' => $decision->reasonCode,
            'reason' => $decision->reason,
        ];
    }

    /**
     * A pending/terminal plan-change request — see
     * App\Support\Billing\PlanChangeState. Never includes
     * target_price_mapping's provider_price_id; that is a provider
     * implementation detail the frontend has no legitimate use for.
     */
    public static function planChange(BillingPlanChange $planChange): array
    {
        return [
            'id' => $planChange->id,
            'change_type' => $planChange->change_type,
            'state' => $planChange->state,
            'source_plan_code' => $planChange->sourcePricingPlan?->code,
            'source_plan_name' => $planChange->sourcePricingPlan?->name,
            'target_plan_code' => $planChange->targetPricingPlan?->code,
            'target_plan_name' => $planChange->targetPricingPlan?->name,
            'requested_effective_at' => $planChange->requested_effective_at,
            'requested_at' => $planChange->requested_at,
            'sent_at' => $planChange->sent_at,
            'provider_confirmed_at' => $planChange->provider_confirmed_at,
            'applied_at' => $planChange->applied_at,
            'cancelled_at' => $planChange->cancelled_at,
            'superseded_at' => $planChange->superseded_at,
            'failure_message' => $planChange->failure_message,
        ];
    }

    /**
     * A purchasable plan for the authenticated organisation's Billing
     * plan-selector — pairs a public `pricing_plans` row with whichever
     * monthly/annual provider Price mapping currently applies (if any),
     * never a raw Stripe Price ID. $isCurrent marks the organisation's
     * currently-subscribed plan so the frontend never needs to compare
     * codes itself.
     */
    public static function purchasablePlan(
        PricingPlan $plan,
        ?PricingPlanProviderPrice $monthly,
        ?PricingPlanProviderPrice $annual,
        bool $isCurrent,
    ): array {
        return [
            'code' => $plan->code,
            'name' => $plan->name,
            'summary' => $plan->summary,
            'description' => $plan->description,
            'is_popular' => $plan->is_popular,
            'is_current' => $isCurrent,
            'monthly' => $monthly ? [
                'currency' => $monthly->currency,
                'unit_amount' => $monthly->unit_amount,
            ] : null,
            'annual' => $annual ? [
                'currency' => $annual->currency,
                'unit_amount' => $annual->unit_amount,
            ] : null,
            // A plan with neither mapping (Enterprise today) is not
            // self-serve — the frontend uses this, not a hardcoded plan
            // code, to decide whether to show "Contact Sales" instead of
            // a Checkout action.
            'is_self_serve' => $monthly !== null || $annual !== null,
            'cta_text' => $plan->cta_text,
            'cta_url' => $plan->cta_url,
        ];
    }
}
