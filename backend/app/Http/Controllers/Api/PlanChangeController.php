<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePlanChangeRequest;
use App\Models\BillingPlanChange;
use App\Models\PricingPlan;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\PlanChangeNotSupportedException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\PlanPriceMappingService;
use App\Services\Billing\SubscriptionPlanChangeService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\PlanChangeClassification;
use App\Support\Billing\PlanChangeClassifier;
use App\Support\Billing\SubscriptionStatus;
use App\Support\Billing\TransitionSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Upgrade/downgrade requests and pending-change cancellation for an
 * Organisation that already has an active subscription (Stripe Sandbox
 * Plan-Change checkpoint — Slice D). Every write is delegated to
 * `SubscriptionPlanChangeService` — this controller decides only WHICH
 * of `requestUpgrade()`/`requestDowngrade()` applies (via
 * `PlanChangeClassifier`, ranking by `PricingPlan::$order`) and resolves
 * the target Price server-side; it never mutates `subscriptions.pricing_plan_id`
 * or `billing_plan_changes.state` directly.
 */
class PlanChangeController extends Controller
{
    public function __construct(
        private SubscriptionPlanChangeService $planChanges,
        private PlanPriceMappingService $priceMapping,
    ) {
    }

    /**
     * POST /billing/plan-change
     */
    public function store(StorePlanChangeRequest $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        $subscription = $organization->subscriptions()->latest('id')->first();

        if ($subscription === null || $subscription->status !== SubscriptionStatus::ACTIVE) {
            return response()->json([
                'message' => 'A plan change requires an active subscription. Manage your subscription from Billing.',
                'code' => 'subscription_not_eligible',
            ], 422);
        }

        $targetPlan = PricingPlan::query()
            ->where('code', $request->validated('plan_code'))
            ->where('status', 'active')
            ->first();

        if ($targetPlan === null) {
            return response()->json(['message' => 'This plan is not currently available.', 'code' => 'plan_unavailable'], 422);
        }

        $targetInterval = $request->validated('billing_interval');
        $currentPlan = $subscription->pricingPlan;

        $classification = $currentPlan
            ? PlanChangeClassifier::classify($currentPlan, $subscription->billing_interval, $targetPlan, $targetInterval)
            : PlanChangeClassification::UPGRADE; // no resolvable current plan row — treat as a fresh selection, not a no-op

        if ($classification === PlanChangeClassification::NO_CHANGE) {
            return response()->json(['message' => 'Your organisation is already on this plan.', 'code' => 'no_change'], 422);
        }

        if ($classification === PlanChangeClassification::AMBIGUOUS_INTERVAL_CHANGE) {
            return response()->json([
                'message' => 'Changing billing interval alone (without changing plan) is not supported yet. Please contact support.',
                'code' => 'interval_change_unsupported',
            ], 422);
        }

        $targetMapping = $this->priceMapping->resolveActivePrice($targetPlan, $targetInterval, $subscription->currency);

        if ($targetMapping === null) {
            return response()->json([
                'message' => "This plan is not currently available for self-service. Please contact sales.",
                'code' => 'plan_change_unavailable',
            ], 422);
        }

        $pending = $this->planChanges->pendingFor($subscription);

        // Identical repeated request — return the existing authoritative
        // operation rather than creating a duplicate (Stage 8).
        if ($pending !== null
            && $pending->target_pricing_plan_id === $targetPlan->id
            && $pending->target_price_mapping_id === $targetMapping->id
            && $pending->change_type === $classification
        ) {
            return response()->json(BillingPresenter::planChange($pending));
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::CUSTOMER_BILLING_ACTION,
            'actor_user_id' => $user->id,
        ]);

        try {
            if ($classification === PlanChangeClassification::UPGRADE) {
                $planChange = $this->planChanges->requestUpgrade(
                    $subscription, $targetPlan, $targetMapping, $context,
                    scheduled: false, supersede: $pending !== null,
                );
                // Immediate upgrades send synchronously — see
                // SubscriptionPlanChangeService::send()'s own docblock.
                $planChange = $this->planChanges->send($planChange);
            } else {
                $planChange = $this->planChanges->requestDowngrade(
                    $subscription, $targetPlan, $targetMapping, $context,
                    supersede: $pending !== null,
                );
                // Downgrades stay REQUESTED — sent later, at the effective
                // date, by the existing billing:subscriptions:process-automation
                // schedule (SubscriptionAutomationService::processDuePlanChanges()).
            }
        } catch (SubscriptionLifecycleConflictException $e) {
            // Phase E6 — never echo this exception's message to the
            // customer: it names internal ids/state values.
            Log::warning('Plan change request rejected', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This plan change is not possible right now. Please refresh the page and try again.',
                'code' => 'plan_change_conflict',
            ], 409);
        } catch (PlanChangeNotSupportedException|InvalidSubscriptionTransitionException $e) {
            Log::warning('Plan change request not supported', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This plan change is not currently supported for your subscription.',
                'code' => 'plan_change_not_supported',
            ], 422);
        } catch (ApiErrorException $e) {
            Log::error('Stripe API error while sending a plan change', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'target_pricing_plan_id' => $targetPlan->id,
                'stripe_error_type' => $e->getStripeCode(),
            ]);

            return response()->json([
                'message' => 'We could not process this plan change right now. Please try again shortly.',
                'code' => 'provider_error',
            ], 502);
        }

        return response()->json(BillingPresenter::planChange($planChange));
    }

    /**
     * POST /billing/plan-change/{planChange}/cancel
     */
    public function cancel(Request $request, BillingPlanChange $planChange)
    {
        $this->authorizePlanChange($request, $planChange);

        $subscription = $planChange->subscription;
        $currentlyPending = $this->planChanges->pendingFor($subscription);

        if ($currentlyPending === null || $currentlyPending->id !== $planChange->id) {
            return response()->json([
                'message' => 'This plan change is no longer pending.',
                'code' => 'not_pending',
            ], 409);
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::CUSTOMER_BILLING_ACTION,
            'actor_user_id' => $request->user()->id,
        ]);

        try {
            $cancelled = $this->planChanges->cancelPending($subscription, $context);
        } catch (SubscriptionLifecycleConflictException $e) {
            Log::warning('Plan change cancellation rejected', [
                'organization_id' => $subscription->organization_id,
                'subscription_id' => $subscription->id,
                'plan_change_id' => $planChange->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This plan change can no longer be cancelled — it may have already been sent to Stripe.',
                'code' => 'no_longer_cancellable',
            ], 409);
        }

        return response()->json(BillingPresenter::planChange($cancelled));
    }

    private function authorizePlanChange(Request $request, BillingPlanChange $planChange): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $planChange->organization_id) {
            abort(403, 'Access denied.');
        }
    }
}
