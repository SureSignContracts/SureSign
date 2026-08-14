'use client';

import Link from 'next/link';
import { ArrowLeft, RefreshCw, CreditCard, ShieldCheck, CalendarSync, ArrowDown } from 'lucide-react';
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
    <div className="mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">
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
        <section className="relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
          <div className="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
          <div className="relative flex flex-wrap items-start justify-between gap-6 px-6 py-7 sm:px-8 sm:py-8">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.15em] text-[#9ee5b5]">Workspace subscription</p>
              <h1 className="mt-4 text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Choose how your team operates.</h1>
              <p className="mt-3 max-w-2xl text-sm leading-6 text-white/50">Select the level of contract control, commercial workflow and support that fits your organisation.</p>
            </div>
            <a href="#plans" className="inline-flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-3 text-sm font-semibold text-[#18211d] transition hover:-translate-y-0.5 hover:bg-[#b5edc7]">Compare plans <ArrowDown size={15} /></a>
          </div>
          <div className="relative grid border-t border-white/10 sm:grid-cols-3">
            {[
              { icon: CreditCard, label: 'Secure checkout', detail: 'Payments handled by Stripe' },
              { icon: CalendarSync, label: 'Flexible billing', detail: 'Monthly or annual terms' },
              { icon: ShieldCheck, label: 'Controlled access', detail: 'Plan limits stay visible' },
            ].map(item => (
              <div key={item.label} className="flex items-center gap-3 border-b border-white/10 px-6 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0">
                <item.icon size={15} className="text-[#9ee5b5]" />
                <div><p className="text-xs font-medium text-white/75">{item.label}</p><p className="mt-0.5 text-[11px] text-white/30">{item.detail}</p></div>
              </div>
            ))}
          </div>
        </section>
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
          {overview.access.mode !== 'none' && (
            <AccessStatusBanner
              access={overview.access}
              graceEndsAt={overview.subscription?.grace_period_ends_at}
              timeZone={timeZone}
            />
          )}

          {(!overview.has_subscription || overview.subscription?.is_abandoned_checkout) && (
            <div className="ss-animate-in flex items-start gap-4 rounded-2xl bg-[#e7eee9] p-5">
              <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-xl bg-white text-[#247044] shadow-[0_5px_16px_rgba(24,33,29,0.05)]"><CreditCard size={17} /></div>
              <div><p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                {overview.subscription?.is_abandoned_checkout
                  ? 'Your previous Checkout was cancelled before payment was taken.'
                  : 'Your workspace is ready for a plan.'}
              </p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {overview.subscription?.is_abandoned_checkout
                  ? 'No subscription was created and nothing was charged. Choose a plan below whenever you’re ready.'
                  : 'Compare the operating levels below and continue through secure Stripe Checkout when you are ready.'}
              </p>
              </div>
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
