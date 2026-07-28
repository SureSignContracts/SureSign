'use client';

import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { ArrowLeft, CreditCard, Loader2 } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import { useBillingOverview, useBillingPlans, PORTAL_RETURN_FLAG_KEY } from '@/hooks/useBilling';
import EmptyState from '@/components/ui/EmptyState';
import AccessStatusBanner from '@/components/billing/AccessStatusBanner';
import SubscriptionSummaryCard from '@/components/billing/SubscriptionSummaryCard';
import PendingPlanChangeCard from '@/components/billing/PendingPlanChangeCard';
import PlanComparisonSection from '@/components/billing/PlanComparisonSection';
import InvoiceListSection from '@/components/billing/InvoiceListSection';
import PaymentListSection from '@/components/billing/PaymentListSection';
import BillingPortalCard from '@/components/billing/BillingPortalCard';
import SubscriptionIntelligenceSection from '@/components/billing/intelligence/SubscriptionIntelligenceSection';

function Skeleton() {
  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="h-9 w-64 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="h-56 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="h-64 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ))}
      </div>
    </div>
  );
}

export default function BillingPage() {
  const { user } = useAuthStore();
  const timeZone = user?.effective_timezone ?? undefined;
  const qc = useQueryClient();

  const { data: overview, isLoading: overviewLoading, isError: overviewError } = useBillingOverview();
  const { data: plansData, isLoading: plansLoading } = useBillingPlans();

  // Phase E5 — detects a genuine return from the Stripe Customer Portal
  // (see PORTAL_RETURN_FLAG_KEY's docblock) and surfaces a brief, real
  // "refreshing" cue tied to the actual invalidated refetch, not a fixed
  // timer. The initial value is computed lazily (not via a same-tick
  // setState in an effect, which react-hooks/set-state-in-effect rightly
  // flags) — the flag is either present at mount or it isn't. The effect
  // below only performs the actual side effects (clearing the one-time
  // flag, invalidating the query) and clears the banner from inside the
  // async `.finally()`, not synchronously in the effect body.
  const [returningFromPortal, setReturningFromPortal] = useState(
    () => typeof window !== 'undefined' && sessionStorage.getItem(PORTAL_RETURN_FLAG_KEY) === '1',
  );
  useEffect(() => {
    if (!returningFromPortal) return;
    sessionStorage.removeItem(PORTAL_RETURN_FLAG_KEY);
    qc.invalidateQueries({ queryKey: ['billing'] }).finally(() => setReturningFromPortal(false));
    // Runs once, on mount, deliberately — this reacts to the one-time
    // return-visit flag computed above, not to qc identity changing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (overviewLoading) return <Skeleton />;

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="ss-animate-in">
        <Link href="/app/settings" className="inline-flex items-center gap-1.5 text-xs mb-4 transition-all duration-200 hover:opacity-70 hover:-translate-x-0.5" style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={13} /> Back to Settings
        </Link>
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl flex items-center justify-center transition-transform duration-300 hover:scale-105" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <CreditCard size={18} style={{ color: 'var(--text-secondary)' }} />
          </div>
          <div>
            <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Billing</h1>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Your subscription, plan and payment history</p>
          </div>
        </div>
      </div>

      {returningFromPortal && (
        <div
          role="status"
          aria-live="polite"
          className="rounded-2xl p-4 flex items-center gap-3 ss-animate-in"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
        >
          <Loader2 size={16} className="animate-spin flex-shrink-0 motion-reduce:animate-none" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
            Welcome back — refreshing your billing information…
          </p>
        </div>
      )}

      {overviewError || !overview ? (
        <EmptyState
          surface
          icon={CreditCard}
          title="Couldn't load Billing"
          description="Something went wrong loading your Billing details. Please try again shortly."
        />
      ) : (
        <>
          <AccessStatusBanner
            access={overview.access}
            graceEndsAt={overview.subscription?.grace_period_ends_at}
            timeZone={timeZone}
          />

          {(!overview.has_subscription || overview.subscription?.is_abandoned_checkout) && (
            <div
              className="rounded-2xl p-5 ss-animate-in"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
            >
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                {overview.subscription?.is_abandoned_checkout
                  ? 'Your previous Checkout was cancelled before payment was taken.'
                  : "Your organisation doesn't have an active subscription yet."}
              </p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {overview.subscription?.is_abandoned_checkout
                  ? 'No subscription was created and nothing was charged. Choose a plan below whenever you’re ready.'
                  : 'Choose a plan below to get started with secure Stripe Checkout.'}
              </p>
            </div>
          )}

          {overview.subscription && !overview.subscription.is_abandoned_checkout && (
            <SubscriptionSummaryCard
              subscription={overview.subscription}
              timeZone={timeZone}
              hasPendingPlanChange={!!overview.pending_plan_change}
            />
          )}

          {overview.pending_plan_change && (
            <PendingPlanChangeCard planChange={overview.pending_plan_change} timeZone={timeZone} />
          )}

          {overview.billing_customer && <BillingPortalCard />}

          {overview.has_subscription && !overview.subscription?.is_abandoned_checkout && (
            <SubscriptionIntelligenceSection timeZone={timeZone} />
          )}

          {!plansLoading && plansData && (
            <PlanComparisonSection
              plans={plansData.plans}
              hasSubscription={!overview.can_start_new_checkout}
              eligibleForPlanChange={overview.subscription?.status === 'active' && !overview.subscription?.cancel_at_period_end}
              hasPendingChange={!!overview.pending_plan_change}
              pendingCheckout={overview.subscription?.pending_checkout ?? null}
            />
          )}

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <InvoiceListSection />
            <PaymentListSection timeZone={timeZone} />
          </div>
        </>
      )}
    </div>
  );
}
