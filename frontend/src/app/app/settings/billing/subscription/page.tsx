'use client';

import Link from 'next/link';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import { useBillingOverview, useBillingPlans } from '@/hooks/useBilling';
import EmptyState from '@/components/ui/EmptyState';
import AccessStatusBanner from '@/components/billing/AccessStatusBanner';
import SubscriptionSummaryCard from '@/components/billing/SubscriptionSummaryCard';
import PendingPlanChangeCard from '@/components/billing/PendingPlanChangeCard';
import PlanComparisonSection from '@/components/billing/PlanComparisonSection';
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

/**
 * Split out of the Billing page — this owns the subscription lifecycle
 * itself (current plan, pending changes, upgrade/downgrade) while Billing
 * stays focused on payment methods and payment/invoice history. See
 * app/settings/billing/page.tsx's own docblock-equivalent history: it
 * previously rendered all of this inline.
 */
export default function SubscriptionPage() {
  const { user } = useAuthStore();
  const timeZone = user?.effective_timezone ?? undefined;

  const { data: overview, isLoading: overviewLoading, isError: overviewError } = useBillingOverview();
  const { data: plansData, isLoading: plansLoading } = useBillingPlans();

  if (overviewLoading) return <Skeleton />;

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div className="ss-animate-in">
        <Link href="/app/settings/billing" className="inline-flex items-center gap-1.5 text-xs mb-4 transition-all duration-200 hover:opacity-70 hover:-translate-x-0.5" style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={13} /> Back to Billing
        </Link>
        <div className="flex items-center gap-3">
          <div className="w-9 h-9 rounded-xl flex items-center justify-center transition-transform duration-300 hover:scale-105" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <RefreshCw size={18} style={{ color: 'var(--text-secondary)' }} />
          </div>
          <div>
            <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Subscription</h1>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Your plan, upgrades and downgrades</p>
          </div>
        </div>
      </div>

      {overviewError || !overview ? (
        <EmptyState
          surface
          icon={RefreshCw}
          title="Couldn't load Subscription"
          description="Something went wrong loading your subscription details. Please try again shortly."
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

          {overview.has_subscription && !overview.subscription?.is_abandoned_checkout && (
            <SubscriptionIntelligenceSection timeZone={timeZone} />
          )}

          {!plansLoading && plansData && (
            <div id="plans">
              <PlanComparisonSection
                plans={plansData.plans}
                hasSubscription={!overview.can_start_new_checkout}
                eligibleForPlanChange={overview.subscription?.status === 'active' && !overview.subscription?.cancel_at_period_end}
                hasPendingChange={!!overview.pending_plan_change}
                pendingCheckout={overview.subscription?.pending_checkout ?? null}
              />
            </div>
          )}
        </>
      )}
    </div>
  );
}
