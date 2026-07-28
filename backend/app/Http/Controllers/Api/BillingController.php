<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Services\Billing\BillingOverviewService;
use App\Support\Billing\BillingPresenter;
use Illuminate\Http\Request;

/**
 * Organisation-facing Billing — read-only (Stripe Test Mode Integration
 * checkpoint, Slice A). No Checkout, Portal, upgrade/downgrade,
 * cancellation, or any other provider write is exposed here — see
 * CheckoutSessionService/SubscriptionPlanChangeService/
 * SubscriptionLifecycleService/BillingPortalService for those, wired up in
 * a later slice. Tenant isolation is enforced by BillingOverviewService
 * scoping every query to $request->user()->organization — never a
 * caller-supplied organisation id.
 */
class BillingController extends Controller
{
    public function __construct(private BillingOverviewService $billing)
    {
    }

    /**
     * GET /billing/overview
     */
    public function overview(Request $request)
    {
        return response()->json($this->billing->overview($request->user()));
    }

    /**
     * GET /billing/subscription
     */
    public function subscription(Request $request)
    {
        return response()->json(['subscription' => $this->billing->subscriptionDetail($request->user())]);
    }

    /**
     * GET /billing/plans
     */
    public function plans(Request $request)
    {
        return response()->json(['plans' => $this->billing->availablePlans($request->user())]);
    }

    /**
     * GET /billing/pending-plan-change
     */
    public function pendingPlanChange(Request $request)
    {
        return response()->json(['pending_plan_change' => $this->billing->pendingPlanChange($request->user())]);
    }

    /**
     * GET /billing/invoices
     */
    public function invoices(Request $request)
    {
        return response()->json($this->billing->invoices($request->user()));
    }

    /**
     * GET /billing/invoices/{invoice}
     */
    public function invoice(Request $request, BillingInvoice $invoice)
    {
        $this->authorizeInvoice($request, $invoice);

        return response()->json(BillingPresenter::invoice($invoice));
    }

    /**
     * GET /billing/payments
     */
    public function payments(Request $request)
    {
        return response()->json($this->billing->payments($request->user()));
    }

    private function authorizeInvoice(Request $request, BillingInvoice $invoice): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $invoice->organization_id) {
            abort(403, 'Access denied.');
        }
    }
}
