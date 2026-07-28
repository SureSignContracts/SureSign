<?php

namespace App\Services\Billing;

use App\Models\BillingInvoice;
use App\Models\BillingPayment;
use App\Models\BillingPlanChange;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\Entitlements\SubscriptionAccessPolicy;
use App\Support\Billing\BillingPresenter;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-only organisation-facing Billing data — the backend half of the
 * customer Billing experience (Stripe Test Mode Integration checkpoint,
 * Slice A). Never mutates a Subscription/BillingCheckoutSession/
 * BillingPlanChange — every write remains the responsibility of
 * SubscriptionLifecycleService/CheckoutSessionService/
 * SubscriptionPlanChangeService/BillingPortalService, called from a later
 * slice. Every method here resolves scope from the authenticated User's own
 * Organization — never accepts an organisation id from the caller.
 */
class BillingOverviewService
{
    public function __construct(
        private SubscriptionAccessPolicy $accessPolicy,
        private SubscriptionPlanChangeService $planChangeService,
        private BillingProviderInterface $provider,
        private CheckoutSessionService $checkoutService,
    ) {
    }

    /**
     * The organisation's most recent subscription regardless of status
     * (draft through cancelled/expired) — deliberately NOT
     * Organization::liveSubscription(), which excludes terminal statuses a
     * Billing overview must still be able to display (e.g. "cancelled").
     */
    private function currentSubscription(Organization $organization): ?Subscription
    {
        return $organization->subscriptions()->latest('id')->first();
    }

    public function overview(User $user): array
    {
        $organization = $user->organization;
        $subscription = $this->currentSubscription($organization);
        $billingCustomer = $organization->billingCustomer;

        $latestInvoice = $subscription?->invoices()->latest('id')->first();
        $latestPayment = $subscription?->payments()->latest('id')->first();
        $pendingPlanChange = $subscription ? $this->planChangeService->pendingFor($subscription) : null;

        return [
            'has_subscription' => $subscription !== null,
            // Phase E6 — whether the organisation may start a fresh Checkout
            // right now. Deliberately NOT the inverse of has_subscription: a
            // cancelled/expired row (including an abandoned Checkout — see
            // isAbandonedCheckout()) never blocks a new attempt, matching
            // SubscriptionLifecycleService::hasConflictingSubscription().
            // The frontend's Plans grid must gate "Subscribe" on this field,
            // not on has_subscription.
            'can_start_new_checkout' => $subscription === null || !SubscriptionStatus::blocksNewCheckout($subscription->status),
            'subscription' => $subscription ? $this->presentSubscription($subscription, $pendingPlanChange) : null,
            'access' => BillingPresenter::accessDecision($this->accessPolicy->resolve($subscription)),
            'billing_customer' => $billingCustomer ? BillingPresenter::billingCustomer($billingCustomer) : null,
            'pending_plan_change' => $pendingPlanChange ? BillingPresenter::planChange($pendingPlanChange) : null,
            'latest_invoice' => $latestInvoice ? BillingPresenter::invoice($latestInvoice) : null,
            'latest_payment' => $latestPayment ? BillingPresenter::payment($latestPayment) : null,
        ];
    }

    public function subscriptionDetail(User $user): ?array
    {
        return $this->subscriptionDetailForOrganization($user->organization);
    }

    /**
     * G4A — the Organization-scoped counterpart to subscriptionDetail(),
     * for Super Admin/Admin organisation subscription administration
     * (read-only). subscriptionDetail() now delegates here rather than
     * duplicating this logic; the caller is responsible for its own
     * authorization — this method itself performs no access check, exactly
     * like every other method on this service.
     */
    public function subscriptionDetailForOrganization(Organization $organization): ?array
    {
        $subscription = $this->currentSubscription($organization);
        if ($subscription === null) {
            return null;
        }

        $pendingPlanChange = $this->planChangeService->pendingFor($subscription);

        return $this->presentSubscription($subscription, $pendingPlanChange) + [
            'access' => BillingPresenter::accessDecision($this->accessPolicy->resolve($subscription)),
        ];
    }

    /**
     * Phase E6 root-cause fix — a subscription cancelled/expired without
     * ever having been activated (e.g. `CheckoutSessionService::
     * cancelPendingCheckout()`, or an abandoned Checkout that reached
     * `expire()`) is an abandoned purchase attempt, not a commercial
     * cancellation. Both paths reach the same `status`/`cancelled_at`/
     * `ended_at` shape as a subscription that really was active and later
     * cancelled, so `activated_at` is the only reliable signal — never
     * infer this from status or timestamps alone. Also excludes any
     * subscription that ever started a trial (`trial_ends_at` is set only
     * by `SubscriptionLifecycleService::startTrial()`) — a trial cancelled/
     * expired without converting still gave the organisation a real period
     * of access, unlike a Checkout cancelled before payment.
     */
    private function isAbandonedCheckout(Subscription $subscription): bool
    {
        return in_array($subscription->status, [SubscriptionStatus::CANCELLED, SubscriptionStatus::EXPIRED], true)
            && $subscription->activated_at === null
            && $subscription->trial_ends_at === null;
    }

