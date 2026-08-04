'use client';

import { useState } from 'react';
import { useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { CheckCircle2, Loader2, AlertTriangle, RefreshCw, Check } from 'lucide-react';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import type { BillingOverview } from '@/hooks/useBilling';

const MAX_POLLS = 15; // ~45s of conservative polling before giving up automatically
const POLL_INTERVAL_MS = 3000;

type StepState = 'done' | 'active' | 'pending';

/** Purely presentational — reflects existing state, introduces no new logic or delay. */
function JourneyStep({ label, state }: { label: string; state: StepState }) {
  return (
    <div className="flex items-center gap-2">
      <div
        className="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 transition-colors duration-300"
        style={
          state === 'done'
            ? { backgroundColor: 'rgba(74,222,128,0.16)', color: '#4ade80' }
            : state === 'active'
              ? { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }
              : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }
        }
      >
        {state === 'done' ? <Check size={11} /> : state === 'active' ? <Loader2 size={11} className="animate-spin" /> : <span className="w-1 h-1 rounded-full bg-current" />}
      </div>
      <span
        className="text-xs font-medium transition-colors duration-300"
        style={{ color: state === 'pending' ? 'var(--text-muted)' : 'var(--text-secondary)' }}
      >
        {label}
      </span>
    </div>
  );
}

/**
 * Never claims activation itself — the `session_id` query string value
 * (Stripe's own `{CHECKOUT_SESSION_ID}` placeholder, substituted by
 * Stripe on redirect) is used only as a display correlation reference.
 * The ONLY source of truth for "is the subscription active" is
 * GET /billing/overview, which only ever reflects a verified, persisted,
 * webhook-confirmed state (see BillingOverviewService/SubscriptionAccessPolicy).
 * The step tracker below is purely presentational sugar over that exact
 * same state — it introduces no new source of truth and no delay.
 */
export default function CheckoutSuccessPage() {
  const searchParams = useSearchParams();
  const sessionId = searchParams.get('session_id');
  const [pollCount, setPollCount] = useState(0);
  const [manualRefreshKey, setManualRefreshKey] = useState(0);

  const { data: overview, isLoading, isError, refetch, isRefetching } = useQuery<BillingOverview>({
    queryKey: ['billing', 'overview', 'checkout-success', manualRefreshKey],
    queryFn: () => api.get('/billing/overview').then(r => {
      setPollCount(c => c + 1);
      return r.data;
    }),
    refetchInterval: (query) => {
      const status = query.state.data?.subscription?.status;
      const settled = status === 'active' || status === 'trialing' || status === 'past_due'
        || status === 'unpaid' || status === 'cancelled' || status === 'expired' || status === 'suspended';
      if (settled) return false;
      if (pollCount >= MAX_POLLS) return false;
      return POLL_INTERVAL_MS;
    },
  });

  const status = overview?.subscription?.status;
  const isActive = status === 'active' || status === 'trialing';
  const isTerminalNonActive = status === 'cancelled' || status === 'expired' || status === 'suspended';
  const stillPending = !isActive && !isTerminalNonActive;
  const gaveUpPolling = stillPending && pollCount >= MAX_POLLS;
  const showJourney = !isLoading && !isError && (stillPending || isActive);

  const handleManualRefresh = () => {
    setPollCount(0);
    setManualRefreshKey(k => k + 1);
    refetch();
  };

  return (
    <div className="p-6 max-w-2xl mx-auto">
      <div
        className="rounded-2xl px-6 py-8 sm:p-10 text-center space-y-5 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <div role="status" aria-live="polite" className="space-y-4">
          {isLoading ? (
            <>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <Loader2 size={24} className="animate-spin motion-reduce:animate-none" style={{ color: 'var(--text-muted)' }} />
              </div>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading your Billing status…</p>
            </>
          ) : isError ? (
            <>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto" style={{ backgroundColor: 'rgba(239,68,68,0.1)' }}>
                <AlertTriangle size={24} style={{ color: '#f87171' }} />
              </div>
              <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Couldn&apos;t check your subscription status</h1>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                Checkout completed, but we couldn&apos;t confirm your subscription status right now. Please refresh or check back shortly.
              </p>
            </>
          ) : isActive ? (
            <>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto ss-menu-pop-in" style={{ backgroundColor: 'rgba(74,222,128,0.12)' }}>
                <CheckCircle2 size={26} style={{ color: '#4ade80' }} />
              </div>
              <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Your subscription is active</h1>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                {overview?.subscription?.plan_name ?? 'Your plan'} is now active for your organisation.
              </p>
            </>
          ) : isTerminalNonActive ? (
            <>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto" style={{ backgroundColor: 'rgba(234,179,8,0.12)' }}>
                <AlertTriangle size={24} style={{ color: '#facc15' }} />
              </div>
              <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>We couldn&apos;t confirm an active subscription</h1>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                Your Billing status shows as {status}. If you believe this is a mistake, please contact support.
              </p>
            </>
          ) : (
            <>
              <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto" style={{ backgroundColor: 'var(--gold-15)' }}>
                <Loader2 size={24} className="animate-spin motion-reduce:animate-none" style={{ color: 'var(--gold)' }} />
              </div>
              <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Checkout completed</h1>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                We are confirming your subscription. This usually takes a few seconds.
                {gaveUpPolling && ' This is taking longer than usual — you can keep waiting or refresh manually.'}
              </p>
            </>
          )}
        </div>

        {showJourney && (
          <div className="flex items-center justify-center gap-4 flex-wrap py-1">
            <JourneyStep label="Payment received" state="done" />
            <div className="w-4 h-px" style={{ backgroundColor: 'var(--border)' }} />
            <JourneyStep label="Confirming with Stripe" state={isActive ? 'done' : 'active'} />
            <div className="w-4 h-px" style={{ backgroundColor: 'var(--border)' }} />
            <JourneyStep label="Subscription active" state={isActive ? 'done' : 'pending'} />
          </div>
        )}

        <div className="flex items-center justify-center gap-3 pt-2 flex-wrap">
          <Button variant="secondary" size="sm" onClick={handleManualRefresh} disabled={isRefetching}>
            <RefreshCw size={13} className={isRefetching ? 'animate-spin' : undefined} /> Refresh
          </Button>
          <Link href="/app/settings/billing/subscription">
            <Button variant="primary" size="sm">Go to Subscription</Button>
          </Link>
        </div>

        {sessionId && (
          <p className="text-[11px] pt-2" style={{ color: 'var(--text-muted)' }}>
            Reference: {sessionId.slice(0, 20)}…
          </p>
        )}
      </div>
    </div>
  );
}
