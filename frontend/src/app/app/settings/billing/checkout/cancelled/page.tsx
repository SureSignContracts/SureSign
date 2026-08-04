'use client';

import Link from 'next/link';
import { XCircle } from 'lucide-react';
import Button from '@/components/ui/Button';
import { useBillingOverview } from '@/hooks/useBilling';
import { subscriptionStatusLabel } from '@/lib/billingStatus';

/**
 * No provider call happens here and nothing is marked active — Checkout
 * cancellation is purely informational. Retrying is just "go back to
 * Billing and select a plan again"; CheckoutSessionService's own reuse
 * logic (via CheckoutController's findReusableCheckoutForPlan() pre-check)
 * transparently reuses the still-open session if one exists, so there is
 * nothing for this page itself to orchestrate.
 */
export default function CheckoutCancelledPage() {
  const { data: overview } = useBillingOverview();

  return (
    <div className="p-6 max-w-2xl mx-auto">
      <div
        className="rounded-2xl px-6 py-8 sm:p-10 text-center space-y-4 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <XCircle size={24} style={{ color: 'var(--text-muted)' }} />
        </div>
        <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Checkout was not completed</h1>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
          Payment was not completed and no subscription was created. You have not been charged.
        </p>
        {overview?.has_subscription && !overview.subscription?.is_abandoned_checkout && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Current Billing status: {subscriptionStatusLabel(overview.subscription?.status)}
          </p>
        )}

        <div className="flex items-center justify-center gap-3 pt-2 flex-wrap">
          <Link href="/app/settings/billing">
            <Button variant="secondary" size="sm">Return to Billing</Button>
          </Link>
          <Link href="/app/settings/billing/subscription">
            <Button variant="primary" size="sm">Try again</Button>
          </Link>
        </div>
      </div>
    </div>
  );
}