    private function presentSubscription(Subscription $subscription, ?BillingPlanChange $pendingPlanChange): array
    {
        return BillingPresenter::subscription($subscription) + [
            'plan_code' => $subscription->pricingPlan?->code,
            'plan_name' => $subscription->pricingPlan?->name,
            'pending_plan_code' => $subscription->pendingPricingPlan?->code,
            'pending_plan_name' => $subscription->pendingPricingPlan?->name,
            'pending_plan_change' => $pendingPlanChange ? BillingPresenter::planChange($pendingPlanChange) : null,
            // Phase E6 — see isAbandonedCheckout()'s docblock. The frontend
            // must never present this subscription as "Current Subscription"
            // / "Cancelled" the same way it presents a real past-active
            // cancellation.
            'is_abandoned_checkout' => $this->isAbandonedCheckout($subscription),
            // Billing Architecture Audit + Slice E1 checkpoint — a pending
            // cancellation is only ever reversible while the subscription
            // is still ACTIVE (once cancelled/expired there is nothing left
            // to resume, per SubscriptionCancellationService::resumeCancellation()).
            'can_resume_cancellation' => $subscription->cancel_at_period_end && $subscription->status === SubscriptionStatus::ACTIVE,
            // Phase E4 — only ever populated while awaiting payment; null
            // for every other status. Never exposes a provider checkout
            // session ID or URL — the frontend "Continue Payment" action
            // resubmits plan_code/billing_interval to the existing
            // POST /billing/checkout endpoint, which transparently reuses
            // the still-open session if is_resumable is true.
            'pending_checkout' => $subscription->status === SubscriptionStatus::PENDING_PAYMENT
                ? $this->presentPendingCheckout($subscription)
                : null,
        ];
    }

    private function presentPendingCheckout(Subscription $subscription): ?array
    {
        $plan = $subscription->pricingPlan;
        if ($plan === null) {
            return null;
        }

        $reusable = $this->checkoutService->findReusableCheckoutForPlan($subscription->organization, $plan, $subscription->billing_interval);

        return [
            'plan_code' => $plan->code,
            'plan_name' => $plan->name,
            'billing_interval' => $subscription->billing_interval,
            'is_resumable' => $reusable !== null,
            'expires_at' => $reusable?->expires_at,
        ];
    }

    public function pendingPlanChange(User $user): ?array
    {
        $subscription = $this->currentSubscription($user->organization);
        if ($subscription === null) {
            return null;
        }

        $pending = $this->planChangeService->pendingFor($subscription);

        return $pending ? BillingPresenter::planChange($pending) : null;
    }

    /**
     * Every active, published plan paired with whichever monthly/annual
     * provider Price mapping currently applies for the organisation's
     * resolved currency and the provider's current livemode — never a raw
     * Stripe Price ID, and never a plan the frontend could submit an
     * arbitrary identifier against (Non-negotiable Principle 4).
     */
    /**
     * Phase E4 fix: a plan is only ever "current" while the subscription
     * has genuinely reached a real commercial relationship with it —
     * never while still pre-activation (`draft`/`pending_payment`/
     * `incomplete`, where Checkout hasn't been confirmed by a webhook
     * yet) and never once it's over (`cancelled`/`expired`). Before this
     * fix, ANY subscription status (including an abandoned
     * `pending_payment` Checkout) marked its plan `is_current: true` —
     * the exact root cause of the "trapped customer" bug this phase
     * fixes: the plan the customer merely attempted to buy looked
     * identical to one they were actually paying for.
     */
    private const CURRENT_PLAN_STATUSES = [
        SubscriptionStatus::TRIALING,
        SubscriptionStatus::ACTIVE,
        SubscriptionStatus::PAST_DUE,
        SubscriptionStatus::UNPAID,
        SubscriptionStatus::PAUSED,
        SubscriptionStatus::SUSPENDED,
    ];

    public function availablePlans(User $user): array
    {
        $organization = $user->organization;
        $currency = CurrencyService::resolveOrganizationCode($organization);
        $livemode = $this->provider->isLivemode();
        $currentSubscription = $this->currentSubscription($organization);
        $currentPlanId = ($currentSubscription !== null && in_array($currentSubscription->status, self::CURRENT_PLAN_STATUSES, true))
            ? $currentSubscription->pricing_plan_id
            : null;

        $plans = PricingPlan::query()
            ->where('status', 'active')
            ->orderBy('order')
            ->get();

        $mappings = PricingPlanProviderPrice::query()
            ->whereIn('pricing_plan_id', $plans->pluck('id'))
            ->where('currency', $currency)
            ->active()
            ->forLivemode($livemode)
            ->get()
            ->groupBy('pricing_plan_id');

        return $plans->map(function (PricingPlan $plan) use ($mappings, $currentPlanId) {
            $planMappings = $mappings->get($plan->id, collect());

            return BillingPresenter::purchasablePlan(
                $plan,
                $planMappings->firstWhere('billing_interval', 'monthly'),
                $planMappings->firstWhere('billing_interval', 'annual'),
                $currentPlanId !== null && $plan->id === $currentPlanId,
            );
        })->values()->all();
    }

    public function invoices(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return BillingInvoice::query()
            ->where('organization_id', $user->organization_id)
            ->latest('id')
            ->paginate($perPage)
            ->through(fn ($invoice) => BillingPresenter::invoice($invoice));
    }

    public function payments(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return BillingPayment::query()
            ->where('organization_id', $user->organization_id)
            ->latest('id')
            ->paginate($perPage)
            ->through(fn ($payment) => BillingPresenter::payment($payment));
    }
}
