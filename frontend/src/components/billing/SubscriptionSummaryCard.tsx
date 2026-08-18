'use client';

import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { XCircle, Undo2, Loader2, Clock } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import { formatMoney } from '@/lib/currency';
import { formatDateTime } from '@/lib/dateTime';
import { minorToMajor, useCancelSubscription, useResumeSubscription, useCreateCheckout, useCancelPendingCheckout } from '@/hooks/useBilling';
import type { SubscriptionSummary } from '@/hooks/useBilling';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { subscriptionStatusLabel, subscriptionStatusTone, billingIntervalSuffix } from '@/lib/billingStatus';
import CancelSubscriptionConfirmDialog from '@/components/billing/CancelSubscriptionConfirmDialog';
import CancelPendingCheckoutConfirmDialog from '@/components/billing/CancelPendingCheckoutConfirmDialog';
import BillingRedirectOverlay from '@/components/billing/BillingRedirectOverlay';
import toast from '@/lib/toast';

function Field({ label, value }: { label: string; value: React.ReactNode }) {
  if (value === null || value === undefined || value === '') return null;
  return (
    <div>
      <p className="text-xs mb-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}

export default function SubscriptionSummaryCard({
  subscription,
  timeZone,
  hasPendingPlanChange = false,
}: {
  subscription: SubscriptionSummary;
  timeZone?: string;
  /** A pending upgrade/downgrade blocks cancellation (Non-negotiable rule 15) — the backend also enforces this; this only avoids an obviously-blocked round trip. */
  hasPendingPlanChange?: boolean;
}) {
  const planName = subscription.plan_name ?? subscription.plan_name_snapshot ?? subscription.plan_code ?? 'Current plan';
  const cancelSubscription = useCancelSubscription();
  const resumeSubscription = useResumeSubscription();
  const checkout = useCreateCheckout();
  const cancelPendingCheckout = useCancelPendingCheckout();
  const qc = useQueryClient();
  const [confirmingCancel, setConfirmingCancel] = useState(false);
  const [confirmingCancelPending, setConfirmingCancelPending] = useState(false);
  const [redirecting, setRedirecting] = useState(false);

  const canCancel = subscription.status === 'active' && !subscription.cancel_at_period_end && !hasPendingPlanChange;
  const isAwaitingPayment = subscription.status === 'pending_payment';
  const pendingCheckout = subscription.pending_checkout;

  const handleContinuePayment = () => {
    if (!pendingCheckout || checkout.isPending || redirecting) return;
    setRedirecting(true);
    checkout.mutate(
      { plan_code: pendingCheckout.plan_code, billing_interval: pendingCheckout.billing_interval },
      {
        onSuccess: (session) => {
          if (session.checkout_url) {
            window.location.href = session.checkout_url;
          } else {
            toast.error('Checkout could not be started. Please try again.');
            setRedirecting(false);
          }
        },
        onError: (err: unknown) => {
          toast.error(getErrorMessage(err, 'Checkout could not be started. Please try again.'));
          setRedirecting(false);
        },
      },
    );
  };

  const handleCancelPendingCheckout = () => {
    cancelPendingCheckout.mutate(undefined, {
      onSuccess: () => {
        toast.success('Pending Checkout cancelled. You can choose a plan again below.');
        setConfirmingCancelPending(false);
        qc.invalidateQueries({ queryKey: ['billing'] });
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'This pending Checkout could not be cancelled. Please try again.'));
        setConfirmingCancelPending(false);
      },
    });
  };

  const handleCancel = () => {
    cancelSubscription.mutate(undefined, {
      onSuccess: () => {
        toast.success('Cancellation scheduled for the end of your billing period.');
        setConfirmingCancel(false);
        qc.invalidateQueries({ queryKey: ['billing'] });
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'This subscription could not be cancelled.'));
        setConfirmingCancel(false);
      },
    });
  };

  const handleResume = () => {
    resumeSubscription.mutate(undefined, {
      onSuccess: () => {
        toast.success('Cancellation undone — your subscription will continue as normal.');
        qc.invalidateQueries({ queryKey: ['billing'] });
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'This cancellation could not be undone.'));
      },
    });
  };

  return (
    <Card className="ss-animate-in transition-shadow duration-300 hover:shadow-[var(--shadow-pop)]">
      <CardHeader>
        {/* Phase E4: never label an unactivated subscription "Current" — see Stage 2. */}
        <CardTitle>{isAwaitingPayment ? 'Pending Checkout' : 'Current Subscription'}</CardTitle>
        <Badge tone={subscriptionStatusTone(subscription.status)}>{subscriptionStatusLabel(subscription.status)}</Badge>
      </CardHeader>
      <CardBody className="space-y-5">
        <div className="flex items-baseline gap-2">
          <h3 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>{planName}</h3>
          <span className="text-sm" style={{ color: 'var(--text-muted)' }}>
            {formatMoney(minorToMajor(subscription.total_amount), subscription.currency)}
            {billingIntervalSuffix(subscription.billing_interval)}
          </span>
        </div>

        {isAwaitingPayment && (
          <div
            className="flex gap-3 p-4 rounded-xl ss-animate-in"
            style={{ backgroundColor: 'rgba(234,179,8,0.07)', border: '1px solid rgba(234,179,8,0.2)' }}
          >
            <div
              className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
              style={{ backgroundColor: 'rgba(234,179,8,0.14)', color: '#facc15' }}
            >
              <Clock size={15} />
            </div>
            <div className="space-y-3 min-w-0">
              <p className="text-xs leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
                {pendingCheckout?.is_resumable ? (
                  <>
                    Checkout for <strong style={{ color: 'var(--text-primary)' }}>{planName}</strong> has not yet been
                    completed. Your subscription has not been activated, no payment has been taken, and your access has
                    not changed.
                  </>
                ) : (
                  <>
                    Your Checkout session for <strong style={{ color: 'var(--text-primary)' }}>{planName}</strong> has
                    expired. No payment was taken and nothing was activated — start a new Checkout below whenever
                    you&apos;re ready.
                  </>
                )}
              </p>
              <div className="flex items-center gap-3 flex-wrap">
                <Button variant="primary" size="sm" onClick={handleContinuePayment} disabled={redirecting}>
                  {redirecting ? <Loader2 size={13} className="animate-spin" /> : null}
                  {redirecting ? 'Starting…' : pendingCheckout?.is_resumable ? 'Continue Payment' : 'Start New Checkout'}
                </Button>
                <button
                  onClick={() => setConfirmingCancelPending(true)}
                  className="inline-flex items-center gap-1.5 text-xs font-medium transition-all duration-200 hover:opacity-70 active:scale-95"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <XCircle size={13} /> Cancel pending Checkout
                </button>
              </div>
            </div>
          </div>
        )}

        {subscription.cancel_at_period_end && (
          <div
            className="flex items-center justify-between gap-3 flex-wrap p-3.5 rounded-xl ss-animate-in"
            style={{ backgroundColor: 'rgba(234,179,8,0.07)', border: '1px solid rgba(234,179,8,0.2)' }}
          >
            <p className="text-xs flex items-center gap-2" style={{ color: 'var(--text-secondary)' }}>
              <Clock size={14} className="flex-shrink-0" style={{ color: '#facc15' }} />
              Cancellation scheduled. Full access continues until{' '}
              <strong style={{ color: 'var(--text-primary)' }}>
                {subscription.current_period_ends_at ? formatDateTime(subscription.current_period_ends_at, { timeZone }) : 'your period end'}
              </strong>.
            </p>
            {subscription.can_resume_cancellation && (
              <Button variant="secondary" size="sm" onClick={handleResume} disabled={resumeSubscription.isPending}>
                {resumeSubscription.isPending ? <Loader2 size={13} className="animate-spin" /> : <Undo2 size={13} />}
                Undo cancellation
              </Button>
            )}
          </div>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-3 gap-4">
          <Field label="Current period start" value={subscription.current_period_starts_at ? formatDateTime(subscription.current_period_starts_at, { timeZone }) : null} />
          <Field label="Renews / period ends" value={subscription.current_period_ends_at ? formatDateTime(subscription.current_period_ends_at, { timeZone }) : null} />
          <Field label="Trial ends" value={subscription.trial_ends_at ? formatDateTime(subscription.trial_ends_at, { timeZone }) : null} />
          <Field label="Grace period ends" value={subscription.grace_period_ends_at ? formatDateTime(subscription.grace_period_ends_at, { timeZone }) : null} />
          <Field label="Cancelled" value={subscription.cancelled_at ? formatDateTime(subscription.cancelled_at, { timeZone }) : null} />
          <Field label="Ended" value={subscription.ended_at ? formatDateTime(subscription.ended_at, { timeZone }) : null} />
        </div>

        {canCancel && (
          <div className="pt-1 border-t" style={{ borderColor: 'var(--border)' }}>
            <button
              onClick={() => setConfirmingCancel(true)}
              className="mt-4 inline-flex items-center gap-1.5 text-xs font-medium transition-all duration-200 hover:opacity-70 active:scale-95"
              style={{ color: 'var(--text-muted)' }}
            >
              <XCircle size={13} /> Cancel subscription
            </button>
          </div>
        )}
      </CardBody>

      {confirmingCancel && (
        <CancelSubscriptionConfirmDialog
          periodEndsAt={subscription.current_period_ends_at}
          timeZone={timeZone}
          isPending={cancelSubscription.isPending}
          onConfirm={handleCancel}
          onClose={() => setConfirmingCancel(false)}
        />
      )}

      {confirmingCancelPending && (
        <CancelPendingCheckoutConfirmDialog
          planName={planName}
          isPending={cancelPendingCheckout.isPending}
          onConfirm={handleCancelPendingCheckout}
          onClose={() => setConfirmingCancelPending(false)}
        />
      )}

      {redirecting && <BillingRedirectOverlay variant="checkout" />}
    </Card>
  );
}
