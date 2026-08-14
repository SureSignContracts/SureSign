'use client';

import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import { ArrowLeft, ArrowRight, CreditCard, Loader2, ReceiptText, RefreshCw, ShieldCheck, WalletCards } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import { useBillingOverview, PORTAL_RETURN_FLAG_KEY } from '@/hooks/useBilling';
import EmptyState from '@/components/ui/EmptyState';
import InvoiceListSection from '@/components/billing/InvoiceListSection';
import PaymentListSection from '@/components/billing/PaymentListSection';
import BillingPortalCard from '@/components/billing/BillingPortalCard';
import { subscriptionStatusLabel } from '@/lib/billingStatus';

function Skeleton() {
  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
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
    <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:py-9">
      <div className="ss-animate-in">
        <Link href="/app/settings" className="inline-flex items-center gap-1.5 text-xs mb-4 transition-all duration-200 hover:opacity-70 hover:-translate-x-0.5" style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={13} /> Back to Settings
        </Link>
        <section className="overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_70px_rgba(24,33,29,0.16)]">
          <div className="p-7 sm:p-10">
            <p className="mb-7 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#9ee5b5]"><CreditCard size={14} /> Financial record</p>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Billing, without the bookkeeping fog.</h1>
            <p className="mt-4 max-w-2xl text-sm leading-6 text-[#b9c5bf] sm:text-base">Review payment methods, invoices and completed transactions in one dependable record.</p>
          </div>
          <div className="grid border-t border-white/10 sm:grid-cols-3">
            {[[WalletCards, 'Payment methods', 'Managed securely in Stripe'], [ReceiptText, 'Invoice trail', 'Downloadable billing records'], [ShieldCheck, 'Secure portal', 'Sensitive details stay protected']].map(([Icon, label, description]) => {
              const ItemIcon = Icon as typeof WalletCards;
              return <div key={label as string} className="flex items-start gap-3 px-7 py-5 sm:border-r sm:border-white/10 last:border-r-0"><ItemIcon size={16} className="mt-0.5 text-[#9ee5b5]" /><div><p className="text-sm font-semibold">{label as string}</p><p className="mt-1 text-xs text-[#8f9c96]">{description as string}</p></div></div>;
            })}
          </div>
        </section>
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
          <Link
            href="/app/settings/billing/subscription"
            className="group flex items-center justify-between rounded-2xl bg-[var(--bg-surface)] p-5 shadow-[0_12px_32px_rgba(24,33,29,0.07)] ss-animate-in transition-all duration-200 hover:-translate-y-0.5"
          >
            <div className="flex items-center gap-3">
              <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <RefreshCw size={16} style={{ color: 'var(--text-secondary)' }} />
              </div>
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                  {overview.has_subscription && !overview.subscription?.is_abandoned_checkout
                    ? `${overview.subscription?.plan_name ?? 'Subscription'} · ${subscriptionStatusLabel(overview.subscription?.status)}`
                    : 'Subscription'}
                </p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {overview.has_subscription && !overview.subscription?.is_abandoned_checkout
                    ? 'Manage your plan, upgrades and downgrades'
                    : 'Choose a plan to get started'}
                </p>
              </div>
            </div>
            <ArrowRight size={15} className="transition-transform duration-200 group-hover:translate-x-0.5" style={{ color: 'var(--text-muted)' }} />
          </Link>

          {overview.billing_customer && <BillingPortalCard />}

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <InvoiceListSection />
            <PaymentListSection timeZone={timeZone} />
          </div>
        </>
      )}
    </div>
  );
}
