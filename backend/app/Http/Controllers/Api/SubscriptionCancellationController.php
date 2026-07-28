<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\SubscriptionCancellationService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\TransitionSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * First-party subscription cancellation (Billing Architecture Audit +
 * Slice E1 checkpoint) — SureSign owns this workflow entirely; Stripe
 * Customer Portal cancellation is never exposed. Both endpoints accept an
 * empty body: cancellation is always scheduled for the current billing
 * period's end (never immediate, never a caller-chosen date), and resume
 * always just undoes that. Neither endpoint accepts a provider
 * subscription ID or an Organisation ID — both are resolved from the
 * authenticated user only.
 */
class SubscriptionCancellationController extends Controller
{
    public function __construct(private SubscriptionCancellationService $cancellation)
    {
    }

    /**
     * POST /billing/subscription/cancel
     */
    public function cancel(Request $request)
    {
        return $this->handle($request, fn ($subscription, $context) => $this->cancellation->requestCancellation($subscription, $context));
    }

    /**
     * POST /billing/subscription/resume
     */
    public function resume(Request $request)
    {
        return $this->handle($request, fn ($subscription, $context) => $this->cancellation->resumeCancellation($subscription, $context));
    }

    private function handle(Request $request, \Closure $action)
    {
        $user = $request->user();
        $organization = $user->organization;

        $subscription = $organization->subscriptions()->latest('id')->first();

        if ($subscription === null) {
            return response()->json([
                'message' => 'Your organisation does not have a subscription to manage.',
                'code' => 'no_subscription',
            ], 422);
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::CUSTOMER_BILLING_ACTION,
            'actor_user_id' => $user->id,
        ]);

        try {
            $subscription = $action($subscription, $context);
        } catch (SubscriptionLifecycleConflictException $e) {
            // Phase E6 — never echo this exception's message to the
            // customer: it is written for logs/developers (internal
            // references, status internals), not for the Billing UI.
            Log::warning('Subscription cancellation/resume rejected', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This subscription could not be updated in its current state. Please refresh the page and try again.',
                'code' => 'cancellation_conflict',
            ], 409);
        } catch (ApiErrorException $e) {
            Log::error('Stripe API error during subscription cancellation/resume', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'stripe_error_type' => $e->getStripeCode(),
            ]);

            return response()->json([
                'message' => 'We could not process this request right now. Please try again shortly.',
                'code' => 'provider_error',
            ], 502);
        }

        return response()->json(BillingPresenter::subscription($subscription));
    }
}
