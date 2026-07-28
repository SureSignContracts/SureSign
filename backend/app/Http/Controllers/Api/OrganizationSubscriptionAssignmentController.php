<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignOrganizationSubscriptionRequest;
use App\Http\Requests\TerminateOrganizationSubscriptionRequest;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Subscription;
use App\Services\Admin\OrganizationSubscriptionAdminService;
use App\Services\Billing\Exceptions\InvalidSubscriptionTransitionException;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use App\Services\Billing\SubscriptionLifecycleService;
use App\Services\Billing\TransitionContext;
use App\Support\Billing\TransitionSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * G4B.2 — the first write-capable subscription workflow: Super
 * Admin-only assignment and termination of `manual`/`complimentary`
 * subscriptions. Gated entirely by the `role:Super Admin` route group
 * (routes/api.php) — Admin keeps only the G4A read-only endpoint, never
 * this controller. Every mutation delegates to
 * `SubscriptionLifecycleService`, the sole authoritative write path; this
 * controller resolves/validates input and shapes the response, nothing
 * more.
 */
class OrganizationSubscriptionAssignmentController extends Controller
{
    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly OrganizationSubscriptionAdminService $adminService,
    ) {
    }

    /**
     * POST /organizations/{organization}/subscriptions/assign-manual
     */
    public function assignManual(AssignOrganizationSubscriptionRequest $request, Organization $organization)
    {
        return $this->assign($request, $organization, fn (PricingPlan $plan, string $interval, string $reason, TransitionContext $context, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt) =>
            $this->lifecycle->assignManualSubscription($organization, $plan, $interval, $reason, $context, $startsAt, $endsAt));
    }

    /**
     * POST /organizations/{organization}/subscriptions/assign-complimentary
     */
    public function assignComplimentary(AssignOrganizationSubscriptionRequest $request, Organization $organization)
    {
        return $this->assign($request, $organization, fn (PricingPlan $plan, string $interval, string $reason, TransitionContext $context, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt) =>
            $this->lifecycle->assignComplimentarySubscription($organization, $plan, $interval, $reason, $context, $startsAt, $endsAt));
    }

    private function assign(AssignOrganizationSubscriptionRequest $request, Organization $organization, \Closure $action)
    {
        // "Marketing visibility must not determine assignability" — a plan
        // may be commercially active but hidden from the public pricing
        // page; PricingPlan::scopeActive() filters on is_visible/
        // published_at (marketing concerns), so it is deliberately NOT
        // used here. Only `status = 'active'` gates whether Super Admin
        // may assign a plan.
        $plan = PricingPlan::query()
            ->where('code', $request->validated('plan_code'))
            ->where('status', 'active')
            ->first();

        if ($plan === null) {
            return response()->json([
                'message' => 'The selected plan is not an active, assignable plan.',
                'code' => 'plan_not_assignable',
            ], 422);
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::SUPER_ADMIN,
            'actor_user_id' => $request->user()->id,
            'reason' => $request->validated('reason'),
        ]);

        $startsAt = $request->filled('starts_at') ? CarbonImmutable::parse($request->validated('starts_at')) : null;
        $endsAt = $request->filled('ends_at') ? CarbonImmutable::parse($request->validated('ends_at')) : null;

        try {
            $action($plan, $request->validated('billing_interval'), $request->validated('reason'), $context, $startsAt, $endsAt);
        } catch (SubscriptionLifecycleConflictException $e) {
            Log::warning('Organisation subscription assignment refused', [
                'organization_id' => $organization->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This organisation already has a subscription that must be resolved or terminated before a new one can be assigned.',
                'code' => 'subscription_conflict',
            ], 409);
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Another assignment request for this organisation is already in progress. Please try again shortly.',
                'code' => 'subscription_conflict',
            ], 409);
        } catch (InvalidSubscriptionTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_request'], 422);
        }

        return response()->json(['data' => $this->adminService->forOrganization($organization->fresh())], 201);
    }

    /**
     * POST /organizations/{organization}/subscriptions/{subscription}/terminate
     */
    public function terminate(TerminateOrganizationSubscriptionRequest $request, Organization $organization, Subscription $subscription)
    {
        if ($subscription->organization_id !== $organization->id) {
            abort(404);
        }

        $context = TransitionContext::make([
            'source' => TransitionSource::SUPER_ADMIN,
            'actor_user_id' => $request->user()->id,
            'reason' => $request->validated('reason'),
        ]);

        try {
            $this->lifecycle->terminateManualOrComplimentarySubscription($subscription, $request->validated('reason'), $context);
        } catch (SubscriptionLifecycleConflictException $e) {
            Log::warning('Organisation subscription termination refused', [
                'organization_id' => $organization->id,
                'subscription_id' => $subscription->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'This subscription cannot be terminated through this action — a Stripe-connected subscription must be managed through the Stripe subscription lifecycle.',
                'code' => 'stripe_termination_not_permitted',
            ], 409);
        } catch (InvalidSubscriptionTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_request'], 422);
        }

        return response()->json(['data' => $this->adminService->forOrganization($organization->fresh())]);
    }
}
