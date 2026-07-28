<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Billing\BillingPortalService;
use App\Services\Billing\Exceptions\SubscriptionLifecycleConflictException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;

/**
 * Slice E2 — restricted Stripe Customer Portal session creation. Accepts
 * an empty body: no Stripe Customer ID, Organisation ID, Portal
 * Configuration ID, or return URL is ever accepted from the frontend —
 * every one of those is resolved server-side (see BillingPortalService).
 * This is the only Billing endpoint that opens a provider-hosted page;
 * every other commercial action (plan changes, cancellation) stays on
 * SureSign's own endpoints.
 */
class BillingPortalController extends Controller
{
    public function __construct(private BillingPortalService $portal)
    {
    }

    /**
     * POST /billing/portal
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $organization = $user->organization;

        try {
            $session = $this->portal->createSession($organization, $user);
        } catch (SubscriptionLifecycleConflictException $e) {
            // Phase E6 — never echo this exception's message to the
            // customer: it names internal config keys/configuration ids.
            Log::warning('Customer Portal session could not be created', [
                'organization_id' => $organization->id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Billing management is not available right now. Please try again shortly.',
                'code' => 'portal_unavailable',
            ], 409);
        } catch (ApiErrorException $e) {
            Log::error('Stripe API error creating a Customer Portal session', [
                'organization_id' => $organization->id,
                'stripe_error_type' => $e->getStripeCode(),
            ]);

            return response()->json([
                'message' => 'We could not open billing management right now. Please try again shortly.',
                'code' => 'provider_error',
            ], 502);
        }

        return response()->json(['url' => $session['url']]);
    }
}
