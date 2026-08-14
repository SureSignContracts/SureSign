'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQueryClient } from '@tanstack/react-query';
import { Check, Sparkles, Loader2, Clock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { formatMoney } from '@/lib/currency';
import { minorToMajor, useCreateCheckout, useRequestPlanChange, useCancelPendingCheckout } from '@/hooks/useBilling';
import type { PurchasablePlan, PendingCheckoutSummary } from '@/hooks/useBilling';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PlanChangeConfirmDialog from '@/components/billing/PlanChangeConfirmDialog';
import PendingCheckoutConflictDialog from '@/components/billing/PendingCheckoutConflictDialog';
import BillingRedirectOverlay from '@/components/billing/BillingRedirectOverlay';
import toast from 'react-hot-toast';

type Interval = 'monthly' | 'annual';

function planActionLabel(
  plan: PurchasablePlan,
  hasSubscription: boolean,
  eligibleForPlanChange: boolean,
  hasPendingChange: boolean,
  currentIndex: number,
  planIndex: number,
  isPendingCheckoutPlan: boolean,
  pendingCheckoutIsResumable: boolean,
): string {
  if (isPendingCheckoutPlan) return pendingCheckoutIsResumable ? 'Continue Payment' : 'Start New Checkout';
  if (plan.is_current) return 'Current plan';
  if (!plan.is_self_serve) return plan.cta_text || 'Contact Sales';
  if (!hasSubscription) return 'Subscribe';
  if (!eligibleForPlanChange) return 'Not available right now';
  if (hasPendingChange) return 'Change already pending';
  return planIndex > currentIndex ? 'Upgrade' : 'Downgrade';
}

export default function PlanComparisonSection({
  plans,
  hasSubscription,
  eligibleForPlanChange = false,
  hasPendingChange = false,
  pendingCheckout = null,
}: {
  plans: PurchasablePlan[];
  hasSubscription: boolean;
  /** Subscription is active and has no pending cancellation — the only state a plan change may be requested from. */
  eligibleForPlanChange?: boolean;
  /** A plan change is already pending confirmation — blocks a conflicting new request (the backend also enforces this; this only avoids a round-trip for an obviously-blocked click). */
  hasPendingChange?: boolean;
  /** Awaiting-payment Checkout in progress, if any (Phase E4) — never null while status is 'pending_payment'. */
  pendingCheckout?: PendingCheckoutSummary | null;
}) {
  const [interval, setInterval] = useState<Interval>('monthly');
  const currentIndex = plans.findIndex(p => p.is_current);
  const checkout = useCreateCheckout();
  const planChange = useRequestPlanChange();
  const cancelPendingCheckout = useCancelPendingCheckout();
  const qc = useQueryClient();
  const [pendingCode, setPendingCode] = useState<string | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<{ plan: PurchasablePlan; changeType: 'upgrade' | 'downgrade' } | null>(null);
  const [conflictTarget, setConflictTarget] = useState<PurchasablePlan | null>(null);
  const [redirecting, setRedirecting] = useState(false);

  const anyAnnual = plans.some(p => p.annual);
  const anyMonthly = plans.some(p => p.monthly);

  const startCheckoutFor = (plan: PurchasablePlan, billingInterval: Interval) => {
    if (checkout.isPending || redirecting) return; // duplicate-click prevention
    setPendingCode(plan.code);
    setRedirecting(true);
    checkout.mutate(
      { plan_code: plan.code, billing_interval: billingInterval },
      {
        onSuccess: (session) => {
          if (session.checkout_url) {
            window.location.href = session.checkout_url;
          } else {
            toast.error('Checkout could not be started. Please try again.');
            setPendingCode(null);
            setRedirecting(false);
          }
        },
        onError: (err: unknown) => {
          toast.error(getErrorMessage(err, 'Checkout could not be started. Please try again.'));
          setPendingCode(null);
          setRedirecting(false);
        },
      },
    );
  };

  const handlePlanClick = (plan: PurchasablePlan) => {
    const isPendingCheckoutPlan = pendingCheckout?.plan_code === plan.code;

    // Continuing (or restarting) the SAME pending plan is always safe —
    // the backend transparently reuses a still-open session, or cleans up
    // a stale expired one before creating a fresh one. Uses the pending
    // checkout's OWN billing interval, not whatever toggle is selected —
    // switching the interval on the same plan is a different plan change,
    // not a "continue payment" action.
    if (isPendingCheckoutPlan && pendingCheckout) {
      return startCheckoutFor(plan, pendingCheckout.billing_interval);
    }

    // A DIFFERENT plan while a still-resumable pending Checkout exists —
    // never silently discard it (Stage 8, Option A): ask first.
    if (pendingCheckout?.is_resumable) {
      setConflictTarget(plan);
      return;
    }

    // No pending Checkout, or an already-expired one — safe to proceed
    // directly (the backend auto-invalidates a stale expired attempt).
    startCheckoutFor(plan, interval);
  };

  const handleConfirmPlanChange = () => {
    if (!confirmTarget || planChange.isPending) return;
    planChange.mutate(
      { plan_code: confirmTarget.plan.code, billing_interval: interval },
      {
        onSuccess: () => {
          toast.success(
            confirmTarget.changeType === 'upgrade'
              ? 'Upgrade requested — confirming with Stripe now.'
              : 'Downgrade scheduled for your next renewal date.',
          );
          setConfirmTarget(null);
          qc.invalidateQueries({ queryKey: ['billing'] });
        },
        onError: (err: unknown) => {
          toast.error(getErrorMessage(err, 'This plan change could not be completed. Please try again.'));
        },
      },
    );
  };

  const handleCancelPendingThenContinue = () => {
    if (!conflictTarget || cancelPendingCheckout.isPending) return;
    const target = conflictTarget;
    cancelPendingCheckout.mutate(undefined, {
      onSuccess: () => {
        toast.success('Pending Checkout cancelled.');
        qc.invalidateQueries({ queryKey: ['billing'] });
        setConflictTarget(null);
        startCheckoutFor(target, interval);
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'This pending Checkout could not be cancelled. Please try again.'));
      },
    });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-end justify-between flex-wrap gap-4">
        <div>
          <p className="text-[10px] font-semibold uppercase tracking-[0.15em] text-[#3f8f60]">Plan comparison</p>
          <h2 className="mt-1 text-2xl font-semibold tracking-[-0.03em]" style={{ color: 'var(--text-primary)' }}>Find your operating level</h2>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Clear capabilities and predictable billing for every stage of growth.</p>
        </div>
        {anyMonthly && anyAnnual && (
          <div className="flex w-fit gap-1 rounded-xl bg-[var(--bg-surface)] p-1 shadow-[0_5px_18px_rgba(24,33,29,0.06)]">
            {(['monthly', 'annual'] as const).map(i => (
              <button
                key={i}
                onClick={() => setInterval(i)}
                className="px-3.5 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
                style={interval === i
                ? { backgroundColor: '#18211d', color: '#ffffff' }
                  : { color: 'var(--text-secondary)' }}
              >
                {i === 'monthly' ? 'Monthly' : 'Annual'}
              </button>
            ))}
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {plans.map((plan, i) => {
          const price = interval === 'monthly' ? plan.monthly : plan.annual;
          const isPendingCheckoutPlan = pendingCheckout?.plan_code === plan.code;
          const actionLabel = planActionLabel(
            plan, hasSubscription, eligibleForPlanChange, hasPendingChange, currentIndex, i,
            isPendingCheckoutPlan, !!pendingCheckout?.is_resumable,
          );
          // A pending-checkout plan is always clickable (Continue Payment /
          // Start New Checkout) — never trapped behind is_current/hasSubscription
          // logic that no longer applies to it (Phase E4 fix).
          const canSubscribe = isPendingCheckoutPlan
            || (!plan.is_current && !hasSubscription && plan.is_self_serve && price !== null);
          const canRequestChange = !isPendingCheckoutPlan && !plan.is_current && hasSubscription && plan.is_self_serve && price !== null
            && eligibleForPlanChange && !hasPendingChange;
          const isCheckoutPending = redirecting && pendingCode === plan.code;

          const onClick = () => {
            if (canSubscribe) return handlePlanClick(plan);
            if (canRequestChange) {
              setConfirmTarget({ plan, changeType: i > currentIndex ? 'upgrade' : 'downgrade' });
            }
          };

          return (
            <div
              key={plan.code}
              className="ss-animate-in group relative flex min-h-[360px] flex-col gap-4 overflow-hidden rounded-2xl p-6 transition-all duration-300 ease-out hover:-translate-y-1"
              style={{
                animationDelay: `${Math.min(i * 45, 360)}ms`,
                backgroundColor: plan.is_popular ? '#18211d' : 'var(--bg-surface)',
                boxShadow: plan.is_popular ? '0 24px 60px rgba(24,33,29,0.18)' : '0 12px 34px rgba(24,33,29,0.07)',
              }}
            >
              <span className={`pointer-events-none absolute right-4 top-0 text-[92px] font-semibold leading-none ${plan.is_popular ? 'text-white/[0.035]' : 'text-[#18211d]/[0.035]'}`}>0{i + 1}</span>
              {plan.is_popular && !plan.is_current && !isPendingCheckoutPlan && (
                <div
                  className="relative z-10 flex w-fit items-center gap-1 rounded-lg bg-[#9ee5b5] px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.1em] text-[#18211d]"
                >
                  <Sparkles size={11} /> Recommended
                </div>
              )}

              <div className="relative z-10">
                <div className="flex items-center gap-2">
                  <h3 className={`text-xl font-semibold tracking-[-0.025em] ${plan.is_popular ? 'text-white' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-primary)' }}>{plan.name}</h3>
                  {plan.is_current && <Badge tone="accent">Current</Badge>}
                  {isPendingCheckoutPlan && <Badge tone="warning">Awaiting Payment</Badge>}
                </div>
                {plan.summary && (
                  <p className={`mt-2 text-xs leading-5 ${plan.is_popular ? 'text-white/45' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-muted)' }}>{plan.summary}</p>
                )}
              </div>

              <p key={`${plan.code}-${interval}`} className={`relative z-10 mt-2 text-3xl font-semibold tabular-nums tracking-[-0.04em] ss-menu-pop-in ${plan.is_popular ? 'text-[#9ee5b5]' : 'text-[#18211d]'}`}>
                {price ? (
                  <>
                    {formatMoney(minorToMajor(price.unit_amount), price.currency)}
                    <span className={`text-sm font-normal ${plan.is_popular ? 'text-white/35' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-muted)' }}>
                      {interval === 'monthly' ? '/month' : '/year'} + VAT
                    </span>
                  </>
                ) : !plan.is_self_serve ? (
                  <span className={`text-sm font-normal ${plan.is_popular ? 'text-white/45' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-muted)' }}>Custom pricing</span>
                ) : (
                  <span className={`text-sm font-normal ${plan.is_popular ? 'text-white/45' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-muted)' }}>Pricing not yet available</span>
                )}
              </p>

              {plan.description && (
                <p className={`relative z-10 flex-1 text-xs leading-5 ${plan.is_popular ? 'text-white/55' : ''}`} style={plan.is_popular ? undefined : { color: 'var(--text-secondary)' }}>{plan.description}</p>
              )}

              {isPendingCheckoutPlan && (
                <p className="text-xs flex items-center gap-1.5 ss-menu-pop-in" style={{ color: '#facc15' }}>
                  <Clock size={12} className={pendingCheckout?.is_resumable ? 'animate-pulse' : undefined} />
                  {pendingCheckout?.is_resumable ? 'Payment not yet completed.' : 'Checkout expired.'}
                </p>
              )}

              {!plan.is_self_serve && plan.cta_url && !isPendingCheckoutPlan ? (
                <Link
                  href={plan.cta_url}
                  className="relative z-10 mt-auto w-full rounded-xl py-3 text-center text-sm font-medium transition-all duration-200 hover:-translate-y-0.5 active:scale-[0.98]"
                  style={{ backgroundColor: plan.is_popular ? '#9ee5b5' : 'var(--bg-elevated)', color: plan.is_popular ? '#18211d' : 'var(--text-primary)' }}
                >
                  {actionLabel}
                </Link>
              ) : (
                <button
                  disabled={(!canSubscribe && !canRequestChange) || isCheckoutPending}
                  onClick={onClick}
                  aria-busy={isCheckoutPending}
                  title={canSubscribe || canRequestChange ? undefined : 'Not yet available in this release'}
                  className="relative z-10 mt-auto flex w-full items-center justify-center gap-1.5 rounded-xl py-3 text-sm font-medium transition-all duration-200 hover:enabled:-translate-y-0.5 active:scale-[0.98] disabled:cursor-not-allowed"
                  style={(canSubscribe || canRequestChange)
                    ? { backgroundColor: plan.is_popular ? '#9ee5b5' : '#18211d', color: plan.is_popular ? '#18211d' : '#ffffff' }
                    : plan.is_current
                      ? { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }
                      : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', opacity: 0.7 }}
                >
                  {isCheckoutPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : plan.is_current ? (
                    <Check size={14} />
                  ) : null}
                  {isCheckoutPending ? 'Starting checkout…' : actionLabel}
                </button>
              )}
            </div>
          );
        })}
      </div>

      {confirmTarget && (
        <PlanChangeConfirmDialog
          planName={confirmTarget.plan.name}
          changeType={confirmTarget.changeType}
          isPending={planChange.isPending}
          onConfirm={handleConfirmPlanChange}
          onClose={() => setConfirmTarget(null)}
        />
      )}

      {conflictTarget && pendingCheckout && (
        <PendingCheckoutConflictDialog
          pendingPlanName={pendingCheckout.plan_name}
          targetPlanName={conflictTarget.name}
          isContinuing={redirecting}
          isCancelling={cancelPendingCheckout.isPending}
          onContinue={() => {
            const pendingPlan = plans.find(p => p.code === pendingCheckout.plan_code);
            setConflictTarget(null);
            if (pendingPlan) startCheckoutFor(pendingPlan, pendingCheckout.billing_interval);
          }}
          onCancelPending={handleCancelPendingThenContinue}
          onClose={() => setConflictTarget(null)}
        />
      )}

      {redirecting && <BillingRedirectOverlay variant="checkout" />}
    </div>
  );
}
