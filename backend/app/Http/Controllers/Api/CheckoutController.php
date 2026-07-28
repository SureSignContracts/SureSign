<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCheckoutSessionRequest;
use App\Models\PricingPlan;
use App\Services\Billing\CheckoutSessionService;
use App\Services\Billing\Exceptions\CheckoutValidationException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\CurrencyService;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * First-subscription Checkout initiation (Stripe Sandbox — Slice C2). This
 * is the only Billing endpoint that ever creates anything — every other
 * endpoint in BillingController stays strictly read-only (see its own
 * docblock). Never accepts a Stripe Price/Product ID, amount, currency,
 * return URL, or provider customer/subscription ID from the request — the
 * approved local plan code and billing interval are the only inputs;
 * everything else is resolved server-side, exactly as
 * CheckoutSessionService already requires.
 */
class CheckoutController extends Controller
{
    public function __construct(private CheckoutSessionService $checkout)
    {
    }

    /**
     * POST /billing/checkout
     */
    public function store(StoreCheckoutSessionRequest $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        $plan = PricingPlan::query()
            ->where('code', $request->validated('plan_code'))
            ->where('status', 'active')
            ->first();

        if ($plan === null) {
            return response()->json(['message' => 'This plan is not currently available.'], 422);
        }

        $billingInterval = $request->validated('billing_interval');
        $currency = CurrencyService::resolveOrganizationCode($organization);

        // A genuine double-click/two-tab/retry against the exact same
        // in-flight attempt reuses it directly, rather than surfacing
        // startCheckout()'s conflict exception (correct for a genuinely
        // different conflicting subscription, not helpful here).
        $reusable = $this->checkout->findReusableCheckoutForPlan($organization, $plan, $billingInterval);
        if ($reusable !== null) {
            return response()->json(BillingPresenter::checkoutSession($reusable));
        }

        try {
            $session = $this->checkout->startCheckout(
                $organization,
                $plan,
                $billingInterval,
                $currency,
                $user,
                $this->successUrl(),
                $this->cancelUrl(),
            );
        } catch (SubscriptionLifecycleConflictException) {
            return response()->json([
                'message' => 'Your organisation already has an active or pending subscription. Manage it from Billing instead of starting a new one.',
                'code' => 'subscription_conflict',
            ], 409);
        } catch (CheckoutValidationException $e) {
            // This plan genuinely has no active Test Mode price mapping yet
            // (e.g. Enterprise, which is Contact Sales only) — the same
            // message CheckoutSessionService already produces is safe to
            // return verbatim: it names only the plan/interval/currency,
            // never a provider identifier or secret.
            return response()->json(['message' => $e->getMessage(), 'code' => 'checkout_unavailable'], 422);
        } catch (ApiErrorException $e) {
            // Never surface a raw Stripe SDK exception (stack trace, file
            // paths, or internal error detail) to the customer-facing API —
            // log the real detail server-side only, correlated by
            // organisation/plan, never a card or secret.
            Log::error('Stripe API error while starting Checkout', [
                'organization_id' => $organization->id,
                'pricing_plan_id' => $plan->id,
                'billing_interval' => $billingInterval,
                'stripe_error_type' => $e->getStripeCode(),
            ]);

            return response()->json([
                'message' => 'We could not start Checkout right now. Please try again shortly.',
                'code' => 'provider_error',
            ], 502);
        }

        return response()->json(BillingPresenter::checkoutSession($session));
    }

    /**
     * POST /billing/checkout/cancel-pending
     *
     * Phase E4 — lets a customer explicitly abandon an unfinished Checkout
     * attempt (closed the Stripe tab, changed their mind) without any
     * administrative intervention. Empty body: the organisation's own
     * current subscription is resolved server-side, exactly like
     * SubscriptionCancellationController's endpoints. Only ever valid
     * while that subscription is `pending_payment` — an active
     * subscription must go through `/billing/subscription/cancel`
     * instead (a different commercial operation: scheduled, not
     * immediate).
     */
    public function cancelPending(Request $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        $subscription = $organization->subscriptions()->latest('id')->first();

        if ($subscription === null || $subscription->status !== SubscriptionStatus::PENDING_PAYMENT) {
            return response()->json([
                'message' => 'There is no pending Checkout to cancel.',
                'code' => 'no_pending_checkout',
            ], 422);
        }

        try {
            $subscription = $this->checkout->cancelPendingCheckout($subscription, $user);
        } catch (SubscriptionLifecycleConflictException $e) {
            // Phase E6 — never echo this exception's message to the
            // customer: it is written for logs/developers.
            Log::warning('Pending Checkout cancellation rejected', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'There is no pending Checkout to cancel.',
                'code' => 'no_pending_checkout',
            ], 409);
        }

        return response()->json(BillingPresenter::subscription($subscription));
    }

    private function successUrl(): string
    {
        return config('billing.checkout_success_url') . '?session_id={CHECKOUT_SESSION_ID}';
    }

    private function cancelUrl(): string
    {
        return config('billing.checkout_cancel_url');
    }
}
