<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces config('billing.enabled')'s own documented contract (see
 * config/billing.php: "'enabled' gates whether any billing feature
 * (checkout, portal, webhook processing) is reachable at all") — before this
 * middleware existed, that flag was read by App\Services\Billing\
 * BillingProviderManager::isEnabled() but nothing ever called it, so
 * Checkout/Portal/plan-change/cancellation were fully live-reachable
 * regardless of BILLING_ENABLED as long as Stripe credentials happened to be
 * configured. This middleware is what actually keeps those endpoints dormant
 * while BILLING_ENABLED=false, per the go-live checklist in
 * internal-docs/super-admin/subscription-billing.md.
 *
 * Deliberately does not touch the read-only BillingController endpoints
 * (overview/subscription/plans/invoices/payments/intelligence) — those stay
 * reachable so an organisation with no subscription simply sees "no
 * subscription" rather than the whole Billing page erroring out.
 */
class EnsureBillingIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('billing.enabled')) {
            return response()->json([
                'message' => 'Billing is not currently available.',
                'code' => 'billing_disabled',
            ], 503);
        }

        return $next($request);
    }
}
